<?php
/**
 * Cashier - Enrollment Queue & Progress Monitor
 * Shows every enrollee with enrollment stage, section, subjects, tuition, and payment status.
 */
require_once '../includes/auth_check.php';
checkRole(['cashier', 'admin']);
require_once '../config/database.php';
require_once '../includes/assessments.php';
require_once '../includes/academic_terms.php';

$filterStatus  = isset($_GET['status'])  ? trim($_GET['status'])  : '';
$filterPayment = isset($_GET['payment']) ? trim($_GET['payment']) : '';
$filterProgram = isset($_GET['program']) ? trim($_GET['program']) : '';
$filterSearch  = isset($_GET['q'])       ? trim($_GET['q'])       : '';
$filterPending = !empty($_GET['filter_pending']);

if (!function_exists('enrollStatusMeta')) {
    function enrollStatusMeta(string $status): array {
        $map = [
            'draft'          => ['label' => 'Draft',           'badge' => 'secondary', 'icon' => 'pencil'],
            'pending'        => ['label' => 'App. Submitted',  'badge' => 'info',      'icon' => 'send'],
            'under_review'   => ['label' => 'Under Review',    'badge' => 'primary',   'icon' => 'eye'],
            'needs_revision' => ['label' => 'Needs Revision',  'badge' => 'warning',   'icon' => 'exclamation-triangle'],
            'approved'       => ['label' => 'Approved',        'badge' => 'success',   'icon' => 'check-circle'],
            'paid'           => ['label' => 'Admission Paid',  'badge' => 'success',   'icon' => 'cash-coin'],
            'section_chosen' => ['label' => 'Section Chosen',  'badge' => 'primary',   'icon' => 'bookmark-check'],
            'walk_in_ready'  => ['label' => 'Walk-in Ready',   'badge' => 'warning',   'icon' => 'person-walking'],
            'enrolled'       => ['label' => 'Enrolled',        'badge' => 'success',   'icon' => 'mortarboard'],
            'rejected'       => ['label' => 'Rejected',        'badge' => 'danger',    'icon' => 'x-circle'],
        ];
        return $map[$status] ?? ['label' => ucfirst(str_replace('_', ' ', $status)), 'badge' => 'secondary', 'icon' => 'question-circle'];
    }
}

$conditions = ["s.enrollment_status NOT IN ('draft')"];
$params = [];

if ($filterPending) {
    $conditions[] = "s.id IN (SELECT student_id FROM payments WHERE or_status = 'pending')";
}
if ($filterStatus !== '') {
    $conditions[] = 's.enrollment_status = :status';
    $params['status'] = $filterStatus;
}
if ($filterPayment !== '') {
    $conditions[] = 's.payment_status = :pstatus';
    $params['pstatus'] = $filterPayment;
}
if ($filterProgram !== '') {
    $conditions[] = '(s.program_applying_for = :prog OR s.program_code = :prog2)';
    $params['prog']  = $filterProgram;
    $params['prog2'] = $filterProgram;
}
if ($filterSearch !== '') {
    $conditions[] = "(CONCAT(s.first_name,' ',s.last_name) LIKE :q1 OR u.username LIKE :q2 OR u.email LIKE :q3)";
    $like = '%' . $filterSearch . '%';
    $params['q1'] = $like;
    $params['q2'] = $like;
    $params['q3'] = $like;
}

$enrollees  = [];
$queryError = null;

