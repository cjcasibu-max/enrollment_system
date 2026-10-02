<?php
/**
 * Instructor Dashboard — Teacher LMS Landing Page (Phase 2)
 *
 * Access control:
 *   requireLmsTeacherAccess() → checkRole(['teacher']) + LMS allowlist check.
 *   No payment or enrollment gate applies to teachers.
 *
 * Data scoping:
 *   Every query binds $userId so only the logged-in teacher's subjects,
 *   students, submissions, and progress records are ever returned.
 */

require_once '../includes/lms_access.php';

$teacher = requireLmsTeacherAccess();
$userId  = $teacher['user_id'];

// ── 1. Teacher display name ───────────────────────────────────────────────────
$nameStmt = $pdo->prepare(
    "SELECT COALESCE(NULLIF(TRIM(CONCAT_WS(' ', first_name, last_name)), ''), username) AS display_name,
            username
     FROM users WHERE id = :id LIMIT 1"
);
$nameStmt->execute(['id' => $userId]);
$teacherInfo = $nameStmt->fetch(PDO::FETCH_ASSOC) ?: ['display_name' => 'Instructor', 'username' => ''];
$displayName = htmlspecialchars($teacherInfo['display_name']);

// ── 2. Assigned subjects (scoped to this teacher) ────────────────────────────
$subjects = fetchLmsTeacherSubjects($pdo, $userId);

// Build a lookup: section_subject_id → subject row (used throughout)
$subjectMap = [];
$ssIds      = [];
foreach ($subjects as $s) {
    $ssId              = (int)$s['section_subject_id'];
    $subjectMap[$ssId] = $s;
    $ssIds[]           = $ssId;
}

// ── 3. Per-subject stats from live data ──────────────────────────────────────
// These are fetched in one query each rather than N+1 loops.

// 3a. Pending (ungraded) assignment submissions per section_subject_id
$pendingBySubject = [];
if (!empty($ssIds)) {
    $inPlaceholders = implode(',', array_fill(0, count($ssIds), '?'));
    $pendingStmt = $pdo->prepare(
        "SELECT a.section_subject_id,
                COUNT(subm.id)                         AS pending_count,
                GROUP_CONCAT(
                    CONCAT(a.title, '|', subm.id)
                    ORDER BY subm.submitted_at ASC
                    SEPARATOR ';;'
                )                                      AS pending_detail
         FROM lms_assignment_submissions subm
         JOIN lms_assignments a ON a.id = subm.assignment_id
         WHERE a.section_subject_id IN ($inPlaceholders)
           AND subm.graded_at IS NULL
           AND subm.score IS NULL
         GROUP BY a.section_subject_id"
    );
    $pendingStmt->execute($ssIds);
    foreach ($pendingStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pendingBySubject[(int)$row['section_subject_id']] = $row;
    }
}

// 3b. Total pending across all this teacher's subjects (KPI)
$totalPending = array_sum(array_column($pendingBySubject, 'pending_count'));

// 3c. Total enrolled students across all this teacher's subjects (KPI)
$totalStudents = 0;
foreach ($subjects as $s) {
    $totalStudents += (int)$s['enrolled_count'];
}

