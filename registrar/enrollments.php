<?php
/**
 * Registrar Enrollments Management Queue
 * Displays all submitted student enrollment records with modern maritime theme, metrics, and dynamic filters.
 * Allows Registrar and Admin to approve (mark enrolled) or reject (mark dropped) records.
 * Fully secured with CSRF protection, checkRole, and SweetAlert2 confirmation dialogs.
 */

require_once '../includes/auth_check.php';
checkRole(['registrar', 'admin']);

require_once '../config/database.php';
require_once '../includes/academic_terms.php';

/** @var PDO $pdo */
$activeTerm = getActiveAcademicTerm($pdo);

// Capture and sanitize filter values
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filterProgram = isset($_GET['program']) ? trim($_GET['program']) : '';
$filterTermId = isset($_GET['academic_term_id']) ? (int)$_GET['academic_term_id'] : ($activeTerm ? (int)$activeTerm['id'] : 0);
$filterStatus = isset($_GET['status']) ? trim($_GET['status']) : '';

// 1. Fetch KPI Metrics
$statCounts = [
    'pending' => 0,
    'enrolled' => 0,
    'dropped' => 0,
    'total' => 0
];
try {
    $statStmt = $pdo->prepare("
        SELECT e.status, COUNT(*) as cnt
        FROM enrollments e
        WHERE (:term_id1 = 0 OR e.academic_term_id = :term_id2)
        GROUP BY e.status
    ");
    $statStmt->execute(['term_id1' => $filterTermId, 'term_id2' => $filterTermId]);
    foreach ($statStmt->fetchAll() as $row) {
        $st = $row['status'];
        $cnt = (int)$row['cnt'];
        if (isset($statCounts[$st])) {
            $statCounts[$st] = $cnt;
        }
        $statCounts['total'] += $cnt;
    }
} catch (\PDOException $e) {
    error_log("Enrollment stats query failed: " . $e->getMessage());
}

// 2. Build Dynamic Query
$query = "
    SELECT e.id AS enrollment_id, e.school_year, e.semester, e.status AS reg_status, e.created_at,
           s.section_name, s.schedule, s.room, s.program AS section_program, s.year_level AS section_year,
           c.course_code, c.course_name, c.units,
           st.id AS student_id, st.first_name, st.middle_name, st.last_name, st.suffix,
           st.program_applying_for, st.program_code, st.year_level AS student_year,
           u.email AS student_email, u.username AS student_username
    FROM enrollments e
    JOIN sections s ON e.section_id = s.id
    LEFT JOIN courses c ON s.course_id = c.id
    JOIN students st ON e.student_id = st.id
    JOIN users u ON st.user_id = u.id
    WHERE 1=1
";
$params = [];

if ($filterTermId > 0) {
    $query .= " AND e.academic_term_id = :term_id";
    $params['term_id'] = $filterTermId;
}
if ($filterProgram !== '') {
    $query .= " AND (st.program_code = :prog1 OR st.program_applying_for = :prog2 OR s.program = :prog3 OR c.course_code LIKE :prog_prefix)";
    $params['prog1'] = $filterProgram;
    $params['prog2'] = $filterProgram;
    $params['prog3'] = $filterProgram;
    $params['prog_prefix'] = ($filterProgram === 'BSMarE' ? 'BSMAR%' : 'BSMT%');
}
if ($filterStatus !== '') {
    $query .= " AND e.status = :status";
    $params['status'] = $filterStatus;
}
if ($search !== '') {
    $query .= " AND (
        st.first_name LIKE :term1
        OR st.last_name LIKE :term2
        OR CONCAT(st.first_name, ' ', st.last_name) LIKE :term3
        OR u.username LIKE :term4
        OR u.email LIKE :term5
        OR s.section_name LIKE :term6
        OR c.course_code LIKE :term7
        OR c.course_name LIKE :term8
    )";
    $searchLike = '%' . $search . '%';
    $params['term1'] = $searchLike;
    $params['term2'] = $searchLike;
    $params['term3'] = $searchLike;
    $params['term4'] = $searchLike;
    $params['term5'] = $searchLike;
    $params['term6'] = $searchLike;
    $params['term7'] = $searchLike;
    $params['term8'] = $searchLike;
}