try {
    $whereClause = implode(' AND ', $conditions);
    $sql = "
        SELECT
            s.id AS student_id, s.first_name, s.last_name,
            s.enrollment_status, s.application_status,
            s.payment_status, s.outstanding_balance,
            s.program_applying_for, s.program_code, s.year_level, s.academic_term_id,
            u.email, u.username,

            sc.section_name, sc.schedule, sc.room,

            a.id AS assessment_id, a.reference_number,
            a.total_amount AS assessed_total, a.finalized_amount,
            a.is_finalized AS tuition_finalized, a.status AS assessment_status,

            COALESCE(SUM(CASE WHEN p.or_status = 'validated' THEN pa.amount ELSE 0 END), 0) AS paid_amount,

            (SELECT COUNT(*) FROM student_selected_subjects sss
             WHERE sss.student_id = s.id AND sss.is_finalized = 1) AS finalized_subjects,
            (SELECT COALESCE(SUM(sss2.units),0) FROM student_selected_subjects sss2
             WHERE sss2.student_id = s.id AND sss2.is_finalized = 1) AS finalized_units

        FROM students s
        JOIN users u ON u.id = s.user_id

        LEFT JOIN (
            SELECT en.student_id, sec.section_name, sec.schedule, sec.room
            FROM enrollments en
            JOIN sections sec ON sec.id = en.section_id
            WHERE en.id = (
                SELECT MAX(en2.id) FROM enrollments en2 WHERE en2.student_id = en.student_id
            )
            UNION
            SELECT sr.student_id, sec2.section_name, sec2.schedule, sec2.room
            FROM section_reservations sr
            JOIN sections sec2 ON sec2.id = sr.section_id
            WHERE sr.status = 'active'
              AND sr.id = (
                SELECT MAX(sr2.id) FROM section_reservations sr2 
                WHERE sr2.student_id = sr.student_id AND sr2.status = 'active'
              )
              AND sr.student_id NOT IN (SELECT en3.student_id FROM enrollments en3)
        ) sc ON sc.student_id = s.id

        LEFT JOIN assessments a
            ON a.student_id = s.id
            AND a.academic_term_id = s.academic_term_id
            AND a.status != 'cancelled'

        LEFT JOIN assessment_items ai ON ai.assessment_id = a.id
        LEFT JOIN payment_allocations pa ON pa.assessment_item_id = ai.id
        LEFT JOIN payments p ON p.id = pa.payment_id

        WHERE {$whereClause}

        GROUP BY
            s.id, s.first_name, s.last_name, s.enrollment_status, s.application_status,
            s.payment_status, s.outstanding_balance, s.program_applying_for, s.program_code,
            s.year_level, s.academic_term_id, u.email, u.username,
            sc.section_name, sc.schedule, sc.room,
            a.id, a.reference_number, a.total_amount, a.finalized_amount,
            a.is_finalized, a.status

        ORDER BY
            FIELD(s.enrollment_status,'walk_in_ready','section_chosen','approved','paid','enrolled',
                  'pending','under_review','needs_revision','rejected','draft'),
            s.last_name ASC, s.first_name ASC
        LIMIT 200
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $enrollees = $stmt->fetchAll();
} catch (Throwable $ex) {
    error_log('Cashier enrollment queue: ' . $ex->getMessage());
    $queryError = 'Unable to load enrollment queue: ' . $ex->getMessage();
}

// Summary Counts
$walkInCount  = 0; $enrolledCount = 0; $approvedCount = 0; $admPaidCount = 0;
$pendingPayments = [];
try {
    $cntRows = $pdo->query("
        SELECT enrollment_status, COUNT(*) AS cnt FROM students
        WHERE enrollment_status NOT IN ('draft')
        GROUP BY enrollment_status
    ")->fetchAll(PDO::FETCH_KEY_PAIR);
    $walkInCount   = (int)($cntRows['walk_in_ready'] ?? 0);
    $enrolledCount = (int)($cntRows['enrolled']      ?? 0);
    $approvedCount = (int)($cntRows['approved']      ?? 0);
    $admPaidCount  = (int)($cntRows['paid']           ?? 0);

    $ppStmt = $pdo->query("
        SELECT p.id, p.student_id, p.amount, p.payment_method, p.payment_reference, p.bank_name, p.check_number,
               p.or_number, p.payment_date, p.notes, p.proof_file, p.created_at
        FROM payments p
        WHERE p.or_status = 'pending'
        ORDER BY p.id DESC
    ");
    while ($r = $ppStmt->fetch(PDO::FETCH_ASSOC)) {
        if (!isset($pendingPayments[$r['student_id']])) {
            $pendingPayments[$r['student_id']] = $r;
        }
    }
} catch (Throwable $ex) {
    error_log('Enrollment queue count/pending error: ' . $ex->getMessage());
}
$pendingPaymentsCount = count($pendingPayments);

$page_title = 'Enrollment Queue';
require_once '../includes/header.php';
?>
<style>
/* Summary Cards Grid */
.eq-summary-grid {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 0.85rem;
}
@media (max-width: 991px) {
    .eq-summary-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}
@media (max-width: 576px) {
    .eq-summary-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
.eq-stat-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 0.85rem 1rem;
    text-align: center;
    text-decoration: none;
    color: inherit;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    min-height: 80px;
    transition: all 0.2s ease;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
}
.eq-stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(11, 155, 152, 0.12);
    border-color: var(--brand-primary);
    color: inherit;
}
.eq-stat-card.is-active {
    background: var(--surface-soft-alt);
    border-color: var(--brand-primary);
    box-shadow: 0 0 0 2px rgba(11, 155, 152, 0.2);
}
.eq-stat-card .stat-num {
    font-size: 1.55rem;
    font-weight: 800;
    line-height: 1.1;
    margin-bottom: 0.25rem;
}
.eq-stat-card .stat-meta {
    font-size: 0.72rem;
    font-weight: 600;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.04em;
    display: flex;
    align-items: center;
    justify-content: center;
}

/* Filter Bar */
.eq-filter-bar {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 1rem 1.25rem;
    margin-bottom: 1.25rem;
}
.eq-filter-label {
    display: block;
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--text-slate);
    margin-bottom: 0.35rem;
    line-height: 1.1;
    white-space: nowrap;
}
.eq-control {
    height: 36px !important;
    font-size: 0.8125rem !important;
    border-radius: 8px !important;
}
.eq-btn {
    height: 36px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    font-size: 0.8125rem !important;
    font-weight: 600 !important;
    border-radius: 8px !important;
    padding: 0 1.25rem !important;
    transition: all 0.2s ease;
}
.eq-btn-primary {
    background-color: var(--brand-primary);
    border: 1px solid var(--brand-primary);
    color: #fff;
}
.eq-btn-primary:hover,
.eq-btn-primary:focus {
    background-color: #087d80;
    border-color: #087d80;
    color: #fff;
}
.eq-btn-clear {
    background-color: #fff;
    border: 1px solid #cbd5e1;
    color: var(--text-slate);
}
.eq-btn-clear:hover,
.eq-btn-clear:focus {
    background-color: var(--surface-soft-alt);
    border-color: #94a3b8;
    color: var(--text-darker);
}

