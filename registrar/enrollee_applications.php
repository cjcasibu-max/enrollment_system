<?php
/**
 * Enrollee Applications Review Dashboard
 * Displays a list of all applicants with pending status.
 * Registrar or Admin reviews their mandatory documents before overall approval/rejection.
 * Integrates SweetAlert2.
 */

require_once '../includes/auth_check.php';
checkRole(['registrar', 'admin']);

require_once '../config/database.php';

// Fetch all students who have a 'pending' application status.
// Only the columns used by this page and its detail modal are selected to keep the query lean.
$selectedProgram = isset($_GET['program']) ? trim($_GET['program']) : '';
$selectedStatus  = isset($_GET['status'])  ? trim($_GET['status'])  : 'pending';
$search          = isset($_GET['search'])  ? trim($_GET['search'])  : '';

$where = [];
$params = [];

if ($selectedStatus === 'pending') {
    $where[] = "s.application_status IN ('pending', 'under_review')";
} elseif ($selectedStatus !== '') {
    $where[] = "s.application_status = :status";
    $params['status'] = $selectedStatus;
}

if ($selectedProgram === 'BSMT') {
    $where[] = "(s.program_applying_for LIKE '%BSMT%' OR s.program_applying_for LIKE '%Marine Transportation%')";
} elseif ($selectedProgram === 'BSMarE') {
    $where[] = "(s.program_applying_for LIKE '%BSMarE%' OR s.program_applying_for LIKE '%Marine Engineering%')";
}

if ($search !== '') {
    $where[] = "(s.first_name LIKE :s1 OR s.last_name LIKE :s2 OR u.username LIKE :s3 OR u.email LIKE :s4 OR s.contact_number LIKE :s5 OR s.shs_track_strand LIKE :s6)";
    $searchLike = '%' . $search . '%';
    $params['s1'] = $searchLike;
    $params['s2'] = $searchLike;
    $params['s3'] = $searchLike;
    $params['s4'] = $searchLike;
    $params['s5'] = $searchLike;
    $params['s6'] = $searchLike;
}

$whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