$query .= " ORDER BY (CASE WHEN e.status = 'pending' THEN 0 ELSE 1 END) ASC, e.id DESC";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $enrollments = $stmt->fetchAll();
} catch (\PDOException $e) {
    error_log("Registrar fetch enrollments failed: " . $e->getMessage());
    $enrollments = [];
}

// Fetch all distinct academic terms for dropdown
$academicTerms = [];
try {
    $stmtTerms = $pdo->query("SELECT id, school_year, semester, is_active FROM academic_terms ORDER BY starts_on DESC");
    $academicTerms = $stmtTerms->fetchAll();
} catch (\PDOException $e) {
    error_log("Fetch terms failed: " . $e->getMessage());
}

$page_title = "Enrollment Registrations Queue";
require_once '../includes/header.php';
?>

<!-- =========================================================================
     PAGE HEADING
     ========================================================================= -->
<div class="page-heading mb-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
    <div>
        <div class="page-eyebrow"><i class="bi bi-person-check-fill me-1"></i> Registrar Workspace</div>
        <h3 class="m-0 text-navy-alt fw-bold">Enrollment Registrations</h3>
        <p class="text-muted small m-0">Review student section registration requests, verify workloads, and process enrollment approvals.</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <a href="sections" class="btn btn-outline-secondary d-flex align-items-center gap-1.5 px-3 py-2 fw-semibold">
            <i class="bi bi-grid-3x3-gap"></i> Class Sections
        </a>
    </div>
</div>

<!-- =========================================================================
     METRICS STATS ROW
     ========================================================================= -->
<div class="row g-3 mb-4">
    <!-- Pending Approvals -->
    <div class="col-6 col-lg-3">
        <div class="card card-stat stat-primary h-100 shadow-sm">
            <div class="card-body d-flex align-items-center justify-content-between p-3.5">
                <div>
                    <div class="text-muted small fw-bold text-uppercase" style="letter-spacing: 0.05em; font-size: 0.72rem;">Pending Review</div>
                    <div class="fs-3 fw-bold text-darker mt-1"><?php echo number_format($statCounts['pending']); ?></div>
                    <div class="small text-muted mt-0.5">Awaiting decision</div>
                </div>
                <div class="stat-icon bg-primary-soft">
                    <i class="bi bi-hourglass-split"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Officially Enrolled -->
    <div class="col-6 col-lg-3">
        <div class="card card-stat stat-primary h-100 shadow-sm">
            <div class="card-body d-flex align-items-center justify-content-between p-3.5">
                <div>
                    <div class="text-muted small fw-bold text-uppercase" style="letter-spacing: 0.05em; font-size: 0.72rem;">Enrolled Cadets</div>
                    <div class="fs-3 fw-bold text-darker mt-1"><?php echo number_format($statCounts['enrolled']); ?></div>
                    <div class="small text-muted mt-0.5">Approved registrations</div>
                </div>
                <div class="stat-icon bg-primary-soft">
                    <i class="bi bi-check2-circle"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Dropped / Rejected -->
    <div class="col-6 col-lg-3">
        <div class="card card-stat stat-secondary h-100 shadow-sm">
            <div class="card-body d-flex align-items-center justify-content-between p-3.5">
                <div>
                    <div class="text-muted small fw-bold text-uppercase" style="letter-spacing: 0.05em; font-size: 0.72rem;">Dropped / Rejected</div>
                    <div class="fs-3 fw-bold text-darker mt-1"><?php echo number_format($statCounts['dropped']); ?></div>
                    <div class="small text-muted mt-0.5">Cancelled workloads</div>
                </div>
                <div class="stat-icon bg-secondary-soft">
                    <i class="bi bi-x-circle"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Total Workloads -->
    <div class="col-6 col-lg-3">
        <div class="card card-stat stat-secondary h-100 shadow-sm">
            <div class="card-body d-flex align-items-center justify-content-between p-3.5">
                <div>
                    <div class="text-muted small fw-bold text-uppercase" style="letter-spacing: 0.05em; font-size: 0.72rem;">Total Registrations</div>
                    <div class="fs-3 fw-bold text-darker mt-1"><?php echo number_format($statCounts['total']); ?></div>
                    <div class="small text-muted mt-0.5">Selected academic term</div>
                </div>
                <div class="stat-icon bg-secondary-soft">
                    <i class="bi bi-journal-text"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- =========================================================================
     UNIFIED MARITIME CONTROL & FILTER BAR
     ========================================================================= -->
