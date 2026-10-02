<?php
/**
 * Teacher LMS - Student Progress View (Phase 9.2)
 *
 * Allows instructors to monitor how each enrolled student is progressing
 * through their assigned course/subject.
 *
 * Uses the exact same underlying criteria and function as the Student-side Course Progress:
 *   - fetchLmsCourseProgress($pdo, $studentId, $subjectId)
 *     1. Completed lessons (lms_lessons + lms_lesson_progress)
 *     2. Viewed learning materials (lms_materials + lms_material_views)
 *     3. Submitted assignments (lms_assignments + lms_assignment_submissions)
 *     4. Completed quizzes (lms_quizzes + lms_quiz_attempts)
 *     5. Completed modules (lms_modules + all lessons viewed)
 *
 * Security:
 *   - Role gate: requireLmsTeacherAccess() (faculty / admin only)
 *   - Scoped strictly to subjects assigned to the logged-in teacher:
 *     section_subjects.instructor_id = $userId OR sections.teacher_id = $userId
 *   - Cannot view progress for unassigned courses.
 */

require_once '../includes/lms_access.php';

$teacher          = requireLmsTeacherAccess();
$userId           = (int)$teacher['user_id'];
$sectionSubjectId = (int)filter_input(INPUT_GET, 'section_subject_id', FILTER_VALIDATE_INT);

// ── Fetch all subjects assigned to this teacher ────────────────────────────────
$allSubjects = fetchLmsTeacherSubjects($pdo, $userId);

// ── Mode detection ─────────────────────────────────────────────────────────────
$subjectMode = false;
$subject     = null;

if ($sectionSubjectId > 0) {
    $subject = verifyTeacherOwnsSubject($pdo, $userId, $sectionSubjectId);
    if (!$subject) {
        $_SESSION['flash_error'] = 'Access denied: you are not assigned to that subject.';
        header('Location: ' . resolveAppUrl('teacher/lms_progress'));
        exit;
    }
    $subjectMode = true;
}

// ── Subject Mode: Fetch Enrolled Students & Their Course Progress ──────────────
$enrolledCadets = [];
$classKpis = [
    'total_students' => 0,
    'class_average'  => 0,
    'ahead_count'    => 0, // >= 80%
    'in_progress'    => 0, // 50% - 79%
    'at_risk_count'  => 0, // < 50%
];

if ($subjectMode) {
    $secId = (int)$subject['section_id'];
    $subId = (int)$subject['subject_id'];

    // Query enrolled students in this section
    $cadetsStmt = $pdo->prepare(
        "SELECT s.id AS student_id,
                s.user_id,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.username) AS student_name,
                u.username,
                u.email
         FROM enrollments e
         JOIN students s ON s.id = e.student_id
         JOIN users u ON u.id = s.user_id
         WHERE e.section_id = :sec_id
           AND e.status = 'enrolled'
           AND s.enrollment_status = 'enrolled'
         ORDER BY student_name ASC"
    );
    $cadetsStmt->execute(['sec_id' => $secId]);
    $rawCadets = $cadetsStmt->fetchAll(PDO::FETCH_ASSOC);

    $totalPercentSum = 0;
    foreach ($rawCadets as $cadet) {
        $stId = (int)$cadet['student_id'];
        // Pull progress from the EXACT same underlying function used on the student side
        $progress = fetchLmsCourseProgress($pdo, $stId, $subId);

        $cadet['progress'] = $progress;
        $pct = (int)$progress['percent'];
        $totalPercentSum += $pct;

        if ($pct >= 80) {
            $classKpis['ahead_count']++;
            $cadet['status_category'] = 'ahead';
            $cadet['status_label']    = 'On Track';
            $cadet['badge_class']     = 'bg-success-subtle text-success border border-success-subtle';
        } elseif ($pct >= 50) {
            $classKpis['in_progress']++;
            $cadet['status_category'] = 'progress';
            $cadet['status_label']    = 'In Progress';
            $cadet['badge_class']     = 'bg-info-subtle text-info-emphasis border border-info-subtle';
        } else {
            $classKpis['at_risk_count']++;
            $cadet['status_category'] = 'at_risk';
            $cadet['status_label']    = 'Needs Support';
            $cadet['badge_class']     = 'bg-warning-subtle text-warning-emphasis border border-warning-subtle';
        }

        $enrolledCadets[] = $cadet;
    }

    $classKpis['total_students'] = count($enrolledCadets);
    if ($classKpis['total_students'] > 0) {
        $classKpis['class_average'] = (int)round($totalPercentSum / $classKpis['total_students']);
    }
}

