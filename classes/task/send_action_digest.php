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

namespace local_aiquizremedial\task;

/**
 * Weekly digest of suggested actions: one message per person, none if nothing changed (v1.5.0).
 *
 * @package    local_aiquizremedial
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
class send_action_digest extends \core\task\scheduled_task {
    /**
     * Task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_send_action_digest', 'local_aiquizremedial');
    }

    /**
     * Run.
     */
    public function execute(): void {
        $since = (int) get_config('local_aiquizremedial', 'insights_lastdigest');
        if (!$since) {
            $since = time() - WEEKSECS;
        }
        // Remember when this run started: anything newer goes in next week's digest.
        $start = time();
        $sent = (new \local_aiquizremedial\insights\actions())->send_digest($since);
        set_config('insights_lastdigest', $start, 'local_aiquizremedial');
        mtrace('  [AIQR-ACTIONS] Digest messages sent: ' . $sent);
    }
}