/* Table Enhancements */
.eq-table {
    table-layout: fixed;
    width: 100%;
    min-width: 1080px;
    font-size: 0.82rem;
}
.eq-table th,
.eq-table td {
    vertical-align: middle !important;
    padding-top: 0.75rem !important;
    padding-bottom: 0.75rem !important;
}
.eq-badge {
    font-size: 0.7rem !important;
    font-weight: 600 !important;
    padding: 0.28rem 0.55rem !important;
    border-radius: 6px !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 0.25rem !important;
    line-height: 1.2 !important;
    letter-spacing: 0.02em;
    max-width: 100%;
}
.eq-action-btn {
    height: 32px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    border-radius: 6px !important;
    font-size: 0.75rem !important;
    font-weight: 600 !important;
    padding: 0 0.65rem !important;
    line-height: 1 !important;
    white-space: nowrap;
    text-decoration: none;
    transition: all 0.15s ease;
}
.eq-action-icon {
    width: 32px !important;
    flex-shrink: 0 !important;
    padding: 0 !important;
}
.eq-bar-wrap {
    background: #e2e8f0;
    border-radius: 999px;
    height: 5px;
    overflow: hidden;
    margin: 0.2rem 0 0;
    width: 100%;
    max-width: 72px;
}
.eq-bar-fill {
    height: 100%;
    border-radius: 999px;
}
</style>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
        <div class="page-eyebrow"><i class="bi bi-people me-1"></i> Cashier</div>
        <h1 class="m-0 fw-bold text-navy-alt" style="font-size:1.45rem;">Enrollment Queue &amp; Payment Progress</h1>
        <p class="text-muted small m-0 mt-1">Real-time view of every enrollee — section, subjects, finalized tuition, and payment status.</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="dashboard" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Dashboard</a>
    </div>
</div>

<!-- Summary Cards -->
<div class="eq-summary-grid mb-4">
    <a href="enrollment_queue?status=walk_in_ready" class="eq-stat-card <?php echo ($filterStatus === 'walk_in_ready' && !$filterPending) ? 'is-active' : ''; ?>">
        <div class="stat-num text-warning"><?php echo $walkInCount; ?></div>
        <div class="stat-meta"><i class="bi bi-person-walking me-1"></i>Walk-in Ready</div>
    </a>
    <a href="enrollment_queue?filter_pending=1" class="eq-stat-card <?php echo $filterPending ? 'is-active' : ''; ?>">
        <div class="stat-num text-warning"><?php echo $pendingPaymentsCount; ?></div>
        <div class="stat-meta"><i class="bi bi-clock-history me-1"></i>Pending Review</div>
    </a>
    <a href="enrollment_queue?status=enrolled" class="eq-stat-card <?php echo ($filterStatus === 'enrolled' && !$filterPending) ? 'is-active' : ''; ?>">
        <div class="stat-num text-success"><?php echo $enrolledCount; ?></div>
        <div class="stat-meta"><i class="bi bi-mortarboard me-1"></i>Enrolled</div>
    </a>
    <a href="enrollment_queue?status=approved" class="eq-stat-card <?php echo ($filterStatus === 'approved' && !$filterPending) ? 'is-active' : ''; ?>">
        <div class="stat-num text-primary"><?php echo $approvedCount; ?></div>
        <div class="stat-meta"><i class="bi bi-check-circle me-1"></i>Approved</div>
    </a>
    <a href="enrollment_queue?status=paid" class="eq-stat-card <?php echo ($filterStatus === 'paid' && !$filterPending) ? 'is-active' : ''; ?>">
        <div class="stat-num text-info"><?php echo $admPaidCount; ?></div>
        <div class="stat-meta"><i class="bi bi-cash-coin me-1"></i>Adm. Paid</div>
    </a>
