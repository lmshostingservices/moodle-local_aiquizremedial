<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_aiquizremedial\insights;

/**
 * Suggested-action lifecycle (v1.5.0): upsert from rules, de-duplication, re-triggering,
 * auto-resolve, impact measurement, audit log and notifications.
 *
 * Statuses: open, acknowledged, inprogress, done, dismissed, snoozed, resolved (system).
 *
 * @package    local_aiquizremedial
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
class actions {
    /** Live statuses (the problem is still being tracked). */
    const LIVE = ['open', 'acknowledged', 'inprogress', 'snoozed'];

    /** All statuses a person can set. */
    const USER_STATUSES = ['open', 'acknowledged', 'inprogress', 'done', 'dismissed', 'snoozed'];

    /** Dismiss reasons. */
    const DISMISS_REASONS = ['intended', 'cohort', 'falsepositive', 'other'];

    /** Rules that notify immediately (they can affect grades). */
    const URGENT = ['R2', 'R3'];

    /** @var callable */
    protected $log;

    /**
     * Constructor.
     *
     * @param callable|null $log
     */
    public function __construct(?callable $log = null) {
        $this->log = $log ?? function (string $line) {
            mtrace($line);
        };
    }

    /**
     * De-duplication key.
     *
     * @param array $c candidate
     * @return string
     */
    public static function key(array $c): string {
        return implode('|', [$c['ruleid'], $c['courseid'], $c['sourcetype'] ?? '-', $c['activityid'], $c['qbeid'],
            $c['groupid']]);
    }

    /**
     * Store rule results: create, refresh, re-open or auto-resolve actions.
     *
     * @param array[] $candidates from rules::evaluate()
     * @param int $courseid 0 = all courses (auto-resolve is limited to the same scope)
     * @return array counts
     */
    public function sync(array $candidates, int $courseid = 0): array {
        global $DB;
        $now = time();
        $counts = ['created' => 0, 'updated' => 0, 'reopened' => 0, 'resolved' => 0];
        $fired = [];
        foreach ($candidates as $c) {
            $key = self::key($c);
            $fired[$key] = true;
            $evidence = json_encode($c['evidence'], JSON_UNESCAPED_UNICODE);
            $existing = $DB->get_record('local_aiqr_action', ['dedupekey' => $key]);
            if (!$existing) {
                $id = $DB->insert_record('local_aiqr_action', (object) [
                    'ruleid' => $c['ruleid'], 'dedupekey' => $key, 'severity' => $c['severity'], 'status' => 'open',
                    'courseid' => $c['courseid'], 'categoryid' => (int) $DB->get_field('course', 'category',
                        ['id' => $c['courseid']]),
                    'sourcetype' => $c['sourcetype'], 'activityid' => $c['activityid'], 'qbeid' => $c['qbeid'],
                    'groupid' => $c['groupid'], 'assigneeid' => max(0, (int) $c['assigneeid']), 'evidence' => $evidence,
                    'misses' => 0, 'snoozeuntil' => 0, 'notified' => 0, 'timecreated' => $now, 'timemodified' => $now,
                    'timeresolved' => 0,
                ]);
                self::log_change($id, 0, null, 'open', null, $evidence);
                $counts['created']++;
                if (in_array($c['ruleid'], self::URGENT, true)) {
                    self::notify_urgent($DB->get_record('local_aiqr_action', ['id' => $id]));
                }
                continue;
            }
            $update = (object) ['id' => $existing->id, 'evidence' => $evidence, 'misses' => 0, 'timemodified' => $now];
            $old = json_decode((string) $existing->evidence, true) ?: [];
            $reopen = null;
            switch ($existing->status) {
                case 'resolved':
                    $reopen = 'returned';
                    break;
                case 'snoozed':
                    if ((int) $existing->snoozeuntil <= $now) {
                        $reopen = 'snoozeended';
                    }
                    break;
                case 'dismissed':
                    $worse = rules::SEVERITY[$c['severity']] > rules::SEVERITY[$existing->severity];
                    $doubled = isset($c['evidence']['n'], $old['n']) && (int) $c['evidence']['n'] >= 2 * max(1, (int) $old['n']);
                    $newversion = isset($c['evidence']['version'], $old['version'])
                        && (int) $c['evidence']['version'] > (int) $old['version'];
                    if ($worse || $doubled || $newversion) {
                        $reopen = 'worse';
                    } else {
                        // Keep the evidence from when it was dismissed so "n doubled" stays measurable.
                        unset($update->evidence);
                    }
                    break;
                case 'done':
                    // Impact is measured separately; the condition still being true after a fix
                    // is reported by the impact check, not by re-opening straight away.
                    unset($update->evidence);
                    break;
            }
            if (in_array($existing->status, self::LIVE, true) || $reopen) {
                $update->severity = $c['severity'];
            }
            if ($reopen) {
                $update->status = 'open';
                $update->snoozeuntil = 0;
                $update->timeresolved = 0;
                $counts['reopened']++;
                self::log_change($existing->id, 0, $existing->status, 'open', 'reopen:' . $reopen, $evidence);
            } else {
                $counts['updated']++;
            }
            $DB->update_record('local_aiqr_action', $update);
        }

        // Live actions whose condition no longer holds: auto-resolve after two clean runs, but
        // only if nobody has picked them up (status still "open").
        $select = "status IN ('open', 'acknowledged', 'inprogress', 'snoozed')";
        $params = [];
        if ($courseid) {
            $select .= ' AND courseid = :courseid';
            $params['courseid'] = $courseid;
        }
        foreach ($DB->get_records_select('local_aiqr_action', $select, $params) as $a) {
            if (isset($fired[$a->dedupekey])) {
                continue;
            }
            $misses = (int) $a->misses + 1;
            if ($misses >= 2 && $a->status === 'open') {
                $DB->update_record('local_aiqr_action', (object) ['id' => $a->id, 'status' => 'resolved', 'misses' => $misses,
                    'timeresolved' => $now, 'timemodified' => $now]);
                self::log_change($a->id, 0, 'open', 'resolved', 'autoresolved', $a->evidence);
                $counts['resolved']++;
            } else {
                $DB->set_field('local_aiqr_action', 'misses', $misses, ['id' => $a->id]);
                if ($a->status === 'snoozed' && (int) $a->snoozeuntil <= $now) {
                    $DB->update_record('local_aiqr_action', (object) ['id' => $a->id, 'status' => 'open', 'snoozeuntil' => 0,
                        'timemodified' => $now]);
                    self::log_change($a->id, 0, 'snoozed', 'open', 'reopen:snoozeended', null);
                }
            }
        }
        ($this->log)('  [AIQR-ACTIONS] ' . json_encode($counts));
        return $counts;
    }

    /**
     * Before/after impact for actions marked done.
     *
     * The baseline is stored when the action is marked done. Once 10 new first attempts exist
     * after that date, the result is Improved (+10 points or more), Worse (-5 points or more)
     * or No change. A fix that made things worse re-opens the action as "fix didn't work".
     * After 60 days without enough attempts the result is "not enough new attempts".
     */
    public function measure_impact(): void {
        global $DB;
        $done = $DB->get_records_select('local_aiqr_action', "status = 'done' AND impact IS NULL AND timeresolved > 0");
        foreach ($done as $a) {
            $baseline = json_decode((string) $a->baseline, true) ?: [];
            if (!isset($baseline['value'])) {
                continue;
            }
            $after = self::current_metric($a, (int) $a->timeresolved);
            $impact = null;
            if ($after && $after['n'] >= 10) {
                $delta = $after['value'] - (float) $baseline['value'];
                $verdict = $delta >= 0.10 ? 'improved' : ($delta <= -0.05 ? 'worse' : 'nochange');
                $impact = ['before' => (float) $baseline['value'], 'beforen' => (int) ($baseline['n'] ?? 0),
                    'after' => round($after['value'], 4), 'aftern' => $after['n'], 'verdict' => $verdict,
                    'metric' => $baseline['metric'] ?? 'facility', 'time' => time()];
            } else if (time() - (int) $a->timeresolved > 60 * DAYSECS) {
                $impact = ['verdict' => 'insufficient', 'aftern' => $after['n'] ?? 0, 'time' => time()];
            }
            if (!$impact) {
                continue;
            }
            $update = (object) ['id' => $a->id, 'impact' => json_encode($impact), 'timemodified' => time()];
            if ($impact['verdict'] === 'worse') {
                $update->status = 'open';
                $update->timeresolved = 0;
                $update->impact = null;
                $evidence = json_decode((string) $a->evidence, true) ?: [];
                $evidence['fixfailed'] = $impact;
                $update->evidence = json_encode($evidence);
                self::log_change($a->id, 0, 'done', 'open', 'reopen:fixfailed', $update->evidence);
            } else {
                self::log_change($a->id, 0, 'done', 'done', 'impact:' . $impact['verdict'], json_encode($impact));
            }
            $DB->update_record('local_aiqr_action', $update);
        }
    }

    /**
     * The metric an action is measured by, from first attempts after $since (or overall).
     *
     * Question rules: % correct first try. R8: revision completion. R11: the group's % correct.
     *
     * @param \stdClass $a action
     * @param int $since 0 = the whole insights window
     * @return array|null ['value' => float 0..1, 'n' => int, 'metric' => string]
     */
    public static function current_metric(\stdClass $a, int $since = 0): ?array {
        global $DB;
        $from = $since ?: time() - builder::window_days() * DAYSECS;
        if ($a->ruleid === 'R8') {
            $row = $DB->get_record_sql(
                "SELECT COUNT(m.id) AS n, SUM(CASE WHEN c.state = 'complete' THEN 1 ELSE 0 END) AS done
                   FROM {local_aiqr_module} m
                   JOIN {local_aiqr_job} j ON j.id = m.jobid
              LEFT JOIN {local_aiqr_completion} c ON c.moduleid = m.id AND c.userid = j.userid
                  WHERE j.courseid = :c AND m.timecreated >= :from",
                ['c' => $a->courseid, 'from' => $from]
            );
            return ['value' => (int) $row->n ? (int) $row->done / (int) $row->n : 0.0, 'n' => (int) $row->n,
                'metric' => 'completion'];
        }
        if (!(int) $a->qbeid) {
            return null;
        }
        $params = ['c' => $a->courseid, 's' => $a->sourcetype, 'a' => $a->activityid, 'q' => $a->qbeid, 'from' => $from];
        $groupsql = '';
        if ((int) $a->groupid) {
            $groupsql = ' AND r.userid IN (SELECT userid FROM {groups_members} WHERE groupid = :g)';
            $params['g'] = $a->groupid;
        }
        $row = $DB->get_record_sql(
            "SELECT COUNT(1) AS n, SUM(r.fraction) AS s
               FROM {local_aiqr_resp} r
              WHERE r.courseid = :c AND r.sourcetype = :s AND r.activityid = :a AND r.qbeid = :q
                AND r.isfirst = 1 AND r.timefinished >= :from {$groupsql}",
            $params
        );
        return ['value' => (int) $row->n ? (float) $row->s / (int) $row->n : 0.0, 'n' => (int) $row->n, 'metric' => 'facility'];
    }

    /**
     * Change an action's status on behalf of a person.
     *
     * @param \stdClass $a
     * @param string $to
     * @param int $userid
     * @param string|null $comment
     * @param string|null $reason dismiss reason
     * @param int $snoozeuntil
     */
    public static function transition(\stdClass $a, string $to, int $userid, ?string $comment = null,
            ?string $reason = null, int $snoozeuntil = 0): void {
        global $DB;
        if (!in_array($to, self::USER_STATUSES, true)) {
            throw new \coding_exception('Invalid action status ' . $to);
        }
        if ($to === 'dismissed' && !in_array($reason, self::DISMISS_REASONS, true)) {
            throw new \moodle_exception('dismissreasonrequired', 'local_aiquizremedial');
        }
        $now = time();
        $update = (object) ['id' => $a->id, 'status' => $to, 'timemodified' => $now, 'misses' => 0];
        if ($to === 'done') {
            $metric = self::current_metric($a);
            $update->timeresolved = $now;
            $update->impact = null;
            $update->baseline = $metric ? json_encode(['value' => round($metric['value'], 4), 'n' => $metric['n'],
                'metric' => $metric['metric'], 'time' => $now]) : null;
        } else if ($to === 'dismissed') {
            $update->dismissreason = $reason;
            $update->timeresolved = $now;
        } else if ($to === 'snoozed') {
            $update->snoozeuntil = max($now + DAYSECS, $snoozeuntil);
        } else {
            $update->timeresolved = 0;
            $update->snoozeuntil = 0;
        }
        $DB->update_record('local_aiqr_action', $update);
        $note = $comment;
        if ($to === 'dismissed') {
            $note = 'reason:' . $reason . ($comment ? ' — ' . $comment : '');
        } else if ($to === 'snoozed') {
            $note = 'until:' . userdate($update->snoozeuntil, '%Y-%m-%d') . ($comment ? ' — ' . $comment : '');
        }
        self::log_change($a->id, $userid, $a->status, $to, $note, $a->evidence);
    }

    /**
     * Reassign an action.
     *
     * @param \stdClass $a
     * @param int $assigneeid
     * @param int $userid who made the change
     */
    public static function assign(\stdClass $a, int $assigneeid, int $userid): void {
        global $DB;
        $DB->update_record('local_aiqr_action', (object) ['id' => $a->id, 'assigneeid' => $assigneeid, 'timemodified' => time()]);
        self::log_change($a->id, $userid, $a->status, $a->status, 'assigned:' . $assigneeid, null);
    }

    /**
     * Add a comment only.
     *
     * @param \stdClass $a
     * @param int $userid
     * @param string $comment
     */
    public static function comment(\stdClass $a, int $userid, string $comment): void {
        self::log_change($a->id, $userid, $a->status, $a->status, $comment, null);
    }

    /**
     * Append to the audit trail (never updated or deleted, except by privacy deletion).
     *
     * @param int $actionid
     * @param int $userid 0 = system
     * @param string|null $from
     * @param string $to
     * @param string|null $comment
     * @param string|null $evidence
     */
    public static function log_change(int $actionid, int $userid, ?string $from, string $to, ?string $comment,
            ?string $evidence): void {
        global $DB;
        $DB->insert_record('local_aiqr_action_log', (object) [
            'actionid' => $actionid, 'userid' => $userid, 'fromstatus' => $from, 'tostatus' => $to,
            'comment' => $comment, 'evidence' => $evidence, 'timecreated' => time(),
        ]);
    }

    /**
     * People who should hear about an action: the assignee plus everyone who can manage
     * actions in the course (restricted to the group for group-level actions).
     *
     * @param \stdClass $a
     * @return \stdClass[] users keyed by id
     */
    public static function recipients(\stdClass $a): array {
        global $DB;
        $ctx = \context_course::instance((int) $a->courseid, IGNORE_MISSING);
        if (!$ctx) {
            return [];
        }
        $users = get_enrolled_users($ctx, 'local/aiquizremedial:manageactions', (int) $a->groupid, 'u.*', null, 0, 0, true);
        if ((int) $a->groupid) {
            // Managers who see all groups also get group-level actions.
            foreach (get_enrolled_users($ctx, 'local/aiquizremedial:manageactions', 0, 'u.*', null, 0, 0, true) as $u) {
                if (has_capability('moodle/site:accessallgroups', $ctx, $u)) {
                    $users[$u->id] = $u;
                }
            }
        }
        if ((int) $a->assigneeid && !isset($users[$a->assigneeid])) {
            $u = $DB->get_record('user', ['id' => $a->assigneeid, 'deleted' => 0]);
            if ($u) {
                $users[$u->id] = $u;
            }
        }
        return $users;
    }

    /**
     * Immediate message for grade-affecting actions (possible wrong key). Once per action.
     *
     * @param \stdClass $a
     */
    public static function notify_urgent(\stdClass $a): void {
        global $DB;
        if ((int) $a->notified) {
            return;
        }
        $text = presenter::action_text($a);
        $title = presenter::action_title($a);
        $url = new \moodle_url('/local/aiquizremedial/insights.php', ['courseid' => $a->courseid, 'view' => 'actions',
            'astatus' => 'all', 'actionid' => $a->id]);
        $url->set_anchor('aiqr-action-' . $a->id);
        foreach (self::recipients($a) as $user) {
            $message = new \core\message\message();
            $message->component = 'local_aiquizremedial';
            $message->name = 'actionurgent';
            $message->userfrom = \core_user::get_noreply_user();
            $message->userto = $user;
            $message->subject = get_string('msg_urgent_subject', 'local_aiquizremedial', $title);
            $message->fullmessage = $text . "\n\n" . $url->out(false);
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = \html_writer::tag('p', s($text)) . \html_writer::link($url,
                get_string('msg_open_action', 'local_aiquizremedial'));
            $message->smallmessage = $title;
            $message->notification = 1;
            $message->contexturl = $url->out(false);
            $message->contexturlname = get_string('suggestedactions', 'local_aiquizremedial');
            $message->courseid = $a->courseid;
            try {
                message_send($message);
            } catch (\Throwable $e) {
                debugging('local_aiquizremedial urgent message failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        $DB->set_field('local_aiqr_action', 'notified', 1, ['id' => $a->id]);
    }

    /**
     * Weekly digest: one message per person, only if something changed.
     *
     * @param int $since timestamp of the previous digest
     * @return int messages sent
     */
    public function send_digest(int $since): int {
        global $DB;
        $actions = $DB->get_records_select('local_aiqr_action',
            "(status IN ('open', 'acknowledged', 'inprogress')) OR (impact IS NOT NULL AND timemodified > :since)",
            ['since' => $since]);
        $peruser = [];
        foreach ($actions as $a) {
            foreach (self::recipients($a) as $u) {
                if (!isset($peruser[$u->id])) {
                    $peruser[$u->id] = ['user' => $u, 'new' => [], 'open' => 0, 'impact' => []];
                }
                if (in_array($a->status, ['open', 'acknowledged', 'inprogress'], true)) {
                    $peruser[$u->id]['open']++;
                    if ((int) $a->timecreated > $since && rules::SEVERITY[$a->severity] >= rules::SEVERITY['high']) {
                        $peruser[$u->id]['new'][] = $a;
                    }
                }
                $impact = json_decode((string) $a->impact, true);
                if ($impact && ($impact['verdict'] ?? '') === 'improved' && (int) ($impact['time'] ?? 0) > $since) {
                    $peruser[$u->id]['impact'][] = $a;
                }
            }
        }
        $sent = 0;
        foreach ($peruser as $data) {
            if (!$data['new'] && !$data['impact']) {
                continue; // Nothing changed for this person — no message.
            }
            $lines = [];
            $html = '';
            if ($data['new']) {
                $html .= \html_writer::tag('h4', get_string('digest_new', 'local_aiquizremedial', count($data['new'])));
                $items = '';
                foreach ($data['new'] as $a) {
                    $lines[] = '• ' . presenter::action_text($a);
                    $items .= \html_writer::tag('li', s(presenter::action_text($a)));
                }
                $html .= \html_writer::tag('ul', $items);
            }
            if ($data['impact']) {
                $html .= \html_writer::tag('h4', get_string('digest_impact', 'local_aiquizremedial', count($data['impact'])));
                $items = '';
                foreach ($data['impact'] as $a) {
                    $t = presenter::impact_text($a);
                    $lines[] = '✓ ' . $t;
                    $items .= \html_writer::tag('li', s($t));
                }
                $html .= \html_writer::tag('ul', $items);
            }
            $url = new \moodle_url('/local/aiquizremedial/insights.php', ['view' => 'actions', 'mine' => 1]);
            $summary = get_string('digest_open', 'local_aiquizremedial', $data['open']);
            $message = new \core\message\message();
            $message->component = 'local_aiquizremedial';
            $message->name = 'actiondigest';
            $message->userfrom = \core_user::get_noreply_user();
            $message->userto = $data['user'];
            $message->subject = get_string('digest_subject', 'local_aiquizremedial');
            $message->fullmessage = implode("\n", $lines) . "\n\n" . $summary . "\n" . $url->out(false);
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = $html . \html_writer::tag('p', s($summary)) . \html_writer::link($url,
                get_string('msg_open_actions', 'local_aiquizremedial'));
            $message->smallmessage = $summary;
            $message->notification = 1;
            $message->contexturl = $url->out(false);
            $message->contexturlname = get_string('suggestedactions', 'local_aiquizremedial');
            try {
                message_send($message);
                $sent++;
            } catch (\Throwable $e) {
                debugging('local_aiquizremedial digest failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        return $sent;
    }
}