// ── Overview Mode: Fetch class-level progress averages for all subjects ────────
$overviewSubjects = [];
if (!$subjectMode && !empty($allSubjects)) {
    foreach ($allSubjects as $subj) {
        $sid   = (int)$subj['section_subject_id'];
        $secId = (int)$subj['section_id'];
        $subId = (int)$subj['subject_id'];

        $enrStmt = $pdo->prepare(
            "SELECT s.id AS student_id
             FROM enrollments e
             JOIN students s ON s.id = e.student_id
             WHERE e.section_id = :sec_id
               AND e.status = 'enrolled'
               AND s.enrollment_status = 'enrolled'"
        );
        $enrStmt->execute(['sec_id' => $secId]);
        $sIds = $enrStmt->fetchAll(PDO::FETCH_COLUMN);

        $subjTotalStudents = count($sIds);
        $subjSumPct        = 0;
        $subjAhead         = 0;
        $subjAtRisk        = 0;

        foreach ($sIds as $stId) {
            $p = fetchLmsCourseProgress($pdo, (int)$stId, $subId);
            $pct = (int)$p['percent'];
            $subjSumPct += $pct;
            if ($pct >= 80) $subjAhead++;
            elseif ($pct < 50) $subjAtRisk++;
        }

        $subjAvg = $subjTotalStudents > 0 ? (int)round($subjSumPct / $subjTotalStudents) : 0;

        $subj['enrolled_count']  = $subjTotalStudents;
        $subj['average_percent'] = $subjAvg;
        $subj['ahead_count']     = $subjAhead;
        $subj['at_risk_count']   = $subjAtRisk;

        $overviewSubjects[] = $subj;
    }
}

$page_title = $subjectMode
    ? htmlspecialchars($subject['subject_code']) . ' — Student Progress'
    : 'Class Progress Overview — Instructor Management';

require_once '../includes/header.php';

$lmsActiveTab = 'progress';
require_once __DIR__ . '/lms_navbar.php';
?>

<!-- ── BREADCRUMBS ──────────────────────────────────────────────────────────── -->
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb mb-0" style="font-size:.82rem;">
        <li class="breadcrumb-item"><a href="lms" class="text-decoration-none text-brand-primary">Instructor LMS</a></li>
        <?php if ($subjectMode): ?>
            <li class="breadcrumb-item"><a href="lms_progress" class="text-decoration-none text-brand-primary">Class Progress Overview</a></li>
            <li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars($subject['subject_code']); ?> — <?php echo htmlspecialchars($subject['section_name']); ?></li>
        <?php else: ?>
            <li class="breadcrumb-item active" aria-current="page">Student Progress Overview</li>
        <?php endif; ?>
    </ol>
</nav>

