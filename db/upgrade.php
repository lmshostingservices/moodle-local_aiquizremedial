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

/**
 * Upgrade steps for local_aiquizremedial.
 *
 * @package    local_aiquizremedial
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade the plugin.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_aiquizremedial_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026072300) {
        upgrade_plugin_savepoint(true, 2026072300, 'local', 'aiquizremedial');
    }

    if ($oldversion < 2026092100) {
        $table = new xmldb_table('local_aiqr_job');

        // Retry counter used by the automatic retry of failed question jobs.
        $field = new xmldb_field('retries', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0', 'errormsg');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Indexes used by the teacher report filters and the de-duplication checks.
        $index = new xmldb_index('idx_course_user', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'userid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        $index = new xmldb_index('idx_attempt_question', XMLDB_INDEX_NOTUNIQUE, ['attemptid', 'questionid', 'sourcetype']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Question-level jobs left in 'processing' by a cron run that died are re-queued
        // so the new task picks them up (previously they were stuck forever).
        $DB->execute("UPDATE {local_aiqr_job}
                         SET status = 'queued', timemodified = :now
                       WHERE status = 'processing' AND questionid IS NOT NULL
                         AND timemodified < :cutoff",
            ['now' => time(), 'cutoff' => time() - HOURSECS]);
        // Umbrella jobs stuck in 'processing' go back to 'pending'.
        $DB->execute("UPDATE {local_aiqr_job}
                         SET status = 'pending', timemodified = :now
                       WHERE status = 'processing' AND questionid IS NULL
                         AND timemodified < :cutoff",
            ['now' => time(), 'cutoff' => time() - HOURSECS]);

        upgrade_plugin_savepoint(true, 2026092100, 'local', 'aiquizremedial');
    }

    if ($oldversion < 2026092101) {
        // Replace the two v1.3.0 checkboxes with the single "Apply remedial learning to" setting.
        $quiz = get_config('local_aiquizremedial', 'enablequiz');
        $kc = get_config('local_aiquizremedial', 'enablekc');
        if ($quiz !== false || $kc !== false) {
            $sources = [];
            if ($quiz === false || $quiz) {
                $sources[] = 'quiz';
            }
            if ($kc === false || $kc) {
                $sources[] = 'knowledgecheck';
            }
            set_config('sources', implode(',', $sources), 'local_aiquizremedial');
        }
        unset_config('enablequiz', 'local_aiquizremedial');
        unset_config('enablekc', 'local_aiquizremedial');
        upgrade_plugin_savepoint(true, 2026092101, 'local', 'aiquizremedial');
    }

    if ($oldversion < 2026092200) {
        // Structured 4-card tutor lesson.
        $table = new xmldb_table('local_aiqr_module');
        $field = new xmldb_field('lesson_json', XMLDB_TYPE_TEXT, null, null, null, null, null, 'translations_json');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026092200, 'local', 'aiquizremedial');
    }

    if ($oldversion < 2026092201) {
        // Explanation images: original-question snapshot + explicit image state.
        $table = new xmldb_table('local_aiqr_module');
        $fields = [
            new xmldb_field('question_json', XMLDB_TYPE_TEXT, null, null, null, null, null, 'lesson_json'),
            new xmldb_field('image_status', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'question_json'),
            new xmldb_field('image_error', XMLDB_TYPE_TEXT, null, null, null, null, null, 'image_status'),
            new xmldb_field('image_attempts', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0', 'image_error'),
            new xmldb_field('image_nextattempt', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'image_attempts'),
            new xmldb_field('image_meta', XMLDB_TYPE_TEXT, null, null, null, null, null, 'image_nextattempt'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        // Existing modules that already have an image are 'ready'. Modules without one keep
        // NULL, which the backfill treats as retryable (max 3 attempts, with back-off).
        $DB->execute("UPDATE {local_aiqr_module} SET image_status = 'ready'
                       WHERE explain_image_url IS NOT NULL AND explain_image_url <> ''");
        upgrade_plugin_savepoint(true, 2026092201, 'local', 'aiquizremedial');
    }

    if ($oldversion < 2026092300) {
        // Version 1.5.0: quiz insights and suggested actions.
        $table = new xmldb_table('local_aiqr_completion');
        $field = new xmldb_field('firstviewed', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'completed_at');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $xmlfile = __DIR__ . '/install.xml';
        foreach (['local_aiqr_resp', 'local_aiqr_qstats', 'local_aiqr_optstats', 'local_aiqr_actstats',
                'local_aiqr_action', 'local_aiqr_action_log'] as $tablename) {
            if (!$dbman->table_exists($tablename)) {
                $dbman->install_one_table_from_xmldb_file($xmlfile, $tablename);
            }
        }
        upgrade_plugin_savepoint(true, 2026092300, 'local', 'aiquizremedial');
    }

    return true;
}