try {
    $stmt = $pdo->prepare("
        SELECT
            s.id,
            s.user_id,
            s.first_name,
            s.middle_name,
            s.last_name,
            s.suffix,
            s.birthdate,
            s.age,
            s.place_of_birth,
            s.gender,
            s.civil_status,
            s.nationality,
            s.religion,
            s.contact_number,
            s.address_street,
            s.address_barangay,
            s.address_city,
            s.address_province,
            s.address_zip_code,
            s.guardian_name,
            s.guardian_relationship,
            s.guardian_contact_number,
            s.guardian_address,
            s.shs_track_strand,
            s.shs_name,
            s.shs_type,
            s.year_graduated,
            s.general_average,
            s.program_applying_for,
            s.applicant_type,
            s.year_level,
            s.application_status,
            s.enrollment_status,
            s.ack_submit_without_docs,
            s.created_at,
            u.username,
            u.email
        FROM students s
        JOIN users u ON s.user_id = u.id
        $whereSql
        ORDER BY s.id DESC
    ");
    $stmt->execute($params);
    $applications = $stmt->fetchAll();
} catch (\PDOException $e) {
    error_log("Fetch enrollee applications failed: " . $e->getMessage());
    $applications = [];
}

// Load all documents for the filtered queue
$documentsByStudent = [];
if (!empty($applications)) {
    $studentIds = array_column($applications, 'id');
    $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
    try {
        $docStmt = $pdo->prepare("SELECT * FROM documents WHERE student_id IN ($placeholders) ORDER BY student_id ASC, id ASC");
        $docStmt->execute($studentIds);
        foreach ($docStmt->fetchAll() as $document) {
            $studentKey = (int)$document['student_id'];
            $documentsByStudent[$studentKey][] = $document;
        }
    } catch (\PDOException $e) {
        error_log("Fetch enrollee documents failed: " . $e->getMessage());
    }
}

// Fetch application status counts for the summary cards
$statusCounts = [
    'pending'        => 0,
    'under_review'   => 0,
    'approved'       => 0,
    'rejected'       => 0,
    'needs_revision' => 0,
    'total'          => 0,
];
try {
    $countRows = $pdo->query("
        SELECT application_status, COUNT(*) as cnt
        FROM students
        GROUP BY application_status
    ")->fetchAll();
    foreach ($countRows as $cr) {
        $st = $cr['application_status'];
        if (isset($statusCounts[$st])) {
            $statusCounts[$st] = (int)$cr['cnt'];
        }
        $statusCounts['total'] += (int)$cr['cnt'];
    }
} catch (\PDOException $e) {
    error_log("Fetch application status counts failed: " . $e->getMessage());
}

$page_title = "Admission Applications";
$page_class = 'page-applications';
require_once '../includes/header.php';
?>

<style>
/* Hover lift effect matching dashboard */
.dashboard-kpis .card-stat { transition: transform 0.18s, box-shadow 0.18s; }
.dashboard-kpis .card-stat:hover { transform: translateY(-3px); box-shadow: 0 8px 24px rgba(11,155,152,0.13) !important; }
/* Doc count badge */
.doc-count-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 10px;
    border-radius: 999px;
    font-size: 0.72rem;
    font-weight: 700;
}
.doc-count-badge.complete   { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
.doc-count-badge.incomplete { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
</style>

<!-- Page Heading -->
<div class="page-heading mb-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
    <div>
        <div class="page-eyebrow"><i class="bi bi-inbox me-1"></i> Admission Management</div>
        <h3 class="m-0 text-navy-alt fw-bold">Admission Applications</h3>
        <p class="text-muted small m-0">Review pending admissions, verify credentials, and process admission queues for maritime cadets.</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <span class="badge" style="background: var(--surface-tint); color: var(--brand-dark); border: 1px solid var(--brand-primary-soft); padding: 8px 14px; font-size: 0.8rem; font-weight: 700; border-radius: 999px;">
            <i class="bi bi-hourglass-split me-1 text-brand-primary"></i> <?php echo number_format($statusCounts['pending'] + $statusCounts['under_review']); ?> Awaiting Decision
        </span>
    </div>
</div>

<!-- =========================================================
     APPLICATION STATUS SUMMARY CARDS — dashboard card-stat style
     ========================================================= -->
<section class="row g-3 mb-4 dashboard-kpis">

    <!-- Total Applications -->
    <div class="col-6 col-sm-4 col-xl-2">
        <a href="enrollee_applications?status=" class="text-decoration-none">
            <div class="card card-stat stat-primary h-100 shadow-sm <?php echo $selectedStatus === '' ? 'border-primary' : ''; ?>">
                <div class="card-body d-flex align-items-center justify-content-between p-3">
                    <div>
                        <div class="text-muted small fw-bold text-uppercase" style="letter-spacing:0.05em;font-size:0.7rem;">Total</div>
                        <div class="fs-2 fw-bold text-darker mt-1"><?php echo number_format($statusCounts['total']); ?></div>
                        <div class="small text-muted mt-0.5">All applicants</div>
                    </div>
                    <div class="stat-icon bg-primary-soft">
                        <i class="bi bi-collection-fill"></i>
                    </div>
                </div>
            </div>
        </a>
    </div>

    <!-- Pending -->
    <div class="col-6 col-sm-4 col-xl-2">
        <a href="enrollee_applications?status=pending" class="text-decoration-none">
            <div class="card card-stat stat-warning h-100 shadow-sm <?php echo $selectedStatus === 'pending' ? 'border-warning' : ''; ?>">
                <div class="card-body d-flex align-items-center justify-content-between p-3">
                    <div>
                        <div class="text-muted small fw-bold text-uppercase" style="letter-spacing:0.05em;font-size:0.7rem;">Pending</div>
                        <div class="fs-2 fw-bold text-darker mt-1"><?php echo number_format($statusCounts['pending']); ?></div>
                        <div class="small text-muted mt-0.5">Awaiting review</div>
                    </div>
                    <div class="stat-icon bg-warning-soft">
                        <i class="bi bi-hourglass-split"></i>
                    </div>
                </div>
            </div>
        </a>
    </div>

    <!-- Under Review -->
    <div class="col-6 col-sm-4 col-xl-2">
        <a href="enrollee_applications?status=under_review" class="text-decoration-none">
            <div class="card card-stat stat-secondary h-100 shadow-sm <?php echo $selectedStatus === 'under_review' ? 'border-secondary' : ''; ?>">
                <div class="card-body d-flex align-items-center justify-content-between p-3">
                    <div>
                        <div class="text-muted small fw-bold text-uppercase" style="letter-spacing:0.05em;font-size:0.7rem;">Under Review</div>
                        <div class="fs-2 fw-bold text-darker mt-1"><?php echo number_format($statusCounts['under_review']); ?></div>
                        <div class="small text-muted mt-0.5">In progress</div>
                    </div>
                    <div class="stat-icon bg-secondary-soft">
                        <i class="bi bi-eye-fill"></i>
                    </div>
                </div>
            </div>
        </a>
    </div>

    <!-- Approved -->
    <div class="col-6 col-sm-4 col-xl-2">
        <a href="enrollee_applications?status=approved" class="text-decoration-none">
            <div class="card card-stat stat-success h-100 shadow-sm <?php echo $selectedStatus === 'approved' ? 'border-success' : ''; ?>">
                <div class="card-body d-flex align-items-center justify-content-between p-3">
                    <div>
                        <div class="text-muted small fw-bold text-uppercase" style="letter-spacing:0.05em;font-size:0.7rem;">Approved</div>
                        <div class="fs-2 fw-bold text-darker mt-1"><?php echo number_format($statusCounts['approved']); ?></div>
                        <div class="small text-muted mt-0.5">Admitted</div>
                    </div>
                    <div class="stat-icon bg-success-soft">
                        <i class="bi bi-check-circle-fill"></i>
                    </div>
                </div>
            </div>
        </a>
    </div>

    <!-- Needs Revision -->
    <div class="col-6 col-sm-4 col-xl-2">
        <a href="enrollee_applications?status=needs_revision" class="text-decoration-none">
            <div class="card card-stat stat-accent h-100 shadow-sm <?php echo $selectedStatus === 'needs_revision' ? 'border-info' : ''; ?>">
                <div class="card-body d-flex align-items-center justify-content-between p-3">
                    <div>
                        <div class="text-muted small fw-bold text-uppercase" style="letter-spacing:0.05em;font-size:0.7rem;">Needs Revision</div>
                        <div class="fs-2 fw-bold text-darker mt-1"><?php echo number_format($statusCounts['needs_revision']); ?></div>
                        <div class="small text-muted mt-0.5">Returned for edits</div>
                    </div>
                    <div class="stat-icon bg-accent-soft">
                        <i class="bi bi-pencil-square"></i>
                    </div>
                </div>
            </div>
        </a>
    </div>

    <!-- Rejected -->
    <div class="col-6 col-sm-4 col-xl-2">
        <a href="enrollee_applications?status=rejected" class="text-decoration-none">
            <div class="card card-stat stat-danger h-100 shadow-sm <?php echo $selectedStatus === 'rejected' ? 'border-danger' : ''; ?>">
                <div class="card-body d-flex align-items-center justify-content-between p-3">
                    <div>
                        <div class="text-muted small fw-bold text-uppercase" style="letter-spacing:0.05em;font-size:0.7rem;">Rejected</div>
                        <div class="fs-2 fw-bold text-darker mt-1"><?php echo number_format($statusCounts['rejected']); ?></div>
                        <div class="small text-muted mt-0.5">Not admitted</div>
                    </div>
                    <div class="stat-icon bg-danger-soft">
                        <i class="bi bi-x-circle-fill"></i>
                    </div>
                </div>
            </div>
        </a>
    </div>

</section>

<!-- =========================================================================
     UNIFIED MARITIME CONTROL & FILTER BAR
     ========================================================================= -->
<div class="maritime-control-bar mb-4">
    <!-- Top Row: Program Selector Tabs -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 pb-3 border-bottom border-light-subtle">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="small fw-bold text-uppercase text-muted me-2"><i class="bi bi-mortarboard me-1"></i> Program:</span>
            <a href="enrollee_applications?status=<?php echo urlencode($selectedStatus); ?>" 
               class="program-pill-btn <?php echo $selectedProgram === '' ? 'active' : ''; ?>">
                All Programs
            </a>
            <a href="enrollee_applications?program=BSMT<?php echo $selectedStatus ? '&status=' . urlencode($selectedStatus) : ''; ?>" 
               class="program-pill-btn <?php echo $selectedProgram === 'BSMT' ? 'active' : ''; ?>">
                <i class="bi bi-compass"></i> BSMT
                <span class="badge ms-1 <?php echo $selectedProgram === 'BSMT' ? 'bg-light text-dark' : 'bg-secondary text-white'; ?>" style="font-size: 0.65rem;">Marine Transportation</span>
            </a>
            <a href="enrollee_applications?program=BSMarE<?php echo $selectedStatus ? '&status=' . urlencode($selectedStatus) : ''; ?>" 
               class="program-pill-btn <?php echo $selectedProgram === 'BSMarE' ? 'active' : ''; ?>">
                <i class="bi bi-gear-wide-connected"></i> BSMarE
                <span class="badge ms-1 <?php echo $selectedProgram === 'BSMarE' ? 'bg-light text-dark' : 'bg-secondary text-white'; ?>" style="font-size: 0.65rem;">Marine Engineering</span>
            </a>
        </div>

        <div class="text-muted small">
            Queue Mode: <strong class="text-navy"><?php echo $selectedStatus ? ucfirst(str_replace('_', ' ', $selectedStatus)) : 'All Applications'; ?></strong>
        </div>
    </div>

    <!-- Bottom Row: Status Filter Pills & Search Input -->
    <form method="GET" action="enrollee_applications" class="row g-3 align-items-center pt-3">
        <?php if ($selectedProgram): ?>
            <input type="hidden" name="program" value="<?php echo htmlspecialchars($selectedProgram); ?>">
        <?php endif; ?>
        
        <!-- Status Filter Pills -->
        <div class="col-12 col-lg-7">
            <label class="form-label small fw-bold text-muted mb-1.5 d-block">Application Status</label>
            <div class="d-flex flex-wrap gap-1">
                <a href="enrollee_applications?<?php echo $selectedProgram ? 'program=' . urlencode($selectedProgram) . '&' : ''; ?>status=" 
                   class="filter-pill <?php echo $selectedStatus === '' ? 'active' : ''; ?>">
                    All (<?php echo $statusCounts['total']; ?>)
                </a>
                <a href="enrollee_applications?<?php echo $selectedProgram ? 'program=' . urlencode($selectedProgram) . '&' : ''; ?>status=pending" 
                   class="filter-pill <?php echo $selectedStatus === 'pending' ? 'active' : ''; ?>">
                    Pending (<?php echo $statusCounts['pending']; ?>)
                </a>
                <a href="enrollee_applications?<?php echo $selectedProgram ? 'program=' . urlencode($selectedProgram) . '&' : ''; ?>status=under_review" 
                   class="filter-pill <?php echo $selectedStatus === 'under_review' ? 'active' : ''; ?>">
                    Under Review (<?php echo $statusCounts['under_review']; ?>)
                </a>
                <a href="enrollee_applications?<?php echo $selectedProgram ? 'program=' . urlencode($selectedProgram) . '&' : ''; ?>status=approved" 
                   class="filter-pill <?php echo $selectedStatus === 'approved' ? 'active' : ''; ?>">
                    Approved (<?php echo $statusCounts['approved']; ?>)
                </a>
                <a href="enrollee_applications?<?php echo $selectedProgram ? 'program=' . urlencode($selectedProgram) . '&' : ''; ?>status=needs_revision" 
                   class="filter-pill <?php echo $selectedStatus === 'needs_revision' ? 'active' : ''; ?>">
                    Needs Revision (<?php echo $statusCounts['needs_revision']; ?>)
                </a>
                <a href="enrollee_applications?<?php echo $selectedProgram ? 'program=' . urlencode($selectedProgram) . '&' : ''; ?>status=rejected" 
                   class="filter-pill <?php echo $selectedStatus === 'rejected' ? 'active' : ''; ?>">
                    Rejected (<?php echo $statusCounts['rejected']; ?>)
                </a>
            </div>
        </div>

        <!-- Live Search Group -->
        <div class="col-12 col-lg-5">
            <label class="form-label small fw-bold text-muted mb-1.5 d-block">Search Applicant</label>
            <div class="input-group">
                <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                <input type="text" name="search" id="applicationSearch" class="form-control border-start-0 ps-0" placeholder="Name, email, contact, Strand..." value="<?php echo htmlspecialchars($search); ?>" autocomplete="off">
                <button type="submit" class="btn btn-brand-primary px-3"><i class="bi bi-funnel"></i></button>
                <?php if ($search !== '' || $selectedProgram !== '' || $selectedStatus !== 'pending'): ?>
                    <a href="enrollee_applications" class="btn btn-outline-secondary px-3" title="Reset Filters"><i class="bi bi-arrow-clockwise"></i></a>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>

<!-- =========================================================================
     APPLICATIONS QUEUE TABLE CARD
     ========================================================================= -->
<div class="card shadow-sm border-0" style="border-radius: 16px; overflow: hidden; border: 1px solid #e0eded !important; box-shadow: 0 4px 24px rgba(11,155,152,0.07) !important;">
    <!-- Card Header -->
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 px-4 py-3" style="background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);">
        <h5 class="m-0 fw-bold text-white d-flex align-items-center gap-2" style="font-size: 1rem;">
            <i class="bi bi-inbox-fill"></i> Admission Review Queue
            <?php if ($selectedProgram): ?> <span style="opacity: 0.85; font-weight: 500; font-size: 0.85rem;">&bull; <?php echo htmlspecialchars($selectedProgram); ?></span><?php endif; ?>
            <?php if ($selectedStatus): ?> <span style="opacity: 0.85; font-weight: 500; font-size: 0.85rem;">&bull; <?php echo ucfirst(str_replace('_', ' ', $selectedStatus)); ?></span><?php endif; ?>
        </h5>
        <span class="badge" style="background: rgba(255,255,255,0.22); color: #ffffff !important; border: 1px solid rgba(255,255,255,0.35); border-radius: 999px; padding: 5px 14px; font-size: 0.75rem; font-weight: 700;">
            <?php echo count($applications); ?> Applicant<?php echo count($applications) !== 1 ? 's' : ''; ?>
        </span>
    </div>

    <!-- Table -->
    <div class="card-body p-0">
        <!-- Mobile Scroll Affordance Hint -->
        <div class="d-md-none px-3 py-2 bg-light border-bottom text-muted small d-flex align-items-center gap-2">
            <i class="bi bi-arrows-expand-vertical text-brand-primary"></i>
            <span>Scroll horizontally to review full applicant record</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-maritime align-middle m-0" id="applicationsTable" style="width: 100%;">
                <thead>
                    <tr>
                        <th class="ps-4" tabulator-field="applicant">Applicant</th>
                        <th tabulator-field="program_year">Program / Year</th>
                        <th tabulator-field="documents">Documents</th>
                        <th tabulator-field="submitted">Submitted</th>
                        <th tabulator-field="status">Status</th>
                        <th class="pe-4 text-end" tabulator-field="action">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($applications)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-5 text-muted">
                                <div class="mb-3 fs-1"><i class="bi bi-inbox"></i></div>
                                <h6 class="fw-bold text-dark">No applications found</h6>
                                <p class="small text-muted mb-0">No applicant records match your selected filter criteria or search keyword.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <!-- Hidden row shown by JS when live-search filters out all results -->
                        <tr id="no-results-row" style="display:none;">
                            <td colspan="6" class="text-center py-5 text-muted">
                                <div class="mb-3 fs-1"><i class="bi bi-search"></i></div>
                                <h6 class="fw-bold text-dark">No applications found</h6>
                                <p class="small text-muted mb-0">No applicant records match your search keyword.</p>
                            </td>
                        </tr>
                        <?php foreach ($applications as $app):
                            $studentDocs = $documentsByStudent[(int)$app['id']] ?? [];
                            $requiredDocTypes = ['form_137', 'shs_diploma', 'good_moral', 'birth_certificate', 'medical_clearance', 'id_photo'];
                            if ($app['civil_status'] === 'Married') {
                                $requiredDocTypes[] = 'marriage_certificate';
                            }
                            $uploadedTypes = [];
                            $verifiedCount = 0;
                            foreach ($studentDocs as $doc) {
                                $uploadedTypes[$doc['document_type']] = $doc['status'];
                                if ($doc['status'] === 'verified') {
                                    $verifiedCount++;
                                }
                            }
                            $allVerified = true;
                            foreach ($requiredDocTypes as $reqType) {
                                if (!isset($uploadedTypes[$reqType]) || $uploadedTypes[$reqType] !== 'verified') {
                                    $allVerified = false;
                                }
                            }
                            $fullName = trim($app['first_name'] . ' ' . ($app['middle_name'] ?: '') . ' ' . $app['last_name'] . ($app['suffix'] ? ' ' . $app['suffix'] : ''));
                            $initials = strtoupper(mb_substr($app['first_name'] ?? '', 0, 1) . mb_substr($app['last_name'] ?? '', 0, 1));
                        ?>
                            <tr class="application-row" data-search-text="<?php echo htmlspecialchars(strtolower($fullName . ' ' . ($app['username'] ?? '') . ' ' . ($app['email'] ?? '') . ' ' . ($app['program_applying_for'] ?? '') . ' ' . ($app['applicant_type'] ?? '') . ' ' . ($app['year_level'] ?? ''))); ?>">

                                <!-- Applicant Identity -->
                                <td class="ps-4">
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="app-avatar"><?php echo htmlspecialchars($initials); ?></div>
                                        <div>
                                            <div class="fw-bold text-navy" style="font-size:.91rem;">
                                                <?php echo htmlspecialchars($fullName); ?>
                                            </div>
                                            <div class="text-muted" style="font-size:.78rem;">@<?php echo htmlspecialchars($app['username']); ?></div>
                                            <div style="font-size:.78rem;" class="text-muted">
                                                <i class="bi bi-envelope text-brand-primary me-1" style="font-size:.7rem;"></i><?php echo htmlspecialchars($app['email']); ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                <!-- Program / Year -->
                                <td>
                                    <?php if ($app['program_applying_for']): ?>
                                        <span class="badge bg-secondary-subtle text-navy border fw-bold" style="font-size:.74rem;padding:4px 10px;">
                                            <?php echo htmlspecialchars($app['program_applying_for']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted small">Not selected</span>
                                    <?php endif; ?>
                                    <div class="text-muted mt-1" style="font-size:.75rem;">
                                        <?php echo htmlspecialchars($app['applicant_type'] ?: 'N/A'); ?>
                                        <?php if ($app['year_level']): ?> &bull; <?php echo htmlspecialchars($app['year_level']); ?><?php endif; ?>
                                    </div>
                                </td>

                                <!-- Documents -->
                                <td>
                                    <span class="doc-count-badge <?php echo $allVerified ? 'complete' : 'incomplete'; ?>">
                                        <i class="bi bi-<?php echo $allVerified ? 'check-circle-fill' : 'files'; ?>"></i>
                                        <?php echo $verifiedCount; ?>/<?php echo count($requiredDocTypes); ?> verified
                                    </span>
                                    <div class="text-muted mt-1" style="font-size:.74rem;"><?php echo count($studentDocs); ?> uploaded</div>
                                </td>

                                <!-- Submitted -->
                                <td>
                                    <div class="text-dark" style="font-size:.82rem;"><?php echo date('M d, Y', strtotime($app['created_at'])); ?></div>
                                    <div class="text-muted" style="font-size:.75rem;"><?php echo date('h:i A', strtotime($app['created_at'])); ?></div>
                                </td>

                                <!-- Status -->
                                <td>
                                    <?php
                                    $st = $app['application_status'];
                                    $badgeClass = 'bg-secondary-subtle text-secondary border border-secondary';
                                    if ($st === 'approved') {
                                        $badgeClass = 'bg-success-subtle text-success border border-success';
                                    } elseif ($st === 'pending') {
                                        $badgeClass = 'bg-warning-subtle text-warning-emphasis border border-warning';
                                    } elseif ($st === 'under_review') {
                                        $badgeClass = 'bg-primary-subtle text-primary border border-primary';
                                    } elseif ($st === 'needs_revision') {
                                        $badgeClass = 'bg-info-subtle text-info-emphasis border border-info';
                                    } elseif ($st === 'rejected') {
                                        $badgeClass = 'bg-danger-subtle text-danger border border-danger';
                                    }
                                    ?>
                                    <span class="badge <?php echo $badgeClass; ?> px-2.5 py-1 text-uppercase fw-bold" style="font-size: 0.68rem;">
                                        <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $st))); ?>
                                    </span>
                                </td>

                                <!-- Action -->
                                <td class="pe-4 text-end">
                                    <button type="button"
                                            class="btn btn-sm btn-outline-primary application-details-trigger d-inline-flex align-items-center justify-content-center gap-1.5 fw-semibold shadow-sm"
                                            style="min-height: 40px; padding: 0.4rem 0.85rem;"
                                            data-student-id="<?php echo (int)$app['id']; ?>"
                                            data-full-name="<?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?>">
                                        <i class="bi bi-person-vcard"></i> Review
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
    </div>