<div class="maritime-control-bar mb-4">
    <!-- Top Row: Program Selector Tabs & Academic Term -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 pb-3 border-bottom border-light-subtle">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="small fw-bold text-uppercase text-muted me-2"><i class="bi bi-mortarboard me-1"></i> Program:</span>
            <a href="enrollments?program=<?php echo $filterStatus ? '&status=' . urlencode($filterStatus) : ''; ?><?php echo $filterTermId ? '&academic_term_id=' . $filterTermId : ''; ?>" 
               class="program-pill-btn <?php echo $filterProgram === '' ? 'active' : ''; ?>">
                All Programs
            </a>
            <a href="enrollments?program=BSMT<?php echo $filterStatus ? '&status=' . urlencode($filterStatus) : ''; ?><?php echo $filterTermId ? '&academic_term_id=' . $filterTermId : ''; ?>" 
               class="program-pill-btn <?php echo $filterProgram === 'BSMT' ? 'active' : ''; ?>">
                <i class="bi bi-compass"></i> BSMT
            </a>
            <a href="enrollments?program=BSMarE<?php echo $filterStatus ? '&status=' . urlencode($filterStatus) : ''; ?><?php echo $filterTermId ? '&academic_term_id=' . $filterTermId : ''; ?>" 
               class="program-pill-btn <?php echo $filterProgram === 'BSMarE' ? 'active' : ''; ?>">
                <i class="bi bi-gear-wide-connected"></i> BSMarE
            </a>
        </div>

        <div class="d-flex align-items-center gap-2 text-muted small">
            <i class="bi bi-calendar-check text-brand-primary"></i>
            <span>Active Term: <strong class="text-navy"><?php echo $activeTerm ? htmlspecialchars($activeTerm['school_year'] . ' - ' . ucfirst($activeTerm['semester']) . ' Sem') : 'No Active Term'; ?></strong></span>
        </div>
    </div>

    <!-- Bottom Row: Status Filter Pills, Term Selector, and Search Input -->
    <form method="GET" action="enrollments" class="row g-3 align-items-center pt-3">
        <input type="hidden" name="program" value="<?php echo htmlspecialchars($filterProgram); ?>">
        
        <!-- Status Filter Pills -->
        <div class="col-12 col-lg-5">
            <label class="form-label small fw-bold text-muted mb-1.5 d-block">Registration Status</label>
            <div class="d-flex flex-wrap gap-1">
                <a href="enrollments?program=<?php echo urlencode($filterProgram); ?>&academic_term_id=<?php echo $filterTermId; ?>&search=<?php echo urlencode($search); ?>" 
                   class="filter-pill <?php echo $filterStatus === '' ? 'active' : ''; ?>">
                    All (<?php echo $statCounts['total']; ?>)
                </a>
                <a href="enrollments?program=<?php echo urlencode($filterProgram); ?>&academic_term_id=<?php echo $filterTermId; ?>&status=pending&search=<?php echo urlencode($search); ?>" 
                   class="filter-pill <?php echo $filterStatus === 'pending' ? 'active' : ''; ?>">
                    Pending (<?php echo $statCounts['pending']; ?>)
                </a>
                <a href="enrollments?program=<?php echo urlencode($filterProgram); ?>&academic_term_id=<?php echo $filterTermId; ?>&status=enrolled&search=<?php echo urlencode($search); ?>" 
                   class="filter-pill <?php echo $filterStatus === 'enrolled' ? 'active' : ''; ?>">
                    Enrolled (<?php echo $statCounts['enrolled']; ?>)
                </a>
                <a href="enrollments?program=<?php echo urlencode($filterProgram); ?>&academic_term_id=<?php echo $filterTermId; ?>&status=dropped&search=<?php echo urlencode($search); ?>" 
                   class="filter-pill <?php echo $filterStatus === 'dropped' ? 'active' : ''; ?>">
                    Dropped (<?php echo $statCounts['dropped']; ?>)
                </a>
            </div>
        </div>

        <!-- Academic Term Dropdown -->
        <div class="col-12 col-md-4 col-lg-3">
            <label for="academic_term_id" class="form-label small fw-bold text-muted mb-1.5 d-block">Academic Term</label>
            <select name="academic_term_id" id="academic_term_id" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="0">All Academic Terms</option>
                <?php foreach ($academicTerms as $term): ?>
                    <option value="<?php echo $term['id']; ?>" <?php echo $filterTermId === (int)$term['id'] ? 'selected' : ''; ?>>
                        SY <?php echo htmlspecialchars($term['school_year'] . ' - ' . ucfirst($term['semester']) . ' Sem'); ?>
                        <?php echo $term['is_active'] ? ' (Active)' : ''; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Live Search Input -->
        <div class="col-12 col-md-8 col-lg-4">
            <label for="search" class="form-label small fw-bold text-muted mb-1.5 d-block">Search Cadet / Course / Section</label>
            <div class="input-group">
                <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                <input type="text" name="search" id="searchInput" class="form-control border-start-0 ps-0" placeholder="Cadet name, username, course, section..." value="<?php echo htmlspecialchars($search); ?>" autocomplete="off">
                <button type="submit" class="btn btn-brand-primary px-3" title="Search / Filter"><i class="bi bi-funnel"></i></button>
                <?php if ($search !== '' || $filterProgram !== '' || $filterStatus !== ''): ?>
                    <a href="enrollments<?php echo $filterTermId ? '?academic_term_id=' . $filterTermId : ''; ?>" class="btn btn-outline-secondary px-3" title="Reset Filters"><i class="bi bi-arrow-clockwise"></i></a>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>

