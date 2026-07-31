<?php
require_once('../../config.php');

$courseid    = optional_param('courseid',    0, PARAM_INT);
$attemptid   = optional_param('attemptid',   0, PARAM_INT);
$userid      = optional_param('userid',      0, PARAM_INT); // Teacher: view a specific student.
$quizid      = optional_param('quizid',      0, PARAM_INT); // v1.2.14 Fix 10: filter by quiz.
$filteruserid = optional_param('filteruserid', 0, PARAM_INT); // v1.2.33: student filter in teacher mode (keeps teacher overview active).

require_login();

$context = $courseid > 0 ? context_course::instance($courseid) : context_system::instance();

// Set page context immediately after resolving it — must happen before any
// format_string() / format_text() calls, which internally access $PAGE->context.
$PAGE->set_context($context);

if ($courseid > 0) {
    require_capability('local/aiquizremedial:viewown', $context);
    // FIX-RL-COURSE-NAV (v1.2.40): Load the course record and register it with the PAGE
    // system so Moodle renders the standard course navigation — breadcrumbs and course
    // menu tabs — giving teachers a clear path back to the course from this page.
    // Previously only $PAGE->set_context() was called (course context), which is not
    // enough: Moodle's layout engine also needs $PAGE->set_course() to know which course's
    // navigation structure to render. Without it the page has no tabs and no breadcrumb
    // link back to the course, leaving the teacher stranded.
    $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
    $PAGE->set_course($course);
}

// ── Determine which user(s) to show ────────────────────────────────────────
// Three modes:
//   1. Teacher with viewall + ?userid=X  → view that specific student's modules.
//   2. Teacher with viewall (no userid)  → view ALL students in the course.
//   3. Default                           → view the logged-in user's own modules.
$canviewall   = has_capability('local/aiquizremedial:viewall', $context);
$teachermode  = false;   // All-students overview.
$targetuserid = (int) $USER->id;
$viewinguser  = null;

if ($canviewall && $userid > 0) {
    // Mode 1: specific student.
    $targetuserid = $userid;
    // FIX-RL-FULLNAME-FIELDS (v1.2.42): include all six name fields required by fullname()
    // in Moodle™ 4.x — passing a partial user object triggers a debugging() warning
    // ("name fields missing: firstnamephonetic, lastnamephonetic, middlename, alternatename")
    // that surfaces in the page heading on the teacher view.
    $viewinguser  = $DB->get_record('user', ['id' => $userid],
        'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename',
        MUST_EXIST);
    $pageheading  = get_string('teacherstudentview', 'local_aiquizremedial', fullname($viewinguser));
} else if ($canviewall && $userid === 0) {
    // Mode 2: teacher overview — all students. Works with OR without a courseid in the URL.
    // Previously required $courseid > 0, which caused teachers navigating to the plain
    // /local/aiquizremedial/index.php URL (no params) to fall through to Mode 3 (own empty
    // modules) even though students had completed quizzes and had fix modules waiting.
    $teachermode  = true;
    $targetuserid = 0;
    // FIX-RL-QUIZID-HEADING (v1.2.34): Show quiz name in heading when arriving from a specific quiz.
    if ($quizid > 0) {
        $quizrecord  = $DB->get_record('quiz', ['id' => $quizid], 'name');
        $pageheading = $quizrecord
            ? get_string('teacherviewheading', 'local_aiquizremedial') . ' — ' . format_string($quizrecord->name)
            : get_string('teacherviewheading', 'local_aiquizremedial');
    } else {
        $pageheading = get_string('teacherviewheading', 'local_aiquizremedial');
    }
} else {
    // Mode 3: own modules (student, or teacher who explicitly passed their own userid).
    $pageheading = get_string('myremedialmodules', 'local_aiquizremedial');
}
// ───────────────────────────────────────────────────────────────────────────

$urlparams = ['courseid' => $courseid];
if ($attemptid    > 0) { $urlparams['attemptid']    = $attemptid;    }
if ($userid       > 0) { $urlparams['userid']       = $userid;       }
if ($quizid       > 0) { $urlparams['quizid']       = $quizid;       }
if ($filteruserid > 0) { $urlparams['filteruserid'] = $filteruserid; }