</div>

<!-- Filter Bar -->
<div class="eq-filter-bar">
    <form method="GET" action="enrollment_queue" class="row g-2 align-items-end">
        <div class="col-12 col-sm-6 col-lg-3">
            <label class="eq-filter-label">Search</label>
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-white border-end-0 text-muted eq-control"><i class="bi bi-search"></i></span>
                <input type="text" name="q" class="form-control border-start-0 ps-0 eq-control" placeholder="Name, username, email…"
                       value="<?php echo htmlspecialchars($filterSearch); ?>" autocomplete="off">
            </div>
        </div>
        <div class="col-6 col-sm-3 col-lg-2">
            <label class="eq-filter-label">Stage</label>
            <select name="status" class="form-select form-select-sm eq-control">
                <option value="">All Stages</option>
                <option value="approved"       <?php echo $filterStatus === 'approved'       ? 'selected' : ''; ?>>Approved</option>
                <option value="paid"           <?php echo $filterStatus === 'paid'           ? 'selected' : ''; ?>>Admission Paid</option>
                <option value="section_chosen" <?php echo $filterStatus === 'section_chosen' ? 'selected' : ''; ?>>Section Chosen</option>
                <option value="walk_in_ready"  <?php echo $filterStatus === 'walk_in_ready'  ? 'selected' : ''; ?>>Walk-in Ready</option>
                <option value="enrolled"       <?php echo $filterStatus === 'enrolled'       ? 'selected' : ''; ?>>Enrolled</option>
                <option value="pending"        <?php echo $filterStatus === 'pending'        ? 'selected' : ''; ?>>Pending</option>
                <option value="under_review"   <?php echo $filterStatus === 'under_review'   ? 'selected' : ''; ?>>Under Review</option>
                <option value="needs_revision" <?php echo $filterStatus === 'needs_revision' ? 'selected' : ''; ?>>Needs Revision</option>
            </select>
        </div>
        <div class="col-6 col-sm-3 col-lg-2">
            <label class="eq-filter-label">Payment</label>
            <select name="payment" class="form-select form-select-sm eq-control">
                <option value="">All</option>
                <option value="unpaid"         <?php echo $filterPayment === 'unpaid'         ? 'selected' : ''; ?>>Unpaid</option>
                <option value="partially_paid" <?php echo $filterPayment === 'partially_paid' ? 'selected' : ''; ?>>Partially Paid</option>
                <option value="fully_paid"     <?php echo $filterPayment === 'fully_paid'     ? 'selected' : ''; ?>>Fully Paid</option>
            </select>
        </div>
        <div class="col-6 col-sm-6 col-lg-2">
            <label class="eq-filter-label">Program</label>
            <select name="program" class="form-select form-select-sm eq-control">
                <option value="">All Programs</option>
                <option value="BSMT"   <?php echo $filterProgram === 'BSMT'   ? 'selected' : ''; ?>>BSMT</option>
                <option value="BSMarE" <?php echo $filterProgram === 'BSMarE' ? 'selected' : ''; ?>>BSMarE</option>
            </select>
        </div>
        <div class="col-6 col-sm-6 col-lg-3">
            <label class="eq-filter-label d-none d-lg-block invisible">&nbsp;</label>
            <div class="d-flex gap-2 align-items-center">
                <button type="submit" class="btn eq-btn eq-btn-primary">
                    <i class="bi bi-funnel me-1"></i> Filter
                </button>
                <a href="enrollment_queue" class="btn eq-btn eq-btn-clear">
                    Clear
                </a>
            </div>
        </div>
    </form>
</div>

<?php if ($queryError): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($queryError); ?></div>
<?php elseif (empty($enrollees)): ?>
    <div class="text-center py-5 text-muted">
        <i class="bi bi-person-x fs-1 d-block mb-2 opacity-40"></i>
        <p class="fw-semibold mb-0">No enrollees match the current filters.</p>
        <p class="small"><a href="enrollment_queue">Clear all filters</a> to see all students.</p>
    </div>