// 3d. Average student progress per section_subject_id
// Progress = (completed lessons + viewed materials + submitted assignments + completed quizzes)
//             as a fraction of (total published lessons + materials + assignments + quizzes)
$progressBySubject = [];
if (!empty($ssIds)) {
    // We run one query that counts totals & completions per section_subject_id
    $progressStmt = $pdo->prepare(
        "SELECT
            ss_id,
            SUM(total_items)     AS grand_total,
            SUM(done_items)      AS grand_done,
            student_count
         FROM (

             -- Lessons completed (per enrolled student)
             SELECT l_ss.id                 AS ss_id,
                    COUNT(l.id)             AS total_items,
                    COALESCE(SUM(lp.id IS NOT NULL), 0) AS done_items,
                    COUNT(DISTINCT e.student_id)         AS student_count
             FROM section_subjects l_ss
             JOIN sections sec ON sec.id = l_ss.section_id
             JOIN enrollments e ON e.section_id = sec.id AND e.status = 'enrolled'
             JOIN lms_modules m ON m.section_subject_id = l_ss.id AND m.is_published = 1
             JOIN lms_lessons l ON l.module_id = m.id AND l.is_published = 1
             LEFT JOIN lms_lesson_progress lp ON lp.lesson_id = l.id AND lp.student_id = e.student_id
             WHERE l_ss.id IN ($inPlaceholders)
             GROUP BY l_ss.id

             UNION ALL

             -- Materials viewed
             SELECT mat_ss.id               AS ss_id,
                    COUNT(mat.id)             AS total_items,
                    COALESCE(SUM(mv.id IS NOT NULL), 0) AS done_items,
                    COUNT(DISTINCT e2.student_id)        AS student_count
             FROM section_subjects mat_ss
             JOIN sections sec2 ON sec2.id = mat_ss.section_id
             JOIN enrollments e2 ON e2.section_id = sec2.id AND e2.status = 'enrolled'
             JOIN lms_materials mat ON mat.section_subject_id = mat_ss.id AND mat.is_available = 1
             LEFT JOIN lms_material_views mv ON mv.material_id = mat.id AND mv.student_id = e2.student_id
             WHERE mat_ss.id IN ($inPlaceholders)
             GROUP BY mat_ss.id

             UNION ALL

             -- Assignments submitted
             SELECT asgn_ss.id              AS ss_id,
                    COUNT(asgn.id)             AS total_items,
                    COALESCE(SUM(subm2.id IS NOT NULL), 0) AS done_items,
                    COUNT(DISTINCT e3.student_id)           AS student_count
             FROM section_subjects asgn_ss
             JOIN sections sec3 ON sec3.id = asgn_ss.section_id
             JOIN enrollments e3 ON e3.section_id = sec3.id AND e3.status = 'enrolled'
             JOIN lms_assignments asgn ON asgn.section_subject_id = asgn_ss.id AND asgn.is_published = 1
             LEFT JOIN lms_assignment_submissions subm2
                ON subm2.assignment_id = asgn.id AND subm2.student_id = e3.student_id
             WHERE asgn_ss.id IN ($inPlaceholders)
             GROUP BY asgn_ss.id

             UNION ALL

             -- Quizzes attempted
             SELECT quiz_ss.id              AS ss_id,
                    COUNT(q.id)                AS total_items,
                    COALESCE(SUM(EXISTS(
                        SELECT 1 FROM lms_quiz_attempts qa2
                        WHERE qa2.quiz_id = q.id
                          AND qa2.student_id = e4.student_id
                          AND qa2.status IN ('submitted','interrupted','timed_out')
                    )), 0)                     AS done_items,
                    COUNT(DISTINCT e4.student_id) AS student_count
             FROM section_subjects quiz_ss
             JOIN sections sec4 ON sec4.id = quiz_ss.section_id
             JOIN enrollments e4 ON e4.section_id = sec4.id AND e4.status = 'enrolled'
             JOIN lms_quizzes q ON q.section_subject_id = quiz_ss.id AND q.is_published = 1
             WHERE quiz_ss.id IN ($inPlaceholders)
             GROUP BY quiz_ss.id

         ) AS combined
         GROUP BY ss_id, student_count"
    );
    // Bind all four sets of placeholders
    $progressStmt->execute(array_merge($ssIds, $ssIds, $ssIds, $ssIds));
    foreach ($progressStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $total = (int)$row['grand_total'];
        $done  = (int)$row['grand_done'];
        $progressBySubject[(int)$row['ss_id']] = [
            'percent'       => $total > 0 ? (int)round(($done / $total) * 100) : 0,
            'total'         => $total,
            'done'          => $done,
            'student_count' => (int)$row['student_count'],
        ];
    }
}

