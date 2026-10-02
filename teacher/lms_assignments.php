<?php
/**
 * Teacher LMS - Assignments Management (Phase 5)
 *
 * Two modes:
 *   With ?section_subject_id=N  -> subject-scoped assignments manager
 *                                   Sub-mode: ?view_submissions=N -> submissions + grading panel
 *   Without                     -> cross-subject overview (counts + "Manage" links)
 *
 * Security:
 *   requireLmsTeacherAccess()       -> role gate
 *   verifyTeacherOwnsSubject()      -> ownership (subject-scoped mode only)
 *
 * Data source:
 *   Reads/writes lms_assignments, lms_assignment_files, lms_assignment_submissions.
 *   Same tables the student side reads from - changes are immediately live.
 */

require_once '../includes/lms_access.php';

$teacher          = requireLmsTeacherAccess();
$userId           = $teacher['user_id'];
$sectionSubjectId = (int)filter_input(INPUT_GET, 'section_subject_id', FILTER_VALIDATE_INT);
$viewSubmissionsId= (int)filter_input(INPUT_GET, 'view_submissions', FILTER_VALIDATE_INT); // assignment id for submissions view
$highlightId      = (int)filter_input(INPUT_GET, 'highlight', FILTER_VALIDATE_INT);

// ── Mode detection ─────────────────────────────────────────────────────────────
$subjectMode = false;
$subject      = null;

if ($sectionSubjectId > 0) {
    $subject = verifyTeacherOwnsSubject($pdo, $userId, $sectionSubjectId);
    if (!$subject) {
        $_SESSION['flash_error'] = 'Access denied: you are not assigned to that subject.';
        header('Location: ' . resolveAppUrl('teacher/lms')); exit;
    }
    $subjectMode = true;
}

// ── Subject-scoped: fetch assignments + ref files ──────────────────────────────
$assignments  = [];
$refFilesByAssignment = [];

if ($subjectMode) {
    $aStmt = $pdo->prepare(
        'SELECT id, assignment_type, title, instructions, due_at, max_score,
                allow_late_submissions, display_order, is_published, created_at
         FROM lms_assignments
         WHERE section_subject_id = :ss
         ORDER BY display_order ASC, id ASC'
    );
    $aStmt->execute(['ss' => $sectionSubjectId]);
    $assignments = $aStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($assignments)) {
        $ids = array_column($assignments, 'id');
        $inPh = implode(',', array_fill(0, count($ids), '?'));
        $rfStmt = $pdo->prepare(
            "SELECT id, assignment_id, file_name FROM lms_assignment_files WHERE assignment_id IN ($inPh) ORDER BY id ASC"
        );
        $rfStmt->execute($ids);
        foreach ($rfStmt->fetchAll(PDO::FETCH_ASSOC) as $rf) {
            $refFilesByAssignment[(int)$rf['assignment_id']][] = $rf;
        }

        // Submission counts per assignment
        $scStmt = $pdo->prepare(
            "SELECT assignment_id,
                    COUNT(*) AS total_submissions,
                    SUM(CASE WHEN graded_at IS NULL AND score IS NULL AND feedback IS NULL THEN 1 ELSE 0 END) AS ungraded
             FROM lms_assignment_submissions
             WHERE assignment_id IN ($inPh)
             GROUP BY assignment_id"
        );
        $scStmt->execute($ids);
        $submCounts = [];
        foreach ($scStmt->fetchAll(PDO::FETCH_ASSOC) as $sc) {
            $submCounts[(int)$sc['assignment_id']] = [
                'total'    => (int)$sc['total_submissions'],
                'ungraded' => (int)$sc['ungraded'],
            ];
        }
        foreach ($assignments as &$a) {
            $a['total_submissions'] = $submCounts[(int)$a['id']]['total'] ?? 0;
            $a['ungraded']         = $submCounts[(int)$a['id']]['ungraded'] ?? 0;
        }
        unset($a);
    }
}

// ── Submissions view: load submissions for a specific assignment ────────────────
$viewingSubmissions    = null;
$submissions           = [];