<?php else: ?>
<div class="card shadow-sm border-0 mb-4" style="border-radius:14px;overflow:hidden;">
    <div class="card-header bg-light border-0 d-flex justify-content-between align-items-center py-3 px-4">
        <div class="fw-bold text-navy-alt">
            <i class="bi bi-list-check me-1"></i> Enrollment Queue
            <span class="badge bg-secondary-subtle text-secondary border ms-2"><?php echo count($enrollees); ?> students</span>
        </div>
        <div class="text-muted small">Walk-in Ready sorted first &middot; then by surname</div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table align-middle mb-0 eq-table">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4" style="width:16%;">Student</th>
                        <th style="width:15%;">Program / Year</th>
                        <th style="width:10%;">Stage</th>
                        <th style="width:11%;">Section</th>
                        <th style="width:7%;" class="text-center">Subjects</th>
                        <th style="width:12%;" class="text-end">Tuition</th>
                        <th style="width:13%;">Payment</th>
                        <th style="width:16%;" class="pe-4 text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($enrollees as $e): ?>
                    <?php
                        $meta       = enrollStatusMeta($e['enrollment_status']);
                        $isWalkIn   = ($e['enrollment_status'] === 'walk_in_ready');
                        $isEnrolled = ($e['enrollment_status'] === 'enrolled');
                        $pending    = $pendingPayments[$e['student_id']] ?? null;
                        $paidAmt     = (float)($e['paid_amount']    ?? 0);
                        $totalAmt    = (float)($e['finalized_amount'] ?: ($e['assessed_total'] ?? 0));
                        $balance     = max(0, round($totalAmt - $paidAmt, 2));
                        $payPct      = ($totalAmt > 0) ? min(100, round($paidAmt / $totalAmt * 100)) : 0;
                        $payStatus   = strtolower(str_replace(' ', '_', getAssessmentPaymentStatus($paidAmt, $totalAmt)));
                        $payBadge    = match($payStatus) {
                            'fully_paid'     => 'success',
                            'partially_paid' => 'warning',
                            default          => 'secondary',
                        };
                        $barColor   = $payPct >= 100 ? '#10b981' : ($payPct > 0 ? '#f59e0b' : '#e2e8f0');
                        $tuitionFin = !empty($e['tuition_finalized']);
                        $subjCount  = (int)($e['finalized_subjects'] ?? 0);
                        $subjUnits  = (float)($e['finalized_units']  ?? 0);
                        $studentName = htmlspecialchars(trim($e['first_name'] . ' ' . $e['last_name']));

                        $dpRules = getTermDownpaymentRules($pdo, (int)$e['academic_term_id']);
                        $dpPercentageAmt = round($totalAmt * ($dpRules['percentage'] / 100), 2);
                        $dpRequired = max($dpRules['min_amount'], $dpPercentageAmt);
                        $dpRemaining = max(0, round($dpRequired - $paidAmt, 2));
                    ?>
                    <tr <?php if ($pending) echo 'style="background:rgba(245,158,11,.08);"'; elseif ($isWalkIn) echo 'style="background:rgba(251,191,36,.07);"'; elseif ($isEnrolled) echo 'style="background:rgba(16,185,129,.05);"'; ?>>
                        <td class="ps-4">
                            <div class="fw-semibold text-dark"><?php echo $studentName; ?></div>
                            <div class="text-muted" style="font-size:.7rem;"><?php echo htmlspecialchars($e['username'] ?? ''); ?></div>
                            <?php if (!empty($e['reference_number'])): ?>
                                <div style="font-size:.65rem;color:#94a3b8;font-family:monospace;"><?php echo htmlspecialchars($e['reference_number']); ?></div>
                            <?php endif; ?>
                        </td>

                        <td>
                            <div class="fw-semibold text-dark"><?php echo htmlspecialchars($e['program_applying_for'] ?: $e['program_code'] ?: '—'); ?></div>
                            <div class="text-muted" style="font-size:.7rem;"><?php echo htmlspecialchars($e['year_level'] ?: '—'); ?></div>
                        </td>

                        <td>
                            <span class="badge bg-<?php echo $meta['badge']; ?>-subtle text-<?php echo $meta['badge']; ?> border border-<?php echo $meta['badge']; ?>-subtle eq-badge">
                                <i class="bi bi-<?php echo $meta['icon']; ?>"></i>
                                <?php echo htmlspecialchars($meta['label']); ?>
                            </span>
                            <?php if ($isWalkIn): ?>
                                <div style="font-size:.65rem;color:#d97706;font-weight:700;margin-top:.2rem;">
                                    <i class="bi bi-lightning-charge-fill me-1"></i>Ready for cashier
                                </div>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php if (!empty($e['section_name'])): ?>
                                <div class="fw-semibold text-dark"><?php echo htmlspecialchars($e['section_name']); ?></div>
                                <?php if (!empty($e['schedule'])): ?>
                                    <div class="text-muted" style="font-size:.68rem;"><i class="bi bi-clock me-1"></i><?php echo htmlspecialchars($e['schedule']); ?></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>

                        <td class="text-center">
                            <?php if ($subjCount > 0): ?>
                                <div class="fw-bold text-dark"><?php echo $subjCount; ?></div>
                                <div class="text-muted" style="font-size:.68rem;"><?php echo number_format($subjUnits, 1); ?> units</div>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>

                        <td class="text-end">
                            <?php if ($totalAmt > 0): ?>
                                <div class="fw-bold text-dark">&#8369;<?php echo number_format($totalAmt, 2); ?></div>
                                <?php if ($tuitionFin): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle eq-badge mt-1"><i class="bi bi-patch-check-fill me-1"></i>Finalized</span>
                                <?php else: ?>
                                    <span class="badge bg-warning-subtle text-warning border border-warning-subtle eq-badge mt-1"><i class="bi bi-clock me-1"></i>Pending</span>
                                <?php endif; ?>
                                <?php if ($balance > 0): ?>
                                    <div class="text-danger fw-semibold mt-1" style="font-size:.68rem;">Bal: &#8369;<?php echo number_format($balance, 2); ?></div>
                                <?php else: ?>
                                    <div class="text-success fw-semibold mt-1" style="font-size:.68rem;"><i class="bi bi-check-lg"></i> Full</div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted" style="font-size:.75rem;">Not assessed</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <div class="d-flex flex-column align-items-start gap-1">
                                <?php if ($pending): ?>
                                    <span class="badge bg-warning text-dark border border-warning eq-badge fw-bold shadow-sm" title="Pending Review: ₱<?php echo number_format((float)$pending['amount'], 2); ?>">
                                        <i class="bi bi-hourglass-split"></i> Review (₱<?php echo number_format((float)$pending['amount'], 2); ?>)
                                    </span>
                                <?php endif; ?>
                                <span class="badge bg-<?php echo $payBadge; ?>-subtle text-<?php echo $payBadge; ?> border border-<?php echo $payBadge; ?>-subtle eq-badge">
                                    <?php echo ucfirst(str_replace('_', ' ', $payStatus)); ?>
                                </span>
                                <?php if ($totalAmt > 0): ?>
                                    <div class="eq-bar-wrap">
                                        <div class="eq-bar-fill" style="width:<?php echo $payPct; ?>%;background:<?php echo $barColor; ?>;"></div>
                                    </div>
                                    <div style="font-size:.62rem;color:#64748b;font-weight:600;"><?php echo $payPct; ?>%</div>
                                    <?php if ($paidAmt > 0 && $dpRemaining > 0 && $e['enrollment_status'] !== 'enrolled'): ?>
                                        <div class="text-danger fw-semibold" style="font-size:.62rem;line-height:1.2;">Downpayment: &#8369;<?php echo number_format($dpRemaining, 2); ?> rem.</div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </td>

                        <td class="pe-4 text-end">
                            <div class="d-flex gap-1 flex-wrap justify-content-end align-items-center">
                                <?php if ($pending): ?>
                                    <button type="button" class="btn btn-warning eq-action-btn shadow-sm"
                                            data-bs-toggle="modal" data-bs-target="#reviewModal<?php echo (int)$pending['id']; ?>"
                                            title="Review Online Payment">
                                        <i class="bi bi-shield-check me-1"></i>Review
                                    </button>
                                <?php endif; ?>
                                <?php if ($e['assessment_id']): ?>
                                    <?php if ($isWalkIn): ?>
                                        <a href="payments?student_id=<?php echo (int)$e['student_id']; ?>"
                                           class="btn btn-warning eq-action-btn fw-bold shadow-sm"
                                           title="Record Walk-in Payment">
                                            <i class="bi bi-credit-card me-1"></i>Pay
                                        </a>
                                    <?php else: ?>
                                        <a href="payments?student_id=<?php echo (int)$e['student_id']; ?>"
                                           class="btn btn-outline-secondary eq-action-btn eq-action-icon"
                                           title="Record Payment">
                                            <i class="bi bi-credit-card"></i>
                                        </a>
                                    <?php endif; ?>
                                    <a href="assessment?student_id=<?php echo (int)$e['student_id']; ?>"
                                       class="btn btn-outline-primary eq-action-btn eq-action-icon" title="View Assessment">
                                        <i class="bi bi-receipt"></i>
                                    </a>
                                <?php else: ?>
                                    <a href="payments?student_id=<?php echo (int)$e['student_id']; ?>"
                                       class="btn btn-outline-secondary eq-action-btn eq-action-icon" title="Generate Assessment">
                                        <i class="bi bi-calculator"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modals for Pending Payments Review -->