</div>

<!-- Complete applicant details stay on the same page; document review opens from this modal. -->
<div id="application-details-modal" class="application-details-modal d-none" role="dialog" aria-modal="true" aria-labelledby="application-details-title" aria-hidden="true">
    <div class="application-details-backdrop" onclick="closeApplicationDetails()"></div>
    <div class="application-details-dialog" role="document">
        <div class="application-details-header">
            <div>
                <div class="page-eyebrow mb-1"><i class="bi bi-person-vcard me-1"></i>Applicant record</div>
                <h4 id="application-details-title" class="m-0 text-navy-alt">Application details</h4>
                <div id="application-details-subtitle" class="text-muted small mt-1">Review the complete admission record.</div>
            </div>
            <button type="button" class="application-details-close" aria-label="Close applicant details" onclick="closeApplicationDetails()"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="application-details-body">
            <div class="application-details-summary-row">
                <div><span class="detail-label">Application ID</span><strong id="details-app-id">—</strong></div>
                <div><span class="detail-label">Submitted</span><strong id="details-submitted">—</strong></div>
                <div><span class="detail-label">Application status</span><span id="details-status" class="badge-status status-pending">Pending</span></div>
                <div><span class="detail-label">Document status</span><strong id="details-doc-count">—</strong></div>
            </div>
            <section class="application-details-section">
                <h6><i class="bi bi-person me-2"></i>Applicant information</h6>
                <div class="application-details-grid">
                    <div><span class="detail-label">Full name</span><strong id="details-full-name">—</strong></div>
                    <div><span class="detail-label">Username</span><strong id="details-username">—</strong></div>
                    <div><span class="detail-label">Email</span><strong id="details-email">—</strong></div>
                    <div><span class="detail-label">Contact number</span><strong id="details-contact">—</strong></div>
                    <div><span class="detail-label">Age</span><strong id="details-age">—</strong></div>
                    <div><span class="detail-label">Birthdate</span><strong id="details-birthdate">—</strong></div>
                    <div><span class="detail-label">Place of birth</span><strong id="details-place-of-birth">—</strong></div>
                    <div><span class="detail-label">Gender</span><strong id="details-gender">—</strong></div>
                    <div><span class="detail-label">Civil status</span><strong id="details-civil-status">—</strong></div>
                    <div><span class="detail-label">Nationality</span><strong id="details-nationality">—</strong></div>
                    <div><span class="detail-label">Religion</span><strong id="details-religion">—</strong></div>
                </div>
            </section>
            <section class="application-details-section">
                <h6><i class="bi bi-geo-alt me-2"></i>Address and guardian</h6>
                <div class="application-details-grid">
                    <div class="detail-span-2"><span class="detail-label">Full address</span><strong id="details-address">—</strong></div>
                    <div><span class="detail-label">Street</span><strong id="details-address-street">—</strong></div>
                    <div><span class="detail-label">Barangay</span><strong id="details-address-barangay">—</strong></div>
                    <div><span class="detail-label">City / Municipality</span><strong id="details-address-city">—</strong></div>
                    <div><span class="detail-label">Province</span><strong id="details-address-province">—</strong></div>
                    <div><span class="detail-label">ZIP code</span><strong id="details-address-zip">—</strong></div>
                    <div><span class="detail-label">Guardian name</span><strong id="details-guardian-name">—</strong></div>
                    <div><span class="detail-label">Relationship</span><strong id="details-guardian-relationship">—</strong></div>
                    <div><span class="detail-label">Guardian contact</span><strong id="details-guardian-contact">—</strong></div>
                    <div class="detail-span-2"><span class="detail-label">Guardian address</span><strong id="details-guardian-address">—</strong></div>
                </div>
            </section>
            <section class="application-details-section">
                <h6><i class="bi bi-mortarboard me-2"></i>Academic background</h6>
                <div class="application-details-grid">
                    <div><span class="detail-label">Applicant type</span><strong id="details-applicant-type">—</strong></div>
                    <div><span class="detail-label">Year level</span><strong id="details-year-level">—</strong></div>
                    <div class="detail-span-2"><span class="detail-label">Program applying for</span><strong id="details-program">—</strong></div>
                    <div><span class="detail-label">SHS name</span><strong id="details-shs-name">—</strong></div>
                    <div><span class="detail-label">SHS type</span><strong id="details-shs-type">—</strong></div>
                    <div><span class="detail-label">Track / strand</span><strong id="details-strand">—</strong></div>
                    <div><span class="detail-label">Year graduated</span><strong id="details-year-graduated">—</strong></div>
                    <div><span class="detail-label">General average</span><strong id="details-general-average">—</strong></div>
                </div>
            </section>
            <section class="application-details-section">
                <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
                    <h6 class="mb-0"><i class="bi bi-file-earmark-check me-2"></i>Submitted documents</h6>
                    <div class="d-flex align-items-center flex-wrap gap-2">
                        <span id="details-doc-heading-count" class="badge-status status-pending">—</span>
                        <button type="button" id="details-preview-all-button" class="btn btn-outline-info btn-sm"><i class="bi bi-files me-1"></i>Preview all</button>
                        <button type="button" id="details-verify-all-button" class="btn btn-outline-success btn-sm"><i class="bi bi-check2-square me-1"></i>Verify selected</button>
                    </div>
                </div>
                <div id="details-document-note" class="alert alert-warning py-2 px-3 mb-3 d-none" role="alert"></div>
                <div id="details-documents-list" class="details-documents-list"></div>
            </section>
        </div>
        <div class="application-details-footer d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div id="details-override-container" class="form-check me-auto d-none" style="text-align: left;">
                <input class="form-check-input" type="checkbox" id="details-override-checkbox">
                <label class="form-check-label small text-warning-emphasis fw-semibold" for="details-override-checkbox">
                    Authorize conditional admission (missing / unverified documents)
                </label>
            </div>
            <div class="d-flex align-items-center gap-2 ms-auto flex-wrap">
                <button type="button" id="details-remark-button" class="btn btn-outline-secondary"><i class="bi bi-chat-left-text me-1"></i>Add remark</button>
                <button type="button" id="details-approve-button" class="btn btn-brand-primary"><i class="bi bi-check-lg me-1"></i>Approve application</button>
                <button type="button" id="details-reject-button" class="btn btn-outline-danger"><i class="bi bi-x-lg me-1"></i>Reject application</button>
                <button type="button" id="details-edits-button" class="btn btn-outline-warning"><i class="bi bi-pencil-square me-1"></i>Request edits</button>
            </div>
        </div>
    </div>
