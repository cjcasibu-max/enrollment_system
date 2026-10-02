<?php
/**
 * Teacher LMS - Announcements Management (Phase 8)
 *
 * Two modes:
 *   With ?section_subject_id=N  -> Subject-scoped announcements manager
 *   Without                     -> Overview of all assigned subjects with announcement counts
 *
 * Security:
 *   requireLmsTeacherAccess()   -> role gate (faculty / admin)
 *   verifyTeacherOwnsSubject()  -> ownership gate (subject-scoped mode only)
 *
 * Data source:
 *   Directly reads/writes `lms_announcements`.
 *   Enrolled students see live announcements via `fetchLmsAnnouncementsForStudentSubject()`.
 *   Changes take immediate effect for enrolled students without any caching layer.
 *   Scoped strictly to `section_subject_id` - never cross-subject or system-wide.
 */

require_once '../includes/lms_access.php';

$teacher          = requireLmsTeacherAccess();
$userId           = (int)$teacher['user_id'];
$sectionSubjectId = (int)filter_input(INPUT_GET, 'section_subject_id', FILTER_VALIDATE_INT);
$highlightId      = (int)filter_input(INPUT_GET, 'highlight', FILTER_VALIDATE_INT);

// ── Mode detection ─────────────────────────────────────────────────────────────
$subjectMode = false;
$subject     = null;

if ($sectionSubjectId > 0) {
    $subject = verifyTeacherOwnsSubject($pdo, $userId, $sectionSubjectId);
    if (!$subject) {
        $_SESSION['flash_error'] = 'Access denied: you are not assigned to that subject.';
        header('Location: ' . resolveAppUrl('teacher/lms_announcements'));
        exit;
    }
    $subjectMode = true;
}

// ── Subject-scoped Mode: fetch announcements & metrics ─────────────────────────
$announcements        = [];
$enrolledStudentCount = 0;
$kpis = [
    'total'     => 0,
    'live'      => 0,
    'scheduled' => 0,
    'draft'     => 0,
    'important' => 0,
];

if ($subjectMode) {
    // 1. Fetch announcements
    $aStmt = $pdo->prepare(
        "SELECT a.id, a.section_subject_id, a.author_id, a.title, a.message,
                a.published_at, a.is_published, a.is_important, a.created_at, a.updated_at,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.username) AS author_name
         FROM lms_announcements a
         JOIN users u ON u.id = a.author_id
         WHERE a.section_subject_id = :ss
         ORDER BY a.is_important DESC,
                  CASE WHEN a.published_at IS NOT NULL THEN a.published_at ELSE a.created_at END DESC,
                  a.id DESC"
    );
    $aStmt->execute(['ss' => $sectionSubjectId]);
    $announcements = $aStmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Fetch enrolled students count
    $enrStmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT s.id)
         FROM section_subjects ss
         JOIN sections sec ON sec.id = ss.section_id
         JOIN enrollments e ON e.section_id = sec.id
         JOIN students s ON s.id = e.student_id
         WHERE ss.id = :ss_id
           AND s.enrollment_status = 'enrolled'
           AND e.status = 'enrolled'
           AND e.academic_term_id = s.academic_term_id
           AND e.academic_term_id = sec.academic_term_id"
    );
    $enrStmt->execute(['ss_id' => $sectionSubjectId]);
    $enrolledStudentCount = (int)$enrStmt->fetchColumn();

    // 3. Compute KPI metrics
    $nowTs = time();
    $kpis['total'] = count($announcements);
    foreach ($announcements as $ann) {
        $pubTs = !empty($ann['published_at']) ? strtotime($ann['published_at']) : null;
        if ((int)$ann['is_published'] === 1 && $pubTs !== null && $pubTs <= $nowTs) {
            $kpis['live']++;
        } elseif ((int)$ann['is_published'] === 1 && $pubTs !== null && $pubTs > $nowTs) {
            $kpis['scheduled']++;
        } else {
            $kpis['draft']++;
        }
        if (!empty($ann['is_important'])) {
            $kpis['important']++;
        }
    }
}