$PAGE->set_url(new moodle_url('/local/aiquizremedial/index.php', $urlparams));
$PAGE->set_title($pageheading);
$PAGE->set_heading($pageheading);
$PAGE->set_pagelayout('standard');

echo $OUTPUT->header();

// ── Build SQL ───────────────────────────────────────────────────────────────
if ($teachermode) {
    // All students in the course — JOIN user table so we can show student names.
    // BUG-REM-QUESTION-PREVIEW (v1.2.35): also JOIN {question} to get the original
    // quiz question name so teacher cards show which question the student got wrong
    // instead of showing the AI explain_text (which reveals the answer up-front).
    $sql = "SELECT m.id AS moduleid, m.explain_text, m.explain_audio_url, m.explain_image_url,
                   m.credits_used, m.timecreated AS module_created,
                   j.quizid, j.kcid, j.questionid, j.attemptid, j.courseid,
                   j.sourcetype, j.userid AS studentid,
                   c.state, c.attempts_count, c.completed_at,
                   q.name AS quizname,
                   kc.name AS kcname,
                   u.firstname, u.lastname,
                   qq.name AS question_name
            FROM {local_aiqr_module} m
            JOIN {local_aiqr_job} j ON j.id = m.jobid
            JOIN {user} u ON u.id = j.userid
            LEFT JOIN {quiz} q ON q.id = j.quizid
            LEFT JOIN {aiknowledgecheck} kc ON kc.id = j.kcid
            LEFT JOIN {local_aiqr_completion} c ON c.moduleid = m.id AND c.userid = j.userid
            LEFT JOIN {question} qq ON qq.id = j.questionid
            WHERE j.status = 'ready'";
    $params = [];
    // Previously this clause was always applied (even when courseid=0), which meant
    // navigating without a courseid returned zero rows because no job has courseid=0.
    if ($courseid > 0) {
        $sql .= " AND j.courseid = :courseid";
        $params['courseid'] = $courseid;
    }
    if ($attemptid > 0) {
        $sql .= " AND j.attemptid = :attemptid";
        $params['attemptid'] = $attemptid;
    }
    // v1.2.14 Fix 10: filter teacher overview by quiz; Fix 11: filter by student.
    if ($quizid > 0) {
        $sql .= " AND j.quizid = :quizid";
        $params['quizid'] = $quizid;
    }
    if ($userid > 0) {
        $sql .= " AND j.userid = :filteruserid";
        $params['filteruserid'] = $userid;
    }
    // v1.2.33: filteruserid keeps teacher-overview mode active while limiting to one student.
    if ($filteruserid > 0 && $userid === 0) {
        $sql .= " AND j.userid = :filteruserid2";
        $params['filteruserid2'] = $filteruserid;
    }
    $sql .= " ORDER BY u.lastname, u.firstname, m.timecreated DESC";
} else {
    // Single user view (own or teacher viewing specific student).
    // BUG-REM-QUESTION-PREVIEW (v1.2.35): JOIN {question} for question_name.
    $sql = "SELECT m.id AS moduleid, m.explain_text, m.explain_audio_url, m.explain_image_url,
                   m.credits_used, m.timecreated AS module_created,
                   j.quizid, j.kcid, j.questionid, j.attemptid, j.courseid,
                   j.sourcetype,
                   c.state, c.attempts_count, c.completed_at,
                   q.name AS quizname,
                   kc.name AS kcname,
                   qq.name AS question_name
            FROM {local_aiqr_module} m
            JOIN {local_aiqr_job} j ON j.id = m.jobid
            LEFT JOIN {quiz} q ON q.id = j.quizid
            LEFT JOIN {aiknowledgecheck} kc ON kc.id = j.kcid
            LEFT JOIN {local_aiqr_completion} c ON c.moduleid = m.id AND c.userid = :userid
            LEFT JOIN {question} qq ON qq.id = j.questionid
            WHERE j.userid = :userid2 AND j.status = 'ready'";
    $params = ['userid' => $targetuserid, 'userid2' => $targetuserid];
    if ($courseid > 0) {
        $sql .= " AND j.courseid = :courseid";
        $params['courseid'] = $courseid;
    }
    if ($attemptid > 0) {
        $sql .= " AND j.attemptid = :attemptid";
        $params['attemptid'] = $attemptid;
    }
    // v1.2.14 Fix 10: filter single-user view by quiz too.
    if ($quizid > 0) {
        $sql .= " AND j.quizid = :quizid";
        $params['quizid'] = $quizid;
    }
    $sql .= " ORDER BY m.timecreated DESC";
}
// ───────────────────────────────────────────────────────────────────────────