</div>

<!-- Preview All Documents Gallery Modal -->
<div id="preview-all-modal" class="preview-all-modal d-none" role="dialog" aria-modal="true" aria-labelledby="preview-all-title" aria-hidden="true">
    <div class="preview-all-backdrop" onclick="closePreviewAllModal()"></div>
    <div class="preview-all-dialog" role="document">
        <div class="preview-all-header">
            <div class="preview-all-header-info">
                <div class="page-eyebrow mb-1"><i class="bi bi-files me-1"></i>All submitted documents</div>
                <h5 id="preview-all-title" class="m-0 text-navy-alt">Preview All Files</h5>
                <div id="preview-all-subtitle" class="text-muted small mt-1">—</div>
            </div>
            <button type="button" class="preview-all-close" aria-label="Close preview" onclick="closePreviewAllModal()"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="preview-all-body">
            <!-- LEFT: document iframe viewer -->
            <section class="preview-all-viewer">
                <div class="preview-all-viewer-toolbar">
                    <div>
                        <span id="preview-all-doc-label" class="fw-semibold text-dark">—</span>
                        <span id="preview-all-doc-status" class="badge-status status-pending ms-2">—</span>
                    </div>
                    <a id="preview-all-open-link" href="#" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-box-arrow-up-right me-1"></i>Open file
                    </a>
                </div>
                <div class="preview-all-frame-wrap">
                    <iframe id="preview-all-iframe" title="Document preview" loading="lazy"></iframe>
                </div>
                <div class="preview-all-nav">
                    <button type="button" id="preview-all-prev" class="btn btn-outline-secondary btn-sm" onclick="navigatePreviewAll(-1)"><i class="bi bi-chevron-left me-1"></i>Previous</button>
                    <span id="preview-all-counter" class="text-muted small">1 / 1</span>
                    <button type="button" id="preview-all-next" class="btn btn-outline-secondary btn-sm" onclick="navigatePreviewAll(1)">Next<i class="bi bi-chevron-right ms-1"></i></button>
                </div>
            </section>
            <!-- RIGHT: document list sidebar -->
            <aside class="preview-all-sidebar">
                <div class="preview-all-sidebar-header">Documents</div>
                <div id="preview-all-list" class="preview-all-list"></div>
            </aside>
        </div>
    </div>
</div>

