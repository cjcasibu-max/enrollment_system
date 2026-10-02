<?php
/**
 * Subject Management Hub — Teacher LMS (Phase 2 / My Subjects)
 *
 * Access control:
 *   requireLmsTeacherAccess()       → role = 'teacher' + LMS allowlist
 *   requireTeacherSubjectAccess()   → verifies this teacher owns the
 *                                     requested section_subject_id; redirects
 *                                     to teacher/lms with a flash error if not.
 *
 * Data source:
 *   The subject row is fetched through the same ownership query used by
 *   fetchLmsTeacherSubjects() on the dashboard — no separate source of truth.
 *
 * Navigation:
 *   Tab-based, ?section= query param, mirrors student/lms_course.php pattern.
 *   Overview is the default section; future phases plug in their content
 *   under the matching $activeSection branch.
 */

require_once '../includes/lms_access.php';

$teacher         = requireLmsTeacherAccess();
$userId          = $teacher['user_id'];
$sectionSubjectId = (int)filter_input(INPUT_GET, 'section_subject_id', FILTER_VALIDATE_INT);

// Re-verify ownership (backend guard — cannot be bypassed via URL manipulation).
$subject = requireTeacherSubjectAccess($pdo, $userId, $sectionSubjectId);

// ── Section tab map (matches the LMS architecture exactly) ────────────────────
// Phases 3–8 each own one of these tabs.  Overview is Phase 2.
$subjectSections = [
    'overview'      => 'Overview',
    'modules'       => 'Lessons / Modules',
    'materials'     => 'Learning Materials',
    'assignments'   => 'Assignments',
    'quizzes'       => 'Quizzes',
    'submissions'   => 'Submissions',
    'grades'        => 'Grades',
    'announcements' => 'Announcements',
    'progress'      => 'Student Progress',
];
$activeSection = (string)($_GET['section'] ?? 'overview');
if (!isset($subjectSections[$activeSection])) {
    $activeSection = 'overview';
}

// ── Overview: live stats for this specific subject ────────────────────────────
$overviewStats = null;
if ($activeSection === 'overview') {
    // Published content counts
    $statsStmt = $pdo->prepare(
        "SELECT
            -- modules & lessons
            (SELECT COUNT(*) FROM lms_modules    WHERE section_subject_id = :ss1 AND is_published = 1) AS module_count,
            (SELECT COUNT(*) FROM lms_lessons l
             JOIN lms_modules m ON m.id = l.module_id
             WHERE m.section_subject_id = :ss2 AND m.is_published = 1 AND l.is_published = 1)          AS lesson_count,
            -- materials
            (SELECT COUNT(*) FROM lms_materials  WHERE section_subject_id = :ss3 AND is_available = 1)  AS material_count,
            -- assignments & activities
            (SELECT COUNT(*) FROM lms_assignments WHERE section_subject_id = :ss4 AND is_published = 1
             AND assignment_type = 'assignment')                                                         AS assignment_count,
            (SELECT COUNT(*) FROM lms_assignments WHERE section_subject_id = :ss5 AND is_published = 1
             AND assignment_type = 'activity')                                                           AS activity_count,
            -- quizzes
            (SELECT COUNT(*) FROM lms_quizzes    WHERE section_subject_id = :ss6 AND is_published = 1)  AS quiz_count,
            -- announcements
            (SELECT COUNT(*) FROM lms_announcements WHERE section_subject_id = :ss7 AND is_published = 1) AS announcement_count,
            -- pending (ungraded) submissions
            (SELECT COUNT(*) FROM lms_assignment_submissions subm
             JOIN lms_assignments a ON a.id = subm.assignment_id
             WHERE a.section_subject_id = :ss8
               AND subm.graded_at IS NULL AND subm.score IS NULL)                                        AS pending_submissions"
    );
    $statsStmt->execute([
        'ss1' => $sectionSubjectId, 'ss2' => $sectionSubjectId,
        'ss3' => $sectionSubjectId, 'ss4' => $sectionSubjectId,
        'ss5' => $sectionSubjectId, 'ss6' => $sectionSubjectId,
        'ss7' => $sectionSubjectId, 'ss8' => $sectionSubjectId,
    ]);
    $overviewStats = $statsStmt->fetch(PDO::FETCH_ASSOC);

    // Enrolled students for this section
    $enrolledStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM enrollments WHERE section_id = :section_id AND status = 'enrolled'"
    );
    $enrolledStmt->execute(['section_id' => (int)$subject['section_id']]);
    $overviewStats['enrolled_count'] = (int)$enrolledStmt->fetchColumn();

    // Average student progress (lesson views + material views + submissions + quiz attempts)
    // Uses the same four-union approach as the dashboard.
    $progressStmt = $pdo->prepare(
        "SELECT
            SUM(total_items) AS grand_total,
            SUM(done_items)  AS grand_done
         FROM (
             SELECT COUNT(l.id) AS total_items,
                    COALESCE(SUM(lp.id IS NOT NULL), 0) AS done_items
             FROM enrollments e
             JOIN lms_modules m  ON m.section_subject_id = :p_ss1 AND m.is_published = 1
             JOIN lms_lessons l  ON l.module_id = m.id AND l.is_published = 1
             LEFT JOIN lms_lesson_progress lp ON lp.lesson_id = l.id AND lp.student_id = e.student_id
             WHERE e.section_id = :p_sec1 AND e.status = 'enrolled'

             UNION ALL

             SELECT COUNT(mat.id) AS total_items,
                    COALESCE(SUM(mv.id IS NOT NULL), 0) AS done_items
             FROM enrollments e2
             JOIN lms_materials mat ON mat.section_subject_id = :p_ss2 AND mat.is_available = 1
             LEFT JOIN lms_material_views mv ON mv.material_id = mat.id AND mv.student_id = e2.student_id
             WHERE e2.section_id = :p_sec2 AND e2.status = 'enrolled'

             UNION ALL

             SELECT COUNT(a.id) AS total_items,
                    COALESCE(SUM(subm.id IS NOT NULL), 0) AS done_items
             FROM enrollments e3
             JOIN lms_assignments a ON a.section_subject_id = :p_ss3 AND a.is_published = 1
             LEFT JOIN lms_assignment_submissions subm ON subm.assignment_id = a.id AND subm.student_id = e3.student_id
             WHERE e3.section_id = :p_sec3 AND e3.status = 'enrolled'

             UNION ALL

             SELECT COUNT(q.id) AS total_items,
                    COALESCE(SUM(EXISTS(
                        SELECT 1 FROM lms_quiz_attempts qa
                        WHERE qa.quiz_id = q.id AND qa.student_id = e4.student_id
                          AND qa.status IN ('submitted','interrupted','timed_out')
                    )), 0) AS done_items
             FROM enrollments e4
             JOIN lms_quizzes q ON q.section_subject_id = :p_ss4 AND q.is_published = 1
             WHERE e4.section_id = :p_sec4 AND e4.status = 'enrolled'
         ) combined"
    );
    $progressStmt->execute([
        'p_ss1' => $sectionSubjectId, 'p_sec1' => (int)$subject['section_id'],
        'p_ss2' => $sectionSubjectId, 'p_sec2' => (int)$subject['section_id'],
        'p_ss3' => $sectionSubjectId, 'p_sec3' => (int)$subject['section_id'],
        'p_ss4' => $sectionSubjectId, 'p_sec4' => (int)$subject['section_id'],
    ]);
    $progressRow = $progressStmt->fetch(PDO::FETCH_ASSOC);
    $grandTotal  = (int)($progressRow['grand_total'] ?? 0);
    $grandDone   = (int)($progressRow['grand_done']  ?? 0);
    $overviewStats['avg_progress_percent'] = $grandTotal > 0
        ? (int)round(($grandDone / $grandTotal) * 100)
        : 0;
    $overviewStats['progress_done']  = $grandDone;
    $overviewStats['progress_total'] = $grandTotal;
}

