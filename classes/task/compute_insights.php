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
 * Nightly: ingest attempts, recompute question statistics, run the suggested-action rules,
 * auto-resolve and measure the impact of completed actions (v1.5.0).
 *
 * @package    local_aiquizremedial
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
class compute_insights extends \core\task\scheduled_task {
    /**
     * Task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_compute_insights', 'local_aiquizremedial');
    }

    /**
     * Run.
     */
    public function execute(): void {
        self::run_for(0);
    }

    /**
     * Full pipeline for one course (or all).
     *
     * @param int $courseid 0 = all courses
     */
    public static function run_for(int $courseid): void {
        \core_php_time_limit::raise(3600);
        raise_memory_limit(MEMORY_EXTRA);
        $builder = new \local_aiquizremedial\insights\builder();
        $builder->run($courseid ? 600 : 1800, $courseid);
        $actions = new \local_aiquizremedial\insights\actions();
        $actions->sync(\local_aiquizremedial\insights\rules::evaluate($courseid), $courseid);
        $actions->measure_impact();
        set_config('insights_lastrun' . ($courseid ? '_' . $courseid : ''), time(), 'local_aiquizremedial');
    }
}