<!-- One shared document-review modal keeps the page DOM small and responsive. -->
<div id="document-review-modal" class="document-review-modal d-none" role="dialog" aria-modal="true" aria-labelledby="document-review-title" aria-hidden="true">
    <div class="document-review-modal-backdrop" onclick="closeDocumentReview()"></div>
    <div class="document-review-modal-dialog" role="document">
        <div class="document-review-modal-header">
            <div>
                <div class="page-eyebrow mb-1"><i class="bi bi-file-earmark-check me-1"></i>Credential review</div>
                <h5 id="document-review-title" class="m-0 text-navy-alt">Select a document to review</h5>
            </div>
            <button type="button" class="document-review-modal-close" aria-label="Close document review" onclick="closeDocumentReview()">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div class="document-review-modal-body">
            <section class="document-review-preview" aria-label="Document preview">
                <div class="document-review-preview-toolbar">
                    <div>
                        <span class="document-review-section-label"><i class="bi bi-eye me-1"></i>Preview</span>
                        <span class="document-review-preview-hint">Review the uploaded credential before making a decision.</span>
                    </div>
                    <a id="document-review-open" class="document-review-open-link" href="#" target="_blank" rel="noopener">
                        <i class="bi bi-box-arrow-up-right me-1"></i>Open file
                    </a>
                </div>
                <div class="document-preview-frame">
                    <iframe id="document-preview" title="Document preview" loading="lazy"></iframe>
                </div>
            </section>
            <aside class="document-review-details">
                <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
                    <div>
                        <div class="text-muted small">Uploaded file</div>
                        <div id="document-review-filename" class="fw-semibold text-dark text-break">—</div>
                    </div>
                    <span id="document-review-status" class="badge-status status-pending">Pending</span>
                </div>
                <div class="document-review-note mb-3">
                    <i class="bi bi-info-circle me-2"></i>Review the file carefully. Approve it only when the credential is complete and valid.
                </div>
                <label for="document-rejection-reason" class="form-label small fw-semibold">Rejection reason <span class="text-danger">(required when rejecting)</span></label>
                <textarea id="document-rejection-reason" class="form-control form-control-sm mb-3" rows="3" placeholder="Explain what the applicant needs to correct..."></textarea>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-sm btn-brand-primary" onclick="submitDocumentReview('verified')">
                        <i class="bi bi-check2-circle me-1"></i>Verify document
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="submitDocumentReview('rejected')">
                        <i class="bi bi-x-circle me-1"></i>Reject document
                    </button>
                </div>
            </aside>
        </div>
    </div>
</div>

<!-- Hidden action forms -->
<form id="approveForm" action="../actions/enrollee_actions" method="POST" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
    <input type="hidden" name="action" value="approve">
    <input type="hidden" name="student_id" id="approve_student_id">
    <input type="hidden" name="registrar_override_missing_docs" id="approve_override_missing_docs" value="0">
</form>

<form id="rejectForm" action="../actions/enrollee_actions" method="POST" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
    <input type="hidden" name="action" value="reject">
    <input type="hidden" name="student_id" id="reject_student_id">
</form>

<form id="verifyDocForm" action="../actions/enrollee_actions" method="POST" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
    <input type="hidden" name="action" value="verify_document">
    <input type="hidden" name="document_id" id="verify_doc_id">
    <input type="hidden" name="status" id="verify_doc_status">
    <input type="hidden" name="rejection_reason" id="verify_doc_rejection_reason">
    <input type="hidden" name="ajax_request" value="1">
</form>

<form id="verifyAllDocsForm" action="../actions/enrollee_actions" method="POST" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
    <input type="hidden" name="action" value="verify_all_documents">
    <input type="hidden" name="student_id" id="verify_all_student_id">
</form>

<form id="verifySelectedDocsForm" action="../actions/enrollee_actions" method="POST" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
    <input type="hidden" name="action" value="verify_selected_documents">
    <div id="verify_selected_document_inputs"></div>
</form>

<form id="requestEditsForm" action="../actions/enrollee_actions" method="POST" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
    <input type="hidden" name="action" value="request_edits">
    <input type="hidden" name="student_id" id="request_edits_student_id">
    <input type="hidden" name="revision_notes" id="request_edits_notes">
</form>
<form id="remarkForm" action="../actions/enrollee_actions" method="POST" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
    <input type="hidden" name="action" value="add_remark">
    <input type="hidden" name="student_id" id="remark_student_id">
    <input type="hidden" name="remark" id="remark_text">
</form>

<!-- =========================================================================
     JAVASCRIPT CONTROLLERS
     ========================================================================= -->
