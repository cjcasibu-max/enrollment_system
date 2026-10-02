<?php
/**
 * Student Records
 * Read-only list of all student profiles with search by name, username, or email.
 * Includes a per-student view modal with full profile details.
 */

require_once '../includes/auth_check.php';
checkRole(['registrar', 'admin']);

require_once '../config/database.php';

$search              = isset($_GET['search'])  ? trim($_GET['search'])  : '';
$selectedProgram     = isset($_GET['program']) ? trim($_GET['program']) : '';
$selectedStatus      = isset($_GET['status'])  ? trim($_GET['status'])  : '';
$selectedYear        = isset($_GET['year_level']) ? trim($_GET['year_level']) : '';

// Build dynamic query
$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $where[]           = "(s.first_name LIKE :term1 OR s.last_name LIKE :term2 OR CONCAT(s.first_name,' ',s.last_name) LIKE :term3 OR u.username LIKE :term4 OR u.email LIKE :term5)";
    $searchLike        = '%' . $search . '%';
    $params['term1']   = $searchLike;
    $params['term2']   = $searchLike;
    $params['term3']   = $searchLike;
    $params['term4']   = $searchLike;
    $params['term5']   = $searchLike;
}
if ($selectedProgram !== '') {
    $where[]              = "s.program_applying_for = :program";
    $params['program']    = $selectedProgram;
}
if ($selectedStatus !== '') {
    $where[]              = "s.enrollment_status = :status";
    $params['status']     = $selectedStatus;
}
if ($selectedYear !== '') {
    $where[]              = "s.year_level = :year_level";
    $params['year_level'] = $selectedYear;
}

$query = "
    SELECT s.id, s.first_name, s.middle_name, s.last_name, s.suffix,
           s.contact_number,
           s.gender, s.birthdate, s.place_of_birth, s.civil_status, s.nationality, s.religion,
           s.address_street, s.address_barangay, s.address_city,
           s.address_province, s.address_zip_code,
           s.guardian_name, s.guardian_relationship, s.guardian_contact_number,
           s.shs_name, s.shs_track_strand, s.shs_type, s.year_graduated, s.general_average,
           s.program_applying_for, s.program_code, s.applicant_type,
           s.year_level, s.enrollment_status, s.application_status,
           s.payment_status, s.created_at,
           u.username, u.email, u.role, u.is_active
    FROM students s
    JOIN users u ON s.user_id = u.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY s.last_name ASC, s.first_name ASC
";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $students = $stmt->fetchAll();
} catch (\PDOException $e) {
    error_log("Fetch students failed: " . $e->getMessage());
    $students = [];
}

// Count per status for summary chips
try {
    $statusCounts = [];
    $rows = $pdo->query("SELECT enrollment_status, COUNT(*) as cnt FROM students GROUP BY enrollment_status")->fetchAll();
    foreach ($rows as $r) $statusCounts[$r['enrollment_status']] = (int)$r['cnt'];
    $totalStudents = array_sum($statusCounts);
} catch (\PDOException $e) {
    $statusCounts  = [];
    $totalStudents = 0;
}

function enrollmentStatusBadge(string $status): string {
    return match ($status) {
        'enrolled', 'paid' => 'badge-status-enrolled',
        'approved', 'section_chosen', 'eligible_to_enroll', 'walk_in_ready' => 'badge-status-approved',
        'pending', 'under_review' => 'badge-status-pending',
        'rejected'          => 'badge-status-rejected',
        default             => 'badge-status-default',
    };
}
function enrollmentStatusLabel(string $status): string {
    return match ($status) {
        'enrolled'           => 'Enrolled',
        'paid'               => 'Paid',
        'approved'           => 'Approved',
        'section_chosen'     => 'Section Chosen',
        'eligible_to_enroll' => 'Eligible',
        'walk_in_ready'      => 'Walk-in Ready',
        'pending'            => 'Pending',
        'under_review'       => 'Under Review',
        'rejected'           => 'Rejected',
        'needs_revision'     => 'Needs Revision',
        'draft'              => 'Draft',
        default              => ucfirst(str_replace('_', ' ', $status)),
    };
}
function avatarInitials(string $first, string $last): string {
    return strtoupper(mb_substr($first, 0, 1) . mb_substr($last, 0, 1));
}
function yearBadgeClass(string $year): string {
    return match ($year) {
        '1st Year' => 'yr-badge-1',
        '2nd Year' => 'yr-badge-2',
        '3rd Year' => 'yr-badge-3',
        '4th Year' => 'yr-badge-4',
        default    => 'yr-badge-default',
    };
}

$page_title = "Student Records";
require_once '../includes/header.php';
?>

<style>
/* ============================================================
   STUDENTS PAGE — MARITIME THEME DESIGN
   ============================================================ */

/* Page Heading */
.students-page-eyebrow {
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.07em;
    text-transform: uppercase;
    color: var(--brand-primary);
    margin-bottom: 4px;
}

