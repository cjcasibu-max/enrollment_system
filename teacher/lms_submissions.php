<?php
/**
 * Teacher LMS - Consolidated Submissions View (Phase 9.1)
 *
 * Consolidated submissions view under Instructor Management where a teacher
 * can see all pending and completed submissions across all their subjects in one place.
 *
 * Pulls from the same underlying assignment & quiz submission data:
 *   - `lms_assignment_submissions` (Assignments & Activities)
 *   - `lms_quiz_attempts` (Quizzes & Exams)
 *
 * Security:
 *   - Role gate: requireLmsTeacherAccess() (faculty / admin only)
 *   - Scoped strictly to subjects assigned to the logged-in teacher:
 *     section_subjects.instructor_id = $userId OR sections.teacher_id = $userId
 *   - Individual submission grading checks teacher ownership.
 */

require_once '../includes/lms_access.php';

$teacher          = requireLmsTeacherAccess();
$userId           = (int)$teacher['user_id'];
$sectionSubjectId = (int)filter_input(INPUT_GET, 'section_subject_id', FILTER_VALIDATE_INT);
$statusFilter     = trim((string)(filter_input(INPUT_GET, 'status', FILTER_DEFAULT) ?? 'all'));
$typeFilter       = trim((string)(filter_input(INPUT_GET, 'type', FILTER_DEFAULT) ?? 'all'));
$searchQuery      = trim((string)(filter_input(INPUT_GET, 'q', FILTER_DEFAULT) ?? ''));

// ── Fetch all subjects assigned to this teacher ────────────────────────────────
$allSubjects = fetchLmsTeacherSubjects($pdo, $userId);
$subjectMap  = [];
foreach ($allSubjects as $subj) {
    $subjectMap[(int)$subj['section_subject_id']] = $subj;
}

// ── Mode detection ─────────────────────────────────────────────────────────────
$subjectMode = false;
$currentSubject = null;

if ($sectionSubjectId > 0) {
    $currentSubject = verifyTeacherOwnsSubject($pdo, $userId, $sectionSubjectId);
    if (!$currentSubject) {
        $_SESSION['flash_error'] = 'Access denied: you are not assigned to that subject.';
        header('Location: ' . resolveAppUrl('teacher/lms_submissions'));
        exit;
    }
    $subjectMode = true;
}

// ── Build target section_subject IDs list ──────────────────────────────────────
$targetSsIds = [];
if ($subjectMode) {
    $targetSsIds = [$sectionSubjectId];
} else {
    $targetSsIds = array_column($allSubjects, 'section_subject_id');
}

// ── Fetch Consolidated Submissions ─────────────────────────────────────────────
$submissions = [];
$kpis = [
    'total'             => 0,
    'pending_grading'   => 0,
    'graded_assignment' => 0,
    'quiz_attempts'     => 0,
    'unique_students'   => 0,
];

if (!empty($targetSsIds)) {
    $inPh = implode(',', array_fill(0, count($targetSsIds), '?'));

    // Combined query for assignment submissions and quiz attempts
    $sql = "
        SELECT 
            'assignment' AS submission_kind,
            subm.id AS submission_id,
            a.id AS item_id,
            a.title AS item_title,
            a.assignment_type AS item_sub_type,
            a.max_score,
            subm.file_name,
            subm.file_path,
            subm.submitted_at,
            subm.score,
            subm.feedback,
            subm.graded_at,
            NULL AS attempt_number,
            NULL AS quiz_status,
            NULL AS passing_score,
            CASE 
                WHEN subm.graded_at IS NOT NULL OR subm.score IS NOT NULL THEN 'graded'
                ELSE 'pending'
            END AS evaluation_status,
            st.id AS student_id,
            COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.username) AS student_name,
            u.username,
            u.email AS student_email,
            ss.id AS section_subject_id,
            sub.subject_code,
            sub.subject_name,
            sec.section_name
        FROM lms_assignment_submissions subm
        JOIN lms_assignments a ON a.id = subm.assignment_id
        JOIN section_subjects ss ON ss.id = a.section_subject_id
        JOIN subjects sub ON sub.id = ss.subject_id
        JOIN sections sec ON sec.id = ss.section_id
        JOIN students st ON st.id = subm.student_id
        JOIN users u ON u.id = st.user_id
        WHERE a.section_subject_id IN ($inPh)

        UNION ALL

        SELECT 
            'quiz' AS submission_kind,
            qa.id AS submission_id,
            q.id AS item_id,
            q.title AS item_title,
            'quiz' AS item_sub_type,
            qa.total_points AS max_score,
            NULL AS file_name,
            NULL AS file_path,
            qa.submitted_at,
            qa.score,
            qa.feedback,
            qa.submitted_at AS graded_at,
            qa.attempt_number,
            qa.status AS quiz_status,
            q.passing_score,
            'graded' AS evaluation_status,
            st.id AS student_id,
            COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.username) AS student_name,
            u.username,
            u.email AS student_email,
            ss.id AS section_subject_id,
            sub.subject_code,
            sub.subject_name,
            sec.section_name
        FROM lms_quiz_attempts qa
        JOIN lms_quizzes q ON q.id = qa.quiz_id
        JOIN section_subjects ss ON ss.id = q.section_subject_id
        JOIN subjects sub ON sub.id = ss.subject_id
        JOIN sections sec ON sec.id = ss.section_id
        JOIN students st ON st.id = qa.student_id
        JOIN users u ON u.id = st.user_id
        WHERE q.section_subject_id IN ($inPh)
          AND qa.status IN ('submitted', 'interrupted', 'timed_out')

        ORDER BY evaluation_status ASC, submitted_at DESC
    ";

    $stmt = $pdo->prepare($sql);
    // Bind targetSsIds twice (once for assignments, once for quizzes)
    $stmt->execute(array_merge($targetSsIds, $targetSsIds));
    $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Compute KPI metrics
    $studentIds = [];
    foreach ($submissions as $subm) {
        $kpis['total']++;
        if ($subm['submission_kind'] === 'assignment') {
            if ($subm['evaluation_status'] === 'pending') {
                $kpis['pending_grading']++;
            } else {
                $kpis['graded_assignment']++;
            }
        } elseif ($subm['submission_kind'] === 'quiz') {
            $kpis['quiz_attempts']++;
        }
        $studentIds[(int)$subm['student_id']] = true;
    }
    $kpis['unique_students'] = count($studentIds);
}

