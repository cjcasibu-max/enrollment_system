<?php
/**
 * Teacher LMS — Learning Materials Management (Phase 4)
 *
 * Two modes:
 *   With ?section_subject_id=N  → subject-scoped materials manager
 *   Without                     → cross-subject overview (all teacher subjects
 *                                  with material counts + "Manage" links)
 *
 * Security:
 *   requireLmsTeacherAccess()         → role gate
 *   verifyTeacherOwnsSubject()        → ownership (subject-scoped mode only)
 *
 * Data source:
 *   Reads lms_materials directly (same table the student side reads from).
 *   is_available = 1 → visible to students; = 0 → hidden.
 *   No cache layer; changes from uploads/edits/toggles are immediately live.
 */

require_once '../includes/lms_access.php';

$teacher  = requireLmsTeacherAccess();
$userId   = $teacher['user_id'];
$sectionSubjectId = (int)filter_input(INPUT_GET, 'section_subject_id', FILTER_VALIDATE_INT);

// ── Determine mode ────────────────────────────────────────────────────────────
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

// ── Subject-scoped: fetch materials ───────────────────────────────────────────
$materials = [];
if ($subjectMode) {
    $matStmt = $pdo->prepare(
        'SELECT id, title, description, material_type, file_name, file_path,
                display_order, is_available, created_at
         FROM lms_materials
         WHERE section_subject_id = :ss
         ORDER BY display_order ASC, id ASC'
    );
    $matStmt->execute(['ss' => $sectionSubjectId]);
    $materials = $matStmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── Overview mode: fetch all teacher subjects with counts ─────────────────────
$allSubjects = [];
if (!$subjectMode) {
    $allSubjects = fetchLmsTeacherSubjects($pdo, $userId);
    if (!empty($allSubjects)) {
        $ssIds = array_column($allSubjects, 'section_subject_id');
        $inPh  = implode(',', array_fill(0, count($ssIds), '?'));
        $cntStmt = $pdo->prepare(
            "SELECT section_subject_id,
                    COUNT(*) AS total,
                    SUM(is_available) AS available
             FROM lms_materials WHERE section_subject_id IN ($inPh)
             GROUP BY section_subject_id"
        );
        $cntStmt->execute($ssIds);
        $countsBySubject = [];
        foreach ($cntStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $countsBySubject[(int)$row['section_subject_id']] = [
                'total'     => (int)$row['total'],
                'available' => (int)$row['available'],
            ];
        }
        foreach ($allSubjects as &$s) {
            $sid = (int)$s['section_subject_id'];
            $s['material_count']     = $countsBySubject[$sid]['total']     ?? 0;
            $s['available_count']    = $countsBySubject[$sid]['available'] ?? 0;
        }
        unset($s);
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
$typeConfig = [
    'pdf'          => ['icon' => 'bi-filetype-pdf',   'label' => 'PDF',          'color' => '#dc2626'],
    'presentation' => ['icon' => 'bi-file-slides',    'label' => 'Presentation', 'color' => '#d97706'],
    'document'     => ['icon' => 'bi-file-earmark-word', 'label' => 'Document',  'color' => '#2563eb'],
    'image'        => ['icon' => 'bi-image',           'label' => 'Image',        'color' => '#7c3aed'],
    'video'        => ['icon' => 'bi-play-btn-fill',   'label' => 'Video',        'color' => '#0891b2'],
    'other'        => ['icon' => 'bi-file-earmark',    'label' => 'Other',        'color' => '#6b7280'],
];

$page_title = $subjectMode
    ? htmlspecialchars($subject['subject_code']) . ' — Learning Materials'
    : 'Learning Materials — Instructor Management';

require_once '../includes/header.php';

$lmsActiveTab = 'materials';
require_once __DIR__ . '/lms_navbar.php';
?>

<!-- Breadcrumb -->
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb mb-0" style="font-size:.82rem;">
        <li class="breadcrumb-item">
            <a href="lms" class="text-decoration-none text-brand-primary"><i class="bi bi-journal-bookmark-fill me-1"></i>LMS Dashboard</a>
        </li>
        <?php if ($subjectMode): ?>
        <li class="breadcrumb-item">
            <a href="lms_subject?section_subject_id=<?php echo $sectionSubjectId; ?>"
               class="text-decoration-none text-brand-primary">
                <?php echo htmlspecialchars($subject['subject_code']); ?>
            </a>
        </li>
        <?php endif; ?>
        <li class="breadcrumb-item active" aria-current="page">Learning Materials</li>
    </ol>
</nav>

<!-- Page header -->
<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <div>
        <?php if ($subjectMode): ?>
        <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
            <span class="badge" style="background:var(--brand-primary, #008080);font-size:.8rem;letter-spacing:.04em;">
                <?php echo htmlspecialchars($subject['subject_code']); ?>
            </span>
            <span class="badge bg-light text-secondary border" style="font-size:.8rem;">
                Section: <?php echo htmlspecialchars($subject['section_name'] ?: 'Section'); ?>
            </span>
        </div>
        <h1 class="h3 fw-bold text-navy-alt mb-1">Learning Materials</h1>
        <p class="text-muted small mb-0">
            <?php echo htmlspecialchars($subject['subject_name']); ?> · Upload and organize course documents, slides, and learning resources.
        </p>
        <?php else: ?>
        <div class="d-flex align-items-center gap-2 mb-1">
            <span class="badge" style="background:var(--brand-primary, #008080);font-size:.75rem;letter-spacing:.04em;text-transform:uppercase;">
                Instructor Management
            </span>
        </div>
        <h1 class="h3 fw-bold text-navy-alt mb-1">Learning Materials</h1>
        <p class="text-muted small mb-0">
            Select an assigned subject to upload, publish, and manage lecture slides, documents, and reference resources.
        </p>
        <?php endif; ?>
    </div>
    <div class="d-flex gap-2 flex-wrap align-items-center" style="gap: 8px;">
        <?php if ($subjectMode): ?>
        <a href="lms_subject?section_subject_id=<?php echo $sectionSubjectId; ?>&section=overview"
           class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Subject Hub
        </a>
        <a href="lms_materials" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-grid-3x3-gap me-1"></i>All Subjects
        </a>
        <button class="btn btn-brand-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modal-upload"
                id="btn-upload-material">
            <i class="bi bi-upload me-1"></i>Upload Material
        </button>
        <?php else: ?>
        <a href="lms" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-grid-fill me-1"></i>LMS Dashboard
        </a>
        <?php endif; ?>
    </div>
</div>

<?php if (!$subjectMode): ?>
<!-- ════════════════════════════════════════════════════════
     OVERVIEW MODE — Subject picker
     ════════════════════════════════════════════════════════ -->
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
            <i class="bi bi-collection me-2 text-brand-primary"></i>Assigned Subjects &amp; Course Materials
        </h2>
        <span class="badge bg-light text-muted border"><?php echo count($allSubjects); ?> Subjects</span>
    </div>
    <div class="card-body p-4">
        <?php 
            $colClass = count($allSubjects) === 1 ? 'col-12' : (count($allSubjects) === 2 ? 'col-12 col-md-6' : 'col-12 col-md-6 col-xl-4');
        ?>
        <div class="row g-3" id="subject-picker-grid">
            <?php foreach ($allSubjects as $s):
                $ssId     = (int)$s['section_subject_id'];
                $matTotal = (int)($s['material_count'] ?? 0);
                $matAvail = (int)($s['available_count'] ?? 0);
                $manageUrl= resolveAppUrl('teacher/lms_materials?section_subject_id=' . $ssId);
            ?>
            <div class="<?php echo $colClass; ?>">
                <div class="card border h-100 shadow-sm" style="border-color:#e2e8f0 !important; border-radius: 10px;">
                    <div class="card-body p-4 d-flex flex-column justify-content-between">
                        <div>
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div>
                                    <div class="text-uppercase small fw-bold text-brand-primary"
                                         style="font-size:.72rem;letter-spacing:.06em;">
                                        <?php echo htmlspecialchars($s['subject_code']); ?>
                                    </div>
                                    <div class="fw-bold text-navy-alt" style="font-size:1rem;">
                                        <?php echo htmlspecialchars($s['subject_name']); ?>
                                    </div>
                                </div>
                                <span class="badge bg-secondary-subtle text-secondary border" style="font-size:.7rem;">
                                    <?php echo htmlspecialchars($s['section_name'] ?? '—'); ?>
                                </span>
                            </div>
                            <div class="d-flex gap-3 mb-3 text-muted" style="font-size:.82rem;">
                                <span><i class="bi bi-file-earmark-text me-1 text-brand-primary"></i><?php echo $matTotal; ?> material<?php echo $matTotal !== 1 ? 's' : ''; ?></span>
                                <span><i class="bi bi-eye me-1 text-success"></i><?php echo $matAvail; ?> available</span>
                            </div>
                        </div>
                        <a href="<?php echo htmlspecialchars($manageUrl); ?>"
                           class="btn btn-sm btn-outline-secondary w-100"
                           id="manage-materials-<?php echo $ssId; ?>">
                            <i class="bi bi-pencil-square me-1"></i>Manage Materials
                        </a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php else: ?>
<!-- ════════════════════════════════════════════════════════
     SUBJECT-SCOPED MODE — Materials list
     ════════════════════════════════════════════════════════ -->

<!-- Stats strip -->
<?php
$totalMat  = count($materials);
$availMat  = count(array_filter($materials, fn($m) => $m['is_available']));
$hiddenMat = $totalMat - $availMat;
?>
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4">
        <div class="card card-premium shadow-sm border-0 h-100" style="padding: 18px 20px;">
            <div class="d-flex align-items-center" style="gap: 14px;">
                <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px; background: rgba(0, 128, 128, 0.1); color: var(--brand-primary, #008080);">
                    <i class="bi bi-file-earmark-text fs-5"></i>
                </div>
                <div class="d-flex flex-column" style="gap: 4px;">
                    <span class="text-muted text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.5px;">Total Materials</span>
                    <span class="fs-5 fw-bold text-navy-alt lh-1"><?php echo $totalMat; ?></span>
                    <span class="text-muted" style="font-size: 0.75rem;">files uploaded</span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="card card-premium shadow-sm border-0 h-100" style="padding: 18px 20px;">
            <div class="d-flex align-items-center" style="gap: 14px;">
                <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px; background: rgba(25, 135, 84, 0.1); color: #198754;">
                    <i class="bi bi-eye fs-5"></i>
                </div>
                <div class="d-flex flex-column" style="gap: 4px;">
                    <span class="text-muted text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.5px;">Available</span>
                    <span class="fs-5 fw-bold text-success lh-1"><?php echo $availMat; ?></span>
                    <span class="text-muted" style="font-size: 0.75rem;">visible to students</span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card card-premium shadow-sm border-0 h-100" style="padding: 18px 20px;">
            <div class="d-flex align-items-center" style="gap: 14px;">
                <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px; background: rgba(108, 117, 125, 0.1); color: #6c757d;">
                    <i class="bi bi-eye-slash fs-5"></i>
                </div>
                <div class="d-flex flex-column" style="gap: 4px;">
                    <span class="text-muted text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.5px;">Hidden (Draft)</span>
                    <span class="fs-5 fw-bold text-navy-alt lh-1"><?php echo $hiddenMat; ?></span>
                    <span class="text-muted" style="font-size: 0.75rem;">not published yet</span>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (empty($materials)): ?>
<!-- Empty state -->
<div class="card card-premium border-0 shadow-sm py-5 text-center">
    <div class="card-body" id="no-materials-state">
        <i class="bi bi-file-earmark-text" style="font-size:3rem;opacity:.35;color:var(--brand-primary, #008080);"></i>
        <h3 class="h5 fw-bold mt-3 mb-1">No learning materials yet</h3>
        <p class="text-muted small mx-auto mb-3" style="max-width: 440px;">Upload your first material — it won't be visible to students until you set it as available.</p>
        <button class="btn btn-brand-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modal-upload">
            <i class="bi bi-upload me-1"></i>Upload First Material
        </button>
    </div>
</div>
<?php else: ?>

<!-- Materials list -->
<div class="card card-premium border-0 shadow-sm mb-4">
    <div class="card-header card-header-premium py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h2 class="h6 fw-bold mb-0 text-navy-alt">
                <i class="bi bi-file-earmark-text me-2 text-brand-primary"></i>Course Learning Materials
            </h2>
            <div class="text-muted" style="font-size:.75rem;">Drag rows to reorder. Toggle visibility to publish or unpublish instantly.</div>
        </div>
        <span class="badge bg-light text-muted border"><?php echo count($materials); ?> Items</span>
    </div>
    <div class="card-body p-0">
        <div id="materials-list"
             data-action-url="../actions/lms_material_actions"
             data-ss-id="<?php echo $sectionSubjectId; ?>"
             data-csrf="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">

            <?php foreach ($materials as $mat):
                $tc  = $typeConfig[$mat['material_type']] ?? $typeConfig['other'];
                $isAvail = (int)$mat['is_available'];
                $createdAgo = '';
                if (!empty($mat['created_at'])) {
                    $diff = time() - strtotime($mat['created_at']);
                    if ($diff < 60)           $createdAgo = 'just now';
                    elseif ($diff < 3600)     $createdAgo = floor($diff/60) . 'm ago';
                    elseif ($diff < 86400)    $createdAgo = floor($diff/3600) . 'h ago';
                    elseif ($diff < 604800)   $createdAgo = floor($diff/86400) . 'd ago';
                    else $createdAgo = date('M j, Y', strtotime($mat['created_at']));
                }
            ?>
            <div class="material-row d-flex align-items-center gap-3 px-4 py-3 border-bottom"
                 id="mat-row-<?php echo $mat['id']; ?>"
                 data-material-id="<?php echo $mat['id']; ?>"
                 style="border-color:#f1f5f9 !important; transition:background .15s; padding-left: 24px !important; padding-right: 24px !important;">

                <!-- Drag handle -->
                <span class="mat-drag-handle text-muted flex-shrink-0"
                      style="cursor:grab;font-size:1rem;opacity:.45;" title="Drag to reorder">
                    <i class="bi bi-grip-vertical"></i>
                </span>

                <!-- Type icon -->
                <span class="flex-shrink-0 text-center" style="width:2rem;">
                    <i class="bi <?php echo $tc['icon']; ?>" style="font-size:1.25rem;color:<?php echo $tc['color']; ?>;"></i>
                </span>

                <!-- Info -->
                <div class="flex-grow-1 min-width-0">
                    <div class="fw-semibold text-truncate" style="font-size:.9rem;" title="<?php echo htmlspecialchars($mat['title']); ?>">
                        <?php echo htmlspecialchars($mat['title']); ?>
                    </div>
                    <div class="d-flex flex-wrap gap-2 align-items-center mt-1">
                        <span class="badge text-bg-light border" style="font-size:.65rem;">
                            <?php echo htmlspecialchars($tc['label']); ?>
                        </span>
                        <?php if (!empty($mat['description'])): ?>
                        <span class="text-muted text-truncate" style="font-size:.76rem;max-width:280px;"
                              title="<?php echo htmlspecialchars($mat['description']); ?>">
                            <?php echo htmlspecialchars($mat['description']); ?>
                        </span>
                        <?php endif; ?>
                        <span class="text-muted" style="font-size:.7rem;"><?php echo $createdAgo; ?></span>
                    </div>
                </div>

                <!-- Availability toggle -->
                <div class="flex-shrink-0 d-flex align-items-center gap-1">
                    <button class="btn btn-xs avail-toggle <?php echo $isAvail ? 'btn-success' : 'btn-outline-secondary'; ?>"
                            title="<?php echo $isAvail ? 'Available to students — click to hide' : 'Hidden from students — click to publish'; ?>"
                            data-material-id="<?php echo $mat['id']; ?>"
                            id="avail-btn-<?php echo $mat['id']; ?>">
                        <i class="bi <?php echo $isAvail ? 'bi-eye-fill' : 'bi-eye-slash'; ?>"></i>
                        <span class="ms-1 d-none d-sm-inline"><?php echo $isAvail ? 'Available' : 'Hidden'; ?></span>
                    </button>
                </div>

                <!-- Actions -->
                <div class="flex-shrink-0 d-flex align-items-center" style="gap: 8px;">
                    <button class="btn btn-xs btn-outline-secondary" title="Edit"
                            onclick="openEditMaterial(<?php echo htmlspecialchars(json_encode([
                                'id'            => $mat['id'],
                                'title'         => $mat['title'],
                                'description'   => $mat['description'] ?? '',
                                'material_type' => $mat['material_type'],
                                'is_available'  => $mat['is_available'],
                                'file_name'     => $mat['file_name'],
                            ])); ?>)"
                            id="edit-mat-<?php echo $mat['id']; ?>">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <button class="btn btn-xs btn-outline-danger" title="Delete"
                            onclick="confirmDeleteMaterial(<?php echo $mat['id']; ?>, '<?php echo htmlspecialchars(addslashes($mat['title'])); ?>')"
                            id="del-mat-<?php echo $mat['id']; ?>">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>

            </div><!-- /material-row -->
            <?php endforeach; ?>

        </div><!-- /materials-list -->
    </div>