/* ---- Control Bar ---- */
.students-control-bar {
    background: #fff;
    border-radius: 14px;
    border: 1px solid #e5eeee;
    box-shadow: 0 2px 12px rgba(11,155,152,0.06);
    padding: 18px 22px;
    margin-bottom: 22px;
}

/* Summary stat chips */
.stat-chip {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    background: var(--brand-primary-soft, #d9efee);
    color: var(--brand-dark, #064b55);
    border-radius: 999px;
    padding: 5px 14px;
    font-size: 0.78rem;
    font-weight: 700;
}
.stat-chip .stat-chip-num {
    background: var(--brand-primary);
    color: #fff;
    border-radius: 999px;
    padding: 1px 8px;
    font-size: 0.73rem;
    font-weight: 800;
}

/* Program tab pills */
.program-pill-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 999px;
    border: 1.5px solid #cde6e5;
    background: #f3fafa;
    color: var(--brand-dark, #064b55);
    font-size: 0.8rem;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.18s;
    white-space: nowrap;
}
.program-pill-btn:hover {
    background: var(--brand-primary-soft);
    border-color: var(--brand-primary);
    color: var(--brand-dark);
}
.program-pill-btn.active {
    background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);
    border-color: transparent;
    color: #fff;
}

/* Filter pills */
.filter-pill-row {
    display: flex;
    flex-wrap: nowrap;
    gap: 6px;
    width: 100%;
}
.filter-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 5px 6px;
    border-radius: 999px;
    border: 1.5px solid #d1e8e8;
    background: #f5fbfb;
    color: #546e7a;
    font-size: 0.74rem;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.17s;
    white-space: nowrap;
    flex: 1 1 0;
    min-width: 0;
}
.filter-pill:hover {
    background: var(--brand-primary-soft);
    border-color: var(--brand-primary);
    color: var(--brand-dark);
}
.filter-pill.active {
    background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);
    border-color: transparent;
    color: #fff;
}
.filter-pill.active .badge {
    background: #fff !important;
    color: var(--brand-dark) !important;
    border-color: transparent !important;
}

@media (max-width: 991px) {
    .filter-pill-row {
        flex-wrap: wrap;
    }
    .filter-pill {
        flex: 1 1 calc(33.333% - 6px);
        min-width: 80px;
    }
    .program-pill-btn {
        flex: 1 1 100%;
    }
}
@media (max-width: 575px) {
    .filter-pill {
        flex: 1 1 calc(50% - 6px);
    }
}

/* ---- Table Card ---- */
.students-card {
    border-radius: 16px;
    border: 1px solid #e0eded;
    box-shadow: 0 4px 24px rgba(11,155,152,0.07);
    overflow: hidden;
    background: #fff;
}
.students-card-header {
    background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);
    padding: 16px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}
.students-card-header h5 {
    color: #fff;
    margin: 0;
    font-size: 1rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 8px;
}
.students-count-badge {
    background: rgba(255,255,255,0.22);
    color: #fff;
    border-radius: 999px;
    padding: 4px 14px;
    font-size: 0.75rem;
    font-weight: 700;
    border: 1px solid rgba(255,255,255,0.3);
    letter-spacing: 0.03em;
}

/* Table */
.table-students {
    margin: 0;
    font-size: 0.87rem;
}
.table-students thead th {
    background: #f4fafa;
    color: var(--brand-dark, #064b55);
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    border-bottom: 2px solid #cde6e5;
    padding: 11px 14px;
}
.table-students tbody tr {
    border-bottom: 1px solid #f0f6f6;
    transition: background 0.13s;
}
.table-students tbody tr:hover {
    background: #f7fcfc;
}
.table-students tbody td {
    padding: 13px 14px;
    vertical-align: middle;
}

/* Avatar */
.student-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.82rem;
    font-weight: 800;
    flex-shrink: 0;
    letter-spacing: 0.05em;
}