// 3e. Overall average progress across all subjects (KPI)
$avgProgress = 0;
if (!empty($progressBySubject)) {
    $avgProgress = (int)round(
        array_sum(array_column($progressBySubject, 'percent')) / count($progressBySubject)
    );
}

// ── 4. Recent pending submissions list (for the dashboard panel) ──────────────
// Up to 15 most recent ungraded submissions across all this teacher's subjects
$recentPendingList = [];
if (!empty($ssIds)) {
    $recentStmt = $pdo->prepare(
        "SELECT subm.id            AS submission_id,
                subm.submitted_at,
                subm.student_id,
                a.title            AS assignment_title,
                a.assignment_type,
                a.section_subject_id,
                sub.subject_code,
                sub.subject_name,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.username) AS student_name
         FROM lms_assignment_submissions subm
         JOIN lms_assignments a     ON a.id = subm.assignment_id
         JOIN section_subjects ss   ON ss.id = a.section_subject_id
         JOIN subjects sub          ON sub.id = ss.subject_id
         JOIN students st           ON st.id = subm.student_id
         JOIN users u               ON u.id = st.user_id
         WHERE a.section_subject_id IN ($inPlaceholders)
           AND subm.graded_at IS NULL
           AND subm.score IS NULL
         ORDER BY subm.submitted_at DESC
         LIMIT 15"
    );
    $recentStmt->execute($ssIds);
    $recentPendingList = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
}

$page_title  = 'Instructor Dashboard';
$page_class  = 'page-dashboard page-lms-instructor';
require_once '../includes/header.php';

$lmsActiveTab = 'dashboard';
require_once __DIR__ . '/lms_navbar.php';
?>

<!-- ============================================================
     Hero / Page Header
     ============================================================ -->
<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <div>
        <h1 class="h3 fw-bold text-navy-alt mb-1">Instructor Dashboard</h1>
        <p class="text-muted small mb-0">
            Welcome back, <?php echo $displayName; ?> &bull;
            <strong><?php echo count($subjects); ?></strong> subject<?php echo count($subjects) !== 1 ? 's' : ''; ?> assigned
            <?php if ($totalPending > 0): ?>
                &bull; <span class="text-warning fw-semibold"><?php echo (int)$totalPending; ?> pending submission<?php echo $totalPending !== 1 ? 's' : ''; ?></span> awaiting review
            <?php endif; ?>
        </p>
    </div>
    <div class="d-flex align-items-center flex-wrap" style="gap: 8px;">
        <a href="lms_submissions" class="btn btn-outline-secondary btn-sm" id="lms-hero-submissions">
            <i class="bi bi-inbox me-1"></i>View Submissions
        </a>
        <a href="lms_announcements" class="btn btn-brand-primary btn-sm" id="lms-hero-announce">
            <i class="bi bi-megaphone me-1"></i>Post Announcement
        </a>
    </div>
</div>

<!-- ============================================================
     KPI Cards
     ============================================================ -->