</div>

<?php endif; // materials not empty ?>

<!-- ════════════════════════════════════════════════════════
     MODALS
     ════════════════════════════════════════════════════════ -->

<!-- Upload Material Modal -->
<div class="modal fade" id="modal-upload" tabindex="-1"
     aria-labelledby="modal-upload-title" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form method="post" action="../actions/lms_material_actions"
              enctype="multipart/form-data" class="modal-content">
            <input type="hidden" name="csrf_token"          value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
            <input type="hidden" name="action"              value="upload_material">
            <input type="hidden" name="section_subject_id"  value="<?php echo $sectionSubjectId; ?>">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="modal-upload-title">
                    <i class="bi bi-upload me-2"></i>Upload Learning Material
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-12 col-md-8">
                        <label class="form-label fw-semibold" for="up-title">
                            Title <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control" id="up-title" name="title"
                               maxlength="150" required autocomplete="off"
                               placeholder="e.g. Introduction to Navigation Concepts">
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label fw-semibold" for="up-type">Category</label>
                        <select class="form-select" id="up-type" name="material_type">
                            <option value="">— auto-detect —</option>
                            <option value="pdf">PDF</option>
                            <option value="presentation">Presentation</option>
                            <option value="document">Document</option>
                            <option value="image">Image</option>
                            <option value="video">Video</option>
                            <option value="other">Other</option>
                        </select>
                        <div class="form-text">Leave blank to detect from file.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold" for="up-description">
                            Description <span class="text-muted fw-normal">(optional)</span>
                        </label>
                        <textarea class="form-control" id="up-description" name="description" rows="2"
                                  placeholder="Brief description for students…"></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold" for="up-file">
                            File <span class="text-danger">*</span>
                        </label>
                        <input type="file" class="form-control" id="up-file" name="material_file" required>
                        <div class="form-text">
                            PDF, PPT/PPTX, DOC/DOCX, XLS/XLSX, TXT, CSV, images, MP4/WebM videos, ZIP — max 200 MB.
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="up-available"
                                   name="is_available" value="1">
                            <label class="form-check-label" for="up-available">
                                Make available to students immediately
                            </label>
                        </div>
                        <div class="form-text">
                            Leave unchecked to save as hidden draft — students won't see it until you publish it.
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-brand-primary" id="btn-upload-submit">
                    <i class="bi bi-upload me-1"></i>Upload Material
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Material Modal -->
<div class="modal fade" id="modal-edit" tabindex="-1"
     aria-labelledby="modal-edit-title" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form method="post" action="../actions/lms_material_actions"
              enctype="multipart/form-data" class="modal-content" id="form-edit-material">
            <input type="hidden" name="csrf_token"          value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
            <input type="hidden" name="action"              value="edit_material">
            <input type="hidden" name="section_subject_id"  value="<?php echo $sectionSubjectId; ?>">
            <input type="hidden" name="material_id"         id="em-material-id">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="modal-edit-title">
                    <i class="bi bi-pencil me-2"></i>Edit Material
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-12 col-md-8">
                        <label class="form-label fw-semibold" for="em-title">
                            Title <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control" id="em-title" name="title"
                               maxlength="150" required autocomplete="off">
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label fw-semibold" for="em-type">Category</label>
                        <select class="form-select" id="em-type" name="material_type">
                            <option value="pdf">PDF</option>
                            <option value="presentation">Presentation</option>
                            <option value="document">Document</option>
                            <option value="image">Image</option>
                            <option value="video">Video</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold" for="em-description">Description</label>
                        <textarea class="form-control" id="em-description" name="description" rows="2"></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold" for="em-file">
                            Replace File
                            <span class="text-muted fw-normal">(leave blank to keep current)</span>
                        </label>
                        <input type="file" class="form-control" id="em-file" name="material_file">
                        <div class="text-muted small mt-1" id="em-current-file"></div>
                    </div>
                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="em-available"
                                   name="is_available" value="1">
                            <label class="form-check-label" for="em-available">
                                Available to students
                            </label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-brand-primary">
                    <i class="bi bi-check-lg me-1"></i>Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Form (hidden) -->