/* Status badges */
.badge-status-enrolled  { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
.badge-status-approved  { background: var(--brand-primary-soft, #d9efee); color: var(--brand-dark, #064b55); border: 1px solid var(--brand-primary); }
.badge-status-pending   { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
.badge-status-rejected  { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
.badge-status-default   { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
.student-status-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    white-space: normal !important;
    text-align: center;
    line-height: 1.25;
    max-width: 100%;
}

/* Year badges — strictly maritime palette */
.yr-badge-1 { background: #e6f7f6; color: #064b55; border: 1px solid #99dedc; }
.yr-badge-2 { background: var(--brand-primary-soft, #d9efee); color: #08616c; border: 1px solid var(--brand-accent, #55b9b5); }
.yr-badge-3 { background: #cef0ee; color: #043840; border: 1px solid #78ccc9; }
.yr-badge-4 { background: #b8e6e4; color: #064b55; border: 1px solid var(--brand-primary, #0b9b98); }
.yr-badge-default { background: #edf5f5; color: #406065; border: 1px solid #cbe3e4; }
.year-badge {
    display: inline-flex;
    align-items: center;
    padding: 2px 9px;
    border-radius: 999px;
    font-size: 0.67rem;
    font-weight: 700;
    letter-spacing: 0.03em;
}

/* Action and View buttons */
.btn-view-student,
.students-card td .btn-sm,
.st-action-btn {
    min-height: 32px !important;
    padding: 0.32rem 0.65rem !important;
    font-size: 0.76rem !important;
    font-weight: 600 !important;
    border-radius: 6px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 5px !important;
    transition: all 0.18s;
    white-space: nowrap !important;
    text-decoration: none !important;
    line-height: 1.2 !important;
    flex-shrink: 0 !important;
}
.btn-view-student {
    background: transparent;
    border: 1.5px solid var(--brand-primary);
    color: var(--brand-primary);
}
.btn-view-student:hover {
    background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);
    border-color: transparent;
    color: #fff;
}

/* Action button group spacing */
.st-action-group {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 7px !important;
    flex-wrap: nowrap;
    width: 100%;
}
.st-action-group > * + * {
    margin-left: 7px;
}

/* Program Column Badges */
.st-program-badge {
    display: inline-block;
    max-width: 100%;
    white-space: normal !important;
    word-break: normal;
    overflow-wrap: break-word;
    font-size: 0.74rem;
    font-weight: 700;
    line-height: 1.35;
    padding: 4px 9px;
    border-radius: 6px;
    background: #eef7f7;
    color: var(--brand-dark, #064b55);
    border: 1px solid #cde6e5;
    text-align: left;
}
.st-applicant-type {
    font-size: 0.73rem;
    white-space: normal;
    display: inline-flex;
    align-items: center;
}

/* ---- Tabulator Sizing & Scroll Prevention ---- */
.students-card .table-responsive {
    overflow-x: hidden;
}
.students-card .tabulator {
    border: none;
    font-size: 0.87rem;
    width: 100% !important;
}
.students-card .tabulator-tableholder {
    overflow-x: hidden !important;
    width: 100% !important;
}
.students-card .tabulator-header {
    background: #f4fafa;
    border-bottom: 2px solid #cde6e5;
    border-top: none;
    color: var(--brand-dark, #064b55);
}
.students-card .tabulator-header .tabulator-col {
    background: #f4fafa;
    border-right: none;
    padding: 10px 8px !important;
}
.students-card .tabulator-header .tabulator-col:first-child,
.students-card .tabulator-row .tabulator-cell:first-child,
.students-card .tabulator-header .tabulator-col[tabulator-field="student"],
.students-card .tabulator-row .tabulator-cell[tabulator-field="student"] {
    padding-left: 1.5rem !important;
}
.students-card .tabulator-header .tabulator-col:last-child,
.students-card .tabulator-row .tabulator-cell:last-child,
.students-card .tabulator-header .tabulator-col[tabulator-field="action"],
.students-card .tabulator-row .tabulator-cell[tabulator-field="action"] {
    padding-right: 1.5rem !important;
    justify-content: flex-end !important;
}
.students-card .tabulator-header .tabulator-col .tabulator-col-content {
    padding: 0 !important;
    display: flex;
    align-items: center;
}
.students-card .tabulator-header .tabulator-col[tabulator-field="action"] .tabulator-col-content {
    justify-content: flex-end !important;
    text-align: right !important;
    width: 100% !important;
}
.students-card .tabulator-header .tabulator-col .tabulator-col-content .tabulator-col-title-holder {
    display: inline-flex;
    align-items: center;
    max-width: 100%;
}
.students-card .tabulator-header .tabulator-col[tabulator-field="action"] .tabulator-col-content .tabulator-col-title-holder {
    justify-content: flex-end !important;
    width: 100% !important;
}
.students-card .tabulator-header .tabulator-col.tabulator-sortable .tabulator-col-title {
    padding-right: 0 !important;
    white-space: normal !important;
    overflow: visible !important;
    text-overflow: clip !important;
}
.students-card .tabulator-header .tabulator-col[tabulator-field="action"] .tabulator-col-title {
    text-align: right !important;
    overflow: visible !important;
    white-space: nowrap !important;
}
.students-card .tabulator-header .tabulator-col .tabulator-col-sorter {
    position: static !important;
    margin-left: 6px;
    display: inline-flex !important;
    align-items: center;
    flex-shrink: 0;
}
.students-card .tabulator-header .tabulator-col .tabulator-col-title {
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--brand-dark, #064b55);
}
.students-card .tabulator-row {
    border-bottom: 1px solid #f0f6f6;
    min-height: 56px;
    height: auto !important;
    background: #ffffff;
}
.students-card .tabulator-row:hover {
    background-color: #f7fcfc !important;
}
.students-card .tabulator-row .tabulator-cell {
    padding: 10px 8px !important;
    border-right: none;
    vertical-align: middle;
    height: auto !important;
    min-height: 56px;
    white-space: normal !important;
    word-break: break-word;
    overflow-wrap: break-word;
    display: inline-flex;
    align-items: center;
    overflow: visible !important;
}

/* Empty state */
.empty-students {
    padding: 64px 24px;
    text-align: center;
}
.empty-students .empty-icon {
    width: 72px;
    height: 72px;
    border-radius: 50%;
    background: var(--brand-primary-soft, #d9efee);
    color: var(--brand-primary);
    font-size: 2rem;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 16px;
}

/* ---- Student View Modal ---- */
.student-modal-avatar {
    width: 72px;
    height: 72px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    font-weight: 800;
    flex-shrink: 0;
    border: 4px solid rgba(11,155,152,0.2);
}
.modal-info-section {
    background: #f7fcfc;
    border-radius: 10px;
    border: 1px solid #ddf0ef;
    padding: 14px 16px;
    margin-bottom: 12px;
}
.modal-info-section .section-title {
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--brand-primary);
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.modal-info-row {
    display: flex;
    gap: 8px;
    margin-bottom: 6px;
    font-size: 0.83rem;
    align-items: flex-start;
    min-width: 0;
}
.modal-info-row:last-child { margin-bottom: 0; }
.modal-info-label {
    color: #78909c;
    font-weight: 600;
    width: 120px;
    min-width: 110px;
    flex-shrink: 0;
    font-size: 0.79rem;
}
.modal-info-value {
    color: #1a2c2e;
    font-weight: 500;
    min-width: 0;
    flex: 1;
    overflow-wrap: anywhere;
    word-break: break-word;
}
.modal-info-value.em { color: #546e7a; font-style: italic; }
</style>

<!-- Page Heading -->
<div class="page-heading mb-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
    <div>
        <div class="students-page-eyebrow"><i class="bi bi-people me-1"></i> Student Registry</div>
        <h3 class="m-0 fw-bold text-navy-alt">Student Records</h3>
        <p class="text-muted small m-0">Browse all registered student profiles and their current enrollment status.</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <div class="stat-chip">
            <i class="bi bi-mortarboard-fill"></i>
            Total Students
            <span class="stat-chip-num"><?php echo $totalStudents; ?></span>
        </div>
    </div>
</div>

<!-- =========================================================
     CONTROL BAR
     ========================================================= -->
<div class="students-control-bar">
    <!-- Program Pills & Search Row -->
    <div class="row g-3 align-items-center pb-3 border-bottom border-light-subtle">
        <!-- Program Selector -->
        <div class="col-12 col-xl-8 col-lg-7">
            <div class="d-flex align-items-center gap-2 flex-wrap flex-md-nowrap">
                <span class="small fw-bold text-uppercase text-muted flex-shrink-0" style="font-size:0.68rem;letter-spacing:.06em;"><i class="bi bi-mortarboard me-1"></i>Program:</span>
                <div class="d-flex gap-2 flex-grow-1 w-100">
                    <a href="students?<?php echo http_build_query(array_filter(['status' => $selectedStatus, 'year_level' => $selectedYear, 'search' => $search])); ?>"
                       class="program-pill-btn flex-fill text-center justify-content-center <?php echo $selectedProgram === '' ? 'active' : ''; ?>">
                        <i class="bi bi-grid"></i> All
                    </a>
                    <a href="students?<?php echo http_build_query(array_filter(['program' => 'BSMT', 'status' => $selectedStatus, 'year_level' => $selectedYear, 'search' => $search])); ?>"
                       class="program-pill-btn flex-fill text-center justify-content-center <?php echo $selectedProgram === 'BSMT' ? 'active' : ''; ?>">
                        <i class="bi bi-compass"></i> BSMT
                        <span class="badge ms-1 <?php echo $selectedProgram === 'BSMT' ? 'bg-light text-dark' : 'bg-secondary text-white'; ?>" style="font-size:0.62rem;">Marine Transportation</span>
                    </a>
                    <a href="students?<?php echo http_build_query(array_filter(['program' => 'BSMarE', 'status' => $selectedStatus, 'year_level' => $selectedYear, 'search' => $search])); ?>"
                       class="program-pill-btn flex-fill text-center justify-content-center <?php echo $selectedProgram === 'BSMarE' ? 'active' : ''; ?>">
                        <i class="bi bi-gear-wide-connected"></i> BSMarE
                        <span class="badge ms-1 <?php echo $selectedProgram === 'BSMarE' ? 'bg-light text-dark' : 'bg-secondary text-white'; ?>" style="font-size:0.62rem;">Marine Engineering</span>
                    </a>
                </div>
            </div>
        </div>

        <!-- Search Input -->
        <div class="col-12 col-xl-4 col-lg-5">
            <form method="GET" action="students" class="d-flex gap-2 align-items-center justify-content-lg-end">
                <input type="hidden" name="program"    value="<?php echo htmlspecialchars($selectedProgram); ?>">
                <input type="hidden" name="status"     value="<?php echo htmlspecialchars($selectedStatus); ?>">
                <input type="hidden" name="year_level" value="<?php echo htmlspecialchars($selectedYear); ?>">
                <div class="input-group" style="width:100%; max-width:340px;">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" name="search" id="searchInput" class="form-control border-start-0 ps-0"
                           placeholder="Name, username, or email…"
                           value="<?php echo htmlspecialchars($search); ?>" autocomplete="off">
                    <button type="submit" class="btn btn-brand-primary px-3" title="Search / Filter"><i class="bi bi-funnel"></i></button>
                    <?php if ($search !== '' || $selectedProgram !== '' || $selectedStatus !== '' || $selectedYear !== ''): ?>
                        <a href="students" class="btn btn-outline-secondary px-3" title="Clear Filters"><i class="bi bi-arrow-clockwise"></i></a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Filter Pills Row -->
    <div class="row g-3 pt-3 align-items-start">
        <!-- Year Level -->
        <div class="col-12 col-lg-5">
            <div class="small fw-bold text-muted text-uppercase mb-1" style="font-size:0.68rem;letter-spacing:.06em;">Year Level</div>
            <div class="filter-pill-row">
                <a href="students?<?php echo http_build_query(array_filter(['program' => $selectedProgram, 'status' => $selectedStatus, 'search' => $search])); ?>"
                   class="filter-pill <?php echo $selectedYear === '' ? 'active' : ''; ?>">All</a>
                <?php foreach (['1st Year', '2nd Year', '3rd Year', '4th Year'] as $yl): ?>
                    <a href="students?<?php echo http_build_query(array_filter(['program' => $selectedProgram, 'status' => $selectedStatus, 'year_level' => $yl, 'search' => $search])); ?>"
                       class="filter-pill <?php echo $selectedYear === $yl ? 'active' : ''; ?>">
                        <?php echo $yl; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Enrollment Status -->
        <div class="col-12 col-lg-7">
            <div class="small fw-bold text-muted text-uppercase mb-1" style="font-size:0.68rem;letter-spacing:.06em;">Status</div>
            <div class="filter-pill-row">
                <a href="students?<?php echo http_build_query(array_filter(['program' => $selectedProgram, 'year_level' => $selectedYear, 'search' => $search])); ?>"
                   class="filter-pill <?php echo $selectedStatus === '' ? 'active' : ''; ?>">All</a>
                <?php foreach (['enrolled', 'paid', 'walk_in_ready', 'approved', 'pending', 'rejected'] as $st): ?>
                    <a href="students?<?php echo http_build_query(array_filter(['program' => $selectedProgram, 'year_level' => $selectedYear, 'status' => $st, 'search' => $search])); ?>"
                       class="filter-pill <?php echo $selectedStatus === $st ? 'active' : ''; ?>">
                        <?php echo enrollmentStatusLabel($st); ?>
                        <?php if (isset($statusCounts[$st])): ?><span class="ms-1 badge bg-white text-dark border" style="font-size:0.62rem;padding:1px 5px;"><?php echo $statusCounts[$st]; ?></span><?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- =========================================================
     STUDENTS TABLE CARD
     ========================================================= -->
<div class="students-card">
    <div class="students-card-header">
        <h5>
            <i class="bi bi-people-fill"></i>
            Student Records
            <?php if ($selectedProgram !== ''): ?>
                <span style="opacity:.7;font-weight:500;">• <?php echo htmlspecialchars($selectedProgram); ?></span>
            <?php endif; ?>
            <?php if ($selectedYear !== ''): ?>
                <span style="opacity:.7;font-weight:500;">• <?php echo htmlspecialchars($selectedYear); ?></span>
            <?php endif; ?>
        </h5>
        <span class="students-count-badge"><?php echo count($students); ?> Record<?php echo count($students) !== 1 ? 's' : ''; ?></span>
    </div>

    <div class="table-responsive">
        <table class="table table-students table-hover align-middle" id="studentsTable" style="width: 100%;">
            <thead>
                <tr>
                    <th class="ps-4" tabulator-field="student">Student</th>
                    <th tabulator-field="contact">Contact</th>
                    <th tabulator-field="program">Program</th>
                    <th class="text-center" tabulator-field="status">Status</th>
                    <th class="pe-4 text-end" tabulator-field="action">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($students)): ?>
                    <?php foreach ($students as $st): ?>
                        <?php
                            $fullName    = trim($st['first_name'] . ' ' . $st['last_name']);
                            $initials    = avatarInitials($st['first_name'], $st['last_name']);
                            $statusClass = enrollmentStatusBadge($st['enrollment_status'] ?? 'pending');
                            $statusLabel = enrollmentStatusLabel($st['enrollment_status'] ?? 'pending');
                            $program     = $st['program_applying_for'] ?: ($st['program_code'] ?: '—');
                        ?>
                        <tr>
                            <!-- Student Identity -->
                            <td class="ps-4">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="student-avatar"><?php echo htmlspecialchars($initials); ?></div>
                                    <div>
                                        <div class="fw-bold text-navy" style="font-size:.91rem;">
                                            <?php echo htmlspecialchars($fullName); ?>
                                            <?php if ($st['suffix']): ?><span class="text-muted fw-normal" style="font-size:.8rem;"> <?php echo htmlspecialchars($st['suffix']); ?></span><?php endif; ?>
                                        </div>
                                        <div class="text-muted" style="font-size:.78rem;">@<?php echo htmlspecialchars($st['username']); ?></div>
                                        <?php if ($st['year_level']): ?>
                                            <span class="year-badge <?php echo yearBadgeClass($st['year_level']); ?> mt-1">
                                                <?php echo htmlspecialchars($st['year_level']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>

                            <!-- Contact -->
                            <td>
                                <div class="d-flex flex-column gap-1 py-1">
                                    <div style="font-size:.82rem;" class="text-dark d-flex align-items-center">
                                        <i class="bi bi-envelope text-brand-primary me-1 flex-shrink-0" style="font-size:.75rem;"></i>
                                        <span class="text-break"><?php echo htmlspecialchars($st['email']); ?></span>
                                    </div>
                                    <div class="text-muted d-flex align-items-center" style="font-size:.79rem;">
                                        <i class="bi bi-telephone text-muted me-1 flex-shrink-0" style="font-size:.73rem;"></i>
                                        <span><?php echo $st['contact_number'] ? htmlspecialchars($st['contact_number']) : '—'; ?></span>
                                    </div>
                                </div>
                            </td>

                            <!-- Program -->
                            <td>
                                <div class="d-flex flex-column align-items-start gap-1 py-1 w-100">
                                    <?php if ($program !== '—'): ?>
                                        <span class="st-program-badge">
                                            <?php echo htmlspecialchars($program); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted" style="font-size:.82rem;">—</span>
                                    <?php endif; ?>
                                    <?php if ($st['applicant_type']): ?>
                                        <div class="st-applicant-type text-muted">
                                            <i class="bi bi-person-fill-check me-1"></i><?php echo htmlspecialchars($st['applicant_type']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <!-- Status -->
                            <td class="text-center">
                                <div class="d-flex align-items-center justify-content-center w-100 py-1">
                                    <span class="student-status-badge <?php echo $statusClass; ?>">
                                        <?php echo $statusLabel; ?>
                                    </span>
                                </div>
                            </td>

                            <!-- View Action -->
                            <td class="pe-4 text-end">
                                <div class="st-action-group">
                                    <?php if (strcasecmp((string)($st['applicant_type'] ?? ''), 'Transferee') === 0): ?>
                                        <a href="credit_evaluation?student_id=<?php echo (int)$st['id']; ?>" class="btn btn-sm btn-outline-primary st-action-btn" title="Transferee Subject Credit Evaluation">
                                            <i class="bi bi-patch-check"></i> Credit Eval
                                        </a>
                                    <?php endif; ?>
                                    <?php if (($st['enrollment_status'] ?? '') === 'section_chosen'): ?>
                                        <a href="student_selection_review?student_id=<?php echo (int)$st['id']; ?>" class="btn btn-sm btn-success st-action-btn" title="Review and finalize subject selection">
                                            <i class="bi bi-journal-check"></i> Review
                                        </a>
                                    <?php elseif (($st['enrollment_status'] ?? '') === 'paid'): ?>
                                        <form method="POST" action="../actions/walk_in_actions" class="d-inline m-0">
                                            <input type="hidden" name="action" value="approve_paid_walk_in">
                                            <input type="hidden" name="student_id" value="<?php echo (int)$st['id']; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                                            <button type="submit" class="btn btn-sm btn-success st-action-btn" title="Approve paid walk-in enrollment">
                                                <i class="bi bi-check-circle"></i> Approve
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <button type="button"
                                            class="btn-view-student st-action-btn"
                                            onclick="viewStudent(<?php echo (int)$st['id']; ?>)"
                                            title="View Student Profile">
                                        <i class="bi bi-eye"></i> View
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- =========================================================
     STUDENT VIEW MODAL
     ========================================================= -->
<div class="modal fade" id="studentViewModal" tabindex="-1" aria-labelledby="studentViewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content" style="border-radius:16px;border:none;overflow:hidden;">
            <div class="modal-header" style="background:linear-gradient(135deg,var(--brand-primary) 0%,var(--brand-dark) 100%);border:none;padding:20px 24px;">
                <div class="d-flex align-items-center gap-3 flex-grow-1">
                    <div class="student-modal-avatar" id="modalAvatar">--</div>
                    <div>
                        <h5 class="modal-title text-white fw-bold mb-0" id="studentViewModalLabel">Student Profile</h5>
                        <div class="text-white opacity-75" id="modalUsername" style="font-size:.83rem;"></div>
                        <div class="mt-1" id="modalStatusBadge"></div>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4" id="studentModalBody">
                <!-- Populated by JS -->
                <div class="text-center py-5 text-muted">
                    <div class="spinner-border spinner-border-sm text-brand-primary me-2"></div>
                    Loading student info…
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function enrollmentStatusLabel(status) {
    const map = {
        enrolled: 'Enrolled', paid: 'Paid', approved: 'Approved',
        section_chosen: 'Section Chosen', eligible_to_enroll: 'Eligible',
        walk_in_ready: 'Walk-in Ready', pending: 'Pending',
        under_review: 'Under Review', rejected: 'Rejected',
        needs_revision: 'Needs Revision', draft: 'Draft'
    };
    return map[status] || status.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
}
function enrollmentStatusBadgeClass(status) {
    const map = {
        enrolled: 'badge-status-enrolled', paid: 'badge-status-enrolled',
        approved: 'badge-status-approved', section_chosen: 'badge-status-approved',
        eligible_to_enroll: 'badge-status-approved', walk_in_ready: 'badge-status-approved',
        pending: 'badge-status-pending', under_review: 'badge-status-pending',
        rejected: 'badge-status-rejected'
    };
    return map[status] || 'badge-status-default';
}
function esc(str) {
    if (!str) return '';
    return String(str).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
}
function infoRow(label, value, icon = '') {
    const val = value ? `<span class="modal-info-value">${esc(value)}</span>` : `<span class="modal-info-value em">—</span>`;
    return `<div class="modal-info-row"><span class="modal-info-label">${icon ? `<i class="bi ${icon} me-1 text-brand-primary"></i>` : ''}${esc(label)}</span>${val}</div>`;
}
function infoSection(title, icon, rows) {
    return `<div class="modal-info-section">
        <div class="section-title"><i class="bi ${icon}"></i> ${title}</div>
        ${rows}
    </div>`;
}

function viewStudent(id) {
    // Show loading state immediately so the modal opens without stale content.
    document.getElementById('modalAvatar').textContent = '…';
    document.getElementById('studentViewModalLabel').textContent = 'Loading…';
    document.getElementById('modalUsername').textContent = '';
    document.getElementById('modalStatusBadge').innerHTML = '';
    document.getElementById('studentModalBody').innerHTML =
        '<div class="text-center py-5 text-muted">'
        + '<div class="spinner-border spinner-border-sm text-brand-primary me-2"></div>'
        + 'Loading student info…'
        + '</div>';

    const modal = new bootstrap.Modal(document.getElementById('studentViewModal'));
    modal.show();

    fetch('student_detail_api?student_id=' + encodeURIComponent(id), {
        method: 'GET',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        credentials: 'same-origin'
    })
    .then(function (res) { return res.json(); })
    .then(function (json) {
        if (!json.success || !json.data) throw new Error(json.message || 'Not found.');
        renderStudentModal(json.data);
    })
    .catch(function () {
        document.getElementById('studentModalBody').innerHTML =
            '<div class="alert alert-danger m-3">Unable to load student profile. Please try again.</div>';
    });
}

function renderStudentModal(st) {
    const fullName = [st.first_name, st.middle_name ? st.middle_name + ' ' : '', st.last_name, st.suffix].filter(Boolean).join(' ').replace(/  +/g, ' ');
    document.getElementById('studentViewModalLabel').textContent = fullName;
    document.getElementById('modalUsername').textContent = '@' + (st.username || '');

    const statusBadgeDiv = document.getElementById('modalStatusBadge');
    statusBadgeDiv.innerHTML = `<span class="student-status-badge ${enrollmentStatusBadgeClass(st.enrollment_status || '')}" style="font-size:.72rem;">${enrollmentStatusLabel(st.enrollment_status || '')}</span>`;

    // Build address
    const addrParts = [st.address_street, st.address_barangay, st.address_city, st.address_province, st.address_zip_code].filter(Boolean);
    const fullAddress = addrParts.length ? addrParts.join(', ') : null;

    // Build body
    const body = document.getElementById('studentModalBody');
    body.innerHTML = `
        <div class="row g-3">
            <div class="col-12 col-md-6">
                ${infoSection('Personal Information', 'bi-person-vcard', `
                    ${infoRow('Full Name', fullName, 'bi-person')}
                    ${infoRow('Gender', st.gender, 'bi-gender-ambiguous')}
                    ${infoRow('Birthdate', st.birthdate ? new Date(st.birthdate).toLocaleDateString('en-US', {year:'numeric',month:'long',day:'numeric'}) : null, 'bi-calendar-heart')}
                    ${infoRow('Place of Birth', st.place_of_birth, 'bi-geo-alt')}
                    ${infoRow('Civil Status', st.civil_status, 'bi-heart')}
                    ${infoRow('Nationality', st.nationality, 'bi-flag')}
                    ${infoRow('Religion', st.religion, 'bi-book')}
                `)}
                ${infoSection('Contact Information', 'bi-telephone', `
                    ${infoRow('Email', st.email, 'bi-envelope')}
                    ${infoRow('Contact No.', st.contact_number, 'bi-telephone')}
                    ${infoRow('Address', fullAddress, 'bi-house')}
                `)}
            </div>
            <div class="col-12 col-md-6">
                ${infoSection('Academic Background', 'bi-mortarboard', `
                    ${infoRow('Program', st.program_applying_for || st.program_code, 'bi-bookmark')}
                    ${infoRow('Year Level', st.year_level, 'bi-layers')}
                    ${infoRow('Applicant Type', st.applicant_type, 'bi-person-check')}
                    ${infoRow('SHS Name', st.shs_name, 'bi-building')}
                    ${infoRow('SHS Track/Strand', st.shs_track_strand, 'bi-journals')}
                    ${infoRow('SHS Type', st.shs_type, 'bi-building-check')}
                    ${infoRow('Year Graduated', st.year_graduated, 'bi-calendar-check')}
                    ${infoRow('General Average', st.general_average, 'bi-graph-up')}
                `)}
                ${infoSection('Guardian Information', 'bi-people', `
                    ${infoRow('Guardian Name', st.guardian_name, 'bi-person-standing')}
                    ${infoRow('Relationship', st.guardian_relationship, 'bi-diagram-3')}
                    ${infoRow('Guardian Contact', st.guardian_contact_number, 'bi-telephone')}
                `)}
                ${infoSection('Enrollment Status', 'bi-clipboard-check', `
                    ${infoRow('Enrollment Status', enrollmentStatusLabel(st.enrollment_status || ''), 'bi-check-circle')}
                    ${infoRow('Application Status', st.application_status ? st.application_status.replace(/_/g,' ').replace(/\b\w/g,c=>c.toUpperCase()) : null, 'bi-journal-check')}
                    ${infoRow('Payment Status', st.payment_status ? st.payment_status.replace(/_/g,' ').replace(/\b\w/g,c=>c.toUpperCase()) : null, 'bi-credit-card')}
                    ${infoRow('Registered', st.created_at ? new Date(st.created_at).toLocaleDateString('en-US', {year:'numeric',month:'long',day:'numeric'}) : null, 'bi-calendar-plus')}
                `)}
            </div>
        </div>
    `;
}

function stripHtml(html) {
    if (!html) return '';
    var tmp = document.createElement('DIV');
    tmp.innerHTML = html;
    return (tmp.textContent || tmp.innerText || '').trim();
}

let studentsTable = null;
document.addEventListener("DOMContentLoaded", function() {
    if (typeof Tabulator === 'undefined') return;

    studentsTable = new Tabulator("#studentsTable", {
        layout: "fitColumns",
        pagination: "local",
        paginationSize: 25,
        paginationSizeSelector: [10, 25, 50, 100],
        placeholder: "<div class='empty-students text-center py-5 text-muted'><div class='empty-icon mb-2 fs-1 text-muted-light'><i class='bi bi-mortarboard'></i></div><h6 class='fw-bold text-dark mb-1'>No students found</h6><p class='text-muted small mb-0'>No student records match your current filters.</p></div>",
        columns: [
            { title: "Student", field: "student", minWidth: 160, widthGrow: 2.2, formatter: "html" },
            { title: "Contact", field: "contact", minWidth: 140, widthGrow: 1.4, formatter: "html" },
            { title: "Program", field: "program", minWidth: 160, widthGrow: 1.8, formatter: "html" },
            { title: "Status", field: "status", minWidth: 95, widthGrow: 0.9, hozAlign: "center", headerHozAlign: "center", formatter: "html" },
            { title: "Action", field: "action", minWidth: 200, widthGrow: 1.8, hozAlign: "right", headerHozAlign: "right", headerSort: false, formatter: "html" }
        ]
    });

    var sInput = document.getElementById('searchInput');
    if (sInput) {
        sInput.addEventListener('input', function() {
            var term = this.value.trim().toLowerCase();
            if (!term) {
                studentsTable.clearFilter();
            } else {
                studentsTable.setFilter(function(data) {
                    return stripHtml(data.student).toLowerCase().includes(term) ||
                           stripHtml(data.contact).toLowerCase().includes(term) ||
                           stripHtml(data.program).toLowerCase().includes(term) ||
                           stripHtml(data.status).toLowerCase().includes(term);
                });
            }
        });
    }
});
</script>

<?php
require_once '../includes/footer.php';
?>