$modules = $DB->get_records_sql($sql, $params);

// BUG-REM-STATUS-FAILED (v1.2.35): in teacher mode also fetch failed question-level jobs
// so the teacher can see which students had AI content generation failures.
// Failed jobs have j.status='failed' and no local_aiqr_module record (with the credit-order
// fix these are never created on failure).  Display them with a distinct "Generation Failed"
// red badge — 0 credits used — no Review Module button.
$failedjobs = [];
if ($teachermode) {
    $failedsql = "SELECT j.id AS jobid, j.quizid, j.kcid, j.questionid,
                         j.attemptid, j.courseid, j.sourcetype,
                         j.userid AS studentid, j.errormsg,
                         q.name AS quizname,
                         kc.name AS kcname,
                         u.firstname, u.lastname,
                         qq.name AS question_name
                  FROM {local_aiqr_job} j
                  JOIN {user} u ON u.id = j.userid
                  LEFT JOIN {quiz} q ON q.id = j.quizid
                  LEFT JOIN {aiknowledgecheck} kc ON kc.id = j.kcid
                  LEFT JOIN {question} qq ON qq.id = j.questionid
                  WHERE j.status = 'failed'
                    AND j.questionid IS NOT NULL";
    $failedparams = [];
    if ($courseid > 0) {
        $failedsql .= " AND j.courseid = :courseid";
        $failedparams['courseid'] = $courseid;
    }
    if ($quizid > 0) {
        $failedsql .= " AND j.quizid = :quizid";
        $failedparams['quizid'] = $quizid;
    }
    if ($userid > 0) {
        $failedsql .= " AND j.userid = :userid";
        $failedparams['userid'] = $userid;
    }
    if ($filteruserid > 0 && $userid === 0) {
        $failedsql .= " AND j.userid = :filteruserid";
        $failedparams['filteruserid'] = $filteruserid;
    }
    $failedsql .= " ORDER BY u.lastname, u.firstname, j.timecreated DESC";
    $failedjobs = $DB->get_records_sql($failedsql, $failedparams);
}