<?php if ($subjectMode): ?>

    <!-- ════════════════════════════════════════════════════════════════════════
         SUBJECT MODE: DETAILED STUDENT PROGRESS ROSTER FOR SPECIFIC CLASS
         ════════════════════════════════════════════════════════════════════════ -->
    <div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                <span class="badge" style="background:var(--brand-primary, #008080);font-size:.8rem;letter-spacing:.04em;">
                    <?php echo htmlspecialchars($subject['subject_code']); ?>
                </span>
                <span class="badge bg-light text-secondary border" style="font-size:.8rem;">
                    Section: <?php echo htmlspecialchars($subject['section_name']); ?>
                </span>
                <?php if (!empty($subject['term_name'])): ?>
                    <span class="badge bg-light text-secondary border" style="font-size:.8rem;">
                        <?php echo htmlspecialchars($subject['term_name']); ?>
                    </span>
                <?php endif; ?>
            </div>
            <h1 class="h3 fw-bold text-navy-alt mb-1"><?php echo htmlspecialchars($subject['subject_name']); ?> — Student Progress</h1>
            <p class="text-muted small mb-0">
                Track each cadet's learning journey across lessons, materials, assignments, quizzes, and completed modules.
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap align-items-center" style="gap: 8px;">
            <a href="lms_subject?section_subject_id=<?php echo $sectionSubjectId; ?>&section=overview" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Subject Hub
            </a>
            <a href="lms_progress" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-grid-3x3-gap me-1"></i>All Classes Overview
            </a>
        </div>
    </div>

    <!-- KPI Metrics Strip -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card card-premium shadow-sm border-0 h-100" style="padding: 18px 20px;">
                <div class="d-flex align-items-center" style="gap: 14px;">
                    <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px; background: rgba(0, 128, 128, 0.1); color: var(--brand-primary, #008080);">
                        <i class="bi bi-speedometer2 fs-5"></i>
                    </div>
                    <div class="d-flex flex-column" style="gap: 4px;">
                        <span class="text-muted text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.5px;">Class Average</span>
                        <span class="fs-5 fw-bold text-navy-alt lh-1"><?php echo $classKpis['class_average']; ?>%</span>
                        <div class="progress mt-1" style="height: 4px; width: 90px;">
                            <div class="progress-bar" role="progressbar" style="width: <?php echo $classKpis['class_average']; ?>%; background: var(--brand-primary, #008080);"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card card-premium shadow-sm border-0 h-100" style="padding: 18px 20px;">
                <div class="d-flex align-items-center" style="gap: 14px;">
                    <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px; background: rgba(108, 117, 125, 0.1); color: #495057;">
                        <i class="bi bi-people fs-5"></i>
                    </div>
                    <div class="d-flex flex-column" style="gap: 4px;">
                        <span class="text-muted text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.5px;">Enrolled Cadets</span>
                        <span class="fs-5 fw-bold text-navy-alt lh-1"><?php echo $classKpis['total_students']; ?></span>
                        <span class="text-muted" style="font-size: 0.75rem;">active students</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card card-premium shadow-sm border-0 h-100" style="padding: 18px 20px;">
                <div class="d-flex align-items-center" style="gap: 14px;">
                    <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px; background: rgba(25, 135, 84, 0.1); color: #198754;">
                        <i class="bi bi-check-circle fs-5"></i>
                    </div>
                    <div class="d-flex flex-column" style="gap: 4px;">
                        <span class="text-muted text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.5px;">On Track (&ge;80%)</span>
                        <span class="fs-5 fw-bold text-success lh-1"><?php echo $classKpis['ahead_count']; ?></span>
                        <span class="text-muted" style="font-size: 0.75rem;">cadets thriving</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card card-premium shadow-sm border-0 h-100" style="padding: 18px 20px;">
                <div class="d-flex align-items-center" style="gap: 14px;">
                    <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px; background: rgba(255, 193, 7, 0.15); color: #b78103;">
                        <i class="bi bi-exclamation-triangle fs-5"></i>
                    </div>
                    <div class="d-flex flex-column" style="gap: 4px;">
                        <span class="text-muted text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.5px;">Needs Support</span>
                        <span class="fs-5 fw-bold text-warning lh-1"><?php echo $classKpis['at_risk_count']; ?></span>
                        <span class="text-muted" style="font-size: 0.75rem;">require intervention</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Controls: Subject Switcher, Filter Pills, Search Input -->
    <div class="card card-premium shadow-sm border-0 mb-4">
        <div class="card-body p-3 p-md-4">
            <div class="row g-2 align-items-center">
                <!-- Subject Switcher -->
                <div class="col-12 col-md-4 col-lg-3">
                    <label for="progress-subject-select" class="small text-muted fw-semibold mb-1 d-block">Switch Subject:</label>
                    <select class="form-select form-select-sm" id="progress-subject-select" onchange="switchSubjectProgress(this.value)">
                        <?php foreach ($allSubjects as $subj): ?>
                            <option value="<?php echo $subj['section_subject_id']; ?>" <?php echo ((int)$subj['section_subject_id'] === $sectionSubjectId) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($subj['subject_code']); ?> — <?php echo htmlspecialchars($subj['section_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Status Filter Pills -->
                <div class="col-12 col-md-5 col-lg-5">
                    <label class="small text-muted fw-semibold mb-1 d-block">Filter by Pace:</label>
                    <div class="btn-group btn-group-sm w-100" role="group">
                        <button type="button" class="btn btn-outline-secondary progress-filter-btn active" data-filter="all">
                            All (<?php echo $classKpis['total_students']; ?>)
                        </button>
                        <button type="button" class="btn btn-outline-success progress-filter-btn" data-filter="ahead">
                            On Track (<?php echo $classKpis['ahead_count']; ?>)
                        </button>
                        <button type="button" class="btn btn-outline-info progress-filter-btn" data-filter="progress">
                            In Progress (<?php echo $classKpis['in_progress']; ?>)
                        </button>
                        <button type="button" class="btn btn-outline-warning progress-filter-btn" data-filter="at_risk">
                            Needs Support (<?php echo $classKpis['at_risk_count']; ?>)
                        </button>
                    </div>
                </div>

                <!-- Search Input -->
                <div class="col-12 col-md-3 col-lg-4">
                    <label for="cadet-search" class="small text-muted fw-semibold mb-1 d-block">Search Cadet:</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-search"></i></span>
                        <input type="text" class="form-control border-start-0" id="cadet-search" placeholder="Search cadet name or username...">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Student Progress Roster Table -->
    <?php if (empty($enrolledCadets)): ?>
        <div class="card card-premium shadow-sm border-0 py-5 text-center">
            <div class="card-body">
                <i class="bi bi-people text-muted" style="font-size:3.5rem;opacity:.35;"></i>
                <h3 class="h5 fw-bold mt-3 mb-1">No Enrolled Cadets Found</h3>
                <p class="text-muted small mx-auto mb-3" style="max-width:440px;">
                    There are no enrolled students registered in this section yet.
                </p>
                <a href="lms_subject?section_subject_id=<?php echo $sectionSubjectId; ?>&section=overview" class="btn btn-sm btn-brand-primary">Return to Subject Hub</a>
            </div>
        </div>
    <?php else: ?>
        <div class="card card-premium shadow-sm border-0 mb-4">
            <div class="card-header card-header-premium py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h2 class="h6 fw-bold mb-0 text-navy-alt">
                    <i class="bi bi-person-lines-fill me-2 text-brand-primary"></i>Enrolled Cadet Progress List
                    <span class="badge bg-secondary ms-1" id="visible-cadets-badge"><?php echo count($enrolledCadets); ?></span>
                </h2>
                <div class="small text-muted">
                    Progress metrics pull directly from student lesson completions, material views, assignment submissions, quiz attempts, and module milestones.
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="cadets-progress-table">
                    <thead class="table-light small text-uppercase text-muted" style="font-size:.72rem;letter-spacing:.05em;">
                        <tr>
                            <th class="ps-4" style="min-width:200px; padding-left: 24px !important;">Cadet Student</th>
                            <th style="min-width:180px;">Overall Completion</th>
                            <th style="min-width:280px;">Activity Breakdown (5 Criteria)</th>
                            <th style="min-width:130px;">Pace Status</th>
                            <th class="text-end pe-4" style="min-width:120px; padding-right: 24px !important;">Action</th>
                        </tr>
                    </thead>
                    <tbody class="small">
                        <?php foreach ($enrolledCadets as $cadet): ?>
                            <?php
                                $prog = $cadet['progress'];
                                $pct  = (int)$prog['percent'];
                                $items = $prog['items'];
                            ?>
                            <tr class="cadet-row"
                                data-category="<?php echo $cadet['status_category']; ?>"
                                data-search="<?php echo htmlspecialchars(strtolower($cadet['student_name'] . ' ' . $cadet['username'])); ?>">
                                
                                <!-- Student Cadet Info -->
                                <td class="ps-4" style="padding-left: 24px !important;">
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold shadow-sm"
                                             style="width:34px;height:34px;font-size:.8rem;background:#0b4f5c;flex-shrink:0;">
                                            <?php echo strtoupper(substr($cadet['student_name'], 0, 1)); ?>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-dark"><?php echo htmlspecialchars($cadet['student_name']); ?></div>
                                            <div class="text-muted" style="font-size:.75rem;">@<?php echo htmlspecialchars($cadet['username']); ?></div>
                                        </div>
                                    </div>
                                </td>

                                <!-- Overall Progress Bar -->
                                <td>
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <strong class="text-dark"><?php echo $pct; ?>%</strong>
                                        <span class="text-muted" style="font-size:.75rem;"><?php echo (int)$prog['completed']; ?> / <?php echo (int)$prog['total']; ?> done</span>
                                    </div>
                                    <div class="progress" style="height:7px;">
                                        <div class="progress-bar <?php echo $pct >= 80 ? 'bg-success' : ($pct >= 50 ? 'bg-info' : 'bg-warning'); ?>"
                                             role="progressbar"
                                             style="width: <?php echo $pct; ?>%;"
                                             aria-valuenow="<?php echo $pct; ?>" aria-valuemin="0" aria-valuemax="100">
                                        </div>
                                    </div>
                                </td>

                                <!-- Activity Breakdown Pills (5 criteria) -->
                                <td>
                                    <div class="d-flex flex-wrap gap-1" style="font-size:.72rem;">
                                        <!-- Lessons -->
                                        <span class="badge bg-light text-dark border" title="Lessons Completed">
                                            <i class="bi bi-book me-1 text-primary"></i>L: <?php echo (int)$items['lessons']['completed']; ?>/<?php echo (int)$items['lessons']['total']; ?>
                                        </span>
                                        <!-- Materials -->
                                        <span class="badge bg-light text-dark border" title="Materials Viewed">
                                            <i class="bi bi-folder2 me-1 text-warning"></i>M: <?php echo (int)$items['materials']['completed']; ?>/<?php echo (int)$items['materials']['total']; ?>
                                        </span>
                                        <!-- Assignments -->
                                        <span class="badge bg-light text-dark border" title="Assignments Submitted">
                                            <i class="bi bi-pencil-square me-1 text-success"></i>A: <?php echo (int)$items['assignments']['completed']; ?>/<?php echo (int)$items['assignments']['total']; ?>
                                        </span>
                                        <!-- Quizzes -->
                                        <span class="badge bg-light text-dark border" title="Quizzes Completed">
                                            <i class="bi bi-patch-question me-1 text-info"></i>Q: <?php echo (int)$items['quizzes']['completed']; ?>/<?php echo (int)$items['quizzes']['total']; ?>
                                        </span>
                                        <!-- Modules -->
                                        <span class="badge bg-light text-dark border" title="Modules Completed">
                                            <i class="bi bi-check-all me-1 text-danger"></i>Mod: <?php echo (int)$items['modules']['completed']; ?>/<?php echo (int)$items['modules']['total']; ?>
                                        </span>
                                    </div>
                                </td>

                                <!-- Status Badge -->
                                <td>
                                    <span class="badge <?php echo $cadet['badge_class']; ?> px-2 py-1">
                                        <?php echo htmlspecialchars($cadet['status_label']); ?>
                                    </span>
                                </td>

                                <!-- Action -->
                                <td class="text-end pe-4" style="padding-right: 24px !important;">
                                    <button type="button" class="btn btn-sm btn-outline-primary btn-view-progress-details"
                                            data-details='<?php echo htmlspecialchars(json_encode([
                                                'name'      => $cadet['student_name'],
                                                'username'  => $cadet['username'],
                                                'email'     => $cadet['email'],
                                                'percent'   => $pct,
                                                'completed' => (int)$prog['completed'],
                                                'total'     => (int)$prog['total'],
                                                'items'     => $prog['items'],
                                                'status'    => $cadet['status_label'],
                                            ]), ENT_QUOTES, 'UTF-8'); ?>'
                                            title="View Detailed Activity Breakdown">
                                        <i class="bi bi-bar-chart-line me-1"></i>Breakdown
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