$page_title = $subjectMode
    ? htmlspecialchars($currentSubject['subject_code']) . ' — Submissions'
    : 'Consolidated Submissions — Instructor Management';

require_once '../includes/header.php';

$lmsActiveTab = 'submissions';
require_once __DIR__ . '/lms_navbar.php';
?>

<!-- ── BREADCRUMBS ──────────────────────────────────────────────────────────── -->
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb mb-0" style="font-size:.82rem;">
        <li class="breadcrumb-item"><a href="lms" class="text-decoration-none text-brand-primary">Instructor LMS</a></li>
        <?php if ($subjectMode): ?>
            <li class="breadcrumb-item"><a href="lms_submissions" class="text-decoration-none text-brand-primary">All Submissions</a></li>
            <li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars($currentSubject['subject_code']); ?> — <?php echo htmlspecialchars($currentSubject['section_name']); ?></li>
        <?php else: ?>
            <li class="breadcrumb-item active" aria-current="page">Consolidated Submissions</li>
        <?php endif; ?>
    </ol>
</nav>

<!-- ── HEADER BANNER ────────────────────────────────────────────────────────── -->
<div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
    <div>
        <?php if ($subjectMode): ?>
            <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                <span class="badge" style="background:var(--brand-primary, #008080);font-size:.8rem;letter-spacing:.04em;">
                    <?php echo htmlspecialchars($currentSubject['subject_code']); ?>
                </span>
                <span class="badge bg-light text-secondary border" style="font-size:.8rem;">
                    Section: <?php echo htmlspecialchars($currentSubject['section_name']); ?>
                </span>
                <?php if (!empty($currentSubject['term_name'])): ?>
                    <span class="badge bg-light text-secondary border" style="font-size:.8rem;">
                        <?php echo htmlspecialchars($currentSubject['term_name']); ?>
                    </span>
                <?php endif; ?>
            </div>
            <h1 class="h3 fw-bold text-navy-alt mb-1"><?php echo htmlspecialchars($currentSubject['subject_name']); ?> — Submissions</h1>
            <p class="text-muted small mb-0">
                Review, evaluate, and provide written feedback on assignment submissions and quiz attempts for this subject.
            </p>
        <?php else: ?>
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge" style="background:var(--brand-primary, #008080);font-size:.75rem;letter-spacing:.04em;text-transform:uppercase;">
                    Instructor Management
                </span>
            </div>
            <h1 class="h3 fw-bold text-navy-alt mb-1">Consolidated Submissions Inbox</h1>
            <p class="text-muted small mb-0">
                View and evaluate all pending and completed student submissions across all of your assigned courses in one central place.
            </p>
        <?php endif; ?>
    </div>
    <div class="d-flex gap-2 flex-wrap align-items-center" style="gap: 8px;">
        <?php if ($subjectMode): ?>
            <a href="lms_subject?section_subject_id=<?php echo $sectionSubjectId; ?>&section=overview" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Subject Hub
            </a>
            <a href="lms_submissions" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-grid-3x3-gap me-1"></i>All Subjects View
            </a>
        <?php else: ?>
            <a href="lms" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-grid-fill me-1"></i>LMS Dashboard
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- ── KPI METRICS STRIP ─────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card card-premium shadow-sm border-0 h-100" style="padding: 18px 20px;">
            <div class="d-flex align-items-center" style="gap: 14px;">
                <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px; background: rgba(0, 128, 128, 0.1); color: var(--brand-primary, #008080);">
                    <i class="bi bi-inbox fs-5"></i>
                </div>
                <div class="d-flex flex-column" style="gap: 4px;">
                    <span class="text-muted text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.5px;">Total</span>
                    <span class="fs-5 fw-bold text-navy-alt lh-1"><?php echo $kpis['total']; ?></span>
                    <span class="text-muted" style="font-size: 0.75rem;">submissions</span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-3">
        <div class="card card-premium shadow-sm border-0 h-100 <?php echo $kpis['pending_grading'] > 0 ? 'border-warning border-start border-4' : ''; ?>" style="padding: 18px 20px;">
            <div class="d-flex align-items-center" style="gap: 14px;">
                <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px; background: rgba(255, 193, 7, 0.15); color: #b78103;">
                    <i class="bi bi-hourglass-split fs-5"></i>
                </div>
                <div class="d-flex flex-column" style="gap: 4px;">
                    <span class="text-muted text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.5px;">Pending Review</span>
                    <span class="fs-5 fw-bold text-warning lh-1"><?php echo $kpis['pending_grading']; ?></span>
                    <span class="text-muted" style="font-size: 0.75rem;">require grading</span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card card-premium shadow-sm border-0 h-100" style="padding: 18px 20px;">
            <div class="d-flex align-items-center" style="gap: 14px;">
                <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px; background: rgba(25, 135, 84, 0.1); color: #198754;">
                    <i class="bi bi-check-circle fs-5"></i>
                </div>
                <div class="d-flex flex-column" style="gap: 4px;">
                    <span class="text-muted text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.5px;">Graded</span>
                    <span class="fs-5 fw-bold text-success lh-1"><?php echo $kpis['graded_assignment']; ?></span>
                    <span class="text-muted" style="font-size: 0.75rem;">tasks evaluated</span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card card-premium shadow-sm border-0 h-100" style="padding: 18px 20px;">
            <div class="d-flex align-items-center" style="gap: 14px;">
                <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px; background: rgba(13, 110, 253, 0.1); color: #0d6efd;">
                    <i class="bi bi-patch-check fs-5"></i>
                </div>
                <div class="d-flex flex-column" style="gap: 4px;">
                    <span class="text-muted text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.5px;">Quiz Attempts</span>
                    <span class="fs-5 fw-bold text-navy-alt lh-1"><?php echo $kpis['quiz_attempts']; ?></span>
                    <span class="text-muted" style="font-size: 0.75rem;">auto-evaluated</span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4 col-xl-3">
        <div class="card card-premium shadow-sm border-0 h-100" style="padding: 18px 20px;">
            <div class="d-flex align-items-center" style="gap: 14px;">
                <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px; background: rgba(108, 117, 125, 0.1); color: #495057;">
                    <i class="bi bi-people fs-5"></i>
                </div>
                <div class="d-flex flex-column" style="gap: 4px;">
                    <span class="text-muted text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.5px;">Active Students</span>
                    <span class="fs-5 fw-bold text-navy-alt lh-1"><?php echo $kpis['unique_students']; ?></span>
                    <span class="text-muted" style="font-size: 0.75rem;">with submissions</span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── SEARCH & FILTER CONTROLS ──────────────────────────────────────────────── -->
<div class="card card-premium shadow-sm border-0 mb-4">
    <div class="card-body p-3 p-md-4">
        <div class="row g-2 align-items-center">
            <!-- Subject Switcher -->
            <div class="col-12 col-md-4 col-lg-3">
                <label for="filter-subject" class="small text-muted fw-semibold mb-1 d-block">Filter Course / Subject:</label>
                <select class="form-select form-select-sm" id="filter-subject" onchange="applySubjectFilter(this.value)">
                    <option value="" <?php echo !$subjectMode ? 'selected' : ''; ?>>— All Assigned Subjects (<?php echo count($allSubjects); ?>) —</option>
                    <?php foreach ($allSubjects as $subj): ?>
                        <option value="<?php echo $subj['section_subject_id']; ?>" <?php echo ($subjectMode && $sectionSubjectId === (int)$subj['section_subject_id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($subj['subject_code']); ?> — <?php echo htmlspecialchars($subj['section_name']); ?> (<?php echo htmlspecialchars($subj['subject_name']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Type Filter Buttons -->
            <div class="col-12 col-md-4 col-lg-4">
                <label class="small text-muted fw-semibold mb-1 d-block">Coursework Type:</label>
                <div class="btn-group btn-group-sm w-100" role="group">
                    <button type="button" class="btn btn-outline-secondary type-filter-btn active" data-type="all">All Types</button>
                    <button type="button" class="btn btn-outline-secondary type-filter-btn" data-type="assignment">Assignments</button>
                    <button type="button" class="btn btn-outline-secondary type-filter-btn" data-type="quiz">Quizzes</button>
                </div>
            </div>

            <!-- Status Filter Buttons -->
            <div class="col-12 col-md-4 col-lg-3">
                <label class="small text-muted fw-semibold mb-1 d-block">Grading Status:</label>
                <div class="btn-group btn-group-sm w-100" role="group">
                    <button type="button" class="btn btn-outline-secondary status-filter-btn active" data-status="all">All</button>
                    <button type="button" class="btn btn-outline-warning status-filter-btn" data-status="pending">
                        Pending (<?php echo $kpis['pending_grading']; ?>)
                    </button>
                    <button type="button" class="btn btn-outline-success status-filter-btn" data-status="graded">Graded</button>
                </div>
            </div>

            <!-- Search Input -->
            <div class="col-12 col-lg-2">
                <label for="submission-search" class="small text-muted fw-semibold mb-1 d-block">Search:</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-search"></i></span>
                    <input type="text" class="form-control border-start-0" id="submission-search" placeholder="Cadet name, title...">
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── CONSOLIDATED SUBMISSIONS TABLE ────────────────────────────────────────── -->
<?php if (empty($submissions)): ?>
    <div class="card shadow-sm border-0 py-5 text-center">
        <div class="card-body">
            <i class="bi bi-inbox text-muted" style="font-size:3.5rem;opacity:.4;"></i>
            <h3 class="h5 fw-bold mt-3 mb-1">No Submissions Found</h3>
            <p class="text-muted small mx-auto mb-3" style="max-width:440px;">
                <?php if ($subjectMode): ?>
                    There are no student submissions or quiz attempts recorded for this subject yet.
                <?php else: ?>
                    No student submissions or quiz attempts have been submitted across any of your assigned subjects yet.
                <?php endif; ?>
            </p>
            <?php if ($subjectMode): ?>
                <a href="lms_subject?section_subject_id=<?php echo $sectionSubjectId; ?>&section=overview" class="btn btn-sm btn-brand-primary">Return to Subject Hub</a>
            <?php else: ?>
                <a href="lms" class="btn btn-sm btn-brand-primary">Return to LMS Dashboard</a>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <div class="card card-premium shadow-sm border-0 mb-4">
        <div class="card-header card-header-premium py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h2 class="h6 fw-bold mb-0 text-navy-alt">
                <i class="bi bi-list-check me-2 text-brand-primary"></i>All Submissions
                <span class="badge bg-secondary ms-1" id="visible-count-badge"><?php echo count($submissions); ?></span>
            </h2>
            <div class="small text-muted">
                Showing submissions across all assigned sections. Ordered by pending reviews first.
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="submissions-table">
                <thead class="table-light small text-uppercase text-muted" style="font-size:.72rem;letter-spacing:.05em;">
                    <tr>
                        <th class="ps-4" style="min-width:180px; padding-left: 24px !important;">Student Cadet</th>
                        <th style="min-width:140px;">Subject &amp; Section</th>
                        <th style="min-width:200px;">Coursework Item</th>
                        <th style="min-width:150px;">Submitted</th>
                        <th style="min-width:140px;">Status / Evaluation</th>
                        <th class="text-end pe-4" style="min-width:140px; padding-right: 24px !important;">Action</th>
                    </tr>
                </thead>
                <tbody class="small">
                    <?php foreach ($submissions as $row): ?>
                        <?php
                            $isAssignment = ($row['submission_kind'] === 'assignment');
                            $isPending    = ($row['evaluation_status'] === 'pending');
                            $submTime     = !empty($row['submitted_at']) ? strtotime($row['submitted_at']) : null;
                        ?>
                        <tr class="submission-row <?php echo $isPending ? 'table-warning-subtle' : ''; ?>"
                            data-kind="<?php echo $row['submission_kind']; ?>"
                            data-status="<?php echo $row['evaluation_status']; ?>"
                            data-ss-id="<?php echo $row['section_subject_id']; ?>"
                            data-search="<?php echo htmlspecialchars(strtolower($row['student_name'] . ' ' . $row['username'] . ' ' . $row['item_title'] . ' ' . $row['subject_code'])); ?>">
                            
                            <!-- Student Info -->
                            <td class="ps-4" style="padding-left: 24px !important;">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold shadow-sm"
                                         style="width:34px;height:34px;font-size:.8rem;background:<?php echo $isAssignment ? '#0b4f5c' : '#0891b2'; ?>;flex-shrink:0;">
                                        <?php echo strtoupper(substr($row['student_name'], 0, 1)); ?>
                                    </div>
                                    <div>
                                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($row['student_name']); ?></div>
                                        <div class="text-muted" style="font-size:.75rem;">@<?php echo htmlspecialchars($row['username']); ?></div>
                                    </div>
                                </div>
                            </td>

                            <!-- Subject & Section -->
                            <td>
                                <span class="badge bg-dark-subtle text-dark border px-2 py-1" style="font-size:.74rem;">
                                    <?php echo htmlspecialchars($row['subject_code']); ?>
                                </span>
                                <div class="text-muted mt-1" style="font-size:.75rem;">
                                    Sec: <?php echo htmlspecialchars($row['section_name']); ?>
                                </div>
                            </td>

                            <!-- Item & Type -->
                            <td>
                                <div class="d-flex align-items-center gap-1 mb-1">
                                    <?php if ($isAssignment): ?>
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle" style="font-size:.68rem;">
                                            <i class="bi bi-pencil-square me-1"></i><?php echo ucfirst(htmlspecialchars($row['item_sub_type'])); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle" style="font-size:.68rem;">
                                            <i class="bi bi-patch-question me-1"></i>Quiz
                                        </span>
                                    <?php endif; ?>
                                    <span class="text-muted" style="font-size:.72rem;">Max: <?php echo htmlspecialchars($row['max_score'] !== null ? number_format((float)$row['max_score'], 1) : 'N/A'); ?> pts</span>
                                </div>
                                <div class="fw-semibold text-dark text-truncate" style="max-width:240px;" title="<?php echo htmlspecialchars($row['item_title']); ?>">
                                    <?php echo htmlspecialchars($row['item_title']); ?>
                                </div>
                                <?php if ($isAssignment && !empty($row['file_name'])): ?>
                                    <div class="text-muted" style="font-size:.72rem;">
                                        <i class="bi bi-paperclip me-1"></i><?php echo htmlspecialchars($row['file_name']); ?>
                                    </div>
                                <?php elseif (!$isAssignment && !empty($row['attempt_number'])): ?>
                                    <div class="text-muted" style="font-size:.72rem;">
                                        <i class="bi bi-arrow-repeat me-1"></i>Attempt #<?php echo (int)$row['attempt_number']; ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <!-- Submitted Timestamp -->
                            <td>
                                <?php if ($submTime): ?>
                                    <div class="text-dark fw-medium"><?php echo htmlspecialchars(date('M j, Y', $submTime)); ?></div>
                                    <div class="text-muted" style="font-size:.75rem;"><?php echo htmlspecialchars(date('g:i A', $submTime)); ?></div>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>

                            <!-- Evaluation / Score -->
                            <td>
                                <?php if ($isAssignment): ?>
                                    <?php if ($isPending): ?>
                                        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2 py-1">
                                            <i class="bi bi-hourglass-split me-1 text-warning"></i>Pending Review
                                        </span>
                                    <?php else: ?>
                                        <div class="d-flex align-items-center gap-1">
                                            <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                                                <i class="bi bi-check-circle me-1"></i>Graded
                                            </span>
                                            <strong class="text-dark ms-1">
                                                <?php echo htmlspecialchars(number_format((float)$row['score'], 1)); ?> / <?php echo htmlspecialchars($row['max_score'] !== null ? number_format((float)$row['max_score'], 1) : '—'); ?>
                                            </strong>
                                        </div>
                                        <?php if (!empty($row['feedback'])): ?>
                                            <div class="text-muted text-truncate mt-1" style="font-size:.72rem;max-width:160px;" title="<?php echo htmlspecialchars($row['feedback']); ?>">
                                                <i class="bi bi-chat-left-text me-1"></i><?php echo htmlspecialchars($row['feedback']); ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <!-- Quiz Attempt -->
                                    <div class="d-flex align-items-center gap-1">
                                        <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle px-2 py-1">
                                            <i class="bi bi-patch-check me-1"></i>Auto-scored
                                        </span>
                                        <strong class="text-dark ms-1">
                                            <?php echo htmlspecialchars(number_format((float)$row['score'], 1)); ?> / <?php echo htmlspecialchars(number_format((float)$row['max_score'], 1)); ?>
                                        </strong>
                                    </div>
                                    <?php if ($row['passing_score'] !== null): ?>
                                        <?php $passed = ((float)$row['score'] >= (float)$row['passing_score']); ?>
                                        <div class="mt-1" style="font-size:.72rem;">
                                            <span class="badge <?php echo $passed ? 'bg-success' : 'bg-danger'; ?> py-0 px-1" style="font-size:.68rem;">
                                                <?php echo $passed ? 'Passed' : 'Failed'; ?> (Pass: <?php echo number_format((float)$row['passing_score'], 1); ?>)
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>

                            <!-- Action Buttons -->
                            <td class="text-end pe-4" style="padding-right: 24px !important;">
                                <div class="d-inline-flex align-items-center" style="gap: 8px;">
                                    <?php if ($isAssignment): ?>
                                        <!-- Download submission file -->
                                        <a href="lms_submission_file?submission_id=<?php echo $row['submission_id']; ?>"
                                           class="btn btn-sm btn-outline-secondary"
                                           title="Download submitted student file">
                                            <i class="bi bi-download"></i>
                                        </a>

                                        <!-- Grade or Edit Grade -->
                                        <button type="button"
                                                class="btn btn-sm <?php echo $isPending ? 'btn-brand-primary' : 'btn-outline-primary'; ?> btn-grade-submission"
                                                data-submission='<?php echo htmlspecialchars(json_encode([
                                                    'submission_id'      => $row['submission_id'],
                                                    'assignment_id'      => $row['item_id'],
                                                    'section_subject_id' => $row['section_subject_id'],
                                                    'student_name'       => $row['student_name'],
                                                    'assignment_title'   => $row['item_title'],
                                                    'subject_code'       => $row['subject_code'],
                                                    'section_name'       => $row['section_name'],
                                                    'max_score'          => $row['max_score'],
                                                    'file_name'          => $row['file_name'],
                                                    'submitted_at'       => $row['submitted_at'] ? date('M j, Y g:i A', strtotime($row['submitted_at'])) : '',
                                                    'score'              => $row['score'] !== null ? (float)$row['score'] : '',
                                                    'feedback'           => $row['feedback'] ?? '',
                                                ]), ENT_QUOTES, 'UTF-8'); ?>'
                                                title="<?php echo $isPending ? 'Grade Submission' : 'Edit Grade & Feedback'; ?>">
                                            <i class="bi bi-pencil me-1"></i><?php echo $isPending ? 'Grade' : 'Edit'; ?>
                                        </button>
                                    <?php else: ?>
                                        <!-- Quiz details -->
                                        <button type="button"
                                                class="btn btn-sm btn-outline-info btn-quiz-details"
                                                data-quiz='<?php echo htmlspecialchars(json_encode([
                                                    'submission_id'      => $row['submission_id'],
                                                    'quiz_id'            => $row['item_id'],
                                                    'student_name'       => $row['student_name'],
                                                    'quiz_title'         => $row['item_title'],
                                                    'subject_code'       => $row['subject_code'],
                                                    'section_name'       => $row['section_name'],
                                                    'score'              => $row['score'],
                                                    'total_points'       => $row['max_score'],
                                                    'passing_score'      => $row['passing_score'],
                                                    'attempt_number'     => $row['attempt_number'],
                                                    'status'             => $row['quiz_status'],
                                                    'submitted_at'       => $row['submitted_at'] ? date('M j, Y g:i A', strtotime($row['submitted_at'])) : '',
                                                ]), ENT_QUOTES, 'UTF-8'); ?>'
                                                title="View Quiz Results">
                                            <i class="bi bi-eye me-1"></i>Details
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<!-- ════════════════════════════════════════════════════════════════════════
     MODALS: GRADING ASSIGNMENT & QUIZ DETAILS
     ════════════════════════════════════════════════════════════════════════ -->

