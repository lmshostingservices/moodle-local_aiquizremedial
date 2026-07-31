<?php
defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\mod_quiz\event\attempt_submitted',
        'callback'  => '\local_aiquizremedial\observer::attempt_submitted',
        'priority'  => 9999,
    ],
];
