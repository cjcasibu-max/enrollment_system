<?php
/**
 * Teacher LMS - Grades Management (Phase 7)
 *
 * Modes:
 *   1. Cross-Subject Overview (no ?section_subject_id)
 *   2. Subject-Scoped Gradebook & Performance (?section_subject_id=N)
 *
 * Security:
 *   requireLmsTeacherAccess()   -> role gate
 *   verifyTeacherOwnsSubject()  -> ownership gate
 *
 * Data source:
 *   Reads/writes student_grades, grade_submissions, lms_assignments,
 *   lms_assignment_submissions, lms_quizzes, lms_quiz_attempts.
 *   Directly connected to student-side LMS and Registrar approvals.
 */

require_once '../includes/lms_access.php';

$teacher          = requireLmsTeacherAccess();
$userId           = $teacher['user_id'];
$sectionSubjectId = (int)filter_input(INPUT_GET, 'section_subject_id', FILTER_VALIDATE_INT);
$sectionIdParam   = (int)filter_input(INPUT_GET, 'section_id', FILTER_VALIDATE_INT);

// Fallback: If section_id was provided without section_subject_id, resolve the instructor's subject in that section
if ($sectionSubjectId <= 0 && $sectionIdParam > 0) {
    try {
        $ssLookup = $pdo->prepare("SELECT id FROM section_subjects WHERE section_id = :sec_id AND instructor_id = :user_id ORDER BY id ASC LIMIT 1");
        $ssLookup->execute(['sec_id' => $sectionIdParam, 'user_id' => $userId]);
        $foundSsId = (int)$ssLookup->fetchColumn();
        if ($foundSsId > 0) {
            $sectionSubjectId = $foundSsId;
        }
    } catch (\PDOException $e) {
        error_log("Failed to resolve section_id to section_subject_id: " . $e->getMessage());
    }
}

// ── Mode detection ─────────────────────────────────────────────────────────────
$subjectMode = false;
$subject     = null;

if ($sectionSubjectId > 0) {
    $subject = verifyTeacherOwnsSubject($pdo, $userId, $sectionSubjectId);
    if (!$subject) {
        $_SESSION['flash_error'] = 'Access denied: you are not assigned to that subject.';
        header('Location: ' . resolveAppUrl('teacher/lms'));
        exit;
    }
    $subjectMode = true;
}

// ── Subject-Scoped Data: Students, Grades, and Performance ─────────────────────
$studentsList        = [];
$gradeSubmission     = null;
$submissionStatus    = 'draft';
$isLocked            = false;
$gradeKpis           = [
    'enrolled'       => 0,
    'graded'         => 0,
    'avg_final'      => 0.0,
    'pass_count'     => 0,
    'pass_rate'      => 0.0,
];
$studentPerformanceMap = [];