if ($subjectMode && $viewSubmissionsId > 0) {
    // Verify assignment belongs to this teacher's subject
    $vaStmt = $pdo->prepare('SELECT id, title, max_score FROM lms_assignments WHERE id=:id AND section_subject_id=:ss LIMIT 1');
    $vaStmt->execute(['id' => $viewSubmissionsId, 'ss' => $sectionSubjectId]);
    $viewingSubmissions = $vaStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($viewingSubmissions) {
        $sStmt = $pdo->prepare(
            "SELECT subm.id, subm.student_id, subm.file_name, subm.submitted_at,
                    subm.score, subm.feedback, subm.graded_at,
                    COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.username) AS student_name,
                    u.username,
                    s.id AS student_record_id
             FROM lms_assignment_submissions subm
             JOIN students s ON s.id = subm.student_id
             JOIN users u ON u.id = s.user_id
             WHERE subm.assignment_id = :aid
             ORDER BY subm.submitted_at DESC"
        );
        $sStmt->execute(['aid' => $viewSubmissionsId]);
        $submissions = $sStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// ── Overview mode ──────────────────────────────────────────────────────────────
$allSubjects = [];
if (!$subjectMode) {
    $allSubjects = fetchLmsTeacherSubjects($pdo, $userId);
    if (!empty($allSubjects)) {
        $ssIds = array_column($allSubjects, 'section_subject_id');
        $inPh  = implode(',', array_fill(0, count($ssIds), '?'));
        $cntStmt = $pdo->prepare(
            "SELECT section_subject_id,
                    COUNT(*) AS total,
                    SUM(is_published) AS published,
                    SUM(CASE WHEN due_at < NOW() AND is_published=1 THEN 1 ELSE 0 END) AS past_due
             FROM lms_assignments WHERE section_subject_id IN ($inPh)
             GROUP BY section_subject_id"
        );
        $cntStmt->execute($ssIds);
        $countsBySubject = [];
        foreach ($cntStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $countsBySubject[(int)$row['section_subject_id']] = [
                'total'     => (int)$row['total'],
                'published' => (int)$row['published'],
                'past_due'  => (int)$row['past_due'],
            ];
        }
        foreach ($allSubjects as &$s) {
            $sid = (int)$s['section_subject_id'];
            $s['assignment_count'] = $countsBySubject[$sid]['total']     ?? 0;
            $s['published_count']  = $countsBySubject[$sid]['published'] ?? 0;
            $s['past_due_count']   = $countsBySubject[$sid]['past_due']  ?? 0;
        }
        unset($s);
    }
}

$csrfToken  = ensureCsrfToken();
$page_title = $subjectMode
    ? htmlspecialchars($subject['subject_code']) . ' — Assignments'
    : 'LMS — Assignments';

require_once '../includes/header.php';

$lmsActiveTab = 'assignments';
require_once __DIR__ . '/lms_navbar.php';
?>

<?php if ($subjectMode): ?>

<!-- Breadcrumb -->
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb mb-0" style="font-size:.82rem;">
        <li class="breadcrumb-item"><a href="lms" class="text-decoration-none text-brand-primary"><i class="bi bi-journal-bookmark-fill me-1"></i>LMS</a></li>
        <li class="breadcrumb-item"><a href="lms_assignments" class="text-decoration-none text-brand-primary">Assignments</a></li>
        <li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars($subject['subject_code']); ?></li>
        <?php if ($viewingSubmissions): ?>
        <li class="breadcrumb-item active" aria-current="page">Submissions: <?php echo htmlspecialchars($viewingSubmissions['title']); ?></li>
        <?php endif; ?>
    </ol>
</nav>

<!-- Subject header -->
<div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <div class="text-uppercase small fw-bold text-brand-primary mb-1" style="letter-spacing:.06em;"><?php echo htmlspecialchars($subject['subject_code']); ?> &middot; <?php echo htmlspecialchars($subject['section_name'] ?: 'Section'); ?></div>
        <h3 class="m-0 text-navy-alt"><?php echo htmlspecialchars($subject['subject_name']); ?></h3>
        <p class="text-muted small m-0">
            Coursework and assignments for <?php echo htmlspecialchars($subject['section_name'] ?: 'this section'); ?>
            <?php if (!empty($subject['year_level'])): ?> &middot; <?php echo htmlspecialchars($subject['year_level']); ?><?php endif; ?>
        </p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <?php if (!$viewingSubmissions): ?>
            <a href="lms_subject?section_subject_id=<?php echo $sectionSubjectId; ?>&section=overview" class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-1 shadow-sm">
                <i class="bi bi-arrow-left"></i> Subject Hub
            </a>
            <button class="btn btn-brand-primary btn-sm d-inline-flex align-items-center gap-1 shadow-sm" data-bs-toggle="modal" data-bs-target="#modal-create-assignment" id="btn-new-assignment">
                <i class="bi bi-plus-lg"></i> New Assignment
            </button>
        <?php else: ?>
            <a href="lms_assignments?section_subject_id=<?php echo $sectionSubjectId; ?>" class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-1 shadow-sm">
                <i class="bi bi-arrow-left"></i> Back to Assignments
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if (!$viewingSubmissions): ?>
<!-- ════════════════════════════════════════════════════════════════════
     ASSIGNMENT LIST VIEW
     ════════════════════════════════════════════════════════════════════ -->

<?php
$totalPub     = count(array_filter($assignments, fn($a) => $a['is_published']));
$totalUngraded= array_sum(array_column($assignments, 'ungraded'));
$totalSubmit  = array_sum(array_column($assignments, 'total_submissions'));
?>

<!-- Stats strip matching Gradebook -->
<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="card card-premium shadow-sm border-0 h-100" style="border-radius: 10px; background: #ffffff;">
            <div class="card-body d-flex align-items-center" style="padding: 18px 20px; gap: 14px;">
                <span class="d-inline-flex align-items-center justify-content-center bg-secondary-subtle text-brand-primary rounded-circle flex-shrink-0" style="width: 44px; height: 44px; font-size: 1.25rem;">
                    <i class="bi bi-pencil-square"></i>
                </span>
                <div class="d-flex flex-column" style="gap: 4px;">
                    <div class="text-muted small text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.5px; line-height: 1.2;">Total Coursework</div>
                    <div class="fw-bold text-navy-alt fs-5" style="line-height: 1.1;"><?php echo count($assignments); ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card card-premium shadow-sm border-0 h-100" style="border-radius: 10px; background: #ffffff;">
            <div class="card-body d-flex align-items-center" style="padding: 18px 20px; gap: 14px;">
                <span class="d-inline-flex align-items-center justify-content-center rounded-circle flex-shrink-0" style="width: 44px; height: 44px; font-size: 1.25rem; background: #dcfce7; color: #166534;">
                    <i class="bi bi-check2-circle"></i>
                </span>
                <div class="d-flex flex-column" style="gap: 4px;">
                    <div class="text-muted small text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.5px; line-height: 1.2;">Published</div>
                    <div class="fw-bold text-success fs-5" style="line-height: 1.1;"><?php echo $totalPub; ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card card-premium shadow-sm border-0 h-100" style="border-radius: 10px; background: #ffffff;">
            <div class="card-body d-flex align-items-center" style="padding: 18px 20px; gap: 14px;">
                <span class="d-inline-flex align-items-center justify-content-center rounded-circle flex-shrink-0" style="width: 44px; height: 44px; font-size: 1.25rem; background: #e0f2fe; color: #0369a1;">
                    <i class="bi bi-inbox-fill"></i>
                </span>
                <div class="d-flex flex-column" style="gap: 4px;">
                    <div class="text-muted small text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.5px; line-height: 1.2;">Submissions</div>
                    <div class="fw-bold text-navy-alt fs-5" style="line-height: 1.1;"><?php echo $totalSubmit; ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card card-premium shadow-sm border-0 h-100" style="border-radius: 10px; background: #ffffff;">
            <div class="card-body d-flex align-items-center" style="padding: 18px 20px; gap: 14px;">
                <span class="d-inline-flex align-items-center justify-content-center rounded-circle flex-shrink-0" style="width: 44px; height: 44px; font-size: 1.25rem; background: #fee2e2; color: #991b1b;">
                    <i class="bi bi-hourglass-split"></i>
                </span>
                <div class="d-flex flex-column" style="gap: 4px;">
                    <div class="text-muted small text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.5px; line-height: 1.2;">Pending Grading</div>
                    <div class="fw-bold text-danger fs-5" style="line-height: 1.1;"><?php echo $totalUngraded; ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card card-premium shadow-sm border-0 mb-4" style="border-radius: 12px; overflow: hidden;">
    <div class="card-header card-header-premium d-flex justify-content-between align-items-center gap-3 flex-wrap">
        <h5 class="card-title m-0 fw-semibold text-navy-alt">
            <i class="bi bi-pencil-square me-1.5 text-brand-primary"></i>
            Coursework &amp; Assignments
        </h5>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-secondary-subtle text-brand-primary border">
                <?php echo count($assignments); ?> Item<?php echo count($assignments) !== 1 ? 's' : ''; ?>
            </span>
        </div>
    </div>
    <div class="card-body card-body-premium p-4">

<?php if (empty($assignments)): ?>
<div class="text-center py-5">
    <div class="mb-3">
        <span class="d-inline-flex align-items-center justify-content-center bg-secondary-subtle text-brand-primary rounded-circle" style="width: 56px; height: 56px; font-size: 1.5rem;">
            <i class="bi bi-pencil-square"></i>
        </span>
    </div>
    <h5 class="text-navy-alt fw-bold mb-1">No assignments created yet</h5>
    <p class="text-muted small mb-3">Create your first assignment or activity to post it for enrolled cadets.</p>
    <button class="btn btn-brand-primary btn-sm shadow-sm" data-bs-toggle="modal" data-bs-target="#modal-create-assignment">
        <i class="bi bi-plus-lg me-1"></i>Create First Assignment
    </button>
</div>
<?php else: ?>

<!-- Assignment cards -->
<div class="d-flex flex-column gap-3 w-100" id="assignment-list">
<?php foreach ($assignments as $a):
    $aid        = (int)$a['id'];
    $isPub      = (int)$a['is_published'];
    $isDue      = strtotime($a['due_at']) < time();
    $typeLabel  = $a['assignment_type'] === 'activity' ? 'Activity' : 'Assignment';
    $typeColor  = $a['assignment_type'] === 'activity' ? '#7c3aed' : 'var(--brand-primary)';
    $typeIcon   = $a['assignment_type'] === 'activity' ? 'bi-lightning-charge-fill' : 'bi-pencil-fill';
    $isHighlight= ($highlightId === $aid);
    $refs       = $refFilesByAssignment[$aid] ?? [];
    $totalSubm  = (int)$a['total_submissions'];
    $ungradedCt = (int)$a['ungraded'];
?>
<div class="card shadow-sm border-0 assignment-card<?php echo $isHighlight ? ' border border-brand-primary' : ''; ?>"
     id="assignment-card-<?php echo $aid; ?>"
     style="<?php echo $isHighlight ? 'box-shadow:0 0 0 3px rgba(var(--brand-primary-rgb,.1),0.25)!important;' : ''; ?>">
    <div class="card-body p-0">
        <!-- Card header row -->
        <div class="d-flex align-items-start gap-3 p-3 pb-2">
            <!-- Type icon -->
            <div class="flex-shrink-0 rounded-3 d-flex align-items-center justify-content-center"
                 style="width:42px;height:42px;background:<?php echo $typeColor; ?>18;">
                <i class="bi <?php echo $typeIcon; ?>" style="font-size:1.15rem;color:<?php echo $typeColor; ?>;"></i>
            </div>

            <!-- Title + meta -->
            <div class="flex-grow-1 min-w-0">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="fw-semibold" style="font-size:.95rem;"><?php echo htmlspecialchars($a['title']); ?></span>
                    <span class="badge" style="font-size:.6rem;background:<?php echo $typeColor; ?>20;color:<?php echo $typeColor; ?>;border:1px solid <?php echo $typeColor; ?>40;">
                        <?php echo $typeLabel; ?>
                    </span>
                    <?php if ($isPub): ?>
                        <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size:.6rem;">Published</span>
                    <?php else: ?>
                        <span class="badge bg-secondary-subtle text-secondary border" style="font-size:.6rem;">Draft</span>
                    <?php endif; ?>
                    <?php if ($isDue && $isPub): ?>
                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle" style="font-size:.6rem;">Past Due</span>
                    <?php endif; ?>
                </div>
                <div class="text-muted mt-1 d-flex flex-wrap gap-3" style="font-size:.78rem;">
                    <span><i class="bi bi-calendar3 me-1"></i>Due: <strong><?php echo date('M j, Y g:i A', strtotime($a['due_at'])); ?></strong></span>
                    <?php if ($a['max_score'] !== null): ?>
                        <span><i class="bi bi-trophy me-1"></i>Max: <strong><?php echo number_format((float)$a['max_score'], 0); ?> pts</strong></span>
                    <?php endif; ?>
                    <?php if ($a['allow_late_submissions']): ?>
                        <span class="text-warning-emphasis"><i class="bi bi-clock-history me-1"></i>Late OK</span>
                    <?php endif; ?>
                    <?php if (!empty($refs)): ?>
                        <span><i class="bi bi-paperclip me-1"></i><?php echo count($refs); ?> ref <?php echo count($refs) === 1 ? 'file' : 'files'; ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Action buttons -->
            <div class="d-flex gap-1 flex-shrink-0 flex-wrap justify-content-end">
                <?php if ($totalSubm > 0): ?>
                <a href="lms_assignments?section_subject_id=<?php echo $sectionSubjectId; ?>&view_submissions=<?php echo $aid; ?>"
                   class="btn btn-sm btn-outline-primary position-relative" title="View submissions">
                    <i class="bi bi-inbox-fill"></i>
                    <?php if ($ungradedCt > 0): ?>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:.6rem;"><?php echo $ungradedCt; ?></span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>
                <button class="btn btn-sm btn-outline-secondary toggle-publish-btn"
                        data-assignment-id="<?php echo $aid; ?>"
                        data-section-subject-id="<?php echo $sectionSubjectId; ?>"
                        data-csrf="<?php echo htmlspecialchars($csrfToken); ?>"
                        title="<?php echo $isPub ? 'Unpublish' : 'Publish'; ?>"
                        id="toggle-pub-<?php echo $aid; ?>">
                    <i class="bi <?php echo $isPub ? 'bi-eye-slash' : 'bi-eye'; ?>"></i>
                </button>
                <button class="btn btn-sm btn-outline-secondary" title="Edit assignment"
                        onclick="openEditAssignment(<?php echo htmlspecialchars(json_encode($a), ENT_QUOTES); ?>)"
                        id="btn-edit-<?php echo $aid; ?>">
                    <i class="bi bi-pencil"></i>
                </button>
                <button class="btn btn-sm btn-outline-danger" title="Delete assignment"
                        onclick="confirmDeleteAssignment(<?php echo $aid; ?>, '<?php echo htmlspecialchars(addslashes($a['title'])); ?>')"
                        id="btn-delete-<?php echo $aid; ?>">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        </div>

        <!-- Instructions preview -->
        <?php if (!empty($a['instructions'])): ?>
        <div class="px-3 pb-2">
            <p class="text-muted mb-0 instructions-preview" style="font-size:.82rem;line-height:1.5;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">
                <?php echo nl2br(htmlspecialchars($a['instructions'])); ?>
            </p>
        </div>
        <?php endif; ?>

        <!-- Reference files + attach -->
        <div class="border-top px-3 py-2 d-flex align-items-center gap-2 flex-wrap" style="background:#f8fafc;">
            <?php foreach ($refs as $rf): ?>
            <span class="badge bg-light text-muted border d-inline-flex align-items-center gap-1" style="font-size:.73rem;">
                <i class="bi bi-paperclip"></i>
                <?php echo htmlspecialchars($rf['file_name']); ?>
                <button type="button"
                        class="btn-close btn-close-sm ms-1"
                        style="font-size:.5rem;"
                        onclick="confirmDeleteRefFile(<?php echo (int)$rf['id']; ?>, <?php echo $aid; ?>, '<?php echo htmlspecialchars(addslashes($rf['file_name'])); ?>')"
                        title="Remove file"></button>
            </span>
            <?php endforeach; ?>
            <button class="btn btn-xs btn-outline-secondary" style="font-size:.72rem;padding:.2rem .5rem;"
                    onclick="openRefFileUpload(<?php echo $aid; ?>, '<?php echo htmlspecialchars(addslashes($a['title'])); ?>')"
                    title="Attach reference file">
                <i class="bi bi-paperclip me-1"></i>Attach File
            </button>
            <?php if ($totalSubm > 0): ?>
            <a href="lms_assignments?section_subject_id=<?php echo $sectionSubjectId; ?>&view_submissions=<?php echo $aid; ?>"
               class="btn btn-xs ms-auto" style="font-size:.72rem;padding:.2rem .6rem;background:var(--brand-primary);color:#fff;border-radius:6px;">
                <i class="bi bi-people-fill me-1"></i>
                <?php echo $totalSubm; ?> submission<?php echo $totalSubm === 1 ? '' : 's'; ?>
                <?php if ($ungradedCt > 0): ?>
                <span class="badge bg-warning text-dark ms-1" style="font-size:.6rem;"><?php echo $ungradedCt; ?> ungraded</span>
                <?php endif; ?>
            </a>
            <?php else: ?>
            <span class="text-muted ms-auto" style="font-size:.73rem;"><i class="bi bi-inbox me-1"></i>No submissions yet</span>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>

<?php endif; // assignments not empty ?>
    </div>
</div>

<?php else: ?>
<!-- ════════════════════════════════════════════════════════════════════
     SUBMISSIONS VIEW (for one assignment)
     ════════════════════════════════════════════════════════════════════ -->
<div class="card card-premium shadow-sm mb-4 border-0" style="border-left: 4px solid var(--brand-primary, #008080) !important; border-radius: 10px; background: #ffffff;">
    <div class="card-body card-body-premium p-3.5">
        <div class="d-flex justify-content-between align-items-start align-items-md-center flex-wrap gap-3">
            <div>
                <div class="text-muted small text-uppercase fw-bold mb-1" style="font-size: 0.7rem; letter-spacing: 0.5px;">
                    Assignment Submissions
                </div>
                <h5 class="m-0 fw-bold text-navy-alt">
                    <?php echo htmlspecialchars($viewingSubmissions['title']); ?>
                </h5>
                <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
                    <span class="badge bg-light text-navy border px-2.5 py-1">
                        <i class="bi bi-trophy text-brand-primary me-1"></i>Max Score: <?php echo $viewingSubmissions['max_score'] !== null ? number_format((float)$viewingSubmissions['max_score'], 2) . ' pts' : 'Not set'; ?>
                    </span>
                    <span class="badge bg-secondary-subtle text-brand-primary border px-2.5 py-1 fw-semibold">
                        <i class="bi bi-people-fill me-1"></i><?php echo count($submissions); ?> Submitted
                    </span>
                </div>
            </div>
            <a href="lms_assignments?section_subject_id=<?php echo $sectionSubjectId; ?>" class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-1 shadow-sm">
                <i class="bi bi-arrow-left"></i> Back to All Assignments
            </a>
        </div>
    </div>
</div>

<div class="card card-premium shadow-sm border-0 mb-4" style="border-radius: 12px; overflow: hidden;">
    <div class="card-header card-header-premium d-flex justify-content-between align-items-center gap-3 flex-wrap">
        <h5 class="card-title m-0 fw-semibold text-navy-alt">
            <i class="bi bi-inbox me-1.5 text-brand-primary"></i>
            Cadet Submissions
        </h5>
        <span class="badge bg-secondary-subtle text-brand-primary border">
            <?php echo count($submissions); ?> Total
        </span>
    </div>
    <div class="card-body card-body-premium p-0">
        <?php if (empty($submissions)): ?>
            <div class="text-center py-5">
                <div class="mb-3">
                    <span class="d-inline-flex align-items-center justify-content-center bg-secondary-subtle text-brand-primary rounded-circle" style="width: 56px; height: 56px; font-size: 1.5rem;">
                        <i class="bi bi-inbox"></i>
                    </span>
                </div>
                <h5 class="text-navy-alt fw-bold mb-1">No submissions yet</h5>
                <p class="text-muted small mb-0">Students haven't submitted files for this coursework item yet.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive w-100">
                <table class="table table-hover align-middle m-0" style="width: 100%; font-size: .88rem;">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4" style="padding-left: 24px !important;">Cadet Student</th>
                            <th>Submitted At</th>
                            <th>File Attachment</th>
                            <th>Score</th>
                            <th>Feedback</th>
                            <th>Status</th>
                            <th class="pe-4 text-end" style="padding-right: 24px !important;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($submissions as $subm):
                        $isGraded  = $subm['graded_at'] !== null || $subm['score'] !== null || $subm['feedback'] !== null;
                        $maxScore  = $viewingSubmissions['max_score'];
                        $scoreDisplay = $subm['score'] !== null
                            ? number_format((float)$subm['score'], 2) . ($maxScore !== null ? ' / ' . number_format((float)$maxScore, 2) : '')
                            : '—';
                    ?>
                    <tr id="subm-row-<?php echo (int)$subm['id']; ?>">
                        <td class="ps-4" style="padding-left: 24px !important;">
                            <div class="fw-semibold text-navy-alt"><?php echo htmlspecialchars($subm['student_name']); ?></div>
                            <div class="text-muted small" style="font-size:.75rem;">@<?php echo htmlspecialchars($subm['username']); ?></div>
                        </td>
                        <td class="text-muted" style="white-space:nowrap;">
                            <div><?php echo date('M j, Y', strtotime($subm['submitted_at'])); ?></div>
                            <div class="text-muted small"><?php echo date('g:i A', strtotime($subm['submitted_at'])); ?></div>
                        </td>
                        <td>
                            <a href="lms_submission_file?submission_id=<?php echo (int)$subm['id']; ?>"
                               class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1 text-nowrap"
                               target="_blank" title="Download submission">
                                <i class="bi bi-file-earmark-arrow-down"></i>
                                <?php echo htmlspecialchars($subm['file_name']); ?>
                            </a>
                        </td>
                        <td class="fw-semibold <?php echo $subm['score'] !== null ? 'text-success' : 'text-muted'; ?>">
                            <?php echo htmlspecialchars($scoreDisplay); ?>
                        </td>
                        <td class="text-muted" style="max-width:220px;">
                            <?php if (!empty($subm['feedback'])): ?>
                                <span title="<?php echo htmlspecialchars($subm['feedback']); ?>"
                                      style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">
                                    <?php echo htmlspecialchars($subm['feedback']); ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted fst-italic">No feedback yet</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isGraded): ?>
                                <span class="badge bg-success-subtle text-success border border-success-subtle px-2.5 py-1" style="font-size:.72rem;">Graded</span>
                                <?php if ($subm['graded_at']): ?>
                                <div class="text-muted mt-0.5" style="font-size:.7rem;"><?php echo date('M j, g:i A', strtotime($subm['graded_at'])); ?></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2.5 py-1" style="font-size:.72rem;">Needs Grading</span>
                            <?php endif; ?>
                        </td>
                        <td class="pe-4 text-end" style="padding-right: 24px !important;">
                            <button class="btn btn-sm btn-brand-primary d-inline-flex align-items-center gap-1 shadow-sm"
                                    onclick="openGradeModal(<?php echo htmlspecialchars(json_encode([
                                        'id'       => (int)$subm['id'],
                                        'student'  => $subm['student_name'],
                                        'score'    => $subm['score'],
                                        'feedback' => $subm['feedback'],
                                        'max_score'=> $viewingSubmissions['max_score'],
                                    ]), ENT_QUOTES); ?>)"
                                    id="btn-grade-<?php echo (int)$subm['id']; ?>">
                                <i class="bi bi-check2-circle"></i><?php echo $isGraded ? 'Re-grade' : 'Grade'; ?>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; // submissions empty ?>
    </div>