<section class="row g-3 mb-4 dashboard-kpis" aria-label="Dashboard overview metrics">
    <div class="col-6 col-xl-3">
        <div class="card card-premium shadow-sm border-0 h-100" style="padding: 18px 20px;" id="kpi-subjects">
            <div class="d-flex align-items-center" style="gap: 14px;">
                <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                     style="width: 44px; height: 44px; background: rgba(0, 128, 128, 0.1); color: var(--brand-primary, #008080); font-size: 1.25rem;">
                    <i class="bi bi-book-fill"></i>
                </div>
                <div class="flex-grow-1 min-w-0">
                    <div class="text-muted text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.5px;">My Subjects</div>
                    <div class="fs-5 fw-bold text-navy-alt lh-1 mt-1"><?php echo count($subjects); ?></div>
                    <div class="small text-muted mt-1 text-truncate" style="font-size: 0.75rem;">Assigned this term</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card card-premium shadow-sm border-0 h-100" style="padding: 18px 20px;" id="kpi-students">
            <div class="d-flex align-items-center" style="gap: 14px;">
                <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                     style="width: 44px; height: 44px; background: rgba(59, 130, 246, 0.1); color: #3b82f6; font-size: 1.25rem;">
                    <i class="bi bi-people-fill"></i>
                </div>
                <div class="flex-grow-1 min-w-0">
                    <div class="text-muted text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.5px;">Total Students</div>
                    <div class="fs-5 fw-bold text-navy-alt lh-1 mt-1"><?php echo number_format($totalStudents); ?></div>
                    <div class="small text-muted mt-1 text-truncate" style="font-size: 0.75rem;">Across all subjects</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card card-premium shadow-sm border-0 h-100" style="padding: 18px 20px;" id="kpi-pending">
            <div class="d-flex align-items-center" style="gap: 14px;">
                <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                     style="width: 44px; height: 44px; background: rgba(245, 159, 0, 0.12); color: #d97706; font-size: 1.25rem;">
                    <i class="bi bi-inbox-fill"></i>
                </div>
                <div class="flex-grow-1 min-w-0">
                    <div class="text-muted text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.5px;">Pending Submissions</div>
                    <div class="fs-5 fw-bold text-navy-alt lh-1 mt-1 <?php echo $totalPending > 0 ? 'text-warning' : ''; ?>">
                        <?php echo number_format($totalPending); ?>
                    </div>
                    <div class="small text-muted mt-1 text-truncate" style="font-size: 0.75rem;">
                        <?php echo $totalPending > 0 ? 'Needs grading' : 'All caught up'; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card card-premium shadow-sm border-0 h-100" style="padding: 18px 20px;" id="kpi-progress">
            <div class="d-flex align-items-center" style="gap: 14px;">
                <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                     style="width: 44px; height: 44px; background: rgba(18, 184, 134, 0.1); color: #12b886; font-size: 1.25rem;">
                    <i class="bi bi-graph-up-arrow"></i>
                </div>
                <div class="flex-grow-1 min-w-0">
                    <div class="text-muted text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.5px;">Avg. Class Progress</div>
                    <div class="fs-5 fw-bold text-navy-alt lh-1 mt-1"><?php echo $avgProgress; ?>%</div>
                    <div class="small text-muted mt-1 text-truncate" style="font-size: 0.75rem;">Overall completion</div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php if (empty($subjects)): ?>
<!-- ============================================================
     Empty state — 0 subjects assigned
     ============================================================ -->
<div class="card card-premium shadow-sm border-0" id="lms-empty-state">
    <div class="card-body text-center py-5 px-4">
        <i class="bi bi-journal-x" style="font-size:3.2rem;color:var(--brand-primary);opacity:.45;"></i>
        <h2 class="h5 fw-bold mt-3 mb-2">No subjects assigned yet</h2>
        <p class="text-muted mb-4" style="max-width:440px;margin:0 auto;">
            Your Instructor Management area is ready. Once the Registrar assigns you
            to a section or subject, your courses will appear here automatically.
        </p>
        <a href="my_classes" class="btn btn-outline-secondary btn-sm" id="lms-view-classes-link">
            <i class="bi bi-door-open me-1"></i>View My Classes
        </a>
    </div>
</div>

<?php else: ?>
<!-- ============================================================
     Two-column main layout: subjects + pending sidebar
     ============================================================ -->