<!-- =========================================================================
     ENROLLMENTS QUEUE TABLE CARD
     ========================================================================= -->
<div class="card shadow-sm border-0" style="border-radius: 16px; overflow: hidden; border: 1px solid #e0eded !important; box-shadow: 0 4px 24px rgba(11,155,152,0.07) !important;">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 px-4 py-3" style="background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);">
        <h5 class="m-0 fw-bold text-white d-flex align-items-center gap-2" style="font-size: 1rem;">
            <i class="bi bi-list-check"></i> Registration Records
            <?php if ($filterStatus): ?> <span style="opacity: 0.85; font-weight: 500; font-size: 0.85rem;">&bull; <?php echo ucfirst($filterStatus); ?></span><?php endif; ?>
        </h5>
        <span class="badge" style="background: rgba(255,255,255,0.22); color: #ffffff !important; border: 1px solid rgba(255,255,255,0.35); border-radius: 999px; padding: 5px 14px; font-size: 0.75rem; font-weight: 700;">
            <?php echo count($enrollments); ?> Record<?php echo count($enrollments) !== 1 ? 's' : ''; ?>
        </span>
    </div>
    
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-maritime align-middle m-0" id="enrollmentsTable" style="width: 100%;">
                <thead>
                    <tr>
                        <th class="ps-4" tabulator-field="ref">Ref #</th>
                        <th tabulator-field="cadet">Cadet Profile</th>
                        <th tabulator-field="section_subject">Class Section & Subject</th>
                        <th tabulator-field="term">Academic Term</th>
                        <th tabulator-field="status">Status</th>
                        <th class="pe-4 text-end" tabulator-field="decision">Decision</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($enrollments)): ?>
                        <?php foreach ($enrollments as $e): 
                            $cadetName = trim($e['first_name'] . ' ' . ($e['middle_name'] ? $e['middle_name'] . ' ' : '') . $e['last_name'] . ' ' . ($e['suffix'] ?? ''));
                            $initials = strtoupper(substr($e['first_name'], 0, 1) . substr($e['last_name'], 0, 1));
                            $progCode = $e['program_code'] ?: ($e['program_applying_for'] ?: ($e['section_program'] ?: 'BSMT'));
                        ?>
                            <tr>
                                <!-- Ref ID & Timestamp -->
                                <td class="ps-4">
                                    <span class="badge bg-light text-navy border font-monospace px-2 py-1" style="font-size: 0.75rem;">
                                        #<?php echo str_pad($e['enrollment_id'], 4, '0', STR_PAD_LEFT); ?>
                                    </span>
                                    <div class="text-muted small mt-1" style="font-size: 0.7rem;">
                                        <?php echo date('M d, Y', strtotime($e['created_at'])); ?>
                                    </div>
                                </td>

                                <!-- Cadet Profile -->
                                <td>
                                    <div class="d-flex align-items-center gap-2.5">
                                        <div class="rounded-circle d-flex align-items-center justify-content-center fw-bold text-white shadow-sm flex-shrink-0" 
                                             style="width: 38px; height: 38px; font-size: 0.8rem; background: linear-gradient(135deg, var(--brand-primary), var(--brand-dark));">
                                            <?php echo htmlspecialchars($initials); ?>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="fw-bold text-dark text-truncate"><?php echo htmlspecialchars($cadetName); ?></div>
                                            <div class="d-flex align-items-center gap-1.5 mt-0.5">
                                                <span class="badge bg-navy-subtle text-navy border" style="font-size: 0.65rem;"><?php echo htmlspecialchars($progCode); ?></span>
                                                <span class="text-muted small" style="font-size: 0.72rem;"><?php echo htmlspecialchars($e['student_username']); ?></span>
                                            </div>
                                            <div class="text-muted-light small text-truncate" style="font-size: 0.72rem; max-width: 200px;">
                                                <?php echo htmlspecialchars($e['student_email']); ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                <!-- Class Section & Subject -->
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="badge bg-light text-dark border font-monospace fw-bold px-2 py-0.5" style="font-size: 0.75rem;">
                                            <?php echo htmlspecialchars($e['section_name'] ?: 'Sec #' . $e['enrollment_id']); ?>
                                        </span>
                                        <strong class="text-navy"><?php echo htmlspecialchars($e['course_code']); ?></strong>
                                        <span class="badge bg-secondary-subtle text-dark" style="font-size: 0.65rem;"><?php echo $e['units']; ?> Units</span>
                                    </div>
                                    <div class="text-muted small text-truncate mt-1" style="max-width: 280px;" title="<?php echo htmlspecialchars($e['course_name']); ?>">
                                        <?php echo htmlspecialchars($e['course_name']); ?>
                                    </div>
                                    <div class="d-flex align-items-center gap-3 text-muted small mt-1" style="font-size: 0.75rem;">
                                        <span><i class="bi bi-clock me-1 text-muted"></i><?php echo htmlspecialchars($e['schedule']); ?></span>
                                        <span><i class="bi bi-geo-alt me-1 text-muted"></i><?php echo htmlspecialchars($e['room'] ?: 'No room allocated'); ?></span>
                                    </div>
                                </td>

                                <!-- Academic Term -->
                                <td>
                                    <div class="fw-semibold text-darker small">SY <?php echo htmlspecialchars($e['school_year']); ?></div>
                                    <div class="text-muted small mt-0.5" style="font-size: 0.75rem;">
                                        <?php echo htmlspecialchars(ucfirst($e['semester'])); ?> Semester
                                    </div>
                                </td>

                                <!-- Status -->
                                <td>
                                    <?php if ($e['reg_status'] === 'enrolled'): ?>
                                        <span class="badge bg-success-subtle text-success border border-success px-2.5 py-1 text-uppercase fw-bold" style="font-size: 0.65rem;">
                                            <i class="bi bi-check2-circle me-1"></i>Enrolled
                                        </span>
                                    <?php elseif ($e['reg_status'] === 'pending'): ?>
                                        <span class="badge bg-warning-subtle text-warning-dark border border-warning px-2.5 py-1 text-uppercase fw-bold" style="font-size: 0.65rem;">
                                            <i class="bi bi-hourglass-split me-1"></i>Pending
                                        </span>
                                    <?php elseif ($e['reg_status'] === 'dropped'): ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger px-2.5 py-1 text-uppercase fw-bold" style="font-size: 0.65rem;">
                                            <i class="bi bi-x-circle me-1"></i>Dropped
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary-subtle text-secondary border px-2.5 py-1 text-uppercase fw-bold" style="font-size: 0.65rem;">
                                            <?php echo htmlspecialchars($e['reg_status']); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- Decision Actions -->
                                <td class="pe-4 text-end">
                                    <?php if ($e['reg_status'] === 'pending'): ?>
                                        <div class="btn-group btn-group-sm shadow-sm" role="group">
                                            <button type="button" class="btn btn-success d-inline-flex align-items-center gap-1 fw-semibold px-2.5 py-1"
                                                    onclick="confirmApprove(<?php echo $e['enrollment_id']; ?>, '<?php echo addslashes($cadetName); ?>', '<?php echo addslashes($e['course_code']); ?>', '<?php echo addslashes($e['section_name'] ?: ''); ?>')"
                                                    title="Approve registration">
                                                <i class="bi bi-check-lg"></i> Approve
                                            </button>
                                            <button type="button" class="btn btn-outline-danger d-inline-flex align-items-center gap-1 fw-semibold px-2 py-1"
                                                    onclick="confirmDrop(<?php echo $e['enrollment_id']; ?>, '<?php echo addslashes($cadetName); ?>', '<?php echo addslashes($e['course_code']); ?>', '<?php echo addslashes($e['section_name'] ?: ''); ?>')"
                                                    title="Drop registration">
                                                <i class="bi bi-x-lg"></i>
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted small fw-medium" style="font-size: 0.75rem;">
                                            <i class="bi bi-shield-check text-muted me-1"></i>Evaluated
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- =========================================================================
     HIDDEN POST ACTION FORMS (Secured with CSRF)
     ========================================================================= -->