</div>

<?php endif; // viewingSubmissions ?>

<!-- ═══════════════════════════ MODALS ═══════════════════════════════════════ -->

<!-- Create Assignment Modal -->
<div class="modal fade" id="modal-create-assignment" tabindex="-1" aria-labelledby="modal-create-assignment-label">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="../actions/lms_teacher_assignment_actions" id="form-create-assignment">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="create_assignment">
                <input type="hidden" name="section_subject_id" value="<?php echo $sectionSubjectId; ?>">
                <div class="modal-header border-0" style="background:var(--brand-primary);color:#fff;">
                    <h5 class="modal-title" id="modal-create-assignment-label"><i class="bi bi-pencil-square me-2"></i>New Assignment</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
                            <input type="text" name="title" class="form-control" required maxlength="150" placeholder="e.g. Research Paper on Maritime Safety">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold">Type</label>
                            <select name="assignment_type" class="form-select">
                                <option value="assignment">Assignment</option>
                                <option value="activity">Activity</option>
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold">Due Date & Time <span class="text-danger">*</span></label>
                            <input type="datetime-local" name="due_at" class="form-control" required>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold">Maximum Score</label>
                            <input type="number" name="max_score" class="form-control" min="0" step="0.01" placeholder="e.g. 100 (leave blank if not scored)">
                        </div>
                        <div class="col-sm-6 d-flex align-items-end gap-3 pb-1">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="allow_late_submissions" id="create-allow-late" value="1">
                                <label class="form-check-label small" for="create-allow-late">Allow late submissions</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_published" id="create-is-pub" value="1" checked>
                                <label class="form-check-label small" for="create-is-pub">Publish immediately</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Instructions <span class="text-danger">*</span></label>
                            <textarea name="instructions" class="form-control" rows="5" required
                                      placeholder="Provide detailed instructions for students..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brand-primary"><i class="bi bi-check-lg me-1"></i>Create Assignment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Assignment Modal -->