<script>
    // Relocate hidden forms to body on load to prevent any container stacking contexts
    document.addEventListener("DOMContentLoaded", function() {
        document.body.appendChild(document.getElementById('approveForm'));
        document.body.appendChild(document.getElementById('rejectForm'));
        document.body.appendChild(document.getElementById('verifyDocForm'));
        document.body.appendChild(document.getElementById('verifyAllDocsForm'));
        document.body.appendChild(document.getElementById('verifySelectedDocsForm'));
        document.body.appendChild(document.getElementById('requestEditsForm'));
        document.body.appendChild(document.getElementById('remarkForm'));
    });

    // Trigger confirmation alert for approval
    function confirmApproval(studentId, fullName, isOverride = false) {
        const title = isOverride ? 'Authorize Conditional Admission?' : 'Approve Admission Application?';
        const text = isOverride
            ? `You are authorizing conditional admission for ${fullName} with unverified/missing credentials. An audit log with your registrar credentials will be recorded, and follow-up physical documents must be presented at walk-in.`
            : `Are you sure you want to approve the application of ${fullName}? This will allow them to choose a class section and proceed with enrollment.`;
        const confirmBtnText = isOverride ? 'Yes, authorize conditional admission' : 'Yes, approve!';
        const confirmBtnColor = isOverride ? '#d97706' : 'var(--brand-primary)';

        Swal.fire({
            title: title,
            text: text,
            icon: isOverride ? 'warning' : 'question',
            iconColor: isOverride ? '#d97706' : '#0b9b98',
            showCancelButton: true,
            confirmButtonColor: confirmBtnColor,
            cancelButtonColor: '#6c757d',
            confirmButtonText: confirmBtnText,
            cancelButtonText: 'Cancel',
            background: '#ffffff',
            color: '#1f2937'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('approve_student_id').value = studentId;
                document.getElementById('approve_override_missing_docs').value = isOverride ? '1' : '0';
                document.getElementById('approveForm').submit();
            }
        });
    }

    // Trigger warning confirmation alert for rejection (destructive action)
    function confirmRejection(studentId, fullName) {
        Swal.fire({
            title: 'Reject Admission Application?',
            text: `You are about to reject ${fullName}'s application. This action is destructive and will restrict further enrollment steps.`,
            icon: 'question',
            iconColor: '#d9535f',
            showCancelButton: true,
            confirmButtonColor: 'var(--color-danger)',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, reject application!',
            cancelButtonText: 'Cancel',
            background: '#ffffff',
            color: '#1f2937'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('reject_student_id').value = studentId;
                document.getElementById('rejectForm').submit();
            }
        });
    }

    let activeApplication = null;
    const documentLabels = {
        form_137: 'Form 137 / SF-10', shs_diploma: 'SHS Diploma', good_moral: 'Good Moral Certificate',
        birth_certificate: 'PSA Birth Certificate', marriage_certificate: 'PSA Marriage Certificate',
        medical_clearance: 'Medical Clearance', id_photo: '2x2 Photo (Formal)'
    };
    function detailText(value) { return value === null || value === undefined || String(value).trim() === '' ? 'N/A' : String(value); }
    function setDetail(id, value) { const element = document.getElementById(id); if (element) element.textContent = detailText(value); }
    function formatApplicationDate(value) {
        if (!value) return 'N/A';
        const date = new Date(String(value).replace(' ', 'T'));
        return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
    }

    function statusClass(status) {
        const normalized = String(status || '').toLowerCase();
        if (normalized === 'approved' || normalized === 'verified' || normalized === 'enrolled' || normalized === 'paid') return 'status-approved';
        if (normalized === 'rejected') return 'status-rejected';
        if (normalized === 'needs_revision') return 'status-needs_revision';
        if (normalized === 'draft') return 'status-draft';
        return 'status-pending';
    }

    function getSelectedDocumentIds() {
        const selectedIds = [];
        document.querySelectorAll('.document-select-checkbox:checked').forEach(function (checkbox) {
            const documentId = Number(checkbox.value);
            if (!Number.isNaN(documentId) && documentId > 0) {
                selectedIds.push(documentId);
            }
        });
        return selectedIds;
    }

    function updateSelectedDocumentButtonState() {
        const verifyButton = document.getElementById('details-verify-all-button');
        if (!verifyButton) return;
        const selectedIds = getSelectedDocumentIds();
        const hasSelection = selectedIds.length > 0;
        verifyButton.disabled = !hasSelection;
        verifyButton.title = hasSelection ? 'Verify only the selected documents' : 'Select at least one document to verify';
    }

    function renderApplicationDocuments(application) {
        const list = document.getElementById('details-documents-list');
        if (!list) return;
        const documents = Array.isArray(application.documents) ? application.documents : [];
        const required = Array.isArray(application.required_documents) ? application.required_documents : [];
        const documentByType = new Map(documents.map(function (document) {
            return [document.document_type, document];
        }));
        const fragment = document.createDocumentFragment();

        required.forEach(function (type) {
            const documentRecord = documentByType.get(type);
            const documentStatus = documentRecord ? documentRecord.status : 'missing';
            const item = document.createElement('div');
            item.className = 'details-document-item d-flex justify-content-between align-items-center gap-2';

            const info = document.createElement('div');
            info.className = 'd-flex align-items-center gap-2 flex-grow-1';
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.className = 'form-check-input document-select-checkbox';
            checkbox.value = documentRecord ? String(documentRecord.id) : '';
            checkbox.disabled = !documentRecord;
            checkbox.title = documentRecord ? 'Select this document for verification' : 'No document uploaded';
            checkbox.setAttribute('aria-label', 'Select ' + (documentLabels[type] || type));
            const label = document.createElement('strong');
            label.textContent = documentLabels[type] || type;
            const statusBadge = document.createElement('span');
            statusBadge.className = 'badge-status ' + (documentStatus === 'missing' ? 'status-draft' : statusClass(documentStatus));
            statusBadge.textContent = documentStatus === 'missing' ? 'Missing' : documentStatus.charAt(0).toUpperCase() + documentStatus.slice(1);
            info.append(checkbox, label, statusBadge);
            item.appendChild(info);

            const actions = document.createElement('div');
            actions.className = 'd-flex align-items-center gap-2';
            if (documentRecord) {
                const viewButton = document.createElement('button');
                viewButton.type = 'button';
                viewButton.className = 'btn btn-sm btn-outline-secondary document-view-trigger';
                viewButton.dataset.studentId = application.id;
                viewButton.dataset.documentId = documentRecord.id;
                viewButton.dataset.documentLabel = documentLabels[type] || type;
                viewButton.dataset.documentStatus = documentRecord.status;
                viewButton.dataset.documentFile = documentRecord.file_url || '';
                viewButton.dataset.documentName = documentRecord.original_filename || '';
                viewButton.innerHTML = '<i class="bi bi-eye me-1"></i>Preview';
                actions.appendChild(viewButton);
            } else {
                const missing = document.createElement('span');
                missing.className = 'text-muted small';
                missing.textContent = 'Awaiting upload';
                actions.appendChild(missing);
            }
            item.appendChild(actions);
            fragment.appendChild(item);
        });
        list.replaceChildren(fragment);
        updateSelectedDocumentButtonState();
    }

    function updateApplicationDocumentSummary(application) {
        const documents = Array.isArray(application.documents) ? application.documents : [];
        const required = Array.isArray(application.required_documents) ? application.required_documents : [];
        const requiredTypes = new Set(required);
        const verifiedCount = documents.filter(function (document) {
            return requiredTypes.has(document.document_type) && document.status === 'verified';
        }).length;
        application.verified_count = verifiedCount;
        application.all_verified = required.length > 0 && verifiedCount === required.length;
        const documentSummary = verifiedCount + '/' + required.length + ' verified';
        setDetail('details-doc-count', documentSummary);
        setDetail('details-doc-heading-count', documentSummary);
        const overrideCheckbox = document.getElementById('details-override-checkbox');
        const overrideContainer = document.getElementById('details-override-container');
        if (overrideContainer) {
            const isPending = ['pending', 'under_review'].includes(String(application.application_status || '').toLowerCase());
            if (!application.all_verified && isPending) {
                overrideContainer.classList.remove('d-none');
            } else {
                overrideContainer.classList.add('d-none');
                if (overrideCheckbox) overrideCheckbox.checked = false;
            }
        }

        const approveButton = document.getElementById('details-approve-button');
        if (approveButton) {
            const isOverrideChecked = Boolean(overrideCheckbox && overrideCheckbox.checked);
            const canApprove = Boolean(application.all_verified || isOverrideChecked);
            approveButton.disabled = !canApprove;
            if (application.all_verified) {
                approveButton.title = 'Approve this application';
                approveButton.classList.remove('btn-warning');
                approveButton.classList.add('btn-brand-primary');
            } else if (isOverrideChecked) {
                approveButton.title = 'Authorize conditional admission with unverified credentials';
                approveButton.classList.remove('btn-brand-primary');
                approveButton.classList.add('btn-warning');
            } else {
                approveButton.title = 'Verify all mandatory documents first, or authorize conditional admission';
                approveButton.classList.remove('btn-warning');
                approveButton.classList.add('btn-brand-primary');
            }
        }

        const verifyAllButton = document.getElementById('details-verify-all-button');
        if (verifyAllButton) {
            const hasPendingDocuments = Array.isArray(application.documents) && application.documents.some(function (document) {
                return document.status !== 'verified' && document.status !== 'rejected';
            });
            verifyAllButton.disabled = !hasPendingDocuments || getSelectedDocumentIds().length === 0;
            verifyAllButton.title = hasPendingDocuments ? 'Verify only the selected documents' : 'All uploaded documents are already verified or rejected';
        }

        const noteBox = document.getElementById('details-document-note');
        const followUpNote = (application && (application.ack_submit_without_docs || application.follow_up_document_note))
            ? (application.follow_up_document_note || 'Applicant chose to submit this application with follow-up documents to be provided later.')
            : '';
        if (noteBox) {
            if (followUpNote) {
                noteBox.textContent = followUpNote;
                noteBox.classList.remove('d-none');
            } else {
                noteBox.textContent = '';
                noteBox.classList.add('d-none');
            }
        }

        const previewAllButton = document.getElementById('details-preview-all-button');
        if (previewAllButton) {
            const hasDocuments = Array.isArray(application.documents) && application.documents.length > 0;
            previewAllButton.disabled = !hasDocuments;
            previewAllButton.title = hasDocuments ? 'Open every uploaded file for review' : 'No uploaded documents are available to preview';
        }
    }

    function openApplicationDetails(application) {
        activeApplication = application;
        const modal = document.getElementById('application-details-modal');
        const fullName = [application.first_name, application.middle_name, application.last_name, application.suffix].filter(Boolean).join(' ');
        const documents = Array.isArray(application.documents) ? application.documents : [];
        const required = Array.isArray(application.required_documents) ? application.required_documents : [];
        const verifiedCount = Number(application.verified_count || 0);
        const documentSummary = verifiedCount + '/' + required.length + ' verified';

        setDetail('application-details-title', fullName);
        setDetail('application-details-subtitle', detailText(application.applicant_type) + ' · ' + detailText(application.program_applying_for));
        setDetail('details-app-id', '#' + application.id);
        setDetail('details-submitted', formatApplicationDate(application.created_at));
        setDetail('details-full-name', fullName);
        setDetail('details-username', application.username);
        setDetail('details-email', application.email);
        setDetail('details-contact', application.contact_number);
        setDetail('details-age', application.age);
        setDetail('details-birthdate', application.birthdate);
        setDetail('details-place-of-birth', application.place_of_birth);
        setDetail('details-gender', application.gender);
        setDetail('details-civil-status', application.civil_status);
        setDetail('details-nationality', application.nationality);
        setDetail('details-religion', application.religion);
        setDetail('details-address', [application.address_street, application.address_barangay, application.address_city, application.address_province, application.address_zip_code].filter(Boolean).join(', '));
        setDetail('details-address-street', application.address_street);
        setDetail('details-address-barangay', application.address_barangay);
        setDetail('details-address-city', application.address_city);
        setDetail('details-address-province', application.address_province);
        setDetail('details-address-zip', application.address_zip_code);

        setDetail('details-guardian-name', application.guardian_name);
        setDetail('details-guardian-relationship', application.guardian_relationship);
        setDetail('details-guardian-contact', application.guardian_contact_number);
        setDetail('details-guardian-address', application.guardian_address);
        setDetail('details-applicant-type', application.applicant_type);
        setDetail('details-year-level', application.year_level);
        setDetail('details-program', application.program_applying_for);
        setDetail('details-shs-name', application.shs_name);
        setDetail('details-shs-type', application.shs_type);
        setDetail('details-strand', application.shs_track_strand);
        setDetail('details-year-graduated', application.year_graduated);
        setDetail('details-general-average', application.general_average);
        setDetail('details-doc-count', documentSummary);
        setDetail('details-doc-heading-count', documentSummary);

        const statusElement = document.getElementById('details-status');
        statusElement.textContent = detailText(application.application_status).replace('_', ' ');
        statusElement.className = 'badge-status ' + statusClass(application.application_status);

        const overrideCheckbox = document.getElementById('details-override-checkbox');
        if (overrideCheckbox) {
            overrideCheckbox.checked = false;
            overrideCheckbox.onchange = function () {
                updateApplicationDocumentSummary(application);
            };
        }

        renderApplicationDocuments(application);
        updateApplicationDocumentSummary(application);

        const approveButton = document.getElementById('details-approve-button');
        approveButton.onclick = function () {
            const isOverride = Boolean(overrideCheckbox && overrideCheckbox.checked && !application.all_verified);
            closeApplicationDetails();
            confirmApproval(application.id, fullName, isOverride);
        };
        document.getElementById('details-reject-button').onclick = function () { closeApplicationDetails(); confirmRejection(application.id, fullName); };
        document.getElementById('details-edits-button').onclick = function () { closeApplicationDetails(); requestEdits(application.id, fullName); };
        document.getElementById('details-remark-button').onclick = function () { addRemark(application.id, fullName); };
        document.getElementById('details-preview-all-button').onclick = function () { previewAllDocuments(application); };
        document.getElementById('details-verify-all-button').onclick = function () { closeApplicationDetails(); verifySelectedDocuments(fullName); };
        modal.classList.remove('d-none');
        modal.setAttribute('aria-hidden', 'false');
    }
    function closeApplicationDetails() {
        const modal = document.getElementById('application-details-modal');
        modal.classList.add('d-none');
        modal.setAttribute('aria-hidden', 'true');
        const overrideCheckbox = document.getElementById('details-override-checkbox');
        if (overrideCheckbox) overrideCheckbox.checked = false;
        activeApplication = null;
    }
    function stripHtml(html) {
        if (!html) return '';
        var tmp = document.createElement('DIV');
        tmp.innerHTML = html;
        return (tmp.textContent || tmp.innerText || '').trim();
    }

    // Live search: filter the server-rendered HTML rows using data-search-text attributes.
    document.addEventListener('DOMContentLoaded', function () {
        const appSearch = document.getElementById('applicationSearch');
        if (!appSearch) return;
        appSearch.addEventListener('input', function () {
            var term = this.value.trim().toLowerCase();
            var rows = document.querySelectorAll('#applicationsTable tbody tr.application-row');
            var visibleCount = 0;
            rows.forEach(function (row) {
                var text = (row.dataset.searchText || '').toLowerCase();
                var match = !term || text.includes(term);
                row.style.display = match ? '' : 'none';
                if (match) visibleCount++;
            });
            // Show or hide the empty-state row
            var emptyRow = document.getElementById('no-results-row');
            if (emptyRow) {
                emptyRow.style.display = (visibleCount === 0) ? '' : 'none';
            }
        });
    });

    document.addEventListener('change', function (event) {
        if (event.target.matches('.document-select-checkbox')) {
            updateSelectedDocumentButtonState();
        }
    });

    // One delegated click handler covers both detail and document actions.
    document.addEventListener('click', function (event) {
        const detailsTrigger = event.target.closest('.application-details-trigger');
        if (detailsTrigger) {
            const studentId = detailsTrigger.dataset.studentId;
            if (!studentId) return;
            // Show loading state while fetching from the API.
            detailsTrigger.disabled = true;
            fetch('application_detail_api?student_id=' + encodeURIComponent(studentId), {
                method: 'GET',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                credentials: 'same-origin'
            })
            .then(function (res) { return res.json(); })
            .then(function (json) {
                if (!json.success || !json.data) throw new Error(json.message || 'Failed to load application.');
                openApplicationDetails(json.data);
            })
            .catch(function (err) {
                Swal.fire({
                    icon: 'error',
                    title: 'Unable to open application',
                    text: 'The application details could not be loaded.',
                    iconColor: '#d9535f',
                    confirmButtonColor: '#6c757d',
                    background: '#ffffff',
                    color: '#1f2937'
                });
            })
            .finally(function () {
                detailsTrigger.disabled = false;
            });
            return;
        }

        const trigger = event.target.closest('.document-view-trigger');
        if (!trigger) return;

        const panel = document.getElementById('document-review-modal');
        const iframe = document.getElementById('document-preview');
        const title = document.getElementById('document-review-title');
        const filename = document.getElementById('document-review-filename');
        const status = document.getElementById('document-review-status');
        const reason = document.getElementById('document-rejection-reason');
        const openFile = document.getElementById('document-review-open');
        const documentStatus = trigger.dataset.documentStatus;

        title.textContent = trigger.dataset.documentLabel;
        filename.textContent = trigger.dataset.documentName || 'Uploaded document';
        iframe.src = trigger.dataset.documentFile;
        if (openFile) openFile.href = trigger.dataset.documentFile;
        panel.dataset.documentId = trigger.dataset.documentId;
        panel.dataset.documentStatus = documentStatus;
        reason.value = '';

        status.textContent = documentStatus === 'verified'
            ? 'Verified'
            : (documentStatus === 'rejected' ? 'Rejected' : 'Pending');
        status.className = 'badge-status ' + (
            documentStatus === 'verified'
                ? 'status-approved'
                : (documentStatus === 'rejected' ? 'status-rejected' : 'status-pending')
        );

        panel.classList.remove('d-none');
        panel.setAttribute('aria-hidden', 'false');
        window.setTimeout(function () {
            const closeButton = panel.querySelector('.document-review-modal-close');
            if (closeButton) closeButton.focus();
        }, 50);
    });

    function closeDocumentReview() {
        const panel = document.getElementById('document-review-modal');
        const iframe = document.getElementById('document-preview');
        panel.classList.add('d-none');
        panel.setAttribute('aria-hidden', 'true');
        iframe.src = '';
    }

    async function submitDocumentReview(decision) {
        const panel = document.getElementById('document-review-modal');
        const documentId = panel.dataset.documentId;
        const reasonField = document.getElementById('document-rejection-reason');
        const reason = reasonField.value.trim();

        if (!documentId) {
            Swal.fire({
                icon: 'info',
                title: 'Select a document first',
                text: 'Open a document using the View button before making a decision.',
                iconColor: '#0b9b98',
                confirmButtonColor: '#6c757d',
                background: '#ffffff',
                color: '#1f2937'
            });
            return;
        }

        if (decision === 'rejected' && !reason) {
            reasonField.focus();
            Swal.fire({
                icon: 'warning',
                title: 'Rejection reason required',
                text: 'Please explain what needs to be corrected before rejecting this document.',
                iconColor: '#d99a1d',
                confirmButtonColor: '#d99a1d',
                background: '#ffffff',
                color: '#1f2937'
            });
            return;
        }

        const result = await Swal.fire({
            icon: decision === 'verified' ? 'question' : 'question',
            title: decision === 'verified' ? 'Verify this document?' : 'Reject this document?',
            text: decision === 'verified' ? 'This credential will be marked as verified.' : 'The applicant will see the rejection reason and may need to upload a corrected file.',
            iconColor: decision === 'verified' ? '#0b9b98' : '#d9535f',
            showCancelButton: true,
            heightAuto: false,
            returnFocus: false,
            confirmButtonText: decision === 'verified' ? 'Yes, verify' : 'Yes, reject',
            cancelButtonText: 'Cancel',
            confirmButtonColor: decision === 'verified' ? 'var(--brand-primary)' : 'var(--color-danger)',
            cancelButtonColor: '#6c757d',
            background: '#ffffff',
            color: '#1f2937'
        });
        if (!result.isConfirmed) return;

        const form = document.getElementById('verifyDocForm');
        document.getElementById('verify_doc_id').value = documentId;
        document.getElementById('verify_doc_status').value = decision;
        document.getElementById('verify_doc_rejection_reason').value = decision === 'rejected' ? reason : '';

        try {
            // Add the marker to the URL as well as the form/header. This avoids
            // local Apache/PHP configurations dropping the AJAX request signal.
            // The hidden input name="action" can shadow HTMLFormElement.action
            // in some browsers. Read the actual attribute to avoid posting to
            // /registrar/[object%20HTMLInputElement] or [object%20HTMLFormElement].
            const actionUrl = form.getAttribute('action');
            if (!actionUrl) {
                throw new Error('The document review form has no action endpoint.');
            }
            const requestUrl = new URL(actionUrl, window.location.href);
            requestUrl.searchParams.set('ajax', '1');
            const response = await fetch(requestUrl.toString(), {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                body: new FormData(form),
                credentials: 'same-origin'
            });
            const responseText = await response.text();
            let payload = null;
            try {
                payload = responseText ? JSON.parse(responseText) : null;
            } catch (parseError) {
                // Non-JSON response — the server returned an unexpected format.
                // Details are logged server-side; do not expose response body here.
            }
            if (!response.ok || !payload || payload.success !== true) {
                throw new Error(payload && payload.message ? payload.message : 'The server could not save the document decision.');
            }

            // The database save has succeeded. Keep UI rendering failures from
            // being reported as a failed verification/rejection.
            try {
                if (activeApplication && Array.isArray(activeApplication.documents)) {
                    const documentRecord = activeApplication.documents.find(function (document) {
                        return String(document.id) === String(documentId);
                    });
                    if (documentRecord) {
                        documentRecord.status = decision;
                        documentRecord.rejection_reason = decision === 'rejected' ? reason : null;
                    }
                    renderApplicationDocuments(activeApplication);
                    updateApplicationDocumentSummary(activeApplication);
                }
            } catch (renderError) {
                // Rendering failed after a successful save — close the modal anyway.
                // Errors are intentionally not logged to the console in production.
            }

            closeDocumentReview();
            Swal.fire({
                icon: 'success',
                title: decision === 'verified' ? 'Document verified' : 'Document rejected',
                text: decision === 'verified' ? 'The document was marked as verified.' : 'The document was rejected with the recorded reason.',
                iconColor: '#22a06b',
                confirmButtonColor: '#22a06b',
                timer: 1500,
                showConfirmButton: false,
                background: '#ffffff',
                color: '#1f2937'
            });
        } catch (error) {
            Swal.fire({
                icon: 'error',
                title: 'Unable to save decision',
                text: error.message,
                iconColor: '#d9535f',
                confirmButtonColor: '#6c757d',
                background: '#ffffff',
                color: '#1f2937'
            });
        }
    }

    // ── Preview All Gallery Modal ─────────────────────────────────────────────
    let previewAllDocs = [];
    let previewAllIndex = 0;

    const documentLabelsAll = {
        form_137: 'Form 137 / SF-10', shs_diploma: 'SHS Diploma', good_moral: 'Good Moral Certificate',
        birth_certificate: 'PSA Birth Certificate', marriage_certificate: 'PSA Marriage Certificate',
        medical_clearance: 'Medical Clearance', id_photo: '2x2 Photo (Formal)'
    };

    function previewAllDocuments(application) {
        const documents = Array.isArray(application.documents) ? application.documents : [];
        const uploaded = documents.filter(function (d) { return d.file_url; });

        if (uploaded.length === 0) {
            Swal.fire({
                icon: 'info',
                title: 'No uploaded documents',
                text: 'This application has no files to preview yet.',
                iconColor: '#0b9b98',
                confirmButtonColor: '#6c757d',
                background: '#ffffff',
                color: '#1f2937'
            });
            return;
        }

        previewAllDocs = uploaded;
        previewAllIndex = 0;

        const fullName = [application.first_name, application.middle_name, application.last_name, application.suffix].filter(Boolean).join(' ');
        document.getElementById('preview-all-title').textContent = fullName + ' — Files';
        document.getElementById('preview-all-subtitle').textContent = uploaded.length + ' uploaded document' + (uploaded.length !== 1 ? 's' : '') + ' · Application #' + application.id;

        // Build sidebar list
        const list = document.getElementById('preview-all-list');
        list.innerHTML = '';
        uploaded.forEach(function (doc, idx) {
            const label = documentLabelsAll[doc.document_type] || doc.document_type || 'Document';
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'preview-all-list-item' + (idx === 0 ? ' active' : '');
            item.dataset.index = idx;
            item.innerHTML =
                '<span class="preview-all-list-icon"><i class="bi bi-file-earmark-text"></i></span>' +
                '<span class="preview-all-list-info">' +
                    '<span class="preview-all-list-label">' + escapeHtml(label) + '</span>' +
                    '<span class="preview-all-list-filename">' + escapeHtml(doc.original_filename || '(unnamed)') + '</span>' +
                '</span>' +
                '<span class="badge-status ' + statusClass(doc.status) + ' ms-auto" style="font-size:0.65rem;">' + escapeHtml(doc.status || 'pending') + '</span>';
            item.addEventListener('click', function () { setPreviewAllIndex(idx); });
            list.appendChild(item);
        });

        renderPreviewAllSlide();

        const modal = document.getElementById('preview-all-modal');
        modal.classList.remove('d-none');
        modal.setAttribute('aria-hidden', 'false');
    }

    function escapeHtml(str) {
        return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function setPreviewAllIndex(idx) {
        previewAllIndex = idx;
        // Highlight sidebar item
        document.querySelectorAll('#preview-all-list .preview-all-list-item').forEach(function (item, i) {
            item.classList.toggle('active', i === idx);
        });
        renderPreviewAllSlide();
    }

    function navigatePreviewAll(direction) {
        const newIndex = previewAllIndex + direction;
        if (newIndex < 0 || newIndex >= previewAllDocs.length) return;
        setPreviewAllIndex(newIndex);
    }

    function renderPreviewAllSlide() {
        const doc = previewAllDocs[previewAllIndex];
        if (!doc) return;
        const label = documentLabelsAll[doc.document_type] || doc.document_type || 'Document';
        document.getElementById('preview-all-doc-label').textContent = label;
        const statusEl = document.getElementById('preview-all-doc-status');
        statusEl.textContent = doc.status ? (doc.status.charAt(0).toUpperCase() + doc.status.slice(1)) : 'Pending';
        statusEl.className = 'badge-status ' + statusClass(doc.status) + ' ms-2';
        document.getElementById('preview-all-open-link').href = doc.file_url;
        document.getElementById('preview-all-iframe').src = doc.file_url;
        document.getElementById('preview-all-counter').textContent = (previewAllIndex + 1) + ' / ' + previewAllDocs.length;
        document.getElementById('preview-all-prev').disabled = previewAllIndex === 0;
        document.getElementById('preview-all-next').disabled = previewAllIndex === previewAllDocs.length - 1;
    }

    function closePreviewAllModal() {
        const modal = document.getElementById('preview-all-modal');
        modal.classList.add('d-none');
        modal.setAttribute('aria-hidden', 'true');
        document.getElementById('preview-all-iframe').src = '';
        previewAllDocs = [];
        previewAllIndex = 0;
    }

    function verifySelectedDocuments(fullName) {
        const selectedIds = getSelectedDocumentIds();
        if (!selectedIds.length) {
            Swal.fire({
                icon: 'warning',
                title: 'No document selected',
                text: 'Select at least one document before clicking Verify selected.',
                iconColor: '#d99a1d',
                confirmButtonColor: '#d99a1d',
                background: '#ffffff',
                color: '#1f2937'
            });
            return;
        }

        Swal.fire({
            title: 'Verify selected documents?',
            text: 'This will mark ' + selectedIds.length + ' selected document(s) for ' + fullName + ' as verified.',
            icon: 'question',
            iconColor: '#0b9b98',
            showCancelButton: true,
            confirmButtonText: 'Yes, verify selected',
            cancelButtonText: 'Cancel',
            confirmButtonColor: 'var(--brand-primary)',
            cancelButtonColor: '#6c757d',
            background: '#ffffff',
            color: '#1f2937'
        }).then(function (result) {
            if (result.isConfirmed) {
                const container = document.getElementById('verify_selected_document_inputs');
                if (container) {
                    container.innerHTML = '';
                    selectedIds.forEach(function (documentId) {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'document_ids[]';
                        input.value = String(documentId);
                        container.appendChild(input);
                    });
                }
                document.getElementById('verifySelectedDocsForm').submit();
            }
        });
    }

    function requestEdits(studentId, fullName) {
        Swal.fire({
            title: 'Return application for edits?',
            text: fullName + ' will be able to edit and resubmit this application.',
            icon: 'warning',
            iconColor: '#d99a1d',
            input: 'textarea',
            inputLabel: 'Revision notes',
            inputPlaceholder: 'List the document(s) or fields that need correction...',
            inputAttributes: { maxlength: 2000, 'aria-label': 'Revision notes' },
            showCancelButton: true,
            confirmButtonText: 'Request edits',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#d99a1d',
            cancelButtonColor: '#6c757d',
            background: '#ffffff',
            color: '#1f2937',
            preConfirm: function (value) {
                const trimmed = (value || '').trim();
                if (!trimmed) {
                    Swal.showValidationMessage('Please include the specific correction(s) needed.');
                    return false;
                }
                return trimmed;
            }
        }).then(function (result) {
            if (result.isConfirmed) {
                document.getElementById('request_edits_student_id').value = studentId;
                document.getElementById('request_edits_notes').value = result.value;
                document.getElementById('requestEditsForm').submit();
            }
        });
    }
    function addRemark(studentId, fullName) {
        Swal.fire({
            title: 'Add registrar remark',
            text: 'This comment will be visible to ' + fullName + ' in their application status.',
            icon: 'info',
            iconColor: '#0b9b98',
            input: 'textarea', inputPlaceholder: 'Write a clear, helpful comment...', inputAttributes: { maxlength: 2000, 'aria-label': 'Registrar remark' },
            showCancelButton: true, confirmButtonText: 'Send remark',
            confirmButtonColor: '#6c757d',
            cancelButtonColor: '#6c757d',
            background: '#ffffff',
            color: '#1f2937',
            preConfirm: function (value) {
                if (!value || !value.trim()) { Swal.showValidationMessage('Please enter a remark.'); }
                return value ? value.trim() : false;
            }
        }).then(function (result) {
            if (result.isConfirmed) {
                document.getElementById('remark_student_id').value = studentId;
                document.getElementById('remark_text').value = result.value;
                document.getElementById('remarkForm').submit();
            }
        });
    }
</script>

<?php
require_once '../includes/footer.php';
?>