if ($subjectMode) {
    $sectionId = (int)$subject['section_id'];
    $termId    = (int)$subject['academic_term_id'];

    // 1. Fetch grade_submissions record for this section & term
    $gsStmt = $pdo->prepare('SELECT * FROM grade_submissions WHERE section_id = :sec_id AND academic_term_id = :term_id LIMIT 1');
    $gsStmt->execute(['sec_id' => $sectionId, 'term_id' => $termId]);
    $gradeSubmission = $gsStmt->fetch(PDO::FETCH_ASSOC);

    if ($gradeSubmission) {
        $submissionStatus = $gradeSubmission['status'];
        if (in_array($submissionStatus, ['approved', 'locked'], true)) {
            $isLocked = true;
        }
    }

    // 2. Fetch enrolled students with existing recorded grades for this section_subject
    $enrStmt = $pdo->prepare(
        "SELECT e.id AS enrollment_id,
                s.id AS student_id,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.username) AS student_name,
                u.email AS student_email,
                sg.id AS grade_id,
                sg.prelim_grade,
                sg.midterm_grade,
                sg.final_exam_grade,
                sg.final_grade,
                sg.remarks
         FROM enrollments e
         JOIN students s ON s.id = e.student_id
         JOIN users u ON u.id = s.user_id
         LEFT JOIN grade_submissions gs ON gs.section_id = e.section_id AND gs.academic_term_id = e.academic_term_id
         LEFT JOIN student_grades sg ON sg.grade_submission_id = gs.id
                                    AND sg.enrollment_id = e.id
                                    AND sg.section_subject_id = :ss_id
         WHERE e.section_id = :sec_id
           AND e.status = 'enrolled'
         ORDER BY student_name ASC"
    );
    $enrStmt->execute(['sec_id' => $sectionId, 'ss_id' => $sectionSubjectId]);
    $studentsList = $enrStmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Fetch LMS Coursework Performance (Assignments, Activities, Quizzes) per student
    // Assignment/Activity scores
    $submStmt = $pdo->prepare(
        "SELECT subm.student_id, a.id AS item_id, a.title, a.assignment_type, a.max_score,
                subm.score, subm.feedback, subm.submitted_at, subm.graded_at
         FROM lms_assignment_submissions subm
         JOIN lms_assignments a ON a.id = subm.assignment_id
         WHERE a.section_subject_id = :ss
         ORDER BY a.assignment_type ASC, a.due_at ASC"
    );
    $submStmt->execute(['ss' => $sectionSubjectId]);
    $submissionsByStudent = [];
    foreach ($submStmt->fetchAll(PDO::FETCH_ASSOC) as $subm) {
        $submissionsByStudent[(int)$subm['student_id']][] = $subm;
    }

    // Quiz scores
    $attStmt = $pdo->prepare(
        "SELECT att.student_id, q.id AS quiz_id, q.title, att.id AS attempt_id, att.attempt_number,
                att.status, att.score, att.total_points, att.submitted_at
         FROM lms_quiz_attempts att
         JOIN lms_quizzes q ON q.id = att.quiz_id
         WHERE q.section_subject_id = :ss
         ORDER BY q.display_order ASC, att.attempt_number ASC"
    );
    $attStmt->execute(['ss' => $sectionSubjectId]);
    $attemptsByStudent = [];
    foreach ($attStmt->fetchAll(PDO::FETCH_ASSOC) as $att) {
        $attemptsByStudent[(int)$att['student_id']][] = $att;
    }

    // Compute coursework summary stats per student
    $validFinalGrades = [];
    $passMarksCount = 0;

    foreach ($studentsList as &$st) {
        $stId = (int)$st['student_id'];
        $userSubs = $submissionsByStudent[$stId] ?? [];
        $userAtts = $attemptsByStudent[$stId] ?? [];

        // Assignment pct average
        $assignmentPcts = [];
        $gradedAssignmentsCount = 0;
        foreach ($userSubs as $us) {
            if ($us['score'] !== null && (float)$us['max_score'] > 0) {
                $assignmentPcts[] = ((float)$us['score'] / (float)$us['max_score']) * 100;
                $gradedAssignmentsCount++;
            }
        }
        $avgAssignmentPct = !empty($assignmentPcts) ? round(array_sum($assignmentPcts) / count($assignmentPcts), 1) : null;

        // Quiz pct average
        $quizPcts = [];
        $completedQuizzesCount = 0;
        foreach ($userAtts as $ua) {
            if ($ua['score'] !== null && (float)$ua['total_points'] > 0 && $ua['status'] !== 'in_progress') {
                $quizPcts[] = ((float)$ua['score'] / (float)$ua['total_points']) * 100;
                $completedQuizzesCount++;
            }
        }
        $avgQuizPct = !empty($quizPcts) ? round(array_sum($quizPcts) / count($quizPcts), 1) : null;

        // Combined Coursework Average
        $allPcts = array_merge($assignmentPcts, $quizPcts);
        $overallCourseworkAvg = !empty($allPcts) ? round(array_sum($allPcts) / count($allPcts), 1) : null;

        $st['coursework_avg']      = $overallCourseworkAvg;
        $st['avg_assignment_pct']  = $avgAssignmentPct;
        $st['avg_quiz_pct']        = $avgQuizPct;
        $st['assignment_count']    = count($userSubs);
        $st['quiz_attempt_count']  = count($userAtts);

        // Track KPIs
        if ($st['final_grade'] !== null && $st['final_grade'] !== '') {
            $fgVal = (float)$st['final_grade'];
            $validFinalGrades[] = $fgVal;
            if ($fgVal >= 75.00) {
                $passMarksCount++;
            }
        }

        // Cache detailed performance data for student modal
        $studentPerformanceMap[$stId] = [
            'student_id'     => $stId,
            'name'           => $st['student_name'],
            'email'          => $st['student_email'],
            'coursework_avg' => $overallCourseworkAvg,
            'prelim'         => $st['prelim_grade'],
            'midterm'        => $st['midterm_grade'],
            'final_exam'     => $st['final_exam_grade'],
            'final_grade'    => $st['final_grade'],
            'remarks'        => $st['remarks'],
            'submissions'    => $userSubs,
            'attempts'       => $userAtts,
        ];
    }
    unset($st);

    // Compute KPI tallies
    $gradeKpis['enrolled']   = count($studentsList);
    $gradeKpis['graded']     = count($validFinalGrades);
    $gradeKpis['avg_final']  = count($validFinalGrades) > 0 ? round(array_sum($validFinalGrades) / count($validFinalGrades), 1) : 0.0;
    $gradeKpis['pass_count'] = $passMarksCount;
    $gradeKpis['pass_rate']  = count($validFinalGrades) > 0 ? round(($passMarksCount / count($validFinalGrades)) * 100, 1) : 0.0;
}

