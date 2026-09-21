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

use core\task\scheduled_task;
use local_aiquizremedial\credit_calculator;

defined('MOODLE_INTERNAL') || die();

/**
 * Scheduled task: expand attempts into question jobs, generate modules, retry failures.
 *
 * The pipeline itself lives in \local_aiquizremedial\job_processor (v1.3.0) so the CLI
 * re-scan script and the teacher report "Retry" action can reuse it.
 *
 * @package    local_aiquizremedial
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
class process_jobs extends scheduled_task {
    /**
     * Task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_process_jobs', 'local_aiquizremedial');
    }

    /**
     * Run the task.
     */
    public function execute(): void {
        if (!get_config('local_aiquizremedial', 'enabled')) {
            mtrace('  [AIQR] Plugin disabled in settings — nothing processed.');
            return;
        }
        $processor = new \local_aiquizremedial\job_processor();
        $processor->run();

        // Missing / retryable images (v1.4.1 replaces the v1.2.51 backfill).
        $this->backfill_missing_images();
    }

    /**
     * Generate images that are missing or failed with a retryable error, at most three per run
     * (v1.4.1: see \local_aiquizremedial\media::backfill). Uses the module's stored original-question
     * snapshot so backfill sends the same question as initial generation; never charges credits
     * again and never clears an existing image.
     */
    private function backfill_missing_images(): void {
        \local_aiquizremedial\media::backfill(function (string $line) {
            mtrace($line);
        });
    }
}