<?php foreach ($enrollees as $e): ?>
    <?php if (isset($pendingPayments[$e['student_id']])): ?>
        <?php
        $pending = $pendingPayments[$e['student_id']];
        $studentName = htmlspecialchars(trim($e['first_name'] . ' ' . $e['last_name']));
        $totalAmt = (float)($e['finalized_amount'] ?: ($e['assessed_total'] ?? 0));
        $validatedPaid = (float)($e['paid_amount'] ?? 0);
        $balance = max(0, round($totalAmt - $validatedPaid, 2));

        // Get downpayment threshold
        $rules = getTermDownpaymentRules($pdo, (int)$e['academic_term_id']);
        $percentageAmount = round($totalAmt * ($rules['percentage'] / 100), 2);
        $requiredDownpayment = max($rules['min_amount'], $percentageAmount);
        $remainingDownpayment = max(0, round($requiredDownpayment - $validatedPaid, 2));
        $willMeetDownpayment = round((float)$pending['amount'] + $validatedPaid, 2) >= $requiredDownpayment;
        ?>
        <div class="modal fade" id="reviewModal<?php echo (int)$pending['id']; ?>" tabindex="-1" aria-labelledby="reviewModalLabel<?php echo (int)$pending['id']; ?>" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content shadow-lg border-0" style="border-radius:14px;overflow:hidden;">
                    <div class="modal-header bg-dark text-white px-4 py-3">
                        <div>
                            <h5 class="modal-title fw-bold" id="reviewModalLabel<?php echo (int)$pending['id']; ?>">
                                <i class="bi bi-shield-check text-warning me-2"></i>Review Online Payment
                            </h5>
                            <span class="small text-muted"><?php echo $studentName; ?> (<?php echo htmlspecialchars($e['username'] ?? ''); ?>)</span>
                        </div>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body px-4 py-3">
                        <!-- Student & Assessment Summary -->
                        <div class="row g-3 mb-3 p-3 bg-light rounded-3">
                            <div class="col-sm-6">
                                <div class="text-muted small">Program / Year:</div>
                                <div class="fw-semibold"><?php echo htmlspecialchars($e['program_applying_for'] ?: $e['program_code'] ?: '—'); ?> - <?php echo htmlspecialchars($e['year_level'] ?: '—'); ?></div>
                                <div class="text-muted small mt-1">Section:</div>
                                <div class="fw-semibold"><?php echo htmlspecialchars($e['section_name'] ?: 'Not assigned'); ?></div>
                            </div>
                            <div class="col-sm-6">
                                <div class="text-muted small">Finalized Tuition:</div>
                                <div class="fw-bold fs-6">₱<?php echo number_format($totalAmt, 2); ?></div>
                                <div class="text-muted small mt-1">Validated Paid / Balance:</div>
                                <div><span class="text-success fw-semibold">₱<?php echo number_format($validatedPaid, 2); ?></span> / <span class="text-danger fw-semibold">₱<?php echo number_format($balance, 2); ?></span></div>
                                <div class="text-muted small mt-1">Required Downpayment (<?php echo (float)$rules['percentage']; ?>%):</div>
                                <div class="fw-bold text-primary">₱<?php echo number_format($requiredDownpayment, 2); ?> <span class="badge bg-secondary-subtle text-secondary small" style="font-size:0.68rem;">Rem: ₱<?php echo number_format($remainingDownpayment, 2); ?></span></div>
                            </div>
                        </div>

                        <!-- Payment Submission Details -->
                        <div class="card border mb-3">
                            <div class="card-header bg-white py-2 px-3 fw-bold small text-navy-alt">
                                <i class="bi bi-receipt me-1"></i> Submitted Payment Details
                            </div>
                            <div class="card-body p-3">
                                <div class="row g-2">
                                    <div class="col-sm-6">
                                        <div class="text-muted small">Amount Submitted:</div>
                                        <div class="fw-bold text-success fs-5">₱<?php echo number_format((float)$pending['amount'], 2); ?></div>
                                    </div>
                                    <div class="col-sm-6">
                                        <div class="text-muted small">Payment Method:</div>
                                        <div class="fw-semibold text-uppercase"><?php echo htmlspecialchars(str_replace('_', ' ', $pending['payment_method'] ?? 'cash')); ?></div>
                                    </div>
                                    <div class="col-sm-6">
                                        <div class="text-muted small">Reference Code:</div>
                                        <div class="font-monospace fw-bold text-primary"><?php echo htmlspecialchars($pending['payment_reference'] ?: 'None'); ?></div>
                                    </div>
                                    <div class="col-sm-6">
                                        <div class="text-muted small">Submission Date:</div>
                                        <div><?php echo date('M j, Y g:i A', strtotime($pending['created_at'])); ?></div>
                                    </div>
                                    <?php if (!empty($pending['notes'])): ?>
                                        <div class="col-12">
                                            <div class="text-muted small">Student Notes:</div>
                                            <div class="p-2 bg-light rounded small"><?php echo htmlspecialchars($pending['notes']); ?></div>
                                        </div>
                                    <?php endif; ?>
                                    <div class="col-12 mt-2">
                                        <div class="text-muted small mb-1">Proof of Payment:</div>
                                        <?php if (!empty($pending['proof_file'])): ?>
                                            <a href="../actions/view_payment_proof?id=<?php echo (int)$pending['id']; ?>" target="_blank" class="btn btn-outline-primary btn-sm d-inline-flex align-items-center gap-2">
                                                <i class="bi bi-file-earmark-arrow-up-fill fs-6"></i> View Uploaded Proof (New Tab)
                                            </a>
                                        <?php else: ?>
                                            <span class="badge bg-secondary-subtle text-secondary border px-2 py-1"><i class="bi bi-file-earmark-x me-1"></i>No proof uploaded</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Status Alert on Approval -->
                        <?php if ($willMeetDownpayment): ?>
                            <div class="alert alert-success d-flex align-items-center gap-2 small py-2 mb-3">
                                <i class="bi bi-check-circle-fill fs-5 text-success"></i>
                                <div>Approving this payment meets the required downpayment (₱<?php echo number_format($requiredDownpayment, 2); ?>). The enrollee will automatically be activated to <strong>Student</strong> (role: <code>student</code>, status: <code>paid</code>).</div>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-warning d-flex align-items-center gap-2 small py-2 mb-3">
                                <i class="bi bi-exclamation-triangle-fill fs-5 text-warning"></i>
                                <div>This payment of ₱<?php echo number_format((float)$pending['amount'], 2); ?> is below the required downpayment (₱<?php echo number_format($requiredDownpayment, 2); ?>). The payment will be recorded, but the enrollee will remain in enrollment queue until full downpayment is settled.</div>
                            </div>
                        <?php endif; ?>

                        <!-- Action Tabs: Validate or Reject -->
                        <div class="border rounded-3 p-3 bg-light">
                            <ul class="nav nav-pills nav-fill mb-3" id="reviewTabs<?php echo (int)$pending['id']; ?>" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active fw-semibold" id="approve-tab-<?php echo (int)$pending['id']; ?>" data-bs-toggle="pill" data-bs-target="#approve-pane-<?php echo (int)$pending['id']; ?>" type="button" role="tab"><i class="bi bi-check2-circle me-1"></i> Approve &amp; Validate</button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link text-danger fw-semibold" id="reject-tab-<?php echo (int)$pending['id']; ?>" data-bs-toggle="pill" data-bs-target="#reject-pane-<?php echo (int)$pending['id']; ?>" type="button" role="tab"><i class="bi bi-x-circle me-1"></i> Reject Payment</button>
                                </li>
                            </ul>
                            <div class="tab-content" id="reviewTabsContent<?php echo (int)$pending['id']; ?>">
                                <!-- Approve Form -->
                                <div class="tab-pane fade show active" id="approve-pane-<?php echo (int)$pending['id']; ?>" role="tabpanel">
                                    <form action="../actions/payment_actions" method="POST">
                                        <input type="hidden" name="action" value="validate">
                                        <input type="hidden" name="payment_id" value="<?php echo (int)$pending['id']; ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                        
                                        <div class="mb-3">
                                            <label class="form-label small fw-semibold">Official OR Number <span class="text-muted fw-normal">(Optional physical OR override)</span></label>
                                            <input type="text" name="or_number" class="form-control form-control-sm font-monospace" value="<?php echo htmlspecialchars($pending['or_number']); ?>" placeholder="Leave as is or enter physical OR number">
                                            <div class="form-text" style="font-size:0.7rem;">Defaults to system ST-... number. If physical OR is issued, enter it here. Must be unique.</div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label small fw-semibold">Validation Notes <span class="text-muted fw-normal">(Optional)</span></label>
                                            <input type="text" name="validation_notes" class="form-control form-control-sm" placeholder="e.g. Verified via GCash reference">
                                        </div>
                                        <div class="d-flex justify-content-end gap-2">
                                            <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-sm btn-success fw-semibold"><i class="bi bi-check-lg me-1"></i> Validate &amp; Issue Receipt</button>
                                        </div>
                                    </form>
                                </div>

                                <!-- Reject Form -->
                                <div class="tab-pane fade" id="reject-pane-<?php echo (int)$pending['id']; ?>" role="tabpanel">
                                    <form action="../actions/payment_actions" method="POST">
                                        <input type="hidden" name="action" value="reject_payment">
                                        <input type="hidden" name="payment_id" value="<?php echo (int)$pending['id']; ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                        
                                        <div class="mb-3">
                                            <label class="form-label small fw-semibold text-danger">Rejection Reason <span class="text-danger">*</span></label>
                                            <textarea name="rejection_reason" class="form-control form-control-sm" rows="3" required maxlength="500" placeholder="State reason clearly (e.g. Reference code not found in bank records, blurred receipt image, amount mismatch)..."></textarea>
                                            <div class="form-text text-danger" style="font-size:0.7rem;">This reason will be displayed to the student and recorded in audit trail.</div>
                                        </div>
                                        <div class="d-flex justify-content-end gap-2">
                                            <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-sm btn-danger fw-semibold"><i class="bi bi-x-octagon me-1"></i> Reject Payment</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
<?php endforeach; ?>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