<div class="modal fade" id="modal-edit-assignment" tabindex="-1" aria-labelledby="modal-edit-assignment-label">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="../actions/lms_teacher_assignment_actions" id="form-edit-assignment">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="edit_assignment">
                <input type="hidden" name="section_subject_id" value="<?php echo $sectionSubjectId; ?>">
                <input type="hidden" name="assignment_id" id="edit-assignment-id">
                <div class="modal-header border-0" style="background:var(--brand-primary);color:#fff;">
                    <h5 class="modal-title" id="modal-edit-assignment-label"><i class="bi bi-pencil me-2"></i>Edit Assignment</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
                            <input type="text" name="title" id="edit-title" class="form-control" required maxlength="150">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold">Type</label>
                            <select name="assignment_type" id="edit-type" class="form-select">
                                <option value="assignment">Assignment</option>
                                <option value="activity">Activity</option>
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold">Due Date & Time <span class="text-danger">*</span></label>
                            <input type="datetime-local" name="due_at" id="edit-due" class="form-control" required>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold">Maximum Score</label>
                            <input type="number" name="max_score" id="edit-max-score" class="form-control" min="0" step="0.01">
                        </div>
                        <div class="col-sm-6 d-flex align-items-end gap-3 pb-1">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="allow_late_submissions" id="edit-allow-late" value="1">
                                <label class="form-check-label small" for="edit-allow-late">Allow late submissions</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_published" id="edit-is-pub" value="1">
                                <label class="form-check-label small" for="edit-is-pub">Published</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Instructions <span class="text-danger">*</span></label>
                            <textarea name="instructions" id="edit-instructions" class="form-control" rows="5" required></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brand-primary"><i class="bi bi-check-lg me-1"></i>Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Assignment (hidden form) -->
