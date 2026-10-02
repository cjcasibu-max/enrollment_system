<?php
/**
 * Payment History
 * Searchable table of all recorded payments, filterable by student name or OR number.
 */

require_once '../includes/auth_check.php';
checkRole(['cashier', 'admin']);

require_once '../config/database.php';

$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$query = "
    SELECT p.id, p.amount, p.payment_method, p.payment_reference, p.bank_name, p.check_number, p.or_number, p.payment_date, p.issue_date, p.or_status, p.notes, p.proof_file, p.validation_notes, p.created_at,
           s.first_name, s.last_name,
           u.username AS cashier_username
    FROM payments p
    JOIN students s ON p.student_id = s.id
    LEFT JOIN users u ON u.id = COALESCE(p.cashier_id, p.validated_by)
    WHERE 1=1
";
$params = [];

if ($search !== '') {
    $query .= " AND (
        s.first_name LIKE :term1
        OR s.last_name LIKE :term2
        OR CONCAT(s.first_name, ' ', s.last_name) LIKE :term3
        OR p.or_number LIKE :term4
    )";
    $searchLike = '%' . $search . '%';
    $params['term1'] = $searchLike;
    $params['term2'] = $searchLike;
    $params['term3'] = $searchLike;
    $params['term4'] = $searchLike;
}

$query .= " ORDER BY p.payment_date DESC, p.id DESC";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $payments = $stmt->fetchAll();
} catch (\PDOException $e) {
    error_log("Fetch payment history failed: " . $e->getMessage());
    $payments = [];
}

$totalAmount = array_sum(array_column($payments, 'amount'));

$page_title = "Payment History";
require_once '../includes/header.php';
?>

<div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h3 class="m-0 text-navy-alt">Payment History</h3>
        <p class="text-muted small m-0">Browse and search all recorded payments by student name or OR number.</p>
    </div>
</div>

<!-- Search Filter Panel -->
<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-body p-3.5 bg-white">
        <form method="GET" action="payment_history" class="row g-2 align-items-end">
            <div class="col-12 col-md-7 col-lg-6">
                <label for="search" class="form-label small fw-bold text-muted mb-1">Search Records</label>
                <div class="input-group input-group-sm ph-input-group">
                    <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-search"></i></span>
                    <input type="text" name="search" id="search" class="form-control border-start-0 ps-0"
                           placeholder="Filter student name, OR number, method..."
                           value="<?php echo htmlspecialchars($search); ?>" autocomplete="off">
                </div>
            </div>
            <div class="col-12 col-md-5 col-lg-4">
                <label class="form-label small fw-bold text-muted mb-1 d-none d-md-block invisible">&nbsp;</label>
                <div class="d-flex gap-2 align-items-center">
                    <button type="submit" class="btn ph-btn ph-btn-primary">
                        <i class="bi bi-filter me-1"></i> Search
                    </button>
                    <a href="payment_history" class="btn ph-btn ph-btn-clear">
                        <i class="bi bi-arrow-counterclockwise me-1"></i> Reset
                    </a>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Payments Table -->