// ── Overview Mode: All Assigned Subjects ───────────────────────────────────────
$allSubjects = [];
if (!$subjectMode) {
    $allSubjects = fetchLmsTeacherSubjects($pdo, $userId);
    if (!empty($allSubjects)) {
        foreach ($allSubjects as &$s) {
            $ssId  = (int)$s['section_subject_id'];
            $secId = (int)$s['section_id'];

            // Grade submission status for this section & term
            $sSubStmt = $pdo->prepare('SELECT status, submitted_at, reviewed_at FROM grade_submissions WHERE section_id = :sec_id LIMIT 1');
            $sSubStmt->execute(['sec_id' => $secId]);
            $subData = $sSubStmt->fetch(PDO::FETCH_ASSOC);
            $s['grade_status'] = $subData['status'] ?? 'draft';

            // Enrolled & graded count
            $cntStmt = $pdo->prepare(
                "SELECT COUNT(e.id) AS enrolled_count,
                        COUNT(sg.id) AS graded_count,
                        AVG(sg.final_grade) AS avg_final
                 FROM enrollments e
                 LEFT JOIN grade_submissions gs ON gs.section_id = e.section_id
                 LEFT JOIN student_grades sg ON sg.grade_submission_id = gs.id
                                            AND sg.enrollment_id = e.id
                                            AND sg.section_subject_id = :ss_id
                                            AND sg.final_grade IS NOT NULL
                 WHERE e.section_id = :sec_id
                   AND e.status = 'enrolled'"
            );
            $cntStmt->execute(['sec_id' => $secId, 'ss_id' => $ssId]);
            $cntData = $cntStmt->fetch(PDO::FETCH_ASSOC);

            $s['enrolled_count'] = (int)($cntData['enrolled_count'] ?? 0);
            $s['graded_count']   = (int)($cntData['graded_count'] ?? 0);
            $s['avg_final']      = $cntData['avg_final'] !== null ? round((float)$cntData['avg_final'], 1) : null;
        }
        unset($s);
    }
}

$page_title = $subjectMode
    ? $subject['subject_code'] . ' — Grades & Performance'
    : 'LMS — Grades & Performance';

require_once '../includes/header.php';

$lmsActiveTab = 'grades';
require_once __DIR__ . '/lms_navbar.php';
?>