// ── Modules/Lessons tab: fetch all modules + lessons for this subject ─────────
$modulesData = []; // [ module_id => [ ...module fields, 'lessons' => [...] ] ]
if ($activeSection === 'modules') {
    $modStmt = $pdo->prepare(
        "SELECT m.id, m.title, m.description, m.display_order, m.is_published,
                l.id AS lesson_id, l.title AS lesson_title, l.description AS lesson_description,
                l.content_type, l.content_body, l.content_path, l.display_order AS lesson_order,
                l.is_published AS lesson_published
         FROM lms_modules m
         LEFT JOIN lms_lessons l ON l.module_id = m.id
         WHERE m.section_subject_id = :ss
         ORDER BY m.display_order ASC, m.id ASC, l.display_order ASC, l.id ASC"
    );
    $modStmt->execute(['ss' => $sectionSubjectId]);
    foreach ($modStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $mid = (int)$row['id'];
        if (!isset($modulesData[$mid])) {
            $modulesData[$mid] = [
                'id'          => $mid,
                'title'       => $row['title'],
                'description' => $row['description'],
                'display_order' => (int)$row['display_order'],
                'is_published'  => (int)$row['is_published'],
                'lessons'     => [],
            ];
        }
        if ($row['lesson_id'] !== null) {
            $modulesData[$mid]['lessons'][] = [
                'id'           => (int)$row['lesson_id'],
                'title'        => $row['lesson_title'],
                'description'  => $row['lesson_description'],
                'content_type' => $row['content_type'],
                'content_body' => $row['content_body'],
                'content_path' => $row['content_path'],
                'display_order'=> (int)$row['lesson_order'],
                'is_published' => (int)$row['lesson_published'],
            ];
        }
    }
}

// ── Schedule helpers ──────────────────────────────────────────────────────────
$scheduleText = '';
if (!empty($subject['day_of_week'])) {
    $scheduleText .= $subject['day_of_week'] . ' ';
}
if (!empty($subject['start_time'])) {
    $scheduleText .= date('g:i A', strtotime($subject['start_time']));
}
if (!empty($subject['start_time']) && !empty($subject['end_time'])) {
    $scheduleText .= ' – ' . date('g:i A', strtotime($subject['end_time']));
}
$scheduleText = trim($scheduleText) ?: 'Not scheduled';

$termLabel = trim(($subject['school_year'] ?? '') . ' ' . ($subject['semester'] ?? '')) ?: 'Current term';
$roleLabel = !empty($subject['is_named_instructor']) ? 'Subject Instructor' : 'Section Lead';

$page_title = htmlspecialchars($subject['subject_code']) . ' — ' . $subjectSections[$activeSection];
require_once '../includes/header.php';

$lmsActiveTab = 'subject_hub';
require_once __DIR__ . '/lms_navbar.php';
?>

<!-- ============================================================
     Breadcrumb & subject header
     ============================================================ -->
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb mb-0" style="font-size:.82rem;">
        <li class="breadcrumb-item">
            <a href="lms" class="text-decoration-none text-brand-primary">
                <i class="bi bi-journal-bookmark-fill me-1"></i>Instructor Dashboard
            </a>
        </li>
        <li class="breadcrumb-item active" aria-current="page">
            <?php echo htmlspecialchars($subject['subject_code']); ?> — <?php echo htmlspecialchars($subject['subject_name']); ?>
        </li>
    </ol>
</nav>

<div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-4">
    <div>
        <div class="text-uppercase small fw-bold text-brand-primary" style="letter-spacing:.06em;">
            <?php echo htmlspecialchars($subject['subject_code']); ?>
            <span class="ms-2 badge bg-secondary-subtle text-muted border" style="font-size:.65rem;text-transform:none;">
                <?php echo htmlspecialchars($roleLabel); ?>
            </span>
        </div>
        <h1 class="h3 fw-bold text-navy-alt mt-1 mb-1">
            <?php echo htmlspecialchars($subject['subject_name']); ?>
        </h1>
        <p class="text-muted mb-0" style="font-size:.88rem;">
            <?php echo htmlspecialchars($subject['section_name'] ?: 'Confirmed section'); ?>
            <?php if (!empty($subject['year_level'])): ?> · <?php echo htmlspecialchars($subject['year_level']); ?><?php endif; ?>
            · <?php echo htmlspecialchars($termLabel); ?>
        </p>
    </div>
    <!-- Quick action buttons — each scoped to this section_subject_id -->
    <div class="d-flex align-items-center flex-wrap" style="gap: 8px;" role="toolbar" aria-label="Subject quick actions">
        <a href="lms_subject?section_subject_id=<?php echo $sectionSubjectId; ?>&section=modules"
           class="btn btn-sm btn-outline-secondary" id="qa-btn-modules">
            <i class="bi bi-layers me-1"></i>Manage Lessons
        </a>
        <a href="lms_subject?section_subject_id=<?php echo $sectionSubjectId; ?>&section=materials"
           class="btn btn-sm btn-outline-secondary" id="qa-btn-materials">
            <i class="bi bi-plus-circle me-1"></i>Add Material
        </a>
        <a href="lms_subject?section_subject_id=<?php echo $sectionSubjectId; ?>&section=assignments"
           class="btn btn-sm btn-outline-secondary" id="qa-btn-assignment">
            <i class="bi bi-pencil me-1"></i>New Assignment
        </a>
        <a href="lms_subject?section_subject_id=<?php echo $sectionSubjectId; ?>&section=quizzes"
           class="btn btn-sm btn-outline-secondary" id="qa-btn-quiz">
            <i class="bi bi-patch-question me-1"></i>New Quiz
        </a>
        <a href="lms_subject?section_subject_id=<?php echo $sectionSubjectId; ?>&section=announcements"
           class="btn btn-sm btn-brand-primary" id="qa-btn-announce">
            <i class="bi bi-megaphone me-1"></i>Post Announcement
        </a>
    </div>
</div>

<!-- ============================================================
     Section tab navigation (mirrors student/lms_course.php pattern)
     ============================================================ -->