<div class="row g-4">

    <!-- ── LEFT: My Subjects ──────────────────────────────────── -->
    <div class="col-12 col-xl-8">

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="h5 fw-bold text-navy-alt mb-0">
                <i class="bi bi-book me-2 text-brand-primary"></i>My Subjects
            </h2>
            <span class="badge bg-secondary-subtle text-brand-primary border" style="font-size:.7rem;">
                <?php echo count($subjects); ?> subject<?php echo count($subjects) !== 1 ? 's' : ''; ?>
            </span>
        </div>

        <div class="row g-3" id="lms-subjects-grid">
            <?php 
            $subjColClass = count($subjects) === 1 ? 'col-12' : 'col-12 col-lg-6';
            foreach ($subjects as $subj):
                $ssId        = (int)$subj['section_subject_id'];
                $pending     = (int)($pendingBySubject[$ssId]['pending_count'] ?? 0);
                $progress    = $progressBySubject[$ssId] ?? ['percent' => 0, 'total' => 0, 'done' => 0];
                $enrolled    = (int)$subj['enrolled_count'];

                $scheduleText = trim(
                    ($subj['day_of_week'] ?? '') . ' ' .
                    ((!empty($subj['start_time'])) ? date('g:i A', strtotime($subj['start_time'])) : '') .
                    ((!empty($subj['start_time']) && !empty($subj['end_time'])) ? ' – ' : '') .
                    ((!empty($subj['end_time']))  ? date('g:i A', strtotime($subj['end_time']))  : '')
                );
                $termLabel = trim(($subj['school_year'] ?? '') . ' ' . ($subj['semester'] ?? '')) ?: 'Current term';
                $roleLabel    = !empty($subj['is_named_instructor']) ? 'Subject Instructor' : 'Section Lead';
                $roleBadgeCss = !empty($subj['is_named_instructor'])
                    ? 'bg-info-subtle text-info border-info-subtle'
                    : 'bg-primary-subtle text-primary border-primary-subtle';

                $progressColor = $progress['percent'] >= 75 ? '#12b886'
                    : ($progress['percent'] >= 40 ? 'var(--brand-primary)' : '#f59f00');
            ?>
            <div class="<?php echo $subjColClass; ?>">
                <article class="card h-100 shadow-sm border-0 card-premium" id="subject-card-<?php echo $ssId; ?>">
                    <div class="card-body d-flex flex-column p-4" style="gap:.85rem;">

                        <!-- Header row -->
                        <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                            <div class="d-flex gap-2 align-items-center flex-wrap">
                                <span class="badge text-bg-light border fw-bold" style="font-size:.78rem;">
                                    <?php echo htmlspecialchars($subj['subject_code']); ?>
                                </span>
                                <span class="badge border <?php echo $roleBadgeCss; ?>" style="font-size:.65rem;">
                                    <?php echo htmlspecialchars($roleLabel); ?>
                                </span>
                            </div>
                            <?php if ($pending > 0): ?>
                                <span class="badge bg-warning text-dark" style="font-size:.68rem;" title="Pending submissions">
                                    <i class="bi bi-inbox me-1"></i><?php echo $pending; ?> pending
                                </span>
                            <?php endif; ?>
                        </div>

                        <!-- Subject name -->
                        <h3 class="fw-bold mb-0" style="font-size:1.05rem;line-height:1.3;color:var(--text-dark, #1e293b);">
                            <?php echo htmlspecialchars($subj['subject_name']); ?>
                        </h3>

                        <!-- Meta -->
                        <dl class="mb-0" style="font-size:.82rem;color:#64748b;display:grid;grid-template-columns:auto 1fr;gap:.2rem .6rem;">
                            <dt class="fw-semibold">Section</dt>
                            <dd class="mb-0">
                                <?php echo htmlspecialchars($subj['section_name'] ?: '—'); ?>
                                <?php if (!empty($subj['year_level'])): ?> · <?php echo htmlspecialchars($subj['year_level']); ?><?php endif; ?>
                            </dd>
                            <?php if ($scheduleText): ?>
                            <dt class="fw-semibold">Schedule</dt>
                            <dd class="mb-0"><?php echo htmlspecialchars($scheduleText); ?></dd>
                            <?php endif; ?>
                            <?php if (!empty($subj['room'])): ?>
                            <dt class="fw-semibold">Room</dt>
                            <dd class="mb-0"><?php echo htmlspecialchars($subj['room']); ?></dd>
                            <?php endif; ?>
                            <dt class="fw-semibold">Term</dt>
                            <dd class="mb-0"><?php echo htmlspecialchars($termLabel); ?></dd>
                            <dt class="fw-semibold">Students</dt>
                            <dd class="mb-0 fw-semibold" style="color:var(--brand-primary);"><?php echo $enrolled; ?> enrolled</dd>
                        </dl>

                        <!-- Progress bar -->
                        <div class="mt-1">
                            <div class="d-flex justify-content-between align-items-center small mb-1">
                                <span class="fw-semibold" style="font-size:.78rem;">Class progress</span>
                                <span style="font-size:.78rem;color:<?php echo $progressColor; ?>;font-weight:700;">
                                    <?php echo $progress['percent']; ?>%
                                </span>
                            </div>
                            <div class="progress" role="progressbar"
                                 aria-label="<?php echo htmlspecialchars($subj['subject_name']); ?> class progress"
                                 aria-valuenow="<?php echo $progress['percent']; ?>"
                                 aria-valuemin="0" aria-valuemax="100"
                                 style="height:6px;border-radius:99px;background:#e2e8f0;">
                                <div class="progress-bar"
                                     style="width:<?php echo $progress['percent']; ?>%;background:<?php echo $progressColor; ?>;border-radius:99px;transition:width .6s ease;"></div>
                            </div>
                            <div class="small text-muted mt-1" style="font-size:.73rem;">
                                <?php echo $progress['done']; ?> of <?php echo $progress['total']; ?> learning activities complete across all students
                            </div>
                        </div>

                        <!-- Quick Actions -->
                        <div class="pt-2 border-top mt-1" style="border-color:#f1f5f9 !important;">
                            <div class="small fw-semibold text-muted mb-2" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;">
                                Quick Actions
                            </div>
                            <div class="d-flex flex-wrap" style="gap: 6px;">
                                <a href="lms_subject?section_subject_id=<?php echo $ssId; ?>#modules"
                                   class="btn btn-xs lms-qa-btn"
                                   id="qa-lessons-<?php echo $ssId; ?>"
                                   title="Manage lessons for this subject">
                                    <i class="bi bi-layers me-1"></i>Lessons
                                </a>
                                <a href="lms_materials?section_subject_id=<?php echo $ssId; ?>"
                                   class="btn btn-xs lms-qa-btn"
                                   id="qa-material-<?php echo $ssId; ?>"
                                   title="Add a learning material">
                                    <i class="bi bi-plus-circle me-1"></i>Material
                                </a>
                                <a href="lms_assignments?section_subject_id=<?php echo $ssId; ?>"
                                   class="btn btn-xs lms-qa-btn"
                                   id="qa-assign-<?php echo $ssId; ?>"
                                   title="Create an assignment">
                                    <i class="bi bi-pencil me-1"></i>Assignment
                                </a>
                                <a href="lms_quizzes?section_subject_id=<?php echo $ssId; ?>"
                                   class="btn btn-xs lms-qa-btn"
                                   id="qa-quiz-<?php echo $ssId; ?>"
                                   title="Create a quiz">
                                    <i class="bi bi-patch-question me-1"></i>Quiz
                                </a>
                                <a href="lms_announcements?section_subject_id=<?php echo $ssId; ?>"
                                   class="btn btn-xs lms-qa-btn"
                                   id="qa-announce-<?php echo $ssId; ?>"
                                   title="Post an announcement">
                                    <i class="bi bi-megaphone me-1"></i>Announce
                                </a>
                            </div>
                        </div>

                        <!-- Manage button -->
                        <a class="btn btn-brand-primary btn-sm mt-auto"
                           href="lms_subject?section_subject_id=<?php echo $ssId; ?>"
                           id="open-subject-<?php echo $ssId; ?>">
                            Manage Subject <i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>
                        </a>

                    </div><!-- /card-body -->
                </article>
            </div>
            <?php endforeach; ?>
        </div><!-- /row subjects -->
    </div><!-- /col left -->

    <!-- ── RIGHT: Pending submissions + enrolled students ─────── -->
    <div class="col-12 col-xl-4">

        <!-- Pending Submissions Panel -->
        <div class="card shadow-sm border-0 card-premium mb-4" id="panel-pending-submissions">
            <div class="card-header card-header-premium py-3 px-4 d-flex justify-content-between align-items-center gap-2">
                <h2 class="card-title h6 fw-bold mb-0 text-navy-alt">
                    <i class="bi bi-inbox-fill me-2 text-warning"></i>Pending Submissions
                </h2>
                <?php if ($totalPending > 0): ?>
                    <span class="badge bg-warning text-dark rounded-pill" style="font-size:.65rem;">
                        <?php echo (int)$totalPending; ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <?php if (!empty($recentPendingList)): ?>
                    <ul class="list-group list-group-flush" id="pending-submissions-list">
                        <?php foreach ($recentPendingList as $sub):
                            $submittedAgo = '';
                            if (!empty($sub['submitted_at'])) {
                                $diff = time() - strtotime($sub['submitted_at']);
                                if ($diff < 3600)      $submittedAgo = round($diff / 60) . 'm ago';
                                elseif ($diff < 86400) $submittedAgo = round($diff / 3600) . 'h ago';
                                else                   $submittedAgo = round($diff / 86400) . 'd ago';
                            }
                            $typeLabel = $sub['assignment_type'] === 'activity' ? 'Activity' : 'Assignment';
                        ?>
                            <li class="list-group-item px-4 py-3 d-flex justify-content-between align-items-start gap-2 border-0 border-bottom">
                                <div style="min-width:0;">
                                    <div class="fw-semibold text-dark" style="font-size:.83rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                        <?php echo htmlspecialchars($sub['assignment_title']); ?>
                                    </div>
                                    <div class="text-muted" style="font-size:.74rem;">
                                        <span class="badge bg-secondary-subtle text-muted border me-1" style="font-size:.64rem;"><?php echo htmlspecialchars($typeLabel); ?></span>
                                        <span class="fw-semibold" style="color:var(--brand-primary);"><?php echo htmlspecialchars($sub['subject_code']); ?></span>
                                        · <?php echo htmlspecialchars($sub['student_name']); ?>
                                    </div>
                                </div>
                                <div class="text-end flex-shrink-0">
                                    <div class="text-muted" style="font-size:.7rem;white-space:nowrap;"><?php echo htmlspecialchars($submittedAgo); ?></div>
                                    <a href="lms_submissions?section_subject_id=<?php echo (int)$sub['section_subject_id']; ?>&submission_id=<?php echo (int)$sub['submission_id']; ?>"
                                       class="btn btn-xs lms-qa-btn mt-1"
                                       id="grade-sub-<?php echo (int)$sub['submission_id']; ?>"
                                       title="Grade this submission">
                                        Grade
                                    </a>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ($totalPending > 15): ?>
                        <div class="px-4 py-3 border-top text-center">
                            <a href="lms_submissions" class="small text-brand-primary fw-semibold" id="view-all-submissions-link">
                                View all <?php echo (int)$totalPending; ?> pending →
                            </a>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="text-center py-4 text-muted px-4" id="no-pending-state">
                        <i class="bi bi-check2-circle" style="font-size:2rem;opacity:.4;display:block;margin-bottom:.5rem;"></i>
                        <div class="fw-semibold" style="font-size:.85rem;">All caught up!</div>
                        <div class="small">No ungraded submissions at the moment.</div>
                    </div>
                <?php endif; ?>
            </div>
        </div><!-- /pending panel -->

        <!-- Enrolled Students Per Subject Panel -->
        <div class="card shadow-sm border-0 card-premium" id="panel-students-per-subject">
            <div class="card-header card-header-premium py-3 px-4">
                <h2 class="card-title h6 fw-bold mb-0 text-navy-alt">
                    <i class="bi bi-people-fill me-2 text-brand-primary"></i>Students per Subject
                </h2>
            </div>
            <div class="card-body p-0">
                <?php if (!empty($subjects)): ?>
                    <ul class="list-group list-group-flush" id="students-per-subject-list">
                        <?php foreach ($subjects as $subj):
                            $ssId     = (int)$subj['section_subject_id'];
                            $enrolled = (int)$subj['enrolled_count'];
                            $prog     = $progressBySubject[$ssId]['percent'] ?? 0;
                            $pending  = (int)($pendingBySubject[$ssId]['pending_count'] ?? 0);
                            $progColor = $prog >= 75 ? '#12b886' : ($prog >= 40 ? 'var(--brand-primary)' : '#f59f00');
                        ?>
                            <li class="list-group-item px-4 py-3 border-0 border-bottom" id="student-row-<?php echo $ssId; ?>">
                                <div class="d-flex justify-content-between align-items-center gap-2 mb-1">
                                    <div style="min-width:0;">
                                        <div class="fw-semibold" style="font-size:.82rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#1e293b;">
                                            <?php echo htmlspecialchars($subj['subject_code']); ?>
                                        </div>
                                        <div class="text-muted" style="font-size:.73rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                            <?php echo htmlspecialchars($subj['subject_name']); ?>
                                        </div>
                                    </div>
                                    <div class="text-end flex-shrink-0 d-flex align-items-center gap-2">
                                        <?php if ($pending > 0): ?>
                                            <span class="badge bg-warning text-dark" style="font-size:.6rem;" title="<?php echo $pending; ?> ungraded submission(s)">
                                                <?php echo $pending; ?> ⏳
                                            </span>
                                        <?php endif; ?>
                                        <div>
                                            <span class="fw-bold" style="font-size:.9rem;color:var(--brand-primary);"><?php echo $enrolled; ?></span>
                                            <span class="text-muted" style="font-size:.7rem;"> students</span>
                                        </div>
                                    </div>
                                </div>
                                <!-- Mini progress bar -->
                                <div class="progress" role="progressbar"
                                     aria-label="<?php echo htmlspecialchars($subj['subject_code']); ?> progress"
                                     aria-valuenow="<?php echo $prog; ?>"
                                     aria-valuemin="0" aria-valuemax="100"
                                     style="height:4px;border-radius:99px;background:#e2e8f0;">
                                    <div style="width:<?php echo $prog; ?>%;background:<?php echo $progColor; ?>;height:100%;border-radius:99px;transition:width .5s;"></div>
                                </div>
                                <div class="d-flex justify-content-between mt-1">
                                    <span class="text-muted" style="font-size:.68rem;">Avg. progress</span>
                                    <span style="font-size:.68rem;font-weight:700;color:<?php echo $progColor; ?>;"><?php echo $prog; ?>%</span>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            <?php if (!empty($subjects)): ?>
                <div class="card-footer bg-transparent border-top py-2 text-center">
                    <a href="lms_progress" class="small text-brand-primary fw-semibold" id="view-full-progress-link">
                        <i class="bi bi-graph-up me-1"></i>View detailed progress →
                    </a>
                </div>
            <?php endif; ?>
        </div><!-- /students per subject panel -->

    </div><!-- /col right -->
</div><!-- /row -->

<?php endif; // subjects not empty ?>

<style>
/* ── Quick-action micro-buttons ───────────────────────────── */
.btn-xs {
    padding: .22rem .55rem;
    font-size: .72rem;
    line-height: 1.4;
    border-radius: .35rem;
}
.lms-qa-btn {
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    color: #334155;
    font-weight: 600;
    transition: background .15s, color .15s, border-color .15s;
}
.lms-qa-btn:hover,
.lms-qa-btn:focus {
    background: var(--brand-primary, #008080);
    border-color: var(--brand-primary, #008080);
    color: #fff;
}

/* ── Card tweaks ──────────────────────────────────────────── */
.card-header-premium {
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    padding: .75rem 1rem;
}
.list-group-item:last-child {
    border-bottom: none !important;
}

/* ── LMS nav bar link hover ───────────────────────────────── */
nav .btn.text-white:hover {
    background: rgba(255,255,255,.15) !important;
    opacity: 1 !important;
}
</style>

<?php require_once '../includes/footer.php'; ?>