// ── Overview Mode: fetch all assigned subjects with counts ─────────────────────
$allSubjects = [];
if (!$subjectMode) {
    $allSubjects = fetchLmsTeacherSubjects($pdo, $userId);
    if (!empty($allSubjects)) {
        $ssIds = array_column($allSubjects, 'section_subject_id');
        $inPh  = implode(',', array_fill(0, count($ssIds), '?'));

        // Announcement counts per subject
        $cntStmt = $pdo->prepare(
            "SELECT section_subject_id,
                    COUNT(*) AS total,
                    SUM(CASE WHEN is_published = 1 AND published_at IS NOT NULL AND published_at <= NOW() THEN 1 ELSE 0 END) AS live_count,
                    SUM(CASE WHEN is_published = 1 AND published_at IS NOT NULL AND published_at > NOW() THEN 1 ELSE 0 END) AS scheduled_count,
                    SUM(CASE WHEN is_published = 0 THEN 1 ELSE 0 END) AS draft_count,
                    SUM(is_important) AS important_count
             FROM lms_announcements
             WHERE section_subject_id IN ($inPh)
             GROUP BY section_subject_id"
        );
        $cntStmt->execute($ssIds);
        $countsBySubject = [];
        foreach ($cntStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $countsBySubject[(int)$row['section_subject_id']] = $row;
        }

        // Enrolled students per subject
        $enrCountsStmt = $pdo->prepare(
            "SELECT ss.id AS section_subject_id, COUNT(DISTINCT s.id) AS enrolled_count
             FROM section_subjects ss
             JOIN sections sec ON sec.id = ss.section_id
             JOIN enrollments e ON e.section_id = sec.id
             JOIN students s ON s.id = e.student_id
             WHERE ss.id IN ($inPh)
               AND s.enrollment_status = 'enrolled'
               AND e.status = 'enrolled'
               AND e.academic_term_id = s.academic_term_id
               AND e.academic_term_id = sec.academic_term_id
             GROUP BY ss.id"
        );
        $enrCountsStmt->execute($ssIds);
        $enrCountsBySubject = [];
        foreach ($enrCountsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $enrCountsBySubject[(int)$row['section_subject_id']] = (int)$row['enrolled_count'];
        }

        // Latest announcement per subject
        $latestStmt = $pdo->prepare(
            "SELECT a.section_subject_id, a.title, a.published_at, a.is_published, a.is_important
             FROM lms_announcements a
             INNER JOIN (
                 SELECT section_subject_id, MAX(id) AS max_id
                 FROM lms_announcements
                 WHERE section_subject_id IN ($inPh)
                 GROUP BY section_subject_id
             ) latest ON latest.max_id = a.id"
        );
        $latestStmt->execute($ssIds);
        $latestAnnouncements = [];
        foreach ($latestStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $latestAnnouncements[(int)$row['section_subject_id']] = $row;
        }

        foreach ($allSubjects as &$s) {
            $sid = (int)$s['section_subject_id'];
            $s['announcement_total']     = (int)($countsBySubject[$sid]['total'] ?? 0);
            $s['announcement_live']      = (int)($countsBySubject[$sid]['live_count'] ?? 0);
            $s['announcement_scheduled'] = (int)($countsBySubject[$sid]['scheduled_count'] ?? 0);
            $s['announcement_draft']     = (int)($countsBySubject[$sid]['draft_count'] ?? 0);
            $s['announcement_important'] = (int)($countsBySubject[$sid]['important_count'] ?? 0);
            $s['enrolled_count']         = (int)($enrCountsBySubject[$sid] ?? 0);
            $s['latest_announcement']    = $latestAnnouncements[$sid] ?? null;
        }
        unset($s);
    }
}

// ── Also prepare assigned subjects list for modal dropdown (if overview) ──────
$teacherSubjectsList = $subjectMode ? [] : $allSubjects;
if ($subjectMode && empty($teacherSubjectsList)) {
    $teacherSubjectsList = fetchLmsTeacherSubjects($pdo, $userId);
}

$page_title = $subjectMode
    ? htmlspecialchars($subject['subject_code']) . ' — Announcements'
    : 'Subject Announcements — Instructor Management';

require_once '../includes/header.php';
$lmsActiveTab = 'announcements';
require_once __DIR__ . '/lms_navbar.php';
?>