<form id="approveEnrollmentForm" action="../actions/enrollment_actions" method="POST" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
    <input type="hidden" name="action" value="approve">
    <input type="hidden" name="enrollment_id" id="approve_enrollment_id">
</form>

<form id="rejectEnrollmentForm" action="../actions/enrollment_actions" method="POST" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
    <input type="hidden" name="action" value="reject">
    <input type="hidden" name="enrollment_id" id="reject_enrollment_id">
</form>

<!-- =========================================================================
     JAVASCRIPT CONTROLLERS
     ========================================================================= -->
<script>
function stripHtml(html) {
    if (!html) return '';
    var tmp = document.createElement('DIV');
    tmp.innerHTML = html;
    return (tmp.textContent || tmp.innerText || '').trim();
}

let enrollmentsTable = null;
document.addEventListener("DOMContentLoaded", function() {
    document.body.appendChild(document.getElementById('approveEnrollmentForm'));
    document.body.appendChild(document.getElementById('rejectEnrollmentForm'));

    if (typeof Tabulator === 'undefined') return;

    enrollmentsTable = new Tabulator("#enrollmentsTable", {
        layout: "fitColumns",
        responsiveLayout: "collapse",
        responsiveLayoutCollapseStartOpen: false,
        rowHeader: {
            formatter: "responsiveCollapse",
            width: 36,
            minWidth: 36,
            hozAlign: "center",
            resizable: false,
            headerSort: false
        },
        pagination: "local",
        paginationSize: 25,
        paginationSizeSelector: [10, 25, 50, 100],
        placeholder: "<div class='text-center py-5 text-muted'><div class='mb-3 fs-1 text-muted-light'><i class='bi bi-inbox'></i></div><h6 class='fw-bold text-dark'>No enrollment records found</h6><p class='small text-muted mb-0'>No registrations match your selected filters or search keyword.</p></div>",
        columns: [
            { title: "Ref #", field: "ref", widthGrow: 0.8, minWidth: 80, formatter: "html" },
            { title: "Cadet Profile", field: "cadet", widthGrow: 2.8, minWidth: 180, formatter: "html" },
            { title: "Class Section & Subject", field: "section_subject", widthGrow: 3.0, minWidth: 200, formatter: "html" },
            { title: "Academic Term", field: "term", widthGrow: 1.3, minWidth: 110, formatter: "html" },
            { title: "Status", field: "status", widthGrow: 1.0, minWidth: 90, formatter: "html" },
            { title: "Decision", field: "decision", widthGrow: 1.2, minWidth: 110, hozAlign: "center", headerSort: false, formatter: "html", responsive: 0 }
        ]
    });

    var sInput = document.getElementById('searchInput');
    if (sInput) {
        sInput.addEventListener('input', function() {
            var term = this.value.trim().toLowerCase();
            if (!term) {
                enrollmentsTable.clearFilter();
            } else {
                enrollmentsTable.setFilter(function(data) {
                    return stripHtml(data.ref).toLowerCase().includes(term) ||
                           stripHtml(data.cadet).toLowerCase().includes(term) ||
                           stripHtml(data.section_subject).toLowerCase().includes(term) ||
                           stripHtml(data.term).toLowerCase().includes(term) ||
                           stripHtml(data.status).toLowerCase().includes(term);
                });
            }
        });
    }
});

