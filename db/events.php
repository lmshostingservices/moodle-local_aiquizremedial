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
 * Part of the local_aiquizremedial plugin.
 *
 * @package    local_aiquizremedial
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\mod_quiz\event\attempt_submitted',
        'callback'  => '\local_aiquizremedial\observer::attempt_submitted',
        'priority'  => 9999,
    ],
    // Moodle 5.0+ grades an attempt after the submitted event (MDL-68806). Listening to
    // attempt_graded as well means the job exists however the attempt was finalised.
    // On Moodle < 5.0 this event class does not exist and the observer is simply unused.
    [
        'eventname' => '\mod_quiz\event\attempt_graded',
        'callback'  => '\local_aiquizremedial\observer::attempt_submitted',
        'priority'  => 9999,
    ],
];