<?php else: ?>

    <!-- ════════════════════════════════════════════════════════════════════════
         OVERVIEW MODE: ALL ASSIGNED COURSES PROGRESS CARDS
         ════════════════════════════════════════════════════════════════════════ -->
    <div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge" style="background:var(--brand-primary, #008080);font-size:.75rem;letter-spacing:.04em;text-transform:uppercase;">
                    Instructor Management
                </span>
            </div>
            <h1 class="h3 fw-bold text-navy-alt mb-1">Class Progress Overview</h1>
            <p class="text-muted small mb-0">
                Monitor completion pacing and student engagement across each of your assigned courses.
            </p>
        </div>
        <div>
            <a href="lms" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-grid-fill me-1"></i>LMS Dashboard
            </a>
        </div>
    </div>

    <?php if (empty($overviewSubjects)): ?>
        <div class="card card-premium shadow-sm border-0 text-center py-5">
            <div class="card-body">
                <i class="bi bi-journal-x text-muted" style="font-size:3.5rem;"></i>
                <h3 class="h5 fw-bold mt-3 mb-1">No Assigned Subjects Found</h3>
                <p class="text-muted small mx-auto" style="max-width:420px;">
                    You do not currently have any active subject assignments.
                </p>
                <a href="lms" class="btn btn-sm btn-brand-primary mt-2">Return to Dashboard</a>
            </div>
        </div>
    <?php else: ?>
        <div class="card card-premium shadow-sm border-0 mb-4">
            <div class="card-header card-header-premium py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h2 class="h6 fw-bold mb-0 text-navy-alt">
                    <i class="bi bi-bar-chart-line me-2 text-brand-primary"></i>Assigned Classes &amp; Student Pacing
                </h2>
                <span class="badge bg-light text-muted border"><?php echo count($overviewSubjects); ?> Classes</span>
            </div>
            <div class="card-body p-4">
                <?php 
                    $colClass = count($overviewSubjects) === 1 ? 'col-12' : (count($overviewSubjects) === 2 ? 'col-12 col-md-6' : 'col-12 col-md-6 col-xl-4');
                ?>
                <div class="row g-3">
                    <?php foreach ($overviewSubjects as $subj): ?>
                        <?php
                            $avgPct = (int)$subj['average_percent'];
                            $barColor = $avgPct >= 80 ? 'bg-success' : ($avgPct >= 50 ? 'bg-primary' : 'bg-warning');
                        ?>
                        <div class="<?php echo $colClass; ?>">
                            <div class="card border h-100 shadow-sm" style="border-color:#e2e8f0 !important; border-radius: 10px;">
                                <div class="card-body p-4 d-flex flex-column justify-content-between">
                                    <div>
                                        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                            <span class="text-uppercase fw-bold text-brand-primary" style="font-size:.72rem;letter-spacing:.06em;">
                                                <?php echo htmlspecialchars($subj['subject_code']); ?>
                                            </span>
                                            <span class="badge bg-light text-muted border px-2 py-1" style="font-size:.75rem;">
                                                Section: <?php echo htmlspecialchars($subj['section_name']); ?>
                                            </span>
                                        </div>

                                        <h3 class="h6 fw-bold mb-1 text-navy-alt">
                                            <?php echo htmlspecialchars($subj['subject_name']); ?>
                                        </h3>
                                        <div class="small text-muted mb-3">
                                            <?php echo htmlspecialchars($subj['term_name'] ?? 'Current Term'); ?>
                                        </div>

                                        <!-- Progress Meter -->
                                        <div class="bg-light p-3 rounded border mb-3">
                                            <div class="d-flex justify-content-between align-items-center mb-1">
                                                <span class="text-muted small fw-semibold">Class Average Progress</span>
                                                <strong class="text-dark fs-6"><?php echo $avgPct; ?>%</strong>
                                            </div>
                                            <div class="progress" style="height:8px;">
                                                <div class="progress-bar <?php echo $barColor; ?>" role="progressbar" style="width: <?php echo $avgPct; ?>%;"></div>
                                            </div>
                                            <div class="d-flex justify-content-between align-items-center mt-2" style="font-size:.75rem;">
                                                <span class="text-success"><i class="bi bi-check-circle me-1"></i><?php echo $subj['ahead_count']; ?> On Track</span>
                                                <span class="text-warning-emphasis"><i class="bi bi-exclamation-triangle me-1"></i><?php echo $subj['at_risk_count']; ?> Need Support</span>
                                            </div>
                                        </div>

                                        <!-- Enrolled Cadets Count -->
                                        <div class="d-flex justify-content-between align-items-center small text-muted mb-3">
                                            <span><i class="bi bi-people me-1"></i>Enrolled Cadets:</span>
                                            <strong class="text-dark"><?php echo $subj['enrolled_count']; ?> students</strong>
                                        </div>
                                    </div>

                                    <!-- Action -->
                                    <div class="mt-auto">
                                        <a href="lms_progress?section_subject_id=<?php echo $subj['section_subject_id']; ?>" class="btn btn-sm btn-brand-primary w-100">
                                            <i class="bi bi-bar-chart-line me-1"></i>Monitor Student Progress
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

<!-- ════════════════════════════════════════════════════════════════════════
     MODAL: DETAILED STUDENT PROGRESS BREAKDOWN
     ════════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="modal-student-progress-details" tabindex="-1" aria-labelledby="modalProgressDetailsTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content shadow">
            <div class="modal-header text-white" style="background:var(--brand-primary,#0b4f5c);">
                <h5 class="modal-title fw-bold" id="modalProgressDetailsTitle">
                    <i class="bi bi-graph-up-arrow me-2"></i>Detailed Course Progress Breakdown
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <!-- Cadet Overview Header -->
                <div class="card border bg-light mb-4">
                    <div class="card-body p-3">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                            <div>
                                <h5 class="fw-bold mb-1 text-dark" id="modal-cadet-name">—</h5>
                                <div class="text-muted small">
                                    <span id="modal-cadet-username">—</span> • <span id="modal-cadet-email">—</span>
                                </div>
                            </div>
                            <div class="text-end">
                                <span class="badge bg-secondary px-2 py-1 mb-1" id="modal-cadet-status">—</span>
                                <div class="fs-4 fw-bold text-brand-primary" id="modal-cadet-percent">0%</div>
                            </div>
                        </div>
                        <div class="progress mt-2" style="height:10px;">
                            <div class="progress-bar bg-primary" id="modal-cadet-progress-bar" role="progressbar" style="width: 0%;"></div>
                        </div>
                        <div class="text-muted small text-end mt-1" id="modal-cadet-items-ratio">0 of 0 activities completed</div>
                    </div>
                </div>

                <!-- 5 Criteria Activity Breakdown Table -->
                <h6 class="fw-bold mb-2 text-dark"><i class="bi bi-list-check me-1 text-primary"></i>5 Learning Activity Criteria</h6>
                <div class="table-responsive border rounded bg-white mb-2">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light small text-uppercase text-muted" style="font-size:.72rem;">
                            <tr>
                                <th>Learning Activity</th>
                                <th class="text-center" style="width:140px;">Completed</th>
                                <th class="text-end" style="width:180px;">Progress</th>
                            </tr>
                        </thead>
                        <tbody id="modal-activity-tbody" class="small">
                            <!-- Populated dynamically via JS -->
                        </tbody>
                    </table>
                </div>

                <div class="small text-muted mt-2">
                    <i class="bi bi-info-circle me-1"></i>Progress is calculated directly from lesson views, material views, assignment submissions, quiz attempts, and complete module views.
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════════════════
     PAGE JAVASCRIPT: FILTERING, SEARCH, AND MODAL
     ════════════════════════════════════════════════════════════════════════ -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    const searchInput      = document.getElementById('cadet-search');
    const filterBtns       = document.querySelectorAll('.progress-filter-btn');
    const tableRows        = document.querySelectorAll('.cadet-row');
    const visibleBadge     = document.getElementById('visible-cadets-badge');

    let currentFilter = 'all';

    function applyCadetFilters() {
        const query = searchInput ? searchInput.value.trim().toLowerCase() : '';
        let count = 0;

        tableRows.forEach(row => {
            const category = row.dataset.category || '';
            const searchStr= row.dataset.search || '';

            let matchCategory = (currentFilter === 'all' || category === currentFilter);
            let matchSearch   = (query === '' || searchStr.includes(query));

            if (matchCategory && matchSearch) {
                row.style.display = '';
                count++;
            } else {
                row.style.display = 'none';
            }
        });

        if (visibleBadge) {
            visibleBadge.textContent = count;
        }
    }

    if (searchInput) {
        searchInput.addEventListener('input', applyCadetFilters);
    }

    filterBtns.forEach(btn => {
        btn.addEventListener('click', function () {
            filterBtns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            currentFilter = this.dataset.filter;
            applyCadetFilters();
        });
    });

    // ── Detailed Progress Modal Population ─────────────────────────────────
    document.querySelectorAll('.btn-view-progress-details').forEach(btn => {
        btn.addEventListener('click', function () {
            try {
                const data = JSON.parse(this.dataset.details);
                document.getElementById('modal-cadet-name').textContent = data.name;
                document.getElementById('modal-cadet-username').textContent = '@' + data.username;
                document.getElementById('modal-cadet-email').textContent = data.email;
                document.getElementById('modal-cadet-status').textContent = data.status;
                document.getElementById('modal-cadet-percent').textContent = data.percent + '%';
                document.getElementById('modal-cadet-progress-bar').style.width = data.percent + '%';
                document.getElementById('modal-cadet-items-ratio').textContent = data.completed + ' of ' + data.total + ' activities completed';

                // Populate tbody
                const tbody = document.getElementById('modal-activity-tbody');
                tbody.innerHTML = '';

                const items = data.items || {};
                const keys = [
                    { k: 'lessons',     icon: 'bi-book text-primary',         label: 'Completed lessons' },
                    { k: 'materials',   icon: 'bi-folder2 text-warning',      label: 'Viewed learning materials' },
                    { k: 'assignments', icon: 'bi-pencil-square text-success',label: 'Submitted assignments' },
                    { k: 'quizzes',     icon: 'bi-patch-question text-info',  label: 'Completed quizzes' },
                    { k: 'modules',     icon: 'bi-check-all text-danger',     label: 'Completed modules' },
                ];

                keys.forEach(entry => {
                    const item = items[entry.k] || { completed: 0, total: 0 };
                    const completed = parseInt(item.completed, 10) || 0;
                    const total     = parseInt(item.total, 10) || 0;
                    const pct       = total > 0 ? Math.round((completed / total) * 100) : 0;

                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td>
                            <i class="bi ${entry.icon} me-2"></i>
                            <strong>${entry.label}</strong>
                        </td>
                        <td class="text-center">
                            ${completed} / ${total}
                        </td>
                        <td class="text-end">
                            <div class="d-flex align-items-center justify-content-end gap-2">
                                <div class="progress flex-grow-1" style="height:6px;max-width:100px;">
                                    <div class="progress-bar ${pct >= 100 ? 'bg-success' : 'bg-primary'}" style="width:${pct}%;"></div>
                                </div>
                                <span class="fw-semibold" style="width:40px;">${pct}%</span>
                            </div>
                        </td>
                    `;
                    tbody.appendChild(tr);
                });

                new bootstrap.Modal(document.getElementById('modal-student-progress-details')).show();
            } catch (e) {
                console.error('Failed to parse progress details:', e);
            }
        });
    });
});

function switchSubjectProgress(ssId) {
    if (ssId) {
        window.location.href = 'lms_progress?section_subject_id=' + encodeURIComponent(ssId);
    } else {
        window.location.href = 'lms_progress';
    }
}
</script>

<?php require_once '../includes/footer.php'; ?>