<form method="post" action="../actions/lms_material_actions" id="form-delete-material" class="d-none">
    <input type="hidden" name="csrf_token"         value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
    <input type="hidden" name="action"             value="delete_material">
    <input type="hidden" name="section_subject_id" value="<?php echo $sectionSubjectId; ?>">
    <input type="hidden" name="material_id"        id="del-material-id">
</form>

<!-- Reorder + toggle CSRF -->
<input type="hidden" id="mat-csrf"  value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
<input type="hidden" id="mat-ss-id" value="<?php echo $sectionSubjectId; ?>">
<input type="hidden" id="mat-action-url" value="../actions/lms_material_actions">

<script>
/* ── Edit Material modal ─────────────────────────────────── */
function openEditMaterial(mat) {
    document.getElementById('em-material-id').value  = mat.id;
    document.getElementById('em-title').value        = mat.title || '';
    document.getElementById('em-description').value  = mat.description || '';
    document.getElementById('em-available').checked  = parseInt(mat.is_available) === 1;
    const sel = document.getElementById('em-type');
    if (sel) sel.value = mat.material_type || 'other';
    const cur = document.getElementById('em-current-file');
    if (cur) cur.textContent = mat.file_name ? 'Current file: ' + mat.file_name : '';
    document.getElementById('em-file').value = '';
    new bootstrap.Modal(document.getElementById('modal-edit')).show();
}