<?php if ($subjectMode): ?>

    <!-- ════════════════════════════════════════════════════════════════════
         SUBJECT MODE: SUBJECT-SCOPED GRADEBOOK & PERFORMANCE
         ════════════════════════════════════════════════════════════════════ -->
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb mb-0" style="font-size:.82rem;">
            <li class="breadcrumb-item"><a href="lms_grades" class="text-decoration-none text-brand-primary">Grades Overview</a></li>
            <li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars($subject['subject_code']); ?></li>
        </ol>
    </nav>

    <!-- Header card with status and action buttons -->
    <div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                <span class="badge" style="background:var(--brand-primary, #008080);"><?php echo htmlspecialchars($subject['subject_code']); ?></span>
                <span class="badge bg-light text-secondary border"><?php echo htmlspecialchars($subject['section_name']); ?></span>
                <span class="badge bg-light text-secondary border"><?php echo (int)$subject['units']; ?> units</span>

                <?php if ($submissionStatus === 'approved'): ?>
                    <span class="badge text-bg-success"><i class="bi bi-shield-check me-1"></i>Approved by Registrar (Locked)</span>
                <?php elseif ($submissionStatus === 'submitted'): ?>
                    <span class="badge text-bg-info"><i class="bi bi-send-check me-1"></i>Submitted to Registrar (Pending Review)</span>
                <?php else: ?>
                    <span class="badge text-bg-secondary"><i class="bi bi-pencil-square me-1"></i>Draft (Instructor Editable)</span>
                <?php endif; ?>
            </div>
            <h1 class="h3 fw-bold text-navy-alt mb-1"><?php echo htmlspecialchars($subject['subject_name']); ?></h1>
            <p class="text-muted small mb-0">Record periodic grades, auto-calculate overall marks, provide feedback, and track student coursework.</p>
        </div>
        <div class="d-flex gap-2 align-items-center flex-wrap" style="gap: 8px;">
            <a href="lms_subject?section_subject_id=<?php echo $sectionSubjectId; ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Subject Hub
            </a>
            <a href="lms_grades" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-grid-3x3-gap me-1"></i>All Subjects
            </a>
        </div>
    </div>

    <!-- KPI Strip -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card card-premium shadow-sm border-0 h-100" style="padding: 18px 20px;">
                <div class="d-flex align-items-center" style="gap: 14px;">
                    <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px; background: rgba(0, 128, 128, 0.1); color: var(--brand-primary, #008080);">
                        <i class="bi bi-people fs-5"></i>
                    </div>
                    <div class="d-flex flex-column" style="gap: 4px;">
                        <span class="text-muted text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.5px;">Class Enrollment</span>
                        <span class="fs-5 fw-bold text-navy-alt lh-1"><?php echo $gradeKpis['enrolled']; ?></span>
                        <span class="text-muted" style="font-size: 0.75rem;">students enrolled</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card card-premium shadow-sm border-0 h-100" style="padding: 18px 20px;">
                <div class="d-flex align-items-center" style="gap: 14px;">
                    <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px; background: rgba(13, 110, 253, 0.1); color: #0d6efd;">
                        <i class="bi bi-check2-circle fs-5"></i>
                    </div>
                    <div class="d-flex flex-column" style="gap: 4px;">
                        <span class="text-muted text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.5px;">Grades Recorded</span>
                        <span class="fs-5 fw-bold text-navy-alt lh-1"><?php echo $gradeKpis['graded']; ?> <span class="text-muted fw-normal fs-6">/ <?php echo $gradeKpis['enrolled']; ?></span></span>
                        <span class="text-muted" style="font-size: 0.75rem;"><?php echo $gradeKpis['enrolled'] > 0 ? round(($gradeKpis['graded'] / $gradeKpis['enrolled']) * 100) : 0; ?>% complete</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card card-premium shadow-sm border-0 h-100" style="padding: 18px 20px;">
                <div class="d-flex align-items-center" style="gap: 14px;">
                    <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px; background: rgba(25, 135, 84, 0.1); color: #198754;">
                        <i class="bi bi-graph-up fs-5"></i>
                    </div>
                    <div class="d-flex flex-column" style="gap: 4px;">
                        <span class="text-muted text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.5px;">Final Average</span>
                        <span class="fs-5 fw-bold <?php echo $gradeKpis['avg_final'] >= 75 ? 'text-success' : 'text-danger'; ?> lh-1">
                            <?php echo $gradeKpis['avg_final'] > 0 ? $gradeKpis['avg_final'] : '—'; ?>
                        </span>
                        <span class="text-muted" style="font-size: 0.75rem;">based on recorded finals</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card card-premium shadow-sm border-0 h-100" style="padding: 18px 20px;">
                <div class="d-flex align-items-center" style="gap: 14px;">
                    <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px; background: rgba(255, 193, 7, 0.15); color: #b78103;">
                        <i class="bi bi-award fs-5"></i>
                    </div>
                    <div class="d-flex flex-column" style="gap: 4px;">
                        <span class="text-muted text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.5px;">Passing Rate</span>
                        <span class="fs-5 fw-bold text-success lh-1"><?php echo $gradeKpis['pass_rate']; ?>%</span>
                        <span class="text-muted" style="font-size: 0.75rem;"><?php echo $gradeKpis['pass_count']; ?> of <?php echo $gradeKpis['graded']; ?> passing (&ge;75)</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Notification / Advisory Banner -->
    <?php if ($isLocked): ?>
        <div class="alert alert-success d-flex align-items-center gap-3 mb-4 shadow-sm">
            <i class="bi bi-lock-fill fs-4 text-success"></i>
            <div>
                <strong>Official Academic Record Locked:</strong> These grades have been approved by the Registrar and are officially recorded in student transcripts. Further modifications are locked.
            </div>
        </div>
    <?php elseif ($submissionStatus === 'submitted'): ?>
        <div class="alert alert-info d-flex align-items-center gap-3 mb-4 shadow-sm">
            <i class="bi bi-send-check-fill fs-4 text-info"></i>
            <div>
                <strong>Pending Registrar Approval:</strong> You submitted these grades to the Registrar. You may save revisions if needed, or await Registrar sign-off.
            </div>
        </div>
    <?php endif; ?>

    <!-- ── GRADING FORM & TABLE ────────────────────────────────────── -->
    <form action="../actions/lms_teacher_grade_actions" method="post" id="form-manage-grades" class="card card-premium shadow-sm border-0 mb-5">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
        <input type="hidden" name="section_subject_id" value="<?php echo $sectionSubjectId; ?>">

        <!-- Header Toolbar -->
        <div class="card-header card-header-premium py-3 px-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h2 class="h6 fw-bold text-navy-alt mb-0"><i class="bi bi-award me-2 text-brand-primary"></i>Student Grades &amp; Evaluations</h2>
                    <span class="text-muted small">Enter scores between 0.00 and 100.00. Changes immediately reflect on the Student LMS portal.</span>
                </div>
                <?php if (!$isLocked): ?>
                    <!-- Quick Calculation Tools -->
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <div class="dropdown">
                            <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-calculator me-1"></i>Auto-Calculate Final
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                <li>
                                    <button class="dropdown-item small" type="button" onclick="autoCalculateFinalGrades('periodic_weighted')">
                                        <i class="bi bi-percent me-2 text-primary"></i>Standard (30% Prelim, 30% Midterm, 40% Final Exam)
                                    </button>
                                </li>
                                <li>
                                    <button class="dropdown-item small" type="button" onclick="autoCalculateFinalGrades('equal_periodic')">
                                        <i class="bi bi-distribute-vertical me-2 text-info"></i>Equal Weight (1/3 Prelim + Midterm + Final Exam)
                                    </button>
                                </li>
                                <li>
                                    <button class="dropdown-item small" type="button" onclick="autoCalculateFinalGrades('with_coursework')">
                                        <i class="bi bi-journal-check me-2 text-success"></i>With LMS Coursework (40% LMS Work + 20% P + 20% M + 20% FE)
                                    </button>
                                </li>
                            </ul>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Grade Table -->
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:.88rem; min-width: 950px;">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4" style="min-width: 220px; width: 22%; padding-left: 24px !important;">Student</th>
                        <th style="min-width: 110px; width: 11%;">LMS Work</th>
                        <th style="min-width: 95px; width: 10%;">Prelim</th>
                        <th style="min-width: 95px; width: 10%;">Midterm</th>
                        <th style="min-width: 105px; width: 11%;">Final Exam</th>
                        <th style="min-width: 110px; width: 11%;">Final Grade</th>
                        <th style="min-width: 180px; width: 20%;">Instructor Remarks / Feedback</th>
                        <th class="text-end pe-4" style="min-width: 90px; width: 5%; padding-right: 24px !important;">Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($studentsList)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-5 text-muted">
                                <i class="bi bi-people" style="font-size:2rem;opacity:.4;"></i>
                                <p class="mt-2 mb-0">No enrolled students found in this section.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($studentsList as $row):
                            $enrId = (int)$row['enrollment_id'];
                            $stId  = (int)$row['student_id'];
                            $cwAvg = $row['coursework_avg'];
                        ?>
                            <tr>
                                <td class="ps-4" style="padding-left: 24px !important;">
                                    <div class="fw-semibold text-dark"><?php echo htmlspecialchars($row['student_name']); ?></div>
                                    <div class="text-muted" style="font-size:.75rem;"><?php echo htmlspecialchars($row['student_email']); ?></div>
                                </td>
                                <td>
                                    <?php if ($cwAvg !== null): ?>
                                        <span class="badge bg-light text-dark border fw-semibold">
                                            <?php echo $cwAvg; ?>%
                                        </span>
                                        <div class="text-muted" style="font-size:.7rem;">
                                            <?php echo (int)$row['assignment_count']; ?> work · <?php echo (int)$row['quiz_attempt_count']; ?> quiz
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted small">No LMS work</span>
                                    <?php endif; ?>
                                    <input type="hidden" name="coursework_avg[<?php echo $enrId; ?>]" id="cw-avg-<?php echo $enrId; ?>" value="<?php echo $cwAvg !== null ? $cwAvg : ''; ?>">
                                </td>
                                <td>
                                    <input type="number" min="0" max="100" step="0.01" class="form-control form-control-sm grade-input"
                                           name="prelim[<?php echo $enrId; ?>]" id="prelim-<?php echo $enrId; ?>"
                                           value="<?php echo htmlspecialchars((string)($row['prelim_grade'] ?? '')); ?>"
                                           placeholder="0.00" <?php echo $isLocked ? 'disabled' : ''; ?>>
                                </td>
                                <td>
                                    <input type="number" min="0" max="100" step="0.01" class="form-control form-control-sm grade-input"
                                           name="midterm[<?php echo $enrId; ?>]" id="midterm-<?php echo $enrId; ?>"
                                           value="<?php echo htmlspecialchars((string)($row['midterm_grade'] ?? '')); ?>"
                                           placeholder="0.00" <?php echo $isLocked ? 'disabled' : ''; ?>>
                                </td>
                                <td>
                                    <input type="number" min="0" max="100" step="0.01" class="form-control form-control-sm grade-input"
                                           name="final_exam[<?php echo $enrId; ?>]" id="final-exam-<?php echo $enrId; ?>"
                                           value="<?php echo htmlspecialchars((string)($row['final_exam_grade'] ?? '')); ?>"
                                           placeholder="0.00" <?php echo $isLocked ? 'disabled' : ''; ?>>
                                </td>
                                <td>
                                    <input type="number" min="0" max="100" step="0.01" class="form-control form-control-sm grade-input fw-bold <?php echo (!empty($row['final_grade']) && (float)$row['final_grade'] < 75) ? 'text-danger' : 'text-success'; ?>"
                                           name="final_grade[<?php echo $enrId; ?>]" id="final-grade-<?php echo $enrId; ?>"
                                           value="<?php echo htmlspecialchars((string)($row['final_grade'] ?? '')); ?>"
                                           placeholder="0.00" <?php echo $isLocked ? 'disabled' : ''; ?>>
                                </td>
                                <td>
                                    <input type="text" maxlength="255" class="form-control form-control-sm"
                                           name="remarks[<?php echo $enrId; ?>]" id="remarks-<?php echo $enrId; ?>"
                                           value="<?php echo htmlspecialchars((string)($row['remarks'] ?? '')); ?>"
                                           placeholder="Feedback for student..." <?php echo $isLocked ? 'disabled' : ''; ?>>
                                </td>
                                <td class="text-end pe-4" style="padding-right: 24px !important;">
                                    <button type="button" class="btn btn-outline-primary btn-sm"
                                            onclick="openPerformanceModal(<?php echo $stId; ?>)"
                                            title="View student coursework performance breakdown">
                                        <i class="bi bi-graph-up"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Footer Actions -->
        <?php if (!$isLocked && !empty($studentsList)): ?>
            <div class="card-footer bg-white py-3 px-4 d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div class="small text-muted">
                    <i class="bi bi-info-circle me-1"></i>
                    <strong>Save Draft</strong> saves your grades to the LMS for students to review.
                    <strong>Submit to Registrar</strong> forwards final grades for official locking.
                </div>
                <div class="d-flex gap-2 ms-auto" style="gap: 8px;">
                    <button type="submit" name="action" value="save_grades" class="btn btn-outline-secondary btn-sm px-3">
                        <i class="bi bi-save me-1"></i>Save Draft Grades
                    </button>
                    <button type="button" class="btn btn-brand-primary btn-sm px-3" onclick="confirmSubmitGrades()">
                        <i class="bi bi-send-check me-1"></i>Submit to Registrar
                    </button>
                </div>
            </div>
        <?php endif; ?>
    </form>

    <!-- ── MODAL: CONFIRM SUBMIT TO REGISTRAR ────────────────────────── -->
    <div class="modal fade" id="modal-confirm-submit" tabindex="-1" aria-labelledby="modalSubmitTitle" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-navy-alt" id="modalSubmitTitle">
                        <i class="bi bi-send-check me-2 text-brand-primary"></i>Submit Final Grades to Registrar
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you ready to submit final grades for <strong><?php echo htmlspecialchars($subject['subject_code'] . ' — ' . $subject['section_name']); ?></strong> to the Registrar?</p>
                    <div class="alert alert-warning small mb-0">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        Please ensure all enrolled students have a valid <strong>Final Grade</strong> entered. Once submitted, the Registrar reviews and approves the records for official transcripts.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-brand-primary btn-sm" onclick="executeSubmitGrades()">
                        <i class="bi bi-send-check me-1"></i>Confirm &amp; Submit
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ── MODAL: STUDENT PERFORMANCE DETAIL BREAKDOWN ────────────────── -->
    <div class="modal fade" id="modal-student-performance" tabindex="-1" aria-labelledby="modalPerfTitle" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title fw-bold" id="modalPerfTitle"><i class="bi bi-person-badge me-2 text-brand-primary"></i>Student Performance Profile</h5>
                        <p class="text-muted small mb-0" id="perf-student-sub"></p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4" id="perf-modal-content">
                    <!-- Populated dynamically via JS -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

<?php else: ?>

    <!-- ════════════════════════════════════════════════════════════════════
         OVERVIEW MODE: CROSS-SUBJECT GRADE MANAGEMENT OVERVIEW
         ════════════════════════════════════════════════════════════════════ -->
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb mb-0" style="font-size:.82rem;">
            <li class="breadcrumb-item"><a href="lms" class="text-decoration-none text-brand-primary"><i class="bi bi-journal-bookmark-fill me-1"></i>LMS Dashboard</a></li>
            <li class="breadcrumb-item active" aria-current="page">Grades &amp; Performance</li>
        </ol>
    </nav>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge" style="background:var(--brand-primary, #008080);font-size:.75rem;letter-spacing:.04em;text-transform:uppercase;">
                    Instructor Management
                </span>
            </div>
            <h1 class="h3 fw-bold text-navy-alt mb-1">Grades &amp; Performance</h1>
            <p class="text-muted small mb-0">Select an assigned subject to enter grades, calculate finals, and monitor student performance.</p>
        </div>
        <div>
            <a href="lms" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-grid-fill me-1"></i>LMS Dashboard
            </a>
        </div>
    </div>

    <?php if (empty($allSubjects)): ?>
        <div class="card card-premium shadow-sm border-0 text-center py-5">
            <div class="card-body">
                <i class="bi bi-mortarboard" style="font-size:3rem;opacity:.3;color:var(--brand-primary, #008080);"></i>
                <h2 class="h6 fw-semibold mt-3 mb-1">No subjects assigned</h2>
                <p class="text-muted small mb-3">You do not have any active subject assignments for the current academic term.</p>
                <a href="my_classes" class="btn btn-outline-secondary btn-sm"><i class="bi bi-door-open me-1"></i>My Classes</a>
            </div>
        </div>
    <?php else: ?>
        <div class="card card-premium shadow-sm border-0 mb-4">
            <div class="card-header card-header-premium py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h2 class="h6 fw-bold mb-0 text-navy-alt">
                    <i class="bi bi-award me-2 text-brand-primary"></i>Assigned Subjects &amp; Grades
                </h2>
                <span class="badge bg-light text-muted border"><?php echo count($allSubjects); ?> Subjects</span>
            </div>
            <div class="card-body p-4">
                <?php 
                    $colClass = count($allSubjects) === 1 ? 'col-12' : (count($allSubjects) === 2 ? 'col-12 col-md-6' : 'col-12 col-md-6 col-xl-4');
                ?>
                <div class="row g-3">
                    <?php foreach ($allSubjects as $s):
                        $sid      = (int)$s['section_subject_id'];
                        $enrolled = (int)$s['enrolled_count'];
                        $graded   = (int)$s['graded_count'];
                        $avgFin   = $s['avg_final'];
                        $status   = $s['grade_status'];

                        $statBadges = [
                            'approved'  => ['Approved (Locked)', 'text-bg-success'],
                            'submitted' => ['Submitted to Registrar', 'text-bg-info'],
                            'draft'     => ['Draft (In Progress)', 'text-bg-secondary'],
                        ];
                        [$statLabel, $statClass] = $statBadges[$status] ?? ['Draft', 'text-bg-secondary'];
                    ?>
                    <div class="<?php echo $colClass; ?>">
                        <div class="card border h-100 shadow-sm" style="border-color:#e2e8f0 !important; border-radius: 10px;">
                            <div class="card-body p-4 d-flex flex-column justify-content-between">
                                <div>
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div>
                                            <span class="text-uppercase fw-bold text-brand-primary" style="font-size:.72rem;letter-spacing:.06em;">
                                                <?php echo htmlspecialchars($s['subject_code']); ?>
                                            </span>
                                            <h2 class="h6 fw-bold mt-1 mb-1 text-navy-alt">
                                                <?php echo htmlspecialchars($s['subject_name']); ?>
                                            </h2>
                                            <div class="text-muted small">
                                                <?php echo htmlspecialchars($s['section_name']); ?>
                                                <?php if (!empty($s['year_level'])): ?> · <?php echo htmlspecialchars($s['year_level']); ?><?php endif; ?>
                                            </div>
                                        </div>
                                        <span class="badge <?php echo $statClass; ?>" style="font-size:.72rem;"><?php echo $statLabel; ?></span>
                                    </div>
                                    <div class="d-flex gap-3 my-3 small text-muted">
                                        <span><i class="bi bi-people me-1 text-primary"></i><?php echo $enrolled; ?> students</span>
                                        <span><i class="bi bi-check2-circle me-1 text-success"></i><?php echo $graded; ?> / <?php echo $enrolled; ?> graded</span>
                                        <?php if ($avgFin !== null): ?>
                                            <span><i class="bi bi-award me-1 text-warning"></i>Avg: <strong><?php echo $avgFin; ?></strong></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="pt-2">
                                    <a href="lms_grades?section_subject_id=<?php echo $sid; ?>" class="btn btn-brand-primary btn-sm w-100">
                                        <i class="bi bi-award me-1"></i>Manage Grades
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

<?php endif; ?>

<!-- ════════════════════════════════════════════════════════════════════
     JAVASCRIPT LOGIC
     ════════════════════════════════════════════════════════════════════ -->
<script>
// ── Auto-Calculate Final Grades ────────────────────────────────────────────────
function autoCalculateFinalGrades(formula) {
    const form = document.getElementById('form-manage-grades');
    if (!form) return;

    const fd = new FormData(form);
    fd.set('action', 'calculate_grades');
    fd.set('formula', formula);

    fetch('../actions/lms_teacher_grade_actions', {
        method: 'POST',
        headers: { 'Accept': 'application/json' },
        body: fd
    })
    .then(res => res.json())
    .then(data => {
        if (!data.ok) {
            alert(data.error || 'Failed to calculate final grades.');
            return;
        }

        const calculated = data.calculated || {};
        let countUpdated = 0;

        for (const [enrId, val] of Object.entries(calculated)) {
            const input = document.getElementById('final-grade-' + enrId);
            if (input && val !== '') {
                input.value = val;
                input.classList.remove('text-danger', 'text-success');
                input.classList.add(parseFloat(val) >= 75 ? 'text-success' : 'text-danger');
                countUpdated++;
            }
        }

        const formulaNames = {
            'periodic_weighted': 'Standard (30% Prelim, 30% Midterm, 40% Final Exam)',
            'equal_periodic': 'Equal Weight (1/3 Prelim + Midterm + Final Exam)',
            'with_coursework': 'With Coursework (40% Coursework + 20% P + 20% M + 20% FE)'
        };

        alert('Calculated ' + countUpdated + ' final grades using: ' + (formulaNames[formula] || formula) + '. Click "Save Draft Grades" to persist these values.');
    })
    .catch(() => {
        alert('An error occurred while calculating final grades.');
    });
}

// ── Submit to Registrar Dialog ─────────────────────────────────────────────────
function confirmSubmitGrades() {
    new bootstrap.Modal(document.getElementById('modal-confirm-submit')).show();
}

function executeSubmitGrades() {
    const form = document.getElementById('form-manage-grades');
    if (!form) return;

    // Set action to submit_grades and submit form
    const hiddenAct = document.createElement('input');
    hiddenAct.type = 'hidden';
    hiddenAct.name = 'action';
    hiddenAct.value = 'submit_grades';
    form.appendChild(hiddenAct);
    form.submit();
}

// ── Student Performance Profile Modal ──────────────────────────────────────────
<?php if ($subjectMode): ?>
const studentPerfData = <?php echo json_encode($studentPerformanceMap, JSON_UNESCAPED_SLASHES); ?>;

function openPerformanceModal(studentId) {
    const data = studentPerfData[studentId];
    if (!data) return;

    document.getElementById('perf-student-sub').textContent = data.name + ' (' + data.email + ')';

    const container = document.getElementById('perf-modal-content');
    container.innerHTML = '';

    // Summary Cards
    const summaryCard = document.createElement('div');
    summaryCard.className = 'card border rounded mb-4 bg-light';
    summaryCard.innerHTML = `
        <div class="card-body p-3">
            <div class="row g-2 text-center">
                <div class="col-3">
                    <span class="small text-muted d-block">Prelim</span>
                    <strong class="fs-6">${data.prelim !== null ? data.prelim : '—'}</strong>
                </div>
                <div class="col-3">
                    <span class="small text-muted d-block">Midterm</span>
                    <strong class="fs-6">${data.midterm !== null ? data.midterm : '—'}</strong>
                </div>
                <div class="col-3">
                    <span class="small text-muted d-block">Final Exam</span>
                    <strong class="fs-6">${data.final_exam !== null ? data.final_exam : '—'}</strong>
                </div>
                <div class="col-3">
                    <span class="small text-muted d-block">Final Grade</span>
                    <strong class="fs-6 ${parseFloat(data.final_grade) >= 75 ? 'text-success' : (data.final_grade ? 'text-danger' : '')}">
                        ${data.final_grade !== null ? data.final_grade : '—'}
                    </strong>
                </div>
            </div>
            ${data.remarks ? `<div class="mt-2 pt-2 border-top small text-muted"><i class="bi bi-chat-quote me-1"></i>Feedback: <strong>${data.remarks}</strong></div>` : ''}
        </div>
    `;
    container.appendChild(summaryCard);

    // Assignments & Activities Table
    const assignHeader = document.createElement('h6');
    assignHeader.className = 'fw-bold text-navy-alt mb-2';
    assignHeader.innerHTML = '<i class="bi bi-pencil-square me-2 text-primary"></i>Assignments &amp; Activities';
    container.appendChild(assignHeader);

    const subs = data.submissions || [];
    if (subs.length === 0) {
        const emptyP = document.createElement('p');
        emptyP.className = 'text-muted small mb-4';
        emptyP.textContent = 'No assignment submissions recorded for this student.';
        container.appendChild(emptyP);
    } else {
        const tableDiv = document.createElement('div');
        tableDiv.className = 'table-responsive mb-4';
        let tableHtml = `
            <table class="table table-sm table-hover border align-middle mb-0" style="font-size:.82rem;">
                <thead class="table-light">
                    <tr><th>Item</th><th>Type</th><th>Score</th><th>Teacher Feedback</th></tr>
                </thead>
                <tbody>
        `;
        subs.forEach(sub => {
            tableHtml += `
                <tr>
                    <td class="fw-semibold">${sub.title}</td>
                    <td><span class="badge bg-light text-muted border">${sub.assignment_type}</span></td>
                    <td><strong>${sub.score !== null ? sub.score : '—'}</strong> / ${sub.max_score || '—'}</td>
                    <td class="small text-muted">${sub.feedback ? sub.feedback : '—'}</td>
                </tr>
            `;
        });
        tableHtml += '</tbody></table>';
        tableDiv.innerHTML = tableHtml;
        container.appendChild(tableDiv);
    }

    // Quizzes & Tests Table
    const quizHeader = document.createElement('h6');
    quizHeader.className = 'fw-bold text-navy-alt mb-2';
    quizHeader.innerHTML = '<i class="bi bi-patch-question me-2 text-info"></i>Quizzes &amp; Tests';
    container.appendChild(quizHeader);

    const atts = data.attempts || [];
    if (atts.length === 0) {
        const emptyP = document.createElement('p');
        emptyP.className = 'text-muted small mb-0';
        emptyP.textContent = 'No quiz attempts recorded for this student.';
        container.appendChild(emptyP);
    } else {
        const tableDiv = document.createElement('div');
        tableDiv.className = 'table-responsive';
        let tableHtml = `
            <table class="table table-sm table-hover border align-middle mb-0" style="font-size:.82rem;">
                <thead class="table-light">
                    <tr><th>Quiz</th><th>Attempt</th><th>Status</th><th>Score</th><th>Submitted Date</th></tr>
                </thead>
                <tbody>
        `;
        atts.forEach(att => {
            tableHtml += `
                <tr>
                    <td class="fw-semibold">${att.title}</td>
                    <td><span class="badge bg-light text-dark border">#${att.attempt_number}</span></td>
                    <td><span class="badge ${att.status === 'submitted' ? 'text-bg-success' : 'text-bg-warning'}">${att.status}</span></td>
                    <td><strong class="text-success">${att.score !== null ? att.score : '—'}</strong> / ${att.total_points || '—'}</td>
                    <td class="small text-muted">${att.submitted_at || '—'}</td>
                </tr>
            `;
        });
        tableHtml += '</tbody></table>';
        tableDiv.innerHTML = tableHtml;
        container.appendChild(tableDiv);
    }

    new bootstrap.Modal(document.getElementById('modal-student-performance')).show();
}
<?php endif; ?>
</script>

<?php require_once '../includes/footer.php'; ?>