<nav class="nav nav-tabs flex-nowrap overflow-auto mb-4" aria-label="Subject management sections" id="subject-tab-nav">
    <?php foreach ($subjectSections as $sectionKey => $sectionLabel): ?>
        <a class="nav-link text-nowrap <?php echo $activeSection === $sectionKey ? 'active fw-semibold' : ''; ?>"
           href="lms_subject?section_subject_id=<?php echo $sectionSubjectId; ?>&section=<?php echo htmlspecialchars($sectionKey); ?>"
           <?php echo $activeSection === $sectionKey ? 'aria-current="page"' : ''; ?>
           id="tab-<?php echo htmlspecialchars($sectionKey); ?>">
            <?php echo htmlspecialchars($sectionLabel); ?>
            <?php if ($sectionKey === 'submissions' && !empty($overviewStats['pending_submissions']) && $activeSection !== 'submissions'): ?>
                <span class="badge bg-warning text-dark ms-1 rounded-pill" style="font-size:.6rem;">
                    <?php echo (int)$overviewStats['pending_submissions']; ?>
                </span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</nav>

<!-- ============================================================
     Section content
     ============================================================ -->
<section class="card card-premium shadow-sm border-0" aria-labelledby="section-heading">
    <div class="card-body p-4">
        <h2 class="h5 fw-bold text-navy-alt mb-4" id="section-heading">
            <?php echo htmlspecialchars($subjectSections[$activeSection]); ?>
        </h2>

        <?php if ($activeSection === 'overview'): ?>
        <!-- ── OVERVIEW ──────────────────────────────────────────── -->

        <!-- Subject details -->
        <div class="row g-4 mb-4">
            <div class="col-12 col-lg-6">
                <div class="fw-semibold text-muted text-uppercase mb-2" style="font-size:.72rem;letter-spacing:.06em;">
                    Subject Details
                </div>
                <dl class="mb-0" style="display:grid;grid-template-columns:auto 1fr;gap:.35rem .75rem;font-size:.88rem;">
                    <dt class="fw-semibold text-muted">Section</dt>
                    <dd class="mb-0"><?php echo htmlspecialchars($subject['section_name'] ?: '—'); ?>
                        <?php if (!empty($subject['year_level'])): ?>
                            <span class="text-muted">· <?php echo htmlspecialchars($subject['year_level']); ?></span>
                        <?php endif; ?>
                    </dd>
                    <dt class="fw-semibold text-muted">Term</dt>
                    <dd class="mb-0"><?php echo htmlspecialchars($termLabel); ?></dd>
                    <dt class="fw-semibold text-muted">Units</dt>
                    <dd class="mb-0"><?php echo htmlspecialchars((string)$subject['units']); ?></dd>
                    <dt class="fw-semibold text-muted">Schedule</dt>
                    <dd class="mb-0"><?php echo htmlspecialchars($scheduleText); ?></dd>
                    <dt class="fw-semibold text-muted">Room</dt>
                    <dd class="mb-0"><?php echo htmlspecialchars($subject['room'] ?: 'Not assigned'); ?></dd>
                    <dt class="fw-semibold text-muted">Role</dt>
                    <dd class="mb-0">
                        <span class="badge <?php echo !empty($subject['is_named_instructor'])
                            ? 'bg-info-subtle text-info border border-info-subtle'
                            : 'bg-primary-subtle text-primary border border-primary-subtle'; ?>"
                              style="font-size:.68rem;">
                            <?php echo htmlspecialchars($roleLabel); ?>
                        </span>
                    </dd>
                    <dt class="fw-semibold text-muted">Program</dt>
                    <dd class="mb-0"><?php echo htmlspecialchars($subject['program'] ?: '—'); ?></dd>
                </dl>
            </div>

            <!-- Live stat cards -->
            <div class="col-12 col-lg-6">
                <div class="fw-semibold text-muted text-uppercase mb-2" style="font-size:.72rem;letter-spacing:.06em;">
                    Live Statistics
                </div>
                <div class="row g-2">
                    <?php
                    $statCards = [
                        ['icon' => 'bi-people-fill',         'label' => 'Enrolled Students',   'value' => $overviewStats['enrolled_count'],    'color' => 'var(--brand-primary, #008080)', 'rgba' => '0, 128, 128'],
                        ['icon' => 'bi-layers-fill',         'label' => 'Published Modules',    'value' => $overviewStats['module_count'],       'color' => '#6366f1',                       'rgba' => '99, 102, 241'],
                        ['icon' => 'bi-file-earmark-text',   'label' => 'Learning Materials',   'value' => $overviewStats['material_count'],     'color' => '#0891b2',                       'rgba' => '8, 145, 178'],
                        ['icon' => 'bi-pencil-fill',         'label' => 'Assignments',          'value' => $overviewStats['assignment_count'],   'color' => '#d97706',                       'rgba' => '217, 119, 6'],
                        ['icon' => 'bi-patch-question-fill', 'label' => 'Quizzes',              'value' => $overviewStats['quiz_count'],          'color' => '#7c3aed',                       'rgba' => '124, 58, 237'],
                        ['icon' => 'bi-inbox-fill',          'label' => 'Pending Submissions',  'value' => $overviewStats['pending_submissions'], 
                         'color' => (int)$overviewStats['pending_submissions'] > 0 ? '#d97706' : '#10b981',
                         'rgba'  => (int)$overviewStats['pending_submissions'] > 0 ? '217, 119, 6' : '16, 185, 129'],
                    ];
                    foreach ($statCards as $sc):
                    ?>
                    <div class="col-6 col-sm-4">
                        <div class="p-3 rounded-3 border h-100 text-center" style="background:#f8fafc;border-color:#e2e8f0 !important;">
                            <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-2"
                                 style="width:38px;height:38px;background:rgba(<?php echo $sc['rgba']; ?>, 0.12);color:<?php echo $sc['color']; ?>;font-size:1.15rem;">
                                <i class="bi <?php echo $sc['icon']; ?>"></i>
                            </div>
                            <div class="fw-bold lh-1 mb-1 text-navy-alt" style="font-size:1.25rem;">
                                <?php echo (int)$sc['value']; ?>
                            </div>
                            <div class="text-muted fw-semibold text-uppercase" style="font-size:.68rem;letter-spacing:.4px;">
                                <?php echo htmlspecialchars($sc['label']); ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Class progress bar -->
        <?php
        $prog      = $overviewStats['avg_progress_percent'];
        $progDone  = $overviewStats['progress_done'];
        $progTotal = $overviewStats['progress_total'];
        $progColor = $prog >= 75 ? '#12b886' : ($prog >= 40 ? 'var(--brand-primary)' : '#f59f00');
        ?>
        <div class="border-top pt-3 mt-2">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="fw-semibold" style="font-size:.85rem;">Average Class Progress</span>
                <span class="fw-bold" style="font-size:.88rem;color:<?php echo $progColor; ?>;"><?php echo $prog; ?>%</span>
            </div>
            <div class="progress mb-1" role="progressbar"
                 aria-label="Average class progress"
                 aria-valuenow="<?php echo $prog; ?>" aria-valuemin="0" aria-valuemax="100"
                 style="height:8px;border-radius:99px;background:#e2e8f0;">
                <div style="width:<?php echo $prog; ?>%;background:<?php echo $progColor; ?>;height:100%;border-radius:99px;transition:width .6s;"></div>
            </div>
            <div class="text-muted" style="font-size:.74rem;">
                <?php echo $progDone; ?> of <?php echo $progTotal; ?> learning activity completions across all enrolled students
            </div>
        </div>

        <!-- Quick navigation into management areas -->
        <div class="border-top pt-4 mt-4">
            <div class="fw-semibold text-muted text-uppercase mb-3" style="font-size:.72rem;letter-spacing:.06em;">
                Manage This Subject
            </div>
            <div class="row g-2" id="overview-management-grid">
                <?php
                $managementLinks = [
                    ['section' => 'modules',       'icon' => 'bi-layers-fill',          'label' => 'Lessons &amp; Modules',   'desc' => 'Create, edit, and publish lesson content'],
                    ['section' => 'materials',     'icon' => 'bi-file-earmark-text-fill','label' => 'Learning Materials',      'desc' => 'Upload reference files and handouts'],
                    ['section' => 'assignments',   'icon' => 'bi-pencil-square',         'label' => 'Assignments',             'desc' => 'Post and manage student assignments'],
                    ['section' => 'quizzes',       'icon' => 'bi-patch-question-fill',   'label' => 'Quizzes',                 'desc' => 'Create timed quizzes and view results'],
                    ['section' => 'submissions',   'icon' => 'bi-inbox-fill',            'label' => 'Submissions',             'desc' => 'Grade and give feedback on submissions', 'badge' => (int)$overviewStats['pending_submissions']],
                    ['section' => 'grades',        'icon' => 'bi-award-fill',            'label' => 'Grades',                  'desc' => 'View and enter course grades'],
                    ['section' => 'announcements', 'icon' => 'bi-megaphone-fill',        'label' => 'Announcements',           'desc' => 'Post notices and important reminders'],
                    ['section' => 'progress',      'icon' => 'bi-graph-up-arrow',        'label' => 'Student Progress',        'desc' => 'Monitor per-student completion rates'],
                ];
                foreach ($managementLinks as $ml):
                    $href = 'lms_subject?section_subject_id=' . $sectionSubjectId . '&section=' . $ml['section'];
                ?>
                <div class="col-12 col-sm-6 col-xl-3">
                    <a href="<?php echo $href; ?>"
                       class="d-flex align-items-start gap-3 p-3 rounded-3 border text-decoration-none h-100 lms-manage-card"
                       id="mgmt-<?php echo htmlspecialchars($ml['section']); ?>">
                        <span class="flex-shrink-0" style="font-size:1.3rem;color:var(--brand-primary);margin-top:.1rem;">
                            <i class="bi <?php echo $ml['icon']; ?>"></i>
                        </span>
                        <span>
                            <span class="d-block fw-semibold text-dark" style="font-size:.87rem;">
                                <?php echo $ml['label']; ?>
                                <?php if (!empty($ml['badge'])): ?>
                                    <span class="badge bg-warning text-dark ms-1 rounded-pill" style="font-size:.6rem;">
                                        <?php echo (int)$ml['badge']; ?>
                                    </span>
                                <?php endif; ?>
                            </span>
                            <span class="text-muted" style="font-size:.75rem;"><?php echo $ml['desc']; ?></span>
                        </span>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php elseif ($activeSection === 'modules'): ?>
        <!-- ── LESSONS / MODULES ──────────────────────────────────── -->

        <!-- Toolbar -->
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
            <p class="text-muted small mb-0">
                Modules and their lessons appear here. Students see published content in the order you set.
                <strong>Drag</strong> rows to reorder.
            </p>
            <button class="btn btn-brand-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modal-create-module"
                    id="btn-new-module">
                <i class="bi bi-plus-lg me-1"></i>New Module
            </button>
        </div>

        <?php if (empty($modulesData)): ?>
        <div class="text-center py-5" id="no-modules-state">
            <i class="bi bi-layers" style="font-size:2.8rem;opacity:.3;color:var(--brand-primary);"></i>
            <p class="mt-3 mb-1 fw-semibold" style="font-size:.9rem;">No modules yet</p>
            <p class="text-muted small mb-3">Create your first module, then add lessons inside it.</p>
            <button class="btn btn-brand-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modal-create-module">
                <i class="bi bi-plus-lg me-1"></i>Create First Module
            </button>
        </div>
        <?php else: ?>

        <!-- Module list (drag-sortable) -->
        <div id="module-list" data-action-url="../actions/lms_module_lesson_actions">
            <?php foreach ($modulesData as $mod): ?>
            <div class="module-block border rounded mb-3 overflow-hidden"
                 id="module-block-<?php echo $mod['id']; ?>"
                 data-module-id="<?php echo $mod['id']; ?>">

                <!-- Module header row -->
                <div class="module-header d-flex align-items-center gap-2 px-4 py-3"
                     style="background:<?php echo $mod['is_published'] ? '#f0faf8' : '#f8fafc'; ?>;border-bottom:1px solid #e2e8f0;">
                    <!-- Drag handle -->
                    <span class="module-drag-handle text-muted me-1" title="Drag to reorder" style="cursor:grab;font-size:1rem;">
                        <i class="bi bi-grip-vertical"></i>
                    </span>
                    <!-- Published badge -->
                    <span class="badge <?php echo $mod['is_published'] ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-secondary-subtle text-secondary border'; ?>"
                          style="font-size:.65rem;">
                        <?php echo $mod['is_published'] ? 'Published' : 'Draft'; ?>
                    </span>
                    <!-- Title -->
                    <span class="fw-semibold flex-grow-1" style="font-size:.92rem;">
                        <?php echo htmlspecialchars($mod['title']); ?>
                    </span>
                    <!-- Lesson count -->
                    <span class="text-muted small me-2" style="font-size:.75rem;white-space:nowrap;">
                        <?php echo count($mod['lessons']); ?> lesson<?php echo count($mod['lessons']) !== 1 ? 's' : ''; ?>
                    </span>
                    <!-- Module actions -->
                    <div class="d-flex flex-shrink-0" style="gap: 6px;">
                        <button class="btn btn-xs btn-outline-secondary" title="Edit module"
                                onclick="openEditModule(<?php echo htmlspecialchars(json_encode($mod)); ?>)">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button class="btn btn-xs btn-outline-danger" title="Delete module"
                                onclick="confirmDeleteModule(<?php echo $mod['id']; ?>, '<?php echo htmlspecialchars(addslashes($mod['title'])); ?>')"
                                <?php echo !empty($mod['lessons']) ? 'data-has-lessons="1"' : ''; ?>>
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>

                <!-- Lesson list inside this module -->
                <div class="lesson-list px-4 py-3" id="lesson-list-<?php echo $mod['id']; ?>"
                     data-module-id="<?php echo $mod['id']; ?>">
                    <?php if (empty($mod['lessons'])): ?>
                    <p class="text-muted small mb-2 ps-4" style="font-size:.8rem;">No lessons yet in this module.</p>
                    <?php else: ?>
                    <?php foreach ($mod['lessons'] as $lesson):
                        $typeIcons = [
                            'text'          => 'bi-file-text',
                            'image'         => 'bi-image',
                            'pdf'           => 'bi-filetype-pdf',
                            'presentation'  => 'bi-file-slides',
                            'video'         => 'bi-play-btn',
                            'external_link' => 'bi-link-45deg',
                        ];
                        $typeIcon  = $typeIcons[$lesson['content_type']] ?? 'bi-file';
                        $typeLabel = ucwords(str_replace('_', ' ', $lesson['content_type']));
                    ?>
                    <div class="lesson-row d-flex align-items-center gap-2 py-2 border-bottom"
                         id="lesson-row-<?php echo $lesson['id']; ?>"
                         data-lesson-id="<?php echo $lesson['id']; ?>"
                         style="border-color:#f1f5f9 !important;">
                        <!-- Lesson drag handle -->
                        <span class="lesson-drag-handle text-muted" title="Drag to reorder"
                              style="cursor:grab;font-size:.85rem;opacity:.5;">
                            <i class="bi bi-grip-vertical"></i>
                        </span>
                        <!-- Type icon -->
                        <i class="bi <?php echo $typeIcon; ?> text-muted" title="<?php echo htmlspecialchars($typeLabel); ?>"
                           style="font-size:.9rem;width:1rem;text-align:center;"></i>
                        <!-- Title -->
                        <span class="flex-grow-1" style="font-size:.85rem;">
                            <?php echo htmlspecialchars($lesson['title']); ?>
                            <span class="text-muted ms-1" style="font-size:.73rem;">· <?php echo htmlspecialchars($typeLabel); ?></span>
                        </span>
                        <!-- Published badge -->
                        <span class="badge <?php echo $lesson['is_published'] ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-secondary-subtle text-secondary border'; ?>"
                              style="font-size:.6rem;">
                            <?php echo $lesson['is_published'] ? 'Published' : 'Draft'; ?>
                        </span>
                        <!-- Lesson actions -->
                        <div class="d-flex" style="gap: 6px;">
                            <button class="btn btn-xs btn-outline-secondary" title="Edit lesson"
                                    onclick="openEditLesson(<?php echo htmlspecialchars(json_encode($lesson)); ?>, <?php echo $mod['id']; ?>)">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="btn btn-xs btn-outline-danger" title="Delete lesson"
                                    onclick="confirmDeleteLesson(<?php echo $lesson['id']; ?>, '<?php echo htmlspecialchars(addslashes($lesson['title'])); ?>')">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>

                    <!-- Add lesson button (inside this module) -->
                    <div class="pt-2 pb-1">
                        <button class="btn btn-xs btn-outline-secondary"
                                onclick="openCreateLesson(<?php echo $mod['id']; ?>, '<?php echo htmlspecialchars(addslashes($mod['title'])); ?>')"
                                id="btn-add-lesson-<?php echo $mod['id']; ?>">
                            <i class="bi bi-plus-lg me-1"></i>Add Lesson
                        </button>
                    </div>
                </div><!-- /lesson-list -->

            </div><!-- /module-block -->
            <?php endforeach; ?>
        </div><!-- /module-list -->

        <?php endif; // modulesData not empty ?>

        <!-- ═══════════════════════════════════════════════════════════
             MODALS
             ═══════════════════════════════════════════════════════════ -->

        <!-- Create Module Modal -->
        <div class="modal fade" id="modal-create-module" tabindex="-1"
             aria-labelledby="modal-create-module-title" aria-hidden="true">
            <div class="modal-dialog">
                <form method="post" action="../actions/lms_module_lesson_actions" class="modal-content">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                    <input type="hidden" name="action" value="create_module">
                    <input type="hidden" name="section_subject_id" value="<?php echo $sectionSubjectId; ?>">
                    <div class="modal-header">
                        <h5 class="modal-title fw-bold" id="modal-create-module-title"><i class="bi bi-plus-lg me-2"></i>New Module</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="cm-title">Module Title <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="cm-title" name="title" maxlength="150" required autocomplete="off">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="cm-description">Description <span class="text-muted fw-normal">(optional)</span></label>
                            <textarea class="form-control" id="cm-description" name="description" rows="3"
                                      placeholder="Brief overview of what this module covers…"></textarea>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="cm-published" name="is_published" value="1">
                            <label class="form-check-label" for="cm-published">Publish immediately (students can see it)</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-brand-primary" id="btn-create-module-submit">
                            <i class="bi bi-check-lg me-1"></i>Create Module
                        </button>
                    </div>
                </form>
            </div>
        </div><!-- /modal-create-module -->

        <!-- Edit Module Modal -->
        <div class="modal fade" id="modal-edit-module" tabindex="-1"
             aria-labelledby="modal-edit-module-title" aria-hidden="true">
            <div class="modal-dialog">
                <form method="post" action="../actions/lms_module_lesson_actions" class="modal-content">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                    <input type="hidden" name="action" value="edit_module">
                    <input type="hidden" name="section_subject_id" value="<?php echo $sectionSubjectId; ?>">
                    <input type="hidden" name="module_id" id="em-module-id">
                    <div class="modal-header">
                        <h5 class="modal-title fw-bold" id="modal-edit-module-title"><i class="bi bi-pencil me-2"></i>Edit Module</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="em-title">Module Title <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="em-title" name="title" maxlength="150" required autocomplete="off">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="em-description">Description</label>
                            <textarea class="form-control" id="em-description" name="description" rows="3"></textarea>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="em-published" name="is_published" value="1">
                            <label class="form-check-label" for="em-published">Published (visible to students)</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-brand-primary"><i class="bi bi-check-lg me-1"></i>Save Changes</button>
                    </div>
                </form>
            </div>
        </div><!-- /modal-edit-module -->

        <!-- Delete Module Form (hidden; submitted by JS) -->
        <form method="post" action="../actions/lms_module_lesson_actions" id="form-delete-module" class="d-none">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
            <input type="hidden" name="action" value="delete_module">
            <input type="hidden" name="section_subject_id" value="<?php echo $sectionSubjectId; ?>">
            <input type="hidden" name="module_id" id="del-module-id">
        </form>

        <!-- Create Lesson Modal -->
        <div class="modal fade" id="modal-create-lesson" tabindex="-1"
             aria-labelledby="modal-create-lesson-title" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <form method="post" action="../actions/lms_module_lesson_actions"
                      enctype="multipart/form-data" class="modal-content" id="form-create-lesson">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                    <input type="hidden" name="action" value="create_lesson">
                    <input type="hidden" name="section_subject_id" value="<?php echo $sectionSubjectId; ?>">
                    <input type="hidden" name="module_id" id="cl-module-id">
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title fw-bold mb-0" id="modal-create-lesson-title"><i class="bi bi-plus-lg me-2"></i>Add Lesson</h5>
                            <div class="text-muted small" id="cl-module-name" style="font-size:.78rem;"></div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-12 col-md-8">
                                <label class="form-label fw-semibold" for="cl-title">Lesson Title <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="cl-title" name="title" maxlength="150" required autocomplete="off">
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label fw-semibold" for="cl-type">Content Type</label>
                                <select class="form-select" id="cl-type" name="content_type"
                                        onchange="toggleLessonFields('cl', this.value)">
                                    <option value="text">Text</option>
                                    <option value="image">Image</option>
                                    <option value="pdf">PDF Document</option>
                                    <option value="presentation">Presentation (PPT/PDF)</option>
                                    <option value="video">Video</option>
                                    <option value="external_link">External Link</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold" for="cl-description">Description / Instructions <span class="text-muted fw-normal">(optional)</span></label>
                                <textarea class="form-control" id="cl-description" name="description" rows="2"
                                          placeholder="Brief context for students…"></textarea>
                            </div>
                            <!-- Text content -->
                            <div class="col-12" id="cl-field-text">
                                <label class="form-label fw-semibold" for="cl-content-body">Lesson Text Content</label>
                                <textarea class="form-control" id="cl-content-body" name="content_body" rows="6"
                                          placeholder="Enter the lesson text here…"></textarea>
                            </div>
                            <!-- External link -->
                            <div class="col-12 d-none" id="cl-field-link">
                                <label class="form-label fw-semibold" for="cl-url">External URL <span class="text-danger">*</span></label>
                                <input type="url" class="form-control" id="cl-url" name="external_url"
                                       placeholder="https://…">
                                <div class="form-text">Must start with http:// or https://.</div>
                            </div>
                            <!-- File upload (image / pdf / presentation / video) -->
                            <div class="col-12 d-none" id="cl-field-file">
                                <label class="form-label fw-semibold" for="cl-file">Upload File <span class="text-danger">*</span></label>
                                <input type="file" class="form-control" id="cl-file" name="lesson_file">
                                <div class="form-text" id="cl-file-hint">Accepted types depend on content type selected.</div>
                            </div>
                        </div>
                        <hr class="my-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="cl-published" name="is_published" value="1">
                            <label class="form-check-label" for="cl-published">Publish immediately (students can see it)</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-brand-primary" id="btn-create-lesson-submit">
                            <i class="bi bi-check-lg me-1"></i>Add Lesson
                        </button>
                    </div>
                </form>
            </div>
        </div><!-- /modal-create-lesson -->

        <!-- Edit Lesson Modal -->
        <div class="modal fade" id="modal-edit-lesson" tabindex="-1"
             aria-labelledby="modal-edit-lesson-title" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <form method="post" action="../actions/lms_module_lesson_actions"
                      enctype="multipart/form-data" class="modal-content" id="form-edit-lesson">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                    <input type="hidden" name="action" value="edit_lesson">
                    <input type="hidden" name="section_subject_id" value="<?php echo $sectionSubjectId; ?>">
                    <input type="hidden" name="lesson_id" id="el-lesson-id">
                    <div class="modal-header">
                        <h5 class="modal-title fw-bold" id="modal-edit-lesson-title"><i class="bi bi-pencil me-2"></i>Edit Lesson</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-12 col-md-8">
                                <label class="form-label fw-semibold" for="el-title">Lesson Title <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="el-title" name="title" maxlength="150" required autocomplete="off">
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label fw-semibold" for="el-type">Content Type</label>
                                <select class="form-select" id="el-type" name="content_type"
                                        onchange="toggleLessonFields('el', this.value)">
                                    <option value="text">Text</option>
                                    <option value="image">Image</option>
                                    <option value="pdf">PDF Document</option>
                                    <option value="presentation">Presentation (PPT/PDF)</option>
                                    <option value="video">Video</option>
                                    <option value="external_link">External Link</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold" for="el-description">Description / Instructions</label>
                                <textarea class="form-control" id="el-description" name="description" rows="2"></textarea>
                            </div>
                            <!-- Text content -->
                            <div class="col-12" id="el-field-text">
                                <label class="form-label fw-semibold" for="el-content-body">Lesson Text Content</label>
                                <textarea class="form-control" id="el-content-body" name="content_body" rows="6"></textarea>
                            </div>
                            <!-- External link -->
                            <div class="col-12 d-none" id="el-field-link">
                                <label class="form-label fw-semibold" for="el-url">External URL</label>
                                <input type="url" class="form-control" id="el-url" name="external_url" placeholder="https://…">
                                <div class="form-text">Must start with http:// or https://.</div>
                            </div>
                            <!-- File upload -->
                            <div class="col-12 d-none" id="el-field-file">
                                <label class="form-label fw-semibold" for="el-file">Replace File <span class="text-muted fw-normal">(leave blank to keep current file)</span></label>
                                <input type="file" class="form-control" id="el-file" name="lesson_file">
                                <div class="form-text" id="el-file-hint">Accepted types depend on content type.</div>
                                <div class="mt-1 text-muted small" id="el-current-file"></div>
                            </div>
                        </div>
                        <hr class="my-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="el-published" name="is_published" value="1">
                            <label class="form-check-label" for="el-published">Published (visible to students)</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-brand-primary"><i class="bi bi-check-lg me-1"></i>Save Changes</button>
                    </div>
                </form>
            </div>
        </div><!-- /modal-edit-lesson -->

        <!-- Delete Lesson Form (hidden; submitted by JS) -->
        <form method="post" action="../actions/lms_module_lesson_actions" id="form-delete-lesson" class="d-none">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
            <input type="hidden" name="action" value="delete_lesson">
            <input type="hidden" name="section_subject_id" value="<?php echo $sectionSubjectId; ?>">
            <input type="hidden" name="lesson_id" id="del-lesson-id">
        </form>

        <!-- Reorder forms (hidden; submitted by JS via fetch) -->
        <input type="hidden" id="reorder-csrf" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
        <input type="hidden" id="reorder-ss-id" value="<?php echo $sectionSubjectId; ?>">

        <!-- Module/Lesson JS -->
        <script>
        /* ── Content-type field toggler ────────────────────────── */
        const fileHints = {
            image:        'Accepted: JPG, PNG, GIF, WebP (max 100 MB)',
            pdf:          'Accepted: PDF (max 100 MB)',
            presentation: 'Accepted: PPT, PPTX, or PDF (max 100 MB)',
            video:        'Accepted: MP4, WebM, OGV (max 100 MB)',
        };
        function toggleLessonFields(prefix, type) {
            const ids = ['text','link','file'];
            ids.forEach(id => document.getElementById(prefix + '-field-' + id)?.classList.add('d-none'));
            if (type === 'text')          document.getElementById(prefix + '-field-text')?.classList.remove('d-none');
            else if (type === 'external_link') document.getElementById(prefix + '-field-link')?.classList.remove('d-none');
            else {
                document.getElementById(prefix + '-field-file')?.classList.remove('d-none');
                const hint = document.getElementById(prefix + '-file-hint');
                if (hint) hint.textContent = fileHints[type] || 'Upload a file.';
            }
        }
        // Init on load for edit modal
        document.addEventListener('DOMContentLoaded', function() {
            toggleLessonFields('cl', 'text');
        });

        /* ── Open Create Lesson modal ──────────────────────────── */
        function openCreateLesson(moduleId, moduleName) {
            document.getElementById('cl-module-id').value = moduleId;
            document.getElementById('cl-module-name').textContent = 'in: ' + moduleName;
            // Reset form
            document.getElementById('form-create-lesson').reset();
            toggleLessonFields('cl', 'text');
            new bootstrap.Modal(document.getElementById('modal-create-lesson')).show();
        }

        /* ── Open Edit Module modal ────────────────────────────── */
        function openEditModule(mod) {
            document.getElementById('em-module-id').value  = mod.id;
            document.getElementById('em-title').value      = mod.title || '';
            document.getElementById('em-description').value= mod.description || '';
            document.getElementById('em-published').checked = !!parseInt(mod.is_published);
            new bootstrap.Modal(document.getElementById('modal-edit-module')).show();
        }

        /* ── Confirm + submit Delete Module ────────────────────── */
        function confirmDeleteModule(moduleId, title) {
            const hasLessons = document.getElementById('module-block-' + moduleId)?.querySelector('[data-has-lessons]');
            const msg = hasLessons
                ? 'Delete module "' + title + '" and ALL its lessons? This cannot be undone.'
                : 'Delete module "' + title + '"? This cannot be undone.';
            if (!confirm(msg)) return;
            document.getElementById('del-module-id').value = moduleId;
            document.getElementById('form-delete-module').submit();
        }

        /* ── Open Edit Lesson modal ────────────────────────────── */
        function openEditLesson(lesson, moduleId) {
            document.getElementById('el-lesson-id').value   = lesson.id;
            document.getElementById('el-title').value       = lesson.title || '';
            document.getElementById('el-description').value = lesson.description || '';
            document.getElementById('el-published').checked = !!parseInt(lesson.is_published);
            // Content type
            const selType = document.getElementById('el-type');
            selType.value = lesson.content_type || 'text';
            toggleLessonFields('el', selType.value);
            // Populate content
            document.getElementById('el-content-body').value = (lesson.content_type === 'text' ? lesson.content_body : '') || '';
            document.getElementById('el-url').value          = (lesson.content_type === 'external_link' ? lesson.content_path : '') || '';
            const curFile = document.getElementById('el-current-file');
            if (curFile) {
                if (lesson.content_path && lesson.content_type !== 'external_link') {
                    curFile.textContent = 'Current file: ' + lesson.content_path.split('/').pop();
                } else {
                    curFile.textContent = '';
                }
            }
            // Reset file input
            document.getElementById('el-file').value = '';
            new bootstrap.Modal(document.getElementById('modal-edit-lesson')).show();
        }

        /* ── Confirm + submit Delete Lesson ────────────────────── */
        function confirmDeleteLesson(lessonId, title) {
            if (!confirm('Delete lesson "' + title + '"? This cannot be undone.')) return;
            document.getElementById('del-lesson-id').value = lessonId;
            document.getElementById('form-delete-lesson').submit();
        }

        /* ── Drag-and-drop reordering (HTML5 native) ───────────── */
        const actionUrl    = document.getElementById('module-list')?.dataset.actionUrl || '';
        const csrfToken    = document.getElementById('reorder-csrf')?.value || '';
        const ssId         = document.getElementById('reorder-ss-id')?.value || '';

        function enableDragSort(listEl, itemSelector, dragHandleSelector, reorderAction, getIds) {
            if (!listEl) return;
            let draggingEl = null;
            listEl.querySelectorAll(itemSelector).forEach(item => {
                const handle = dragHandleSelector ? item.querySelector(dragHandleSelector) : item;
                if (!handle) return;
                handle.addEventListener('mousedown', () => { item.setAttribute('draggable', 'true'); });
                handle.addEventListener('mouseup',   () => { item.setAttribute('draggable', 'false'); });
                item.addEventListener('dragstart', e => {
                    draggingEl = item;
                    setTimeout(() => item.classList.add('opacity-50'), 0);
                    e.dataTransfer.effectAllowed = 'move';
                });
                item.addEventListener('dragend', () => {
                    item.classList.remove('opacity-50');
                    item.setAttribute('draggable', 'false');
                    draggingEl = null;
                    // Save new order
                    const ids = getIds(listEl);
                    const body = new URLSearchParams({ action: reorderAction, csrf_token: csrfToken, section_subject_id: ssId });
                    ids.forEach(id => body.append(reorderAction === 'reorder_modules' ? 'module_ids[]' : 'lesson_ids[]', id));
                    if (reorderAction === 'reorder_lessons') {
                        body.append('module_id', listEl.dataset.moduleId);
                    }
                    fetch(actionUrl, { method: 'POST', body }).catch(() => {});
                });
                item.addEventListener('dragover', e => {
                    e.preventDefault();
                    if (!draggingEl || draggingEl === item) return;
                    const rect = item.getBoundingClientRect();
                    const mid  = rect.top + rect.height / 2;
                    if (e.clientY < mid) listEl.insertBefore(draggingEl, item);
                    else listEl.insertBefore(draggingEl, item.nextSibling);
                });
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Module-level sorting
            enableDragSort(
                document.getElementById('module-list'),
                '.module-block',
                '.module-drag-handle',
                'reorder_modules',
                el => [...el.querySelectorAll('.module-block')].map(b => b.dataset.moduleId)
            );
            // Lesson-level sorting (per module)
            document.querySelectorAll('.lesson-list').forEach(ll => {
                enableDragSort(
                    ll,
                    '.lesson-row',
                    '.lesson-drag-handle',
                    'reorder_lessons',
                    el => [...el.querySelectorAll('.lesson-row')].map(r => r.dataset.lessonId)
                );
            });
        });
        </script>

        <?php elseif ($activeSection === 'materials'): ?>
        <!-- ── LEARNING MATERIALS — forwards to dedicated page ───── -->
        <?php
        // The Materials management view lives at teacher/lms_materials.php.
        // Redirect immediately so the tab click lands on the real page.
        header('Location: ' . resolveAppUrl('teacher/lms_materials?section_subject_id=' . $sectionSubjectId));
        exit;
        ?>

        <?php elseif ($activeSection === 'assignments'): ?>
        <!-- ── ASSIGNMENTS ────────────────────────────────────────── -->
        <div class="text-center py-5" id="assignments-hub-view">
            <i class="bi bi-pencil-square" style="font-size:2.8rem;opacity:.8;color:var(--brand-primary);"></i>
            <h3 class="h6 fw-semibold mt-3 mb-1">Assignments Management</h3>
            <p class="text-muted small mb-4">
                Create, edit, attach reference files, and grade student assignment submissions for this subject.
            </p>
            <div class="d-flex justify-content-center flex-wrap" style="gap: 8px;">
                <a href="lms_subject?section_subject_id=<?php echo $sectionSubjectId; ?>&section=overview"
                   class="btn btn-sm btn-outline-secondary">← Back to Overview</a>
                <a href="lms_assignments?section_subject_id=<?php echo $sectionSubjectId; ?>"
                   class="btn btn-sm btn-brand-primary">
                    <i class="bi bi-pencil-square me-1"></i>Open Assignments Manager
                </a>
            </div>
        </div>

        <?php elseif ($activeSection === 'quizzes'): ?>
        <!-- ── QUIZZES ────────────────────────────────────────────── -->
        <div class="text-center py-5" id="quizzes-hub-view">
            <i class="bi bi-patch-question" style="font-size:2.8rem;opacity:.8;color:var(--brand-primary);"></i>
            <h3 class="h6 fw-semibold mt-3 mb-1">Quizzes & Exams Management</h3>
            <p class="text-muted small mb-4">
                Build timed quizzes, add Multiple Choice, True/False, and Identification questions, and view student attempt results.
            </p>
            <div class="d-flex justify-content-center flex-wrap" style="gap: 8px;">
                <a href="lms_subject?section_subject_id=<?php echo $sectionSubjectId; ?>&section=overview"
                   class="btn btn-sm btn-outline-secondary">← Back to Overview</a>
                <a href="lms_quizzes?section_subject_id=<?php echo $sectionSubjectId; ?>"
                   class="btn btn-sm btn-brand-primary">
                    <i class="bi bi-patch-question me-1"></i>Open Quizzes Manager
                </a>
            </div>
        </div>

        <?php elseif ($activeSection === 'submissions'): ?>
        <!-- ── SUBMISSIONS ─────────────────────────────────────────── -->
        <div class="text-center py-5" id="submissions-hub-view">
            <i class="bi bi-inbox-fill" style="font-size:2.8rem;opacity:.8;color:var(--brand-primary);"></i>
            <h3 class="h6 fw-semibold mt-3 mb-1">Subject Coursework Submissions</h3>
            <p class="text-muted small mb-4">
                View, download, score, and provide written feedback on student assignment submissions and quiz attempts for this subject.
            </p>
            <div class="d-flex justify-content-center flex-wrap" style="gap: 8px;">
                <a href="lms_subject?section_subject_id=<?php echo $sectionSubjectId; ?>&section=overview"
                   class="btn btn-sm btn-outline-secondary">← Back to Overview</a>
                <a href="lms_submissions?section_subject_id=<?php echo $sectionSubjectId; ?>"
                   class="btn btn-sm btn-brand-primary">
                    <i class="bi bi-inbox-fill me-1"></i>Open Submissions Manager
                </a>
            </div>
        </div>

        <?php elseif ($activeSection === 'grades'): ?>
        <!-- ── GRADES ────────────────────────────────────────────── -->
        <div class="text-center py-5" id="grades-hub-view">
            <i class="bi bi-award" style="font-size:2.8rem;opacity:.8;color:var(--brand-primary);"></i>
            <h3 class="h6 fw-semibold mt-3 mb-1">Subject Grades &amp; Evaluations</h3>
            <p class="text-muted small mb-4">
                Record and edit Prelim, Midterm, and Final grades, auto-calculate overall marks, provide student feedback, and submit to the Registrar.
            </p>
            <div class="d-flex justify-content-center flex-wrap" style="gap: 8px;">
                <a href="lms_subject?section_subject_id=<?php echo $sectionSubjectId; ?>&section=overview"
                   class="btn btn-sm btn-outline-secondary">← Back to Overview</a>
                <a href="lms_grades?section_subject_id=<?php echo $sectionSubjectId; ?>"
                   class="btn btn-sm btn-brand-primary">
                    <i class="bi bi-award me-1"></i>Open Grades Manager
                </a>
            </div>
        </div>

        <?php elseif ($activeSection === 'announcements'): ?>
        <!-- ── ANNOUNCEMENTS ───────────────────────────────────────── -->
        <div class="text-center py-5" id="announcements-hub-view">
            <i class="bi bi-megaphone" style="font-size:2.8rem;opacity:.8;color:var(--brand-primary);"></i>
            <h3 class="h6 fw-semibold mt-3 mb-1">Subject Announcements</h3>
            <p class="text-muted small mb-4">
                Post important updates, reminders, and class guidelines strictly to students enrolled in this section.
            </p>
            <div class="d-flex justify-content-center flex-wrap" style="gap: 8px;">
                <a href="lms_subject?section_subject_id=<?php echo $sectionSubjectId; ?>&section=overview"
                   class="btn btn-sm btn-outline-secondary">← Back to Overview</a>
                <a href="lms_announcements?section_subject_id=<?php echo $sectionSubjectId; ?>"
                   class="btn btn-sm btn-brand-primary">
                    <i class="bi bi-megaphone me-1"></i>Open Announcements Manager
                </a>
            </div>
        </div>

        <?php elseif ($activeSection === 'progress'): ?>
        <!-- ── STUDENT PROGRESS ────────────────────────────────────── -->
        <div class="text-center py-5" id="progress-hub-view">
            <i class="bi bi-graph-up-arrow" style="font-size:2.8rem;opacity:.8;color:var(--brand-primary);"></i>
            <h3 class="h6 fw-semibold mt-3 mb-1">Subject Student Progress</h3>
            <p class="text-muted small mb-4">
                Monitor how each cadet is progressing through lessons, learning materials, assignments, quizzes, and modules.
            </p>
            <div class="d-flex justify-content-center flex-wrap" style="gap: 8px;">
                <a href="lms_subject?section_subject_id=<?php echo $sectionSubjectId; ?>&section=overview"
                   class="btn btn-sm btn-outline-secondary">← Back to Overview</a>
                <a href="lms_progress?section_subject_id=<?php echo $sectionSubjectId; ?>"
                   class="btn btn-sm btn-brand-primary">
                    <i class="bi bi-graph-up-arrow me-1"></i>Open Student Progress
                </a>
            </div>
        </div>

        <?php else: ?>
        <p class="text-muted mb-0">This area is not available yet.</p>
        <?php endif; ?>

    </div><!-- /card-body -->
</section>

<style>
/* ── Management nav tabs ──────────────────────────────── */
#subject-tab-nav .nav-link {
    color: #475569;
    border-bottom: 2px solid transparent;
    padding: .55rem .9rem;
    font-size: .84rem;
    transition: color .15s, border-color .15s;
}
#subject-tab-nav .nav-link:hover {
    color: var(--brand-primary, #008080);
    border-color: transparent;
    background: #f1f5f9;
}
#subject-tab-nav .nav-link.active {
    color: var(--brand-primary, #008080);
    border-bottom-color: var(--brand-primary, #008080);
    background: #fff;
}

/* ── Management grid cards ────────────────────────────── */
.lms-manage-card {
    background: #f8fafc;
    border-color: #e2e8f0 !important;
    transition: background .15s, border-color .15s, box-shadow .15s;
}
.lms-manage-card:hover {
    background: #fff;
    border-color: var(--brand-primary, #008080) !important;
    box-shadow: 0 2px 8px rgba(0,128,128,.12);
    text-decoration: none;
}

/* ── Top nav bar hover ────────────────────────────────── */
nav .btn.text-white:hover {
    background: rgba(255,255,255,.15) !important;
    opacity: 1 !important;
}

/* ── btn-xs — compact action buttons for module/lesson rows ─ */
.btn-xs {
    padding: .15rem .45rem;
    font-size: .72rem;
    line-height: 1.4;
    border-radius: .25rem;
}

/* ── Module/Lesson list styles ────────────────────────── */
.module-block {
    border-color: #e2e8f0 !important;
    transition: box-shadow .15s;
}
.module-block:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,.06);
}
.module-drag-handle:hover,
.lesson-drag-handle:hover { opacity: .8; }

.lesson-row:last-of-type {
    border-bottom: none !important;
}
</style>

<?php require_once '../includes/footer.php'; ?>