/* ── Delete material ─────────────────────────────────────── */
function confirmDeleteMaterial(materialId, title) {
    if (!confirm('Delete "' + title + '"? This cannot be undone.')) return;
    document.getElementById('del-material-id').value = materialId;
    document.getElementById('form-delete-material').submit();
}

/* ── Availability toggle (AJAX) ──────────────────────────── */
document.addEventListener('DOMContentLoaded', function() {
    const actionUrl = document.getElementById('mat-action-url')?.value || '';
    const csrf      = document.getElementById('mat-csrf')?.value || '';
    const ssId      = document.getElementById('mat-ss-id')?.value || '';

    document.querySelectorAll('.avail-toggle').forEach(btn => {
        btn.addEventListener('click', function() {
            const matId = this.dataset.materialId;
            const body  = new URLSearchParams({
                action: 'toggle_availability',
                csrf_token: csrf,
                section_subject_id: ssId,
                material_id: matId,
            });
            this.disabled = true;
            fetch(actionUrl, { method: 'POST', body })
                .then(r => r.json())
                .then(data => {
                    if (!data.ok) { alert(data.error || 'Toggle failed.'); this.disabled = false; return; }
                    const isNowAvail = data.is_available === 1;
                    this.classList.toggle('btn-success',          isNowAvail);
                    this.classList.toggle('btn-outline-secondary', !isNowAvail);
                    const icon = this.querySelector('i');
                    const txt  = this.querySelector('span');
                    if (icon) { icon.className = isNowAvail ? 'bi bi-eye-fill' : 'bi bi-eye-slash'; }
                    if (txt)  { txt.textContent = isNowAvail ? ' Available' : ' Hidden'; }
                    this.title = isNowAvail ? 'Available to students — click to hide' : 'Hidden from students — click to publish';
                    // Update row background tint
                    const row = document.getElementById('mat-row-' + matId);
                    if (row) row.style.background = isNowAvail ? '#f0faf8' : '';
                    this.disabled = false;
                })
                .catch(() => { alert('Network error. Please try again.'); this.disabled = false; });
        });
    });

    /* ── Drag-and-drop reorder ───────────────────────────── */
    const list = document.getElementById('materials-list');
    if (!list) return;
    let dragging = null;
    list.querySelectorAll('.material-row').forEach(row => {
        const handle = row.querySelector('.mat-drag-handle');
        if (!handle) return;
        handle.addEventListener('mousedown', () => row.setAttribute('draggable', 'true'));
        handle.addEventListener('mouseup',   () => row.setAttribute('draggable', 'false'));
        row.addEventListener('dragstart', e => {
            dragging = row;
            setTimeout(() => row.classList.add('opacity-50'), 0);
            e.dataTransfer.effectAllowed = 'move';
        });
        row.addEventListener('dragend', () => {
            row.classList.remove('opacity-50');
            row.setAttribute('draggable', 'false');
            dragging = null;
            // AJAX save
            const ids = [...list.querySelectorAll('.material-row')].map(r => r.dataset.materialId);
            const body = new URLSearchParams({ action: 'reorder_materials', csrf_token: csrf, section_subject_id: ssId });
            ids.forEach(id => body.append('material_ids[]', id));
            fetch(actionUrl, { method: 'POST', body }).catch(() => {});
        });
        row.addEventListener('dragover', e => {
            e.preventDefault();
            if (!dragging || dragging === row) return;
            const rect = row.getBoundingClientRect();
            const mid  = rect.top + rect.height / 2;
            if (e.clientY < mid) list.insertBefore(dragging, row);
            else list.insertBefore(dragging, row.nextSibling);
        });
    });
});
</script>

<?php endif; // subject-scoped mode ?>

<style>
/* ── LMS nav bar ──────────────────────────────────────── */
.lms-nav-btn { opacity:.85; transition:opacity .15s,background .15s; }
.lms-nav-btn:hover { opacity:1 !important; background:rgba(255,255,255,.15) !important; }

/* ── btn-xs ───────────────────────────────────────────── */
.btn-xs { padding:.15rem .45rem; font-size:.72rem; line-height:1.4; border-radius:.25rem; }

/* ── Material rows ────────────────────────────────────── */
.material-row:last-child { border-bottom:none !important; }
.material-row:hover { background:#f8fafc; }
.mat-drag-handle:hover { opacity:.8 !important; }

/* ── Availability toggle active state ────────────────── */
.avail-toggle.btn-success { background:#10b981; border-color:#10b981; color:#fff; }
.avail-toggle.btn-success:hover { background:#059669; border-color:#059669; }
</style>

<?php require_once '../includes/footer.php'; ?>