<div class="card card-premium shadow-sm">
    <div class="card-header card-header-premium d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h5 class="card-title m-0 fw-semibold text-navy-alt">All Payments</h5>
        <div class="d-flex gap-2 align-items-center">
            <?php if ($search !== ''): ?>
                <span class="badge bg-light text-muted border px-2 py-1 small">
                    Filtered results
                </span>
            <?php endif; ?>
            <span class="badge bg-secondary-subtle text-brand-primary border border-secondary px-2.5 py-1 text-uppercase fw-semibold" style="font-size: 0.7rem;">
                <?php echo count($payments); ?> Record<?php echo count($payments) !== 1 ? 's' : ''; ?>
                <?php if (!empty($payments)): ?>
                    &middot; ₱<?php echo number_format($totalAmount, 2); ?>
                <?php endif; ?>
            </span>
        </div>
    </div>

    <div class="card-body card-body-premium p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle m-0" id="paymentHistoryTable" style="width: 100%;">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3" tabulator-field="payment_date">Date</th>
                        <th tabulator-field="or_number">OR Number</th>
                        <th tabulator-field="student">Student</th>
                        <th tabulator-field="amount" class="text-end">Amount</th>
                        <th tabulator-field="method">Method</th>
                        <th tabulator-field="or_status">Status</th>
                        <th tabulator-field="cashier">Cashier</th>
                        <th class="pe-3" tabulator-field="notes">Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($payments)): ?>
                        <?php foreach ($payments as $p): ?>
                            <tr>
                                <td class="ps-3">
                                    <div class="fw-semibold text-darker text-nowrap"><?php echo date('M j, Y', strtotime($p['payment_date'])); ?></div>
                                    <span class="text-muted small" style="font-size: 0.75rem;">#<?php echo (int)$p['id']; ?></span>
                                </td>
                                <td>
                                    <span class="badge bg-light text-navy border px-2 py-1 font-monospace fw-bold ph-or-badge">
                                        <?php echo htmlspecialchars($p['or_number']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="fw-semibold text-darker ph-wrap-text">
                                        <?php echo htmlspecialchars($p['first_name'] . ' ' . $p['last_name']); ?>
                                    </div>
                                </td>
                                <td class="text-end">
                                    <span class="fw-bold text-success font-monospace text-nowrap">₱<?php echo number_format((float)$p['amount'], 2); ?></span>
                                </td>
                                <td>
                                    <div class="d-flex flex-column align-items-start gap-1">
                                        <span class="text-darker fw-medium"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string)($p['payment_method'] ?? 'cash')))); ?></span>
                                        <?php if (!empty($p['payment_reference'])): ?>
                                            <small class="font-monospace ph-ref-text"><?php echo htmlspecialchars($p['payment_reference']); ?></small>
                                        <?php endif; ?>
                                        <?php if (!empty($p['proof_file'])): ?>
                                            <a href="../actions/view_payment_proof?id=<?php echo (int)$p['id']; ?>" target="_blank" class="badge bg-light text-primary border text-decoration-none d-inline-flex align-items-center gap-1 ph-proof-link">
                                                <i class="bi bi-file-earmark-image"></i> View Proof
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php
                                    $orSt = $p['or_status'] ?? 'pending';
                                    $badgeClass = match($orSt) {
                                        'validated' => 'status-approved',
                                        'rejected', 'voided' => 'status-rejected',
                                        default => 'status-pending',
                                    };
                                    ?>
                                    <div class="d-flex flex-column align-items-start gap-1">
                                        <span class="badge-status <?php echo $badgeClass; ?>"><?php echo htmlspecialchars(ucfirst($orSt)); ?></span>
                                        <?php if ($orSt === 'rejected' && !empty($p['validation_notes'])): ?>
                                            <small class="text-danger ph-reject-reason" title="<?php echo htmlspecialchars($p['validation_notes']); ?>">
                                                Reason: <?php echo htmlspecialchars($p['validation_notes']); ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="text-muted small"><?php echo !empty($p['cashier_username']) ? htmlspecialchars($p['cashier_username']) : '—'; ?></span>
                                </td>
                                <td class="pe-3">
                                    <span class="text-muted small ph-notes-text">
                                        <?php echo $p['notes'] ? htmlspecialchars($p['notes']) : '—'; ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
/* Filter Bar Controls */
.ph-input-group .input-group-text,
.ph-input-group .form-control {
    height: 36px !important;
    font-size: 0.8125rem !important;
    border-radius: 8px !important;
}
.ph-btn {
    height: 36px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    font-size: 0.8125rem !important;
    font-weight: 600 !important;
    border-radius: 8px !important;
    padding: 0 1.25rem !important;
    white-space: nowrap !important;
    transition: all 0.2s ease;
}
.ph-btn-primary {
    background-color: var(--brand-primary);
    border: 1px solid var(--brand-primary);
    color: #fff;
}
.ph-btn-primary:hover,
.ph-btn-primary:focus {
    background-color: #087d80;
    border-color: #087d80;
    color: #fff;
}
.ph-btn-clear {
    background-color: #fff;
    border: 1px solid #cbd5e1;
    color: var(--text-slate);
}
.ph-btn-clear:hover,
.ph-btn-clear:focus {
    background-color: var(--surface-soft-alt);
    border-color: #94a3b8;
    color: var(--text-darker);
}

/* Custom Table Helpers */
.ph-wrap-text {
    word-break: break-word;
    overflow-wrap: break-word;
    white-space: normal !important;
    line-height: 1.35;
}
.ph-or-badge {
    word-break: break-all;
    white-space: normal !important;
    line-height: 1.3;
    font-size: 0.76rem;
    display: inline-block;
    max-width: 100%;
    text-align: left;
}
.ph-ref-text {
    word-break: break-all;
    font-size: 0.72rem;
    line-height: 1.25;
    color: #64748b;
    display: block;
    max-width: 100%;
}
.ph-proof-link {
    font-size: 0.7rem;
    padding: 0.2rem 0.5rem;
    border-radius: 4px;
}
.ph-reject-reason {
    font-size: 0.72rem;
    line-height: 1.25;
    word-break: break-word;
    overflow-wrap: break-word;
    white-space: normal !important;
    display: block;
    max-width: 100%;
}
.ph-notes-text {
    word-break: break-word;
    overflow-wrap: break-word;
    white-space: normal !important;
    font-size: 0.8rem;
    line-height: 1.35;
    display: block;
    max-width: 100%;
}

/* Table Container & Scrollbar Behavior */
.table-responsive {
    overflow-x: auto;
}