// ── Teacher filter form (v1.2.33) ───────────────────────────────────────────
// Only shown in teacher overview mode so teachers can narrow results by quiz
// and/or student without losing the all-students overview context.
if ($teachermode && $courseid > 0) {
    // Fetch distinct quizzes that have ready remedial modules in this course.
    $quizsql = "SELECT DISTINCT q.id, q.name
                FROM {quiz} q
                JOIN {local_aiqr_job} j ON j.quizid = q.id
                JOIN {local_aiqr_module} m ON m.jobid = j.id
                WHERE j.status = 'ready' AND j.courseid = :courseid
                ORDER BY q.name";
    $availablequizzes = $DB->get_records_sql($quizsql, ['courseid' => $courseid]);

    // Fetch distinct students that have ready remedial modules (filtered by quiz if selected).
    $studentsql = "SELECT DISTINCT u.id, u.firstname, u.lastname
                   FROM {user} u
                   JOIN {local_aiqr_job} j ON j.userid = u.id
                   JOIN {local_aiqr_module} m ON m.jobid = j.id
                   WHERE j.status = 'ready' AND j.courseid = :courseid";
    $studentparams = ['courseid' => $courseid];
    if ($quizid > 0) {
        $studentsql .= " AND j.quizid = :quizid";
        $studentparams['quizid'] = $quizid;
    }
    $studentsql .= " ORDER BY u.lastname, u.firstname";
    $availablestudents = $DB->get_records_sql($studentsql, $studentparams);

    // Build the filter form URL (POST to GET redirect keeps URLs clean).
    $formaction = new moodle_url('/local/aiquizremedial/index.php');

    echo html_writer::start_tag('form', ['method' => 'get', 'action' => $formaction->out(false), 'class' => 'form-inline mb-3 d-flex flex-wrap align-items-center gap-2']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]);

    // Quiz filter.
    echo html_writer::start_div('form-group mr-3 mb-2');
    echo html_writer::tag('label', get_string('filter_by_quiz', 'local_aiquizremedial'), ['for' => 'aiqr-filter-quiz', 'class' => 'mr-2 font-weight-bold']);
    $quizoptions = [0 => get_string('filter_all_quizzes', 'local_aiquizremedial')];
    foreach ($availablequizzes as $q) {
        $quizoptions[$q->id] = format_string($q->name);
    }
    echo html_writer::select($quizoptions, 'quizid', $quizid, false, ['id' => 'aiqr-filter-quiz', 'class' => 'custom-select mr-2']);
    echo html_writer::end_div();

    // Student filter.
    echo html_writer::start_div('form-group mr-3 mb-2');
    echo html_writer::tag('label', get_string('filter_by_student', 'local_aiquizremedial'), ['for' => 'aiqr-filter-student', 'class' => 'mr-2 font-weight-bold']);
    $studentoptions = [0 => get_string('filter_all_students', 'local_aiquizremedial')];
    foreach ($availablestudents as $s) {
        $studentoptions[$s->id] = format_string($s->lastname . ', ' . $s->firstname);
    }
    echo html_writer::select($studentoptions, 'filteruserid', $filteruserid, false, ['id' => 'aiqr-filter-student', 'class' => 'custom-select mr-2']);
    echo html_writer::end_div();

    // Apply button.
    echo html_writer::tag('button', get_string('filter_apply', 'local_aiquizremedial'), ['type' => 'submit', 'class' => 'btn btn-secondary mb-2']);

    // Reset link (only when a filter is active).
    if ($quizid > 0 || $filteruserid > 0) {
        $reseturl = new moodle_url('/local/aiquizremedial/index.php', ['courseid' => $courseid]);
        echo html_writer::link($reseturl, get_string('filter_reset', 'local_aiquizremedial'), ['class' => 'btn btn-outline-secondary mb-2 ml-2']);
    }

    echo html_writer::end_tag('form');
}
// ───────────────────────────────────────────────────────────────────────────