// Confirm enrollment approval with SweetAlert2
function confirmApprove(id, cadetName, courseCode, sectionName) {
    const secLabel = sectionName ? ` (${sectionName})` : '';
    Swal.fire({
        title: 'Approve Registration?',
        html: `Are you sure you want to approve registration of <strong>${cadetName}</strong> for <strong>${courseCode}${secLabel}</strong>?<br><br><small class="text-muted">This will officially enroll the cadet in this class section.</small>`,
        icon: 'question',
        iconColor: '#0b9b98',
        showCancelButton: true,
        confirmButtonColor: 'var(--brand-primary)',
        cancelButtonColor: '#6c757d',
        confirmButtonText: '<i class="bi bi-check-lg me-1"></i> Yes, Approve Cadet',
        cancelButtonText: 'Cancel',
        background: '#ffffff',
        color: '#1f2937'
    }).then((result) => {
        if (result.isConfirmed) {
            document.getElementById('approve_enrollment_id').value = id;
            document.getElementById('approveEnrollmentForm').submit();
        }
    });
}

// Confirm enrollment drop / reject with SweetAlert2
function confirmDrop(id, cadetName, courseCode, sectionName) {
    const secLabel = sectionName ? ` (${sectionName})` : '';
    Swal.fire({
        title: 'Drop Registration?',
        html: `Are you sure you want to drop <strong>${cadetName}</strong> from <strong>${courseCode}${secLabel}</strong>?<br><br><small class="text-danger">This registration will be flagged as dropped and removed from section capacity.</small>`,
        icon: 'warning',
        iconColor: '#ef4444',
        showCancelButton: true,
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#6c757d',
        confirmButtonText: '<i class="bi bi-trash me-1"></i> Yes, Drop Registration',
        cancelButtonText: 'Cancel',
        background: '#ffffff',
        color: '#1f2937'
    }).then((result) => {
        if (result.isConfirmed) {
            document.getElementById('reject_enrollment_id').value = id;
            document.getElementById('rejectEnrollmentForm').submit();
        }
    });
}
</script>

<?php
require_once '../includes/footer.php';
?>