/* Tabulator Styling & Layout Adjustments */
.tabulator {
    font-family: inherit;
    border: none;
    background-color: transparent;
    width: 100% !important;
    min-width: 0 !important;
}
.tabulator .tabulator-header {
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    color: #475569;
    font-weight: 600;
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}
.tabulator .tabulator-header .tabulator-col {
    background: transparent;
    border-right: none;
    padding: 8px 6px !important;
}
.tabulator .tabulator-header .tabulator-col:first-child,
.tabulator .tabulator-row .tabulator-cell:first-child {
    padding-left: 1.25rem !important;
}
.tabulator .tabulator-header .tabulator-col:last-child,
.tabulator .tabulator-row .tabulator-cell:last-child {
    padding-right: 1.25rem !important;
}
.tabulator .tabulator-header .tabulator-col .tabulator-col-content {
    padding: 0 !important;
}
.tabulator .tabulator-header .tabulator-col .tabulator-col-content .tabulator-col-title-holder {
    display: inline-flex;
    align-items: center;
    max-width: 100%;
}
.tabulator .tabulator-header .tabulator-col.tabulator-sortable .tabulator-col-title {
    padding-right: 0 !important;
    white-space: normal !important;
    overflow: visible !important;
    text-overflow: clip !important;
}
.tabulator .tabulator-header .tabulator-col .tabulator-col-sorter {
    position: static !important;
    margin-left: 5px;
    display: inline-flex !important;
    align-items: center;
    flex-shrink: 0;
}
.tabulator .tabulator-row {
    border-bottom: 1px solid #f1f5f9;
    min-height: 52px;
    height: auto !important;
    background: #ffffff;
}
.tabulator .tabulator-row:hover {
    background-color: #f8fafc !important;
}
.tabulator .tabulator-row .tabulator-cell {
    padding: 10px 6px !important;
    border-right: none;
    vertical-align: middle;
    font-size: 0.84rem;
    height: auto !important;
    min-height: 52px;
    white-space: normal !important;
    word-break: break-word;
    overflow-wrap: break-word;
    display: inline-flex;
    align-items: center;
    min-width: 0 !important;
}
.tabulator .tabulator-footer {
    background: #ffffff;
    border-top: 1px solid #e2e8f0;
    padding: 8px 16px;
}

@media (max-width: 991px) {
    .tabulator {
        min-width: 820px !important;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    function stripHtml(html) {
        var tmp = document.createElement('div');
        tmp.innerHTML = html;
        return tmp.textContent || tmp.innerText || '';
    }

    var paymentHistoryTable = new Tabulator("#paymentHistoryTable", {
        layout: "fitColumns",
        pagination: "local",
        paginationSize: 25,
        paginationSizeSelector: [10, 25, 50, 100],
        placeholder: "<div class='text-center py-5 text-muted'><i class='bi bi-receipt fs-1 d-block mb-2 text-muted-light'></i>No payment records found.</div>",
        initialSort: [
            { column: "payment_date", dir: "desc" }
        ],
        columns: [
            { title: "Date", field: "payment_date", minWidth: 90, widthGrow: 0.9, formatter: "html" },
            { title: "OR Number", field: "or_number", minWidth: 120, widthGrow: 1.2, formatter: "html" },
            { title: "Student", field: "student", minWidth: 120, widthGrow: 1.3, formatter: "html" },
            { 
                title: "Amount", 
                field: "amount", 
                minWidth: 90, 
                widthGrow: 1.0, 
                hozAlign: "right",
                headerHozAlign: "right",
                formatter: "html",
                sorter: function(a, b) {
                    var aNum = parseFloat(stripHtml(a).replace(/[^0-9.-]+/g, '')) || 0;
                    var bNum = parseFloat(stripHtml(b).replace(/[^0-9.-]+/g, '')) || 0;
                    return aNum - bNum;
                }
            },
            { title: "Method", field: "method", minWidth: 110, widthGrow: 1.1, formatter: "html" },
            { title: "Status", field: "or_status", minWidth: 95, widthGrow: 1.0, formatter: "html" },
            { title: "Cashier", field: "cashier", minWidth: 75, widthGrow: 0.8, formatter: "html" },
            { title: "Notes", field: "notes", minWidth: 110, widthGrow: 1.2, formatter: "html" }
        ]
    });

    var searchInput = document.getElementById('search');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            var term = this.value.trim().toLowerCase();
            if (!term) {
                paymentHistoryTable.clearFilter();
            } else {
                paymentHistoryTable.setFilter(function(data) {
                    return stripHtml(data.student).toLowerCase().includes(term) ||
                           stripHtml(data.or_number).toLowerCase().includes(term) ||
                           stripHtml(data.method).toLowerCase().includes(term) ||
                           stripHtml(data.or_status).toLowerCase().includes(term) ||
                           stripHtml(data.notes).toLowerCase().includes(term);
                });
            }
        });
    }
});
</script>

<?php
require_once '../includes/footer.php';
?>