if (empty($modules) && empty($failedjobs)) {
    $emptystr = ($teachermode || ($canviewall && $userid > 0))
        ? get_string('nomodules_course', 'local_aiquizremedial')
        : get_string('nomodules', 'local_aiquizremedial');
    echo html_writer::div($emptystr, 'alert alert-info');
} else {
    echo html_writer::start_div('local-aiqr-modules');

    foreach ($modules as $mod) {
        $state = $mod->state ?? 'notstarted';

        if ($state === 'complete') {
            $badgeclass = 'badge-success';
            $badgelabel = get_string('state_complete', 'local_aiquizremedial');
        } else if ($state === 'inprogress') {
            $badgeclass = 'badge-warning';
            $badgelabel = get_string('state_inprogress', 'local_aiquizremedial');
        } else {
            $badgeclass = 'badge-secondary';
            $badgelabel = get_string('state_notstarted', 'local_aiquizremedial');
        }

        $sourcetype = $mod->sourcetype ?? 'quiz';
        if ($sourcetype === 'knowledgecheck') {
            $activityname = !empty($mod->kcname) ? format_string($mod->kcname) : '';
        } else {
            $activityname = !empty($mod->quizname) ? format_string($mod->quizname) : '';
        }

        $viewurl = new moodle_url('/local/aiquizremedial/view.php', ['moduleid' => $mod->moduleid]);
        // Teacher viewing a student's module: pass userid so view.php can build the correct back link.
        if ($canviewall && $userid > 0) {
            $viewurl->param('userid', $userid);
        } else if ($teachermode) {
            $viewurl->param('userid', $mod->studentid);
        }
        // FIX-RL-FILTER-PERSIST (v1.2.44): propagate the active filter context so view.php
        // can render the same filter panel inside the student review screen AND so the
        // "Back to My Modules" link returns to the filtered list rather than the unfiltered
        // overview. Without this the teacher loses filter state the moment they open a
        // module and has to re-apply filters every time they return.
        if ($teachermode || ($canviewall && $userid > 0)) {
            if ($quizid > 0)       { $viewurl->param('filterquizid',  $quizid); }
            if ($filteruserid > 0) { $viewurl->param('filteruserid',  $filteruserid); }
        }

        echo html_writer::start_div('card mb-3 aiqr-section-card aiqr-module-card');
        echo html_writer::start_div('card-body');

        // In teacher mode show student name above the card title.
        if ($teachermode) {
            $studentname = format_string($mod->firstname . ' ' . $mod->lastname);
            echo html_writer::tag('p',
                get_string('student_label', 'local_aiquizremedial', $studentname),
                ['class' => 'card-subtitle text-muted mb-1 small']
            );
        }

        echo html_writer::tag('h5',
            get_string('fixmodule_title', 'local_aiquizremedial') .
            ' <span class="badge ' . $badgeclass . '">' . $badgelabel . '</span>',
            ['class' => 'card-title']
        );

        if (!empty($activityname)) {
            echo html_writer::tag('p', get_string('fromquiz', 'local_aiquizremedial', $activityname), ['class' => 'card-text text-muted']);
        }

        // BUG-REM-QUESTION-PREVIEW (v1.2.35): show the original quiz question name so the
        // teacher can see which question the student got wrong at a glance, instead of
        // showing the explain_text preview which starts "The correct answer is..." and
        // reveals the answer in the list without providing question context.
        if (!empty($mod->question_name)) {
            echo html_writer::tag('p',
                get_string('question_label', 'local_aiquizremedial', s(format_string($mod->question_name))),
                ['class' => 'card-text text-muted small mb-1']
            );
        }

        echo html_writer::tag('p',
            get_string('credits_used_label', 'local_aiquizremedial', (int) $mod->credits_used),
            ['class' => 'card-text text-muted small']
        );

        $btnlabel = ($canviewall && ($teachermode || $userid > 0))
            ? get_string('viewmodule_teacher', 'local_aiquizremedial')
            : get_string('viewmodule', 'local_aiquizremedial');
        echo html_writer::link($viewurl, $btnlabel, ['class' => 'btn btn-primary btn-sm']);

        echo html_writer::end_div(); // card-body
        echo html_writer::end_div(); // card
    }

    // BUG-REM-STATUS-FAILED (v1.2.35): render failed jobs with a distinct red badge.
    // These are question-level jobs where AI content generation threw an exception
    // (credit-order fix means no credits were consumed for these).
    foreach ($failedjobs as $fj) {
        $sourcetype = $fj->sourcetype ?? 'quiz';
        $activityname = ($sourcetype === 'knowledgecheck')
            ? (!empty($fj->kcname) ? format_string($fj->kcname) : '')
            : (!empty($fj->quizname) ? format_string($fj->quizname) : '');

        echo html_writer::start_div('card mb-3 aiqr-section-card aiqr-module-card border-danger');
        echo html_writer::start_div('card-body');

        $studentname = format_string($fj->firstname . ' ' . $fj->lastname);
        echo html_writer::tag('p',
            get_string('student_label', 'local_aiquizremedial', $studentname),
            ['class' => 'card-subtitle text-muted mb-1 small']
        );

        echo html_writer::tag('h5',
            get_string('fixmodule_title', 'local_aiquizremedial') .
            ' <span class="badge badge-danger">' . get_string('state_failed', 'local_aiquizremedial') . '</span>',
            ['class' => 'card-title']
        );

        if (!empty($activityname)) {
            echo html_writer::tag('p', get_string('fromquiz', 'local_aiquizremedial', $activityname), ['class' => 'card-text text-muted']);
        }

        if (!empty($fj->question_name)) {
            echo html_writer::tag('p',
                get_string('question_label', 'local_aiquizremedial', s(format_string($fj->question_name))),
                ['class' => 'card-text text-muted small mb-1']
            );
        }

        echo html_writer::tag('p',
            get_string('generation_failed_desc', 'local_aiquizremedial'),
            ['class' => 'card-text text-danger small']
        );
        echo html_writer::tag('p',
            get_string('credits_used_label', 'local_aiquizremedial', 0),
            ['class' => 'card-text text-muted small']
        );

        echo html_writer::end_div(); // card-body
        echo html_writer::end_div(); // card
    }

    echo html_writer::end_div();
}

echo $OUTPUT->footer();