<form method="POST" action="../actions/lms_teacher_assignment_actions" id="form-delete-assignment" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
    <input type="hidden" name="action" value="delete_assignment">
    <input type="hidden" name="section_subject_id" value="<?php echo $sectionSubjectId; ?>">
    <input type="hidden" name="assignment_id" id="delete-assignment-id">
</form>

<!-- Ref File Upload Modal -->
<div class="modal fade" id="modal-ref-upload" tabindex="-1" aria-labelledby="modal-ref-upload-label">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="../actions/lms_teacher_assignment_actions" enctype="multipart/form-data" id="form-ref-upload">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="upload_ref_file">
                <input type="hidden" name="section_subject_id" value="<?php echo $sectionSubjectId; ?>">
                <input type="hidden" name="assignment_id" id="ref-upload-assignment-id">
                <div class="modal-header border-0" style="background:var(--brand-primary);color:#fff;">
                    <h5 class="modal-title" id="modal-ref-upload-label"><i class="bi bi-paperclip me-2"></i>Attach Reference File</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <p class="text-muted small mb-3">Attaching file to: <strong id="ref-upload-title"></strong></p>
                    <label class="form-label fw-semibold">File <span class="text-danger">*</span></label>
                    <input type="file" name="ref_file" class="form-control" required
                           accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt,.csv,.rtf,.jpg,.jpeg,.png,.gif,.webp,.zip">
                    <div class="form-text">PDF, Office docs, images, ZIP — max 50 MB.</div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brand-primary"><i class="bi bi-upload me-1"></i>Attach</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Ref File (hidden form) -->