<!-- 1. GRADE ASSIGNMENT MODAL -->
<div class="modal fade" id="modal-grade-submission" tabindex="-1" aria-labelledby="modalGradeTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form method="post" action="../actions/lms_teacher_assignment_actions" class="modal-content shadow">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
            <input type="hidden" name="action" value="grade_submission">
            <input type="hidden" name="submission_id" id="modal-grade-submission-id">
            <input type="hidden" name="assignment_id" id="modal-grade-assignment-id">
            <input type="hidden" name="section_subject_id" id="modal-grade-ss-id">
            <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars($subjectMode ? 'teacher/lms_submissions?section_subject_id=' . $sectionSubjectId : 'teacher/lms_submissions'); ?>">

            <div class="modal-header text-white" style="background:var(--brand-primary,#0b4f5c);">
                <h5 class="modal-title fw-bold" id="modalGradeTitle">
                    <i class="bi bi-award-fill me-2"></i>Grade Assignment Submission
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body p-4">
                <!-- Submission Info Header Card -->
                <div class="card border bg-light mb-3">
                    <div class="card-body p-3">
                        <div class="row g-2">
                            <div class="col-sm-6">
                                <span class="text-muted small d-block">Student Cadet</span>
                                <strong class="text-dark" id="modal-grade-student-name">—</strong>
                            </div>
                            <div class="col-sm-6">
                                <span class="text-muted small d-block">Course &amp; Section</span>
                                <span class="text-dark fw-medium" id="modal-grade-subject-sec">—</span>
                            </div>
                            <div class="col-sm-6">
                                <span class="text-muted small d-block">Assignment</span>
                                <span class="text-dark fw-medium" id="modal-grade-assignment-title">—</span>
                            </div>
                            <div class="col-sm-6">
                                <span class="text-muted small d-block">Submitted At</span>
                                <span class="text-dark" id="modal-grade-submitted-at">—</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Attached File Download Card -->
                <div class="d-flex justify-content-between align-items-center p-3 border rounded bg-white mb-3">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-file-earmark-arrow-down-fill text-primary" style="font-size:1.8rem;"></i>
                        <div>
                            <div class="fw-semibold text-dark" id="modal-grade-file-name">filename.ext</div>
                            <small class="text-muted">Submitted file from cadet</small>
                        </div>
                    </div>
                    <a href="#" class="btn btn-sm btn-outline-primary" id="modal-grade-file-link">
                        <i class="bi bi-download me-1"></i>Download File
                    </a>
                </div>

                <!-- Score Input -->
                <div class="row g-3 mb-3">
                    <div class="col-12 col-md-6">
                        <label class="form-label fw-semibold" for="modal-grade-score">
                            Grade / Score <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <input type="number" step="0.01" min="0" class="form-control" name="score" id="modal-grade-score" required placeholder="0.00">
                            <span class="input-group-text bg-light text-muted">/ <span id="modal-grade-max-score" class="ms-1">100</span> pts</span>
                        </div>
                        <div class="form-text" id="modal-grade-score-help">Enter score within maximum points.</div>
                    </div>
                </div>

                <!-- Feedback Input -->
                <div class="mb-2">
                    <label class="form-label fw-semibold" for="modal-grade-feedback">
                        Instructor Feedback &amp; Remarks <span class="text-muted fw-normal">(optional)</span>
                    </label>
                    <textarea class="form-control" name="feedback" id="modal-grade-feedback" rows="4" placeholder="Provide constructive feedback, corrections, or praise for the cadet's work..."></textarea>
                    <div class="form-text">
                        Feedback is immediately visible to the student cadet on their assignment view and grade report.
                    </div>
                </div>
            </div>

            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-brand-primary px-4 fw-semibold">
                    <i class="bi bi-check-circle-fill me-1"></i>Save Grade &amp; Feedback
                </button>
            </div>
        </form>
    </div>