<?php if ($subjectMode): ?>

    <!-- ════════════════════════════════════════════════════════════════════════
         SUBJECT MODE: ANNOUNCEMENTS MANAGEMENT FOR SPECIFIC SUBJECT
         ════════════════════════════════════════════════════════════════════════ -->
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb mb-0" style="font-size:.82rem;">
            <li class="breadcrumb-item"><a href="lms_announcements" class="text-decoration-none text-brand-primary">Announcements Overview</a></li>
            <li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars($subject['subject_code']); ?> — <?php echo htmlspecialchars($subject['section_name']); ?></li>
        </ol>
    </nav>

    <!-- Header Card -->
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
            <h1 class="h3 fw-bold text-navy-alt mb-1"><?php echo htmlspecialchars($subject['subject_name']); ?></h1>
            <p class="text-muted small mb-0">
                Announcements posted here are strictly visible to students enrolled in this section only.
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap align-items-center" style="gap: 8px;">
            <a href="lms_subject?section_subject_id=<?php echo $sectionSubjectId; ?>&section=overview" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Subject Hub
            </a>
            <a href="lms_announcements" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-grid-3x3-gap me-1"></i>All Subjects
            </a>
            <button type="button" class="btn btn-sm btn-brand-primary" data-bs-toggle="modal" data-bs-target="#modal-create-announcement">
                <i class="bi bi-megaphone-fill me-1"></i>Post Announcement
            </button>
        </div>
    </div>

    <!-- KPI Strip -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card card-premium shadow-sm border-0 h-100" style="padding: 18px 20px;">
                <div class="d-flex align-items-center" style="gap: 14px;">
                    <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px; background: rgba(0, 128, 128, 0.1); color: var(--brand-primary, #008080);">
                        <i class="bi bi-megaphone fs-5"></i>
                    </div>
                    <div class="d-flex flex-column" style="gap: 4px;">
                        <span class="text-muted text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.5px;">Total Posts</span>
                        <span class="fs-5 fw-bold text-navy-alt lh-1"><?php echo $kpis['total']; ?></span>
                        <span class="text-muted" style="font-size: 0.75rem;">announcements</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card card-premium shadow-sm border-0 h-100" style="padding: 18px 20px;">
                <div class="d-flex align-items-center" style="gap: 14px;">
                    <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px; background: rgba(25, 135, 84, 0.1); color: #198754;">
                        <i class="bi bi-broadcast fs-5"></i>
                    </div>
                    <div class="d-flex flex-column" style="gap: 4px;">
                        <span class="text-muted text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.5px;">Live</span>
                        <span class="fs-5 fw-bold text-success lh-1"><?php echo $kpis['live']; ?></span>
                        <span class="text-muted" style="font-size: 0.75rem;">published now</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card card-premium shadow-sm border-0 h-100" style="padding: 18px 20px;">
                <div class="d-flex align-items-center" style="gap: 14px;">
                    <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px; background: rgba(13, 110, 253, 0.1); color: #0d6efd;">
                        <i class="bi bi-clock-history fs-5"></i>
                    </div>
                    <div class="d-flex flex-column" style="gap: 4px;">
                        <span class="text-muted text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.5px;">Scheduled</span>
                        <span class="fs-5 fw-bold text-navy-alt lh-1"><?php echo $kpis['scheduled']; ?></span>
                        <span class="text-muted" style="font-size: 0.75rem;">future release</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card card-premium shadow-sm border-0 h-100" style="padding: 18px 20px;">
                <div class="d-flex align-items-center" style="gap: 14px;">
                    <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px; background: rgba(108, 117, 125, 0.1); color: #6c757d;">
                        <i class="bi bi-file-earmark fs-5"></i>
                    </div>
                    <div class="d-flex flex-column" style="gap: 4px;">
                        <span class="text-muted text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.5px;">Drafts</span>
                        <span class="fs-5 fw-bold text-navy-alt lh-1"><?php echo $kpis['draft']; ?></span>
                        <span class="text-muted" style="font-size: 0.75rem;">hidden draft</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card card-premium shadow-sm border-0 h-100" style="padding: 18px 20px;">
                <div class="d-flex align-items-center" style="gap: 14px;">
                    <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px; background: rgba(220, 53, 69, 0.1); color: #dc3545;">
                        <i class="bi bi-exclamation-triangle fs-5"></i>
                    </div>
                    <div class="d-flex flex-column" style="gap: 4px;">
                        <span class="text-muted text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.5px;">Important</span>
                        <span class="fs-5 fw-bold text-danger lh-1"><?php echo $kpis['important']; ?></span>
                        <span class="text-muted" style="font-size: 0.75rem;">pinned notices</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card card-premium shadow-sm border-0 h-100" style="padding: 18px 20px;">
                <div class="d-flex align-items-center" style="gap: 14px;">
                    <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px; background: rgba(13, 202, 240, 0.15); color: #087990;">
                        <i class="bi bi-people fs-5"></i>
                    </div>
                    <div class="d-flex flex-column" style="gap: 4px;">
                        <span class="text-muted text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.5px;">Recipients</span>
                        <span class="fs-5 fw-bold text-navy-alt lh-1"><?php echo $enrolledStudentCount; ?></span>
                        <span class="text-muted" style="font-size: 0.75rem;">students enrolled</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Search & Filter Controls -->
    <div class="card card-premium shadow-sm border-0 mb-4">
        <div class="card-body p-3">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div class="d-flex flex-wrap gap-1 align-items-center">
                    <span class="small text-muted me-1 fw-semibold">Filter:</span>
                    <button type="button" class="btn btn-sm btn-outline-secondary filter-btn active" data-filter="all">
                        All (<?php echo $kpis['total']; ?>)
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-success filter-btn" data-filter="live">
                        Live (<?php echo $kpis['live']; ?>)
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-info filter-btn" data-filter="scheduled">
                        Scheduled (<?php echo $kpis['scheduled']; ?>)
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary filter-btn" data-filter="draft">
                        Drafts (<?php echo $kpis['draft']; ?>)
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-danger filter-btn" data-filter="important">
                        Important (<?php echo $kpis['important']; ?>)
                    </button>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <div class="input-group input-group-sm" style="max-width:280px;">
                        <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-search"></i></span>
                        <input type="text" class="form-control border-start-0" id="announcement-search" placeholder="Search announcements...">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Announcements Feed -->
    <?php if (empty($announcements)): ?>
        <div class="card shadow-sm border-0 py-5 text-center">
            <div class="card-body">
                <i class="bi bi-megaphone" style="font-size:3.5rem;color:var(--brand-primary);opacity:.35;"></i>
                <h3 class="h5 fw-bold mt-3 mb-1">No Announcements Posted Yet</h3>
                <p class="text-muted small mx-auto mb-4" style="max-width:480px;">
                    Keep students informed about class updates, deadline reminders, schedule adjustments, or important notices.
                    Announcements posted here are instantly visible to enrolled students.
                </p>
                <button type="button" class="btn btn-brand-primary" data-bs-toggle="modal" data-bs-target="#modal-create-announcement">
                    <i class="bi bi-plus-circle me-1"></i>Create First Announcement
                </button>
            </div>
        </div>
    <?php else: ?>
        <div class="vstack gap-3" id="announcements-container">
            <?php foreach ($announcements as $ann): ?>
                <?php
                    $isLive      = false;
                    $isScheduled = false;
                    $isDraft     = ((int)$ann['is_published'] === 0);
                    $pubTs       = !empty($ann['published_at']) ? strtotime($ann['published_at']) : null;

                    if (!$isDraft) {
                        if ($pubTs !== null && $pubTs <= time()) {
                            $isLive = true;
                        } else {
                            $isScheduled = true;
                        }
                    }

                    $filterType = 'draft';
                    if ($isLive) {
                        $filterType = 'live';
                    } elseif ($isScheduled) {
                        $filterType = 'scheduled';
                    }
                    if (!empty($ann['is_important'])) {
                        $filterType .= ' important';
                    }

                    $isHighlighted = ($highlightId === (int)$ann['id']);
                ?>
                <article class="card card-premium shadow-sm border-0 announcement-card mb-3 <?php echo !empty($ann['is_important']) ? 'border-start border-4 border-danger' : ''; ?> <?php echo $isHighlighted ? 'ring-highlight' : ''; ?>"
                         id="announcement-<?php echo $ann['id']; ?>"
                         data-filter-type="<?php echo $filterType; ?>"
                         data-title="<?php echo htmlspecialchars(strtolower($ann['title'])); ?>"
                         data-content="<?php echo htmlspecialchars(strtolower($ann['message'])); ?>"
                         style="<?php echo $isHighlighted ? 'outline:2px solid #0b4f5c;background:#f9fdfd;' : ''; ?>">
                    <div class="card-body p-4">
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <?php if ($isLive): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1" title="Currently visible to enrolled students">
                                        <i class="bi bi-broadcast me-1"></i>Live to Students
                                    </span>
                                <?php elseif ($isScheduled): ?>
                                    <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle px-2 py-1" title="Scheduled to automatically publish on the set date/time">
                                        <i class="bi bi-clock-history me-1"></i>Scheduled: <?php echo htmlspecialchars(date('M j, Y g:i A', $pubTs)); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2 py-1" title="Hidden draft - students cannot see this">
                                        <i class="bi bi-file-earmark-lock me-1"></i>Draft (Hidden)
                                    </span>
                                <?php endif; ?>

                                <?php if (!empty($ann['is_important'])): ?>
                                    <span class="badge bg-danger text-white px-2 py-1">
                                        <i class="bi bi-exclamation-triangle-fill me-1"></i>Important Notice
                                    </span>
                                <?php endif; ?>

                                <span class="badge bg-light text-muted border px-2 py-1" style="font-size:.72rem;">
                                    <i class="bi bi-people me-1"></i>Visible to Section <?php echo htmlspecialchars($subject['section_name']); ?> only
                                </span>
                            </div>

                            <!-- Actions dropdown / buttons -->
                            <div class="d-flex align-items-center" style="gap: 8px;">
                                <!-- Publish / Unpublish Toggle button -->
                                <button type="button"
                                        class="btn btn-sm btn-toggle-publish <?php echo $ann['is_published'] ? 'btn-outline-secondary' : 'btn-outline-success'; ?>"
                                        data-id="<?php echo $ann['id']; ?>"
                                        title="<?php echo $ann['is_published'] ? 'Unpublish (hide from students)' : 'Publish (make visible & notify students)'; ?>">
                                    <i class="bi <?php echo $ann['is_published'] ? 'bi-eye-slash' : 'bi-send-check'; ?> me-1"></i>
                                    <span><?php echo $ann['is_published'] ? 'Unpublish' : 'Publish'; ?></span>
                                </button>

                                <!-- Edit button -->
                                <button type="button"
                                        class="btn btn-sm btn-outline-primary btn-edit-announcement"
                                        data-announcement='<?php echo htmlspecialchars(json_encode([
                                            'id'           => $ann['id'],
                                            'title'        => $ann['title'],
                                            'message'      => $ann['message'],
                                            'published_at' => $ann['published_at'] ? date('Y-m-d\TH:i', strtotime($ann['published_at'])) : '',
                                            'is_published' => (int)$ann['is_published'],
                                            'is_important' => (int)$ann['is_important'],
                                        ]), ENT_QUOTES, 'UTF-8'); ?>'
                                        title="Edit Announcement">
                                    <i class="bi bi-pencil-square me-1"></i>Edit
                                </button>

                                <!-- Delete button -->
                                <button type="button"
                                        class="btn btn-sm btn-outline-danger btn-delete-announcement"
                                        data-id="<?php echo $ann['id']; ?>"
                                        data-title="<?php echo htmlspecialchars($ann['title'], ENT_QUOTES, 'UTF-8'); ?>"
                                        title="Delete Announcement">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Title -->
                        <h2 class="h5 fw-bold mb-2 text-dark">
                            <?php echo htmlspecialchars($ann['title']); ?>
                        </h2>

                        <!-- Metadata -->
                        <div class="small text-muted mb-3 d-flex flex-wrap align-items-center gap-2">
                            <span><i class="bi bi-person-circle me-1"></i>Posted by <?php echo htmlspecialchars($ann['author_name']); ?></span>
                            <span>•</span>
                            <?php if (!empty($ann['published_at'])): ?>
                                <span><i class="bi bi-calendar-event me-1"></i>Publication Date: <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($ann['published_at']))); ?></span>
                            <?php else: ?>
                                <span><i class="bi bi-calendar-plus me-1"></i>Created: <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($ann['created_at']))); ?></span>
                            <?php endif; ?>
                            <?php if ($ann['updated_at'] !== $ann['created_at']): ?>
                                <span class="text-muted" style="font-size:.75rem;">(Updated <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($ann['updated_at']))); ?>)</span>
                            <?php endif; ?>
                        </div>

                        <!-- Message Body -->
                        <div class="p-3 rounded bg-light border announcement-body" style="font-size:.95rem;line-height:1.6;white-space:pre-line;">
                            <?php echo htmlspecialchars($ann['message']); ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php else: ?>

    <!-- ════════════════════════════════════════════════════════════════════════
         OVERVIEW MODE: ALL ASSIGNED COURSES & ANNOUNCEMENTS OVERVIEW
         ════════════════════════════════════════════════════════════════════════ -->
    <div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge" style="background:var(--brand-primary, #008080);font-size:.75rem;letter-spacing:.04em;text-transform:uppercase;">
                    Instructor Management
                </span>
            </div>
            <h1 class="h3 fw-bold text-navy-alt mb-1">Subject Announcements</h1>
            <p class="text-muted small mb-0">
                Publish course announcements for your assigned classes. Announcements are strictly delivered to enrolled students in each subject.
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap align-items-center" style="gap: 8px;">
            <a href="lms" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-grid-fill me-1"></i>LMS Dashboard
            </a>
            <button type="button" class="btn btn-sm btn-brand-primary" data-bs-toggle="modal" data-bs-target="#modal-create-announcement">
                <i class="bi bi-megaphone-fill me-1"></i>Post Announcement
            </button>
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
                    <i class="bi bi-megaphone me-2 text-brand-primary"></i>Assigned Subjects &amp; Announcements
                </h2>
                <span class="badge bg-light text-muted border"><?php echo count($allSubjects); ?> Subjects</span>
            </div>
            <div class="card-body p-4">
                <?php 
                    $colClass = count($allSubjects) === 1 ? 'col-12' : (count($allSubjects) === 2 ? 'col-12 col-md-6' : 'col-12 col-md-6 col-xl-4');
                ?>
                <div class="row g-3">
                    <?php foreach ($allSubjects as $subj): ?>
                        <div class="<?php echo $colClass; ?>">
                            <div class="card border h-100 shadow-sm" style="border-color:#e2e8f0 !important; border-radius: 10px;">
                                <div class="card-body p-4 d-flex flex-column justify-content-between">
                                    <div>
                                        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                            <span class="text-uppercase fw-bold text-brand-primary" style="font-size:.72rem;letter-spacing:.06em;">
                                                <?php echo htmlspecialchars($subj['subject_code']); ?>
                                            </span>
                                            <span class="badge bg-light text-muted border px-2 py-1" style="font-size:.75rem;">
                                                Section <?php echo htmlspecialchars($subj['section_name']); ?>
                                            </span>
                                        </div>

                                        <h3 class="h6 fw-bold mb-1 text-navy-alt">
                                            <?php echo htmlspecialchars($subj['subject_name']); ?>
                                        </h3>
                                        <div class="small text-muted mb-3">
                                            <?php echo htmlspecialchars($subj['term_name'] ?? 'Current Term'); ?>
                                        </div>

                                        <!-- Metrics -->
                                        <div class="bg-light rounded p-2 mb-3 border">
                                            <div class="row g-1 text-center" style="font-size:.78rem;">
                                                <div class="col-4 border-end">
                                                    <div class="fw-bold text-dark"><?php echo $subj['announcement_total']; ?></div>
                                                    <div class="text-muted" style="font-size:.7rem;">Total</div>
                                                </div>
                                                <div class="col-4 border-end">
                                                    <div class="fw-bold text-success"><?php echo $subj['announcement_live']; ?></div>
                                                    <div class="text-muted" style="font-size:.7rem;">Live</div>
                                                </div>
                                                <div class="col-4">
                                                    <div class="fw-bold text-primary"><?php echo $subj['enrolled_count']; ?></div>
                                                    <div class="text-muted" style="font-size:.7rem;">Students</div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Latest Announcement preview -->
                                        <div class="mb-3 small flex-grow-1">
                                            <?php if (!empty($subj['latest_announcement'])): ?>
                                                <div class="text-muted fw-semibold mb-1" style="font-size:.72rem;text-transform:uppercase;">Latest Announcement:</div>
                                                <div class="p-2 rounded border bg-white text-truncate">
                                                    <?php if (!empty($subj['latest_announcement']['is_important'])): ?>
                                                        <span class="badge bg-danger-subtle text-danger me-1">Important</span>
                                                    <?php endif; ?>
                                                    <span class="fw-medium text-dark"><?php echo htmlspecialchars($subj['latest_announcement']['title']); ?></span>
                                                </div>
                                            <?php else: ?>
                                                <div class="text-muted fst-italic">No announcements posted yet.</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- Actions -->
                                    <div class="d-flex gap-2 mt-auto" style="gap: 8px;">
                                        <a href="lms_announcements?section_subject_id=<?php echo $subj['section_subject_id']; ?>" class="btn btn-sm btn-brand-primary flex-grow-1">
                                            <i class="bi bi-megaphone me-1"></i>Manage
                                        </a>
                                        <button type="button" class="btn btn-sm btn-outline-secondary btn-quick-post"
                                                data-ss-id="<?php echo $subj['section_subject_id']; ?>"
                                                data-ss-name="<?php echo htmlspecialchars($subj['subject_code'] . ' — ' . $subj['section_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                title="Quick Post Announcement">
                                            <i class="bi bi-plus-lg"></i>
                                        </button>
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
     MODALS: CREATE, EDIT, DELETE
     ════════════════════════════════════════════════════════════════════════ -->

<!-- 1. CREATE ANNOUNCEMENT MODAL -->
<div class="modal fade" id="modal-create-announcement" tabindex="-1" aria-labelledby="modalCreateTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form method="post" action="../actions/lms_teacher_announcement_actions" class="modal-content shadow">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
            <input type="hidden" name="action" value="create_announcement">

            <div class="modal-header text-white" style="background:var(--brand-primary,#0b4f5c);">
                <h5 class="modal-title fw-bold" id="modalCreateTitle">
                    <i class="bi bi-megaphone-fill me-2"></i>Post Subject Announcement
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body p-4">
                <!-- Subject selection -->
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="create-ss-id">Target Course / Subject <span class="text-danger">*</span></label>
                    <?php if ($subjectMode): ?>
                        <input type="hidden" name="section_subject_id" value="<?php echo $sectionSubjectId; ?>">
                        <div class="form-control bg-light">
                            <strong><?php echo htmlspecialchars($subject['subject_code']); ?></strong> — <?php echo htmlspecialchars($subject['subject_name']); ?> (Section: <?php echo htmlspecialchars($subject['section_name']); ?>)
                        </div>
                    <?php else: ?>
                        <select class="form-select" name="section_subject_id" id="create-ss-id" required>
                            <option value="">— Select an Assigned Subject —</option>
                            <?php foreach ($teacherSubjectsList as $s): ?>
                                <option value="<?php echo $s['section_subject_id']; ?>">
                                    <?php echo htmlspecialchars($s['subject_code']); ?> — <?php echo htmlspecialchars($s['subject_name']); ?> (Section: <?php echo htmlspecialchars($s['section_name']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <div class="form-text text-muted">
                        <i class="bi bi-lock me-1"></i>Announcements are strictly isolated to enrolled students in this specific course and section.
                    </div>
                </div>

                <!-- Title -->
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="create-title">Announcement Title <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="title" id="create-title" maxlength="150" required placeholder="e.g. Schedule Change for Next Week's Lab Session">
                </div>

                <!-- Message Body -->
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="create-message">Announcement Details / Message <span class="text-danger">*</span></label>
                    <textarea class="form-control" name="message" id="create-message" rows="5" required placeholder="Write your announcement details here. You can include links, reminders, room numbers, and instructions..."></textarea>
                </div>

                <!-- Priority & Publication Settings -->
                <div class="card border bg-light mb-3">
                    <div class="card-body p-3">
                        <h6 class="fw-bold mb-3 text-dark"><i class="bi bi-gear me-1"></i>Publishing &amp; Priority Options</h6>

                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <label class="form-label fw-semibold" for="create-published-at">Publication Date &amp; Time</label>
                                <input type="datetime-local" class="form-control" name="published_at" id="create-published-at" value="<?php echo date('Y-m-d\TH:i'); ?>">
                                <div class="form-text">
                                    Leave as current time to publish immediately, or pick a future date/time to schedule automatic release.
                                </div>
                            </div>
                            <div class="col-12 col-md-6 d-flex flex-column justify-content-center">
                                <div class="form-check form-switch mb-2">
                                    <input class="form-check-input" type="checkbox" name="is_published" value="1" id="create-is-published" checked>
                                    <label class="form-check-label fw-semibold" for="create-is-published">
                                        Publish Announcement
                                    </label>
                                    <div class="form-text">If turned off, this announcement will be saved as a draft hidden from students.</div>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="is_important" value="1" id="create-is-important">
                                    <label class="form-check-label fw-semibold text-danger" for="create-is-important">
                                        <i class="bi bi-exclamation-triangle-fill me-1"></i>Mark as Important Notice
                                    </label>
                                    <div class="form-text">Pinned to the top of the student feed with a high-priority warning badge.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Notification Alert Info -->
                <div class="alert alert-info py-2 px-3 mb-0 small">
                    <i class="bi bi-bell-fill me-1 text-primary"></i>
                    <strong>Student Notification:</strong> When published, all students currently enrolled in this subject section will immediately receive an in-app alert following the standard notification system.
                </div>
            </div>

            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-brand-primary px-4 fw-semibold">
                    <i class="bi bi-send-fill me-1"></i>Submit Announcement
                </button>
            </div>
        </form>
    </div>
</div>

<!-- 2. EDIT ANNOUNCEMENT MODAL -->
<div class="modal fade" id="modal-edit-announcement" tabindex="-1" aria-labelledby="modalEditTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form method="post" action="../actions/lms_teacher_announcement_actions" class="modal-content shadow">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
            <input type="hidden" name="action" value="edit_announcement">
            <input type="hidden" name="section_subject_id" id="edit-ss-id" value="<?php echo $sectionSubjectId; ?>">
            <input type="hidden" name="announcement_id" id="edit-announcement-id">

            <div class="modal-header text-white" style="background:var(--brand-primary,#0b4f5c);">
                <h5 class="modal-title fw-bold" id="modalEditTitle">
                    <i class="bi bi-pencil-square me-2"></i>Edit Announcement
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body p-4">
                <!-- Title -->
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="edit-title">Announcement Title <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="title" id="edit-title" maxlength="150" required>
                </div>

                <!-- Message Body -->
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="edit-message">Announcement Details / Message <span class="text-danger">*</span></label>
                    <textarea class="form-control" name="message" id="edit-message" rows="5" required></textarea>
                </div>

                <!-- Publishing & Priority Settings -->
                <div class="card border bg-light mb-3">
                    <div class="card-body p-3">
                        <h6 class="fw-bold mb-3 text-dark"><i class="bi bi-gear me-1"></i>Publishing &amp; Priority Options</h6>

                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <label class="form-label fw-semibold" for="edit-published-at">Publication Date &amp; Time</label>
                                <input type="datetime-local" class="form-control" name="published_at" id="edit-published-at">
                                <div class="form-text">Adjust date/time for scheduled release or record keeping.</div>
                            </div>
                            <div class="col-12 col-md-6 d-flex flex-column justify-content-center">
                                <div class="form-check form-switch mb-2">
                                    <input class="form-check-input" type="checkbox" name="is_published" value="1" id="edit-is-published">
                                    <label class="form-check-label fw-semibold" for="edit-is-published">
                                        Published
                                    </label>
                                    <div class="form-text">Toggle off to unpublish and hide from students.</div>
                                </div>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" name="is_important" value="1" id="edit-is-important">
                                    <label class="form-check-label fw-semibold text-danger" for="edit-is-important">
                                        <i class="bi bi-exclamation-triangle-fill me-1"></i>Mark as Important Notice
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="notify_students" value="1" id="edit-notify-students">
                                    <label class="form-check-label fw-semibold text-primary" for="edit-notify-students">
                                        <i class="bi bi-bell-fill me-1"></i>Send update alert to enrolled students
                                    </label>
                                    <div class="form-text">Check this if you made an urgent modification that requires re-alerting students.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-brand-primary px-4 fw-semibold">
                    <i class="bi bi-check-lg me-1"></i>Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<!-- 3. DELETE CONFIRMATION MODAL -->
<div class="modal fade" id="modal-delete-announcement" tabindex="-1" aria-labelledby="modalDeleteTitle" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" action="../actions/lms_teacher_announcement_actions" class="modal-content shadow">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
            <input type="hidden" name="action" value="delete_announcement">
            <input type="hidden" name="section_subject_id" id="delete-ss-id" value="<?php echo $sectionSubjectId; ?>">
            <input type="hidden" name="announcement_id" id="delete-announcement-id">

            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title fw-bold" id="modalDeleteTitle">
                    <i class="bi bi-trash-fill me-2"></i>Delete Announcement
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body p-4 text-center">
                <i class="bi bi-exclamation-circle text-danger mb-3" style="font-size:3rem;"></i>
                <h5 class="fw-bold mb-2">Are you sure you want to delete this announcement?</h5>
                <p class="text-muted small mb-3">
                    "<strong id="delete-announcement-title"></strong>"
                </p>
                <p class="text-danger small mb-0 fw-semibold">
                    This action is permanent and cannot be undone. Enrolled students will no longer see this announcement.
                </p>
            </div>

            <div class="modal-footer bg-light justify-content-center">
                <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger px-4 fw-semibold">
                    <i class="bi bi-trash me-1"></i>Confirm Delete
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Hidden form for AJAX toggle fallback -->
<form method="post" action="../actions/lms_teacher_announcement_actions" id="form-toggle-publish" class="d-none">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
    <input type="hidden" name="action" value="toggle_publish">
    <input type="hidden" name="section_subject_id" value="<?php echo $sectionSubjectId; ?>">
    <input type="hidden" name="announcement_id" id="toggle-announcement-id">
</form>

<input type="hidden" id="lms-csrf-token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
<input type="hidden" id="lms-action-url" value="../actions/lms_teacher_announcement_actions">
<input type="hidden" id="lms-current-ss" value="<?php echo $sectionSubjectId; ?>">

<!-- ════════════════════════════════════════════════════════════════════════
     PAGE JAVASCRIPT: FILTERING, SEARCH, MODAL POPULATION, AND AJAX TOGGLE
     ════════════════════════════════════════════════════════════════════════ -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    const csrfToken = document.getElementById('lms-csrf-token')?.value || '';
    const actionUrl = document.getElementById('lms-action-url')?.value || '';
    const currentSs = document.getElementById('lms-current-ss')?.value || '';

    // ── Quick Post from Overview mode ─────────────────────────────────────
    document.querySelectorAll('.btn-quick-post').forEach(btn => {
        btn.addEventListener('click', function () {
            const ssId = this.dataset.ssId;
            const selectEl = document.getElementById('create-ss-id');
            if (selectEl) {
                selectEl.value = ssId;
            }
            new bootstrap.Modal(document.getElementById('modal-create-announcement')).show();
        });
    });

    // ── Edit Announcement Modal ───────────────────────────────────────────
    document.querySelectorAll('.btn-edit-announcement').forEach(btn => {
        btn.addEventListener('click', function () {
            try {
                const data = JSON.parse(this.dataset.announcement);
                document.getElementById('edit-announcement-id').value = data.id;
                document.getElementById('edit-title').value = data.title || '';
                document.getElementById('edit-message').value = data.message || '';
                document.getElementById('edit-published-at').value = data.published_at || '';
                document.getElementById('edit-is-published').checked = (parseInt(data.is_published, 10) === 1);
                document.getElementById('edit-is-important').checked = (parseInt(data.is_important, 10) === 1);
                document.getElementById('edit-notify-students').checked = false;

                new bootstrap.Modal(document.getElementById('modal-edit-announcement')).show();
            } catch (e) {
                console.error('Failed to parse announcement data for editing:', e);
            }
        });
    });

    // ── Delete Announcement Modal ─────────────────────────────────────────
    document.querySelectorAll('.btn-delete-announcement').forEach(btn => {
        btn.addEventListener('click', function () {
            const id = this.dataset.id;
            const title = this.dataset.title;
            document.getElementById('delete-announcement-id').value = id;
            document.getElementById('delete-announcement-title').textContent = title;

            new bootstrap.Modal(document.getElementById('modal-delete-announcement')).show();
        });
    });

    // ── Quick Toggle Publish (AJAX) ───────────────────────────────────────
    document.querySelectorAll('.btn-toggle-publish').forEach(btn => {
        btn.addEventListener('click', function () {
            const annId = this.dataset.id;
            const card = document.getElementById('announcement-' + annId);
            const originalHtml = this.innerHTML;

            this.disabled = true;
            this.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span> Updating...';

            const formData = new FormData();
            formData.append('csrf_token', csrfToken);
            formData.append('action', 'toggle_publish');
            formData.append('section_subject_id', currentSs);
            formData.append('announcement_id', annId);

            fetch(actionUrl, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            })
            .then(res => res.json())
            .then(data => {
                if (data.ok) {
                    window.location.reload();
                } else {
                    alert(data.error || 'Failed to update publication status.');
                    btn.disabled = false;
                    btn.innerHTML = originalHtml;
                }
            })
            .catch(err => {
                console.error(err);
                // Fallback to standard form submission
                document.getElementById('toggle-announcement-id').value = annId;
                document.getElementById('form-toggle-publish').submit();
            });
        });
    });

    // ── Search & Filter ───────────────────────────────────────────────────
    const searchInput = document.getElementById('announcement-search');
    const filterBtns  = document.querySelectorAll('.filter-btn');
    const cards       = document.querySelectorAll('.announcement-card');

    let currentFilter = 'all';

    function applyFilterAndSearch() {
        const query = searchInput ? searchInput.value.trim().toLowerCase() : '';

        cards.forEach(card => {
            const cardFilter = card.dataset.filterType || '';
            const cardTitle  = card.dataset.title || '';
            const cardContent= card.dataset.content || '';

            // Filter match
            let matchesFilter = false;
            if (currentFilter === 'all') {
                matchesFilter = true;
            } else if (currentFilter === 'live' && cardFilter.includes('live')) {
                matchesFilter = true;
            } else if (currentFilter === 'scheduled' && cardFilter.includes('scheduled')) {
                matchesFilter = true;
            } else if (currentFilter === 'draft' && cardFilter.includes('draft')) {
                matchesFilter = true;
            } else if (currentFilter === 'important' && cardFilter.includes('important')) {
                matchesFilter = true;
            }

            // Search match
            let matchesSearch = true;
            if (query !== '') {
                matchesSearch = (cardTitle.includes(query) || cardContent.includes(query));
            }

            if (matchesFilter && matchesSearch) {
                card.style.display = '';
            } else {
                card.style.display = 'none';
            }
        });
    }

    if (searchInput) {
        searchInput.addEventListener('input', applyFilterAndSearch);
    }

    filterBtns.forEach(btn => {
        btn.addEventListener('click', function () {
            filterBtns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            currentFilter = this.dataset.filter;
            applyFilterAndSearch();
        });
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>