<form method="POST" action="../actions/lms_teacher_assignment_actions" id="form-delete-ref" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
    <input type="hidden" name="action" value="delete_ref_file">
    <input type="hidden" name="section_subject_id" value="<?php echo $sectionSubjectId; ?>">
    <input type="hidden" name="assignment_id" id="delete-ref-assignment-id">
    <input type="hidden" name="file_id" id="delete-ref-file-id">
</form>

<!-- Grade Submission Modal -->
<div class="modal fade" id="modal-grade" tabindex="-1" aria-labelledby="modal-grade-label">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="../actions/lms_teacher_assignment_actions" id="form-grade">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="grade_submission">
                <input type="hidden" name="section_subject_id" value="<?php echo $sectionSubjectId; ?>">
                <input type="hidden" name="assignment_id" value="<?php echo $viewSubmissionsId; ?>">
                <input type="hidden" name="submission_id" id="grade-submission-id">
                <div class="modal-header border-0" style="background:var(--brand-primary);color:#fff;">
                    <h5 class="modal-title" id="modal-grade-label"><i class="bi bi-check2-circle me-2"></i>Grade Submission</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <p class="mb-3">
                        Student: <strong id="grade-student-name"></strong>
                    </p>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Score</label>
                        <div class="input-group">
                            <input type="number" name="score" id="grade-score" class="form-control" min="0" step="0.01" placeholder="Enter score">
                            <span class="input-group-text" id="grade-max-score-label">/ —</span>
                        </div>
                        <div class="form-text">Leave blank to save feedback only.</div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-semibold">Feedback</label>
                        <textarea name="feedback" id="grade-feedback" class="form-control" rows="4"
                                  placeholder="Optional feedback for the student..."></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brand-primary"><i class="bi bi-check-lg me-1"></i>Save Grade</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// ── Edit assignment ────────────────────────────────────────────────────────────
