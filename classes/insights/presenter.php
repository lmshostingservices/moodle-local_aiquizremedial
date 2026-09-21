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

use local_aiquizremedial\helper;

/**
 * Names and plain-English wording for insights and suggested actions (v1.5.0).
 *
 * Wording is built at display time from the stored numbers, so renamed quizzes, courses and
 * categories always show their current names.
 *
 * @package    local_aiquizremedial
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
class presenter {
    /** @var array name caches */
    protected static $cache = [];

    /**
     * Activity name (quiz or Knowledge Check).
     *
     * @param string|null $source
     * @param int $activityid
     * @return string
     */
    public static function activity_name(?string $source, int $activityid): string {
        global $DB;
        $key = 'a' . $source . $activityid;
        if (!isset(self::$cache[$key])) {
            $name = '';
            if ($source === 'quiz') {
                $name = (string) $DB->get_field('quiz', 'name', ['id' => $activityid]);
            } else if ($source === 'knowledgecheck' && helper::kc_installed()) {
                $name = (string) $DB->get_field('aiknowledgecheck', 'name', ['id' => $activityid]);
            }
            self::$cache[$key] = $name !== '' ? format_string($name) : get_string('deletedactivity', 'local_aiquizremedial');
        }
        return self::$cache[$key];
    }

    /**
     * Course module id for an activity (for "open activity" links).
     *
     * @param string|null $source
     * @param int $activityid
     * @return int
     */
    public static function cmid(?string $source, int $activityid): int {
        $modname = $source === 'knowledgecheck' ? 'aiknowledgecheck' : 'quiz';
        $key = 'cm' . $modname . $activityid;
        if (!isset(self::$cache[$key])) {
            try {
                $cm = get_coursemodule_from_instance($modname, $activityid, 0, false, IGNORE_MISSING);
                self::$cache[$key] = $cm ? (int) $cm->id : 0;
            } catch (\Throwable $e) {
                self::$cache[$key] = 0;
            }
        }
        return self::$cache[$key];
    }

    /**
     * Course short name.
     *
     * @param int $courseid
     * @return string
     */
    public static function course_short(int $courseid): string {
        global $DB;
        $key = 'c' . $courseid;
        if (!isset(self::$cache[$key])) {
            $c = $DB->get_record('course', ['id' => $courseid], 'id, shortname, fullname, category');
            self::$cache[$key] = $c ? format_string($c->shortname) : '#' . $courseid;
            self::$cache['cf' . $courseid] = $c ? format_string($c->fullname) : '#' . $courseid;
            self::$cache['cc' . $courseid] = $c ? (int) $c->category : 0;
        }
        return self::$cache[$key];
    }

    /**
     * Course full name.
     *
     * @param int $courseid
     * @return string
     */
    public static function course_full(int $courseid): string {
        self::course_short($courseid);
        return self::$cache['cf' . $courseid];
    }

    /**
     * Category path ("Health › Nursing") for a course.
     *
     * @param int $courseid
     * @return string
     */
    public static function category_path(int $courseid): string {
        global $DB;
        self::course_short($courseid);
        $catid = self::$cache['cc' . $courseid];
        $key = 'cat' . $catid;
        if (!isset(self::$cache[$key])) {
            $names = [];
            $path = (string) $DB->get_field('course_categories', 'path', ['id' => $catid]);
            $ids = array_filter(explode('/', $path));
            if ($ids) {
                [$in, $params] = $DB->get_in_or_equal($ids);
                $cats = $DB->get_records_select_menu('course_categories', "id $in", $params, '', 'id, name');
                foreach ($ids as $id) {
                    if (isset($cats[$id])) {
                        $names[] = format_string($cats[$id]);
                    }
                }
            }
            self::$cache[$key] = implode(' › ', $names);
        }
        return self::$cache[$key];
    }

    /**
     * Question name/text for a stats or action row.
     *
     * @param string|null $source
     * @param int $questionid
     * @return string
     */
    public static function question_name(?string $source, int $questionid): string {
        global $DB;
        $key = 'q' . $source . $questionid;
        if (!isset(self::$cache[$key])) {
            $name = '';
            if ($source === 'quiz') {
                $q = $DB->get_record('question', ['id' => $questionid], 'id, name, questiontext');
                if ($q) {
                    $name = trim(format_string($q->name));
                    if ($name === '') {
                        $name = shorten_text(html_to_text((string) $q->questiontext, 0, false), 90);
                    }
                }
            } else if ($source === 'knowledgecheck' && helper::kc_installed()) {
                $text = (string) $DB->get_field('aiknowledgecheck_questions', 'questiontext', ['id' => $questionid]);
                $name = shorten_text(html_to_text($text, 0, false), 90);
            }
            self::$cache[$key] = $name !== '' ? $name : get_string('deletedquestion', 'local_aiquizremedial');
        }
        return self::$cache[$key];
    }

    /**
     * Full question text (for the detail view).
     *
     * @param string|null $source
     * @param int $questionid
     * @return string plain text
     */
    public static function question_text(?string $source, int $questionid): string {
        global $DB;
        if ($source === 'quiz') {
            $text = (string) $DB->get_field('question', 'questiontext', ['id' => $questionid]);
        } else if (helper::kc_installed()) {
            $text = (string) $DB->get_field('aiknowledgecheck_questions', 'questiontext', ['id' => $questionid]);
        } else {
            $text = '';
        }
        $text = preg_replace('/<img\b[^>]*alt\s*=\s*"([^"]+)"[^>]*>/i', ' [$1] ', $text);
        return trim(html_to_text(str_replace('@@PLUGINFILE@@/', '', $text), 0, false));
    }

    /**
     * Human "Q4" label (position in the activity).
     *
     * @param int $slot
     * @return string
     */
    public static function qlabel(int $slot): string {
        return $slot > 0 ? 'Q' . $slot : 'Q?';
    }

    /**
     * Percentage text.
     *
     * @param float|null $v 0..1
     * @return string
     */
    public static function pct(?float $v): string {
        return $v === null ? '–' : round($v * 100) . '%';
    }

    /**
     * Short title for an action.
     *
     * @param \stdClass $a
     * @return string
     */
    public static function action_title(\stdClass $a): string {
        $ev = json_decode((string) $a->evidence, true) ?: [];
        $parts = [];
        if ((int) $a->qbeid) {
            $parts[] = self::qlabel((int) ($ev['slot'] ?? 0));
        }
        if ((int) $a->activityid) {
            $parts[] = self::activity_name($a->sourcetype, (int) $a->activityid);
        }
        $parts[] = self::course_short((int) $a->courseid);
        return get_string('rule_' . $a->ruleid . '_name', 'local_aiquizremedial') . ': ' . implode(' · ', $parts);
    }

    /**
     * Wording the teacher sees for an action.
     *
     * @param \stdClass $a
     * @return string
     */
    public static function action_text(\stdClass $a): string {
        global $DB;
        $ev = json_decode((string) $a->evidence, true) ?: [];
        $x = new \stdClass();
        $x->q = self::qlabel((int) ($ev['slot'] ?? 0));
        $x->activity = (int) $a->activityid ? self::activity_name($a->sourcetype, (int) $a->activityid) : '';
        $x->course = self::course_short((int) $a->courseid);
        $x->category = self::category_path((int) $a->courseid);
        $x->where = $x->activity !== ''
            ? get_string('where_activity', 'local_aiquizremedial', $x)
            : get_string('where_course', 'local_aiquizremedial', $x);
        $x->n = (int) ($ev['n'] ?? 0);
        $x->pct = self::pct(isset($ev['facility']) ? (float) $ev['facility'] : null);
        $x->top = (string) ($ev['toplabel'] ?? '');
        $x->toppct = self::pct(isset($ev['toprate']) ? (float) $ev['toprate'] : null);
        $x->key = (string) ($ev['keylabel'] ?? '');
        $x->keypct = self::pct(isset($ev['keyrate']) ? (float) $ev['keyrate'] : null);
        $x->disc = isset($ev['disc']) ? (string) $ev['disc'] : '';
        $x->blank = (int) ($ev['blank'] ?? 0);
        $x->blankpct = self::pct(isset($ev['blankrate']) ? (float) $ev['blankrate'] : null);
        $x->modules = (int) ($ev['modules'] ?? 0);
        $x->completed = (int) ($ev['completed'] ?? 0);
        $x->rate = self::pct(isset($ev['rate']) ? (float) $ev['rate'] : null);
        $x->qcfirst = (int) ($ev['qcfirst'] ?? 0);
        $x->qcpct = self::pct($x->completed ? $x->qcfirst / $x->completed : null);
        $x->reenc = (int) ($ev['reenc'] ?? 0);
        $x->recovered = (int) ($ev['recovered'] ?? 0);
        $x->before = self::pct(isset($ev['before']) ? (float) $ev['before'] : null);
        $x->after = self::pct(isset($ev['after']) ? (float) $ev['after'] : null);
        $x->edited = !empty($ev['edited']) ? userdate((int) $ev['edited'], get_string('strftimedate', 'langconfig')) : '';
        $x->groupn = (int) ($ev['groupn'] ?? 0);
        $x->grouprate = self::pct(isset($ev['grouprate']) ? (float) $ev['grouprate'] : null);
        $x->restrate = self::pct(isset($ev['restrate']) ? (float) $ev['restrate'] : null);
        $x->group = (int) $a->groupid ? format_string((string) $DB->get_field('groups', 'name', ['id' => $a->groupid])) : '';
        $x->credits = (int) ($ev['credits'] ?? 0);
        $x->share = self::pct(isset($ev['share']) ? (float) $ev['share'] : null);
        $x->dead = !empty($ev['dead']) ? implode(', ', array_map(function ($d) {
            return '"' . shorten_text($d, 30) . '"';
        }, $ev['dead'])) : '';
        $x->window = builder::window_days();

        $key = 'rule_' . $a->ruleid . '_text';
        if ($a->ruleid === 'R6' && !empty($ev['late'])) {
            $key .= '_late';
        } else if ($a->ruleid === 'R12' && ($ev['direction'] ?? '') === 'up') {
            $key .= '_up';
        } else if ($a->ruleid === 'R5') {
            $key .= !empty($ev['easy']) ? '_easy' : '_dead';
        } else if (in_array($a->ruleid, ['R1', 'R13'], true) && $x->top === '') {
            $key .= '_notop';
        }
        $text = get_string($key, 'local_aiquizremedial', $x);
        if (!empty($ev['fixfailed'])) {
            $f = (object) ['before' => self::pct((float) ($ev['fixfailed']['before'] ?? 0)),
                'after' => self::pct((float) ($ev['fixfailed']['after'] ?? 0))];
            $text = get_string('fixfailed_prefix', 'local_aiquizremedial', $f) . ' ' . $text;
        }
        if ($x->n > 0 && $x->n < 20 && (int) $a->qbeid) {
            $text .= ' ' . get_string('smallsample', 'local_aiquizremedial', $x->n);
        }
        return $text;
    }

    /**
     * Impact sentence for a done action.
     *
     * @param \stdClass $a
     * @return string
     */
    public static function impact_text(\stdClass $a): string {
        $i = json_decode((string) $a->impact, true) ?: [];
        if (!$i) {
            return '';
        }
        if (($i['verdict'] ?? '') === 'insufficient') {
            return get_string('impact_insufficient', 'local_aiquizremedial');
        }
        $x = (object) ['before' => self::pct((float) $i['before']), 'beforen' => (int) $i['beforen'],
            'after' => self::pct((float) $i['after']), 'aftern' => (int) $i['aftern'],
            'verdict' => get_string('impact_' . $i['verdict'], 'local_aiquizremedial'),
            'title' => self::action_title($a)];
        return get_string('impact_text', 'local_aiquizremedial', $x);
    }
}