</div>

<!-- 2. QUIZ DETAILS MODAL -->
<div class="modal fade" id="modal-quiz-details" tabindex="-1" aria-labelledby="modalQuizTitle" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content shadow">
            <div class="modal-header text-white" style="background:#0891b2;">
                <h5 class="modal-title fw-bold" id="modalQuizTitle">
                    <i class="bi bi-patch-question-fill me-2"></i>Quiz Attempt Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="text-center py-2 mb-3">
                    <div class="display-6 fw-bold text-dark" id="modal-quiz-score-display">—</div>
                    <div class="text-muted small" id="modal-quiz-percentage">—</div>
                </div>
                <dl class="row mb-0 small">
                    <dt class="col-sm-4 text-muted">Student Cadet:</dt>
                    <dd class="col-sm-8 fw-semibold text-dark" id="modal-quiz-student">—</dd>

                    <dt class="col-sm-4 text-muted">Subject / Section:</dt>
                    <dd class="col-sm-8 text-dark" id="modal-quiz-subject">—</dd>

                    <dt class="col-sm-4 text-muted">Quiz Name:</dt>
                    <dd class="col-sm-8 text-dark" id="modal-quiz-name">—</dd>

                    <dt class="col-sm-4 text-muted">Attempt #:</dt>
                    <dd class="col-sm-8 text-dark" id="modal-quiz-attempt">—</dd>

                    <dt class="col-sm-4 text-muted">Attempt Status:</dt>
                    <dd class="col-sm-8 text-dark" id="modal-quiz-status">—</dd>

                    <dt class="col-sm-4 text-muted">Passing Threshold:</dt>
                    <dd class="col-sm-8 text-dark" id="modal-quiz-passing">—</dd>

                    <dt class="col-sm-4 text-muted">Submitted At:</dt>
                    <dd class="col-sm-8 text-dark" id="modal-quiz-submitted">—</dd>
                </dl>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════════════════
     PAGE JAVASCRIPT: FILTERING, SEARCH, AND MODAL POPULATION
     ════════════════════════════════════════════════════════════════════════ -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    // ── Filter & Search Elements ──────────────────────────────────────────
    const searchInput      = document.getElementById('submission-search');
    const typeFilterBtns   = document.querySelectorAll('.type-filter-btn');
    const statusFilterBtns = document.querySelectorAll('.status-filter-btn');
    const tableRows        = document.querySelectorAll('.submission-row');
    const visibleCount     = document.getElementById('visible-count-badge');

    let currentTypeFilter   = 'all';
    let currentStatusFilter = 'all';

    function applyFilters() {
        const query = searchInput ? searchInput.value.trim().toLowerCase() : '';
        let count = 0;

        tableRows.forEach(row => {
            const rowKind   = row.dataset.kind || '';
            const rowStatus = row.dataset.status || '';
            const rowSearch = row.dataset.search || '';

            let matchType = (currentTypeFilter === 'all' || rowKind === currentTypeFilter);
            let matchStatus = (currentStatusFilter === 'all' || rowStatus === currentStatusFilter);
            let matchSearch = (query === '' || rowSearch.includes(query));

            if (matchType && matchStatus && matchSearch) {
                row.style.display = '';
                count++;
            } else {
                row.style.display = 'none';
            }
        });

        if (visibleCount) {
            visibleCount.textContent = count;
        }
    }

    if (searchInput) {
        searchInput.addEventListener('input', applyFilters);
    }

    typeFilterBtns.forEach(btn => {
        btn.addEventListener('click', function () {
            typeFilterBtns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            currentTypeFilter = this.dataset.type;
            applyFilters();
        });
    });

    statusFilterBtns.forEach(btn => {
        btn.addEventListener('click', function () {
            statusFilterBtns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            currentStatusFilter = this.dataset.status;
            applyFilters();
        });
    });

    // ── Grade Assignment Modal ────────────────────────────────────────────
    document.querySelectorAll('.btn-grade-submission').forEach(btn => {
        btn.addEventListener('click', function () {
            try {
                const data = JSON.parse(this.dataset.submission);
                document.getElementById('modal-grade-submission-id').value = data.submission_id;
                document.getElementById('modal-grade-assignment-id').value = data.assignment_id;
                document.getElementById('modal-grade-ss-id').value = data.section_subject_id;
                document.getElementById('modal-grade-student-name').textContent = data.student_name;
                document.getElementById('modal-grade-subject-sec').textContent = data.subject_code + ' — Section ' + data.section_name;
                document.getElementById('modal-grade-assignment-title').textContent = data.assignment_title;
                document.getElementById('modal-grade-submitted-at').textContent = data.submitted_at || '—';
                document.getElementById('modal-grade-file-name').textContent = data.file_name || 'No file attached';
                document.getElementById('modal-grade-file-link').href = 'lms_submission_file?submission_id=' + data.submission_id;

                const maxScore = (data.max_score !== null && data.max_score !== '') ? parseFloat(data.max_score) : 100;
                document.getElementById('modal-grade-max-score').textContent = maxScore;
                
                const scoreInput = document.getElementById('modal-grade-score');
                scoreInput.max = maxScore;
                scoreInput.value = (data.score !== null && data.score !== '') ? data.score : '';

                document.getElementById('modal-grade-feedback').value = data.feedback || '';

                new bootstrap.Modal(document.getElementById('modal-grade-submission')).show();
            } catch (e) {
                console.error('Failed to parse submission data for grading:', e);
            }
        });
    });

    // ── Quiz Details Modal ────────────────────────────────────────────────
    document.querySelectorAll('.btn-quiz-details').forEach(btn => {
        btn.addEventListener('click', function () {
            try {
                const data = JSON.parse(this.dataset.quiz);
                const score = data.score !== null ? parseFloat(data.score) : 0;
                const total = data.total_points !== null ? parseFloat(data.total_points) : 0;
                const pct   = total > 0 ? Math.round((score / total) * 100) : 0;

                document.getElementById('modal-quiz-score-display').textContent = score.toFixed(1) + ' / ' + total.toFixed(1);
                document.getElementById('modal-quiz-percentage').textContent = pct + '% score achieved';
                document.getElementById('modal-quiz-student').textContent = data.student_name;
                document.getElementById('modal-quiz-subject').textContent = data.subject_code + ' (Sec: ' + data.section_name + ')';
                document.getElementById('modal-quiz-name').textContent = data.quiz_title;
                document.getElementById('modal-quiz-attempt').textContent = '#' + (data.attempt_number || 1);
                document.getElementById('modal-quiz-status').textContent = data.status || 'submitted';
                document.getElementById('modal-quiz-passing').textContent = data.passing_score !== null ? (parseFloat(data.passing_score).toFixed(1) + ' pts required') : 'None configured';
                document.getElementById('modal-quiz-submitted').textContent = data.submitted_at || '—';

                new bootstrap.Modal(document.getElementById('modal-quiz-details')).show();
            } catch (e) {
                console.error('Failed to parse quiz attempt data:', e);
            }
        });
    });
});

function applySubjectFilter(ssId) {
    if (ssId) {
        window.location.href = 'lms_submissions?section_subject_id=' + encodeURIComponent(ssId);
    } else {
        window.location.href = 'lms_submissions';
    }
}
</script>

<?php require_once '../includes/footer.php'; ?>