function openEditAssignment(a) {
    document.getElementById('edit-assignment-id').value  = a.id;
    document.getElementById('edit-title').value          = a.title;
    document.getElementById('edit-instructions').value   = a.instructions;
    document.getElementById('edit-type').value           = a.assignment_type;
    document.getElementById('edit-max-score').value      = a.max_score !== null ? a.max_score : '';
    document.getElementById('edit-allow-late').checked   = parseInt(a.allow_late_submissions) === 1;
    document.getElementById('edit-is-pub').checked       = parseInt(a.is_published) === 1;
    // Format due_at for datetime-local input
    const raw = a.due_at.replace(' ', 'T').substring(0, 16);
    document.getElementById('edit-due').value = raw;
    new bootstrap.Modal(document.getElementById('modal-edit-assignment')).show();
}

// ── Delete assignment ──────────────────────────────────────────────────────────
function confirmDeleteAssignment(id, title) {
    if (!confirm('Delete assignment "' + title + '"?\n\nAll student submissions will also be deleted. This cannot be undone.')) return;
    document.getElementById('delete-assignment-id').value = id;
    document.getElementById('form-delete-assignment').submit();
}

// ── Toggle publish ─────────────────────────────────────────────────────────────
document.querySelectorAll('.toggle-publish-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        const aid = this.dataset.assignmentId;
        const ssid = this.dataset.sectionSubjectId;
        const csrf = this.dataset.csrf;
        const el = this;
        el.disabled = true;
        fetch('../actions/lms_teacher_assignment_actions', {
            method: 'POST',
            body: new URLSearchParams({
                action: 'toggle_publish',
                assignment_id: aid,
                section_subject_id: ssid,
                csrf_token: csrf
            })
        })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                const pub = parseInt(data.is_published) === 1;
                const icon = el.querySelector('i');
                icon.className = pub ? 'bi bi-eye-slash' : 'bi bi-eye';
                el.title = pub ? 'Unpublish' : 'Publish';
                // Update badge
                const card = document.getElementById('assignment-card-' + aid);
                if (card) {
                    const badges = card.querySelectorAll('.badge');
                    badges.forEach(b => {
                        if (b.classList.contains('bg-success-subtle') || b.classList.contains('bg-secondary-subtle')) {
                            b.className = pub
                                ? 'badge bg-success-subtle text-success border border-success-subtle'
                                : 'badge bg-secondary-subtle text-secondary border';
                            b.style.fontSize = '.6rem';
                            b.textContent = pub ? 'Published' : 'Draft';
                        }
                    });
                }
            } else {
                alert(data.error || 'Could not toggle publish status.');
            }
        })
        .catch(() => alert('Network error. Please try again.'))
        .finally(() => { el.disabled = false; });
    });
});

// ── Reference file upload ──────────────────────────────────────────────────────
function openRefFileUpload(assignmentId, title) {
    document.getElementById('ref-upload-assignment-id').value = assignmentId;
    document.getElementById('ref-upload-title').textContent   = title;
    new bootstrap.Modal(document.getElementById('modal-ref-upload')).show();
}

// ── Delete reference file ──────────────────────────────────────────────────────
function confirmDeleteRefFile(fileId, assignmentId, fileName) {
    if (!confirm('Remove reference file "' + fileName + '"?')) return;
    document.getElementById('delete-ref-file-id').value       = fileId;
    document.getElementById('delete-ref-assignment-id').value = assignmentId;
    document.getElementById('form-delete-ref').submit();
}

// ── Grade submission ───────────────────────────────────────────────────────────
function openGradeModal(data) {
    document.getElementById('grade-submission-id').value    = data.id;
    document.getElementById('grade-student-name').textContent = data.student;
    document.getElementById('grade-score').value            = data.score !== null ? data.score : '';
    document.getElementById('grade-feedback').value         = data.feedback !== null ? data.feedback : '';
    const maxLabel = document.getElementById('grade-max-score-label');
    maxLabel.textContent = data.max_score !== null ? '/ ' + parseFloat(data.max_score).toFixed(2) : '/ —';
    if (data.max_score !== null) {
        document.getElementById('grade-score').max = parseFloat(data.max_score);
    } else {
        document.getElementById('grade-score').removeAttribute('max');
    }
    new bootstrap.Modal(document.getElementById('modal-grade')).show();
}

// ── Auto-open create modal on highlight ───────────────────────────────────────
<?php if ($highlightId > 0): ?>
(function() {
    const card = document.getElementById('assignment-card-<?php echo $highlightId; ?>');
    if (card) {
        card.scrollIntoView({ behavior: 'smooth', block: 'center' });
        card.style.transition = 'box-shadow .4s';
        card.style.boxShadow  = '0 0 0 4px rgba(var(--brand-primary-rgb,14,116,144),.25)';
        setTimeout(() => { card.style.boxShadow = ''; }, 2000);
    }
})();
<?php endif; ?>
</script>

<?php else: ?>
<!-- ════════════════════════════════════════════════════════════════════
     OVERVIEW MODE (no section_subject_id)
     ════════════════════════════════════════════════════════════════════ -->
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb mb-0" style="font-size:.82rem;">
        <li class="breadcrumb-item"><a href="lms" class="text-decoration-none text-brand-primary"><i class="bi bi-journal-bookmark-fill me-1"></i>LMS Dashboard</a></li>
        <li class="breadcrumb-item active" aria-current="page">Assignments</li>
    </ol>
</nav>

<div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h3 class="m-0 text-navy-alt">Assignments Overview</h3>
        <p class="text-muted small m-0">Select an assigned subject to manage coursework, upload references, and grade submissions.</p>
    </div>
    <span class="badge bg-secondary-subtle text-brand-primary border border-secondary px-2.5 py-1 text-uppercase fw-semibold" style="font-size: 0.7rem;">
        <?php echo count($allSubjects); ?> Subject<?php echo count($allSubjects) !== 1 ? 's' : ''; ?>
    </span>
</div>

<div class="card card-premium shadow-sm border-0 mb-4" style="border-radius: 12px; overflow: hidden;">
    <div class="card-header card-header-premium d-flex justify-content-between align-items-center gap-3 flex-wrap">
        <h5 class="card-title m-0 fw-semibold text-navy-alt">
            <i class="bi bi-pencil-square me-1.5 text-brand-primary"></i>
            Assigned Subjects
        </h5>
        <span class="text-muted small"><?php echo count($allSubjects); ?> Available</span>
    </div>
    <div class="card-body card-body-premium p-4">
        <?php if (empty($allSubjects)): ?>
            <div class="text-center py-5">
                <div class="mb-3">
                    <span class="d-inline-flex align-items-center justify-content-center bg-secondary-subtle text-brand-primary rounded-circle" style="width: 56px; height: 56px; font-size: 1.5rem;">
                        <i class="bi bi-journal-x"></i>
                    </span>
                </div>
                <h5 class="text-navy-alt fw-bold mb-1">No subjects assigned</h5>
                <p class="text-muted small mb-3">You do not have any active subject assignments for the current academic term.</p>
                <a href="my_classes" class="btn btn-outline-secondary btn-sm"><i class="bi bi-door-open me-1"></i>My Classes</a>
            </div>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($allSubjects as $s):
                    $sid  = (int)$s['section_subject_id'];
                    $total= (int)$s['assignment_count'];
                    $pub  = (int)$s['published_count'];
                    $colClass = count($allSubjects) === 1 ? 'col-12' : (count($allSubjects) === 2 ? 'col-12 col-md-6' : 'col-12 col-md-6 col-xl-4');
                ?>
                <div class="<?php echo $colClass; ?>">
                    <div class="card h-100 shadow-sm border" style="border-radius: 10px; border-color: #e2e8f0; background: #ffffff;">
                        <div class="card-body p-3.5 d-flex flex-column justify-content-between">
                            <div>
                                <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                    <span class="badge bg-secondary-subtle text-brand-primary border fw-semibold px-2 py-1" style="font-size: 0.72rem;">
                                        <?php echo htmlspecialchars($s['subject_code']); ?>
                                    </span>
                                    <span class="badge bg-light text-navy-alt border px-2 py-1 fw-semibold" style="font-size: 0.7rem;">
                                        <?php echo $total; ?> Coursework
                                    </span>
                                </div>
                                <h6 class="fw-bold text-navy-alt mb-1 text-truncate" title="<?php echo htmlspecialchars($s['subject_name']); ?>">
                                    <?php echo htmlspecialchars($s['subject_name']); ?>
                                </h6>
                                <div class="text-muted small mb-2 text-truncate" style="font-size: 0.78rem;">
                                    <?php echo htmlspecialchars($s['section_name']); ?>
                                    <?php if (!empty($s['year_level'])): ?> &middot; <?php echo htmlspecialchars($s['year_level']); ?><?php endif; ?>
                                </div>
                            </div>
                            <div class="pt-3 border-top d-flex justify-content-between align-items-center text-muted small" style="font-size: 0.75rem; border-color: #f1f5f9 !important;">
                                <div class="d-flex gap-2">
                                    <span class="text-success"><i class="bi bi-eye me-1"></i><?php echo $pub; ?> live</span>
                                    <span class="text-muted"><i class="bi bi-pencil me-1"></i><?php echo $total - $pub; ?> draft</span>
                                </div>
                                <a href="lms_assignments?section_subject_id=<?php echo $sid; ?>"
                                   class="btn btn-sm btn-brand-primary d-inline-flex align-items-center gap-1 shadow-sm text-nowrap" id="manage-assignments-<?php echo $sid; ?>">
                                    <i class="bi bi-pencil-square"></i> Manage
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php endif; // subjectMode ?>

<?php
require_once '../includes/footer.php';
