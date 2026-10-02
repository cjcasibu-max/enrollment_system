<?php
/**
 * Official Receipt Viewer
 * Renders a clean, printable receipt for a given payment ID.
 */

require_once '../includes/auth_check.php';
checkRole(['cashier', 'admin', 'student']);

require_once '../config/database.php';

$paymentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$payment = null;
$isStudentViewer = ($_SESSION['role'] ?? '') === 'student';

if ($paymentId > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT p.id, p.amount, p.payment_method, p.payment_reference, p.bank_name, p.check_number, p.or_number, p.payment_date, p.issue_date, p.or_status, p.notes, p.created_at,
                   s.first_name, s.last_name,
                   u.username AS cashier_username
            FROM payments p
            JOIN students s ON p.student_id = s.id
            LEFT JOIN users u ON u.id = COALESCE(p.cashier_id, p.validated_by)
            WHERE p.id = :id
              AND p.or_status = 'validated'
              AND (:is_student_viewer = 0 OR s.user_id = :viewer_id)
            LIMIT 1
        ");
        $stmt->execute(['id' => $paymentId, 'is_student_viewer' => $isStudentViewer ? 1 : 0, 'viewer_id' => (int)$_SESSION['user_id']]);
        $payment = $stmt->fetch();
    } catch (\PDOException $e) {
        error_log("Fetch receipt failed: " . $e->getMessage());
    }
}

$recentReceipts = [];
if (!$payment) {
    try {
        $rStmt = $pdo->prepare("
            SELECT p.id, p.amount, p.payment_method, p.payment_reference, p.or_number, p.payment_date, p.issue_date, p.or_status, p.notes, p.created_at,
                   s.first_name, s.last_name, s.id AS student_id,
                   u.username AS cashier_username
            FROM payments p
            JOIN students s ON p.student_id = s.id
            LEFT JOIN users u ON u.id = COALESCE(p.cashier_id, p.validated_by)
            WHERE p.or_status = 'validated'
              AND (:is_student_viewer = 0 OR s.user_id = :viewer_id)
            ORDER BY p.payment_date DESC, p.id DESC
            LIMIT 25
        ");
        $rStmt->execute([
            'is_student_viewer' => $isStudentViewer ? 1 : 0,
            'viewer_id' => (int)($_SESSION['user_id'] ?? 0)
        ]);
        $recentReceipts = $rStmt->fetchAll();
    } catch (\PDOException $e) {
        error_log("Fetch recent receipts failed: " . $e->getMessage());
        $recentReceipts = [];
    }
}

$page_title = "Official Receipt";
require_once '../includes/header.php';
?>

<style>
    .receipt-container {
        max-width: 480px;
        margin: 0 auto;
        background: var(--surface-white);
        border: 2px solid var(--brand-primary);
        border-radius: 12px;
        overflow: hidden;
    }
    .receipt-header {
        background: var(--brand-dark);
        color: #fff;
        padding: 1.5rem;
        text-align: center;
    }
    .receipt-header .school-name {
        font-size: 1.1rem;
        font-weight: 700;
        letter-spacing: 0.5px;
        text-transform: uppercase;
    }
    .receipt-header .receipt-label {
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 2px;
        color: var(--brand-primary-soft);
        margin-top: 0.25rem;
    }
    .receipt-body {
        padding: 1.75rem;
    }
    .receipt-row {
        display: flex;
        justify-content: space-between;
        align-items: baseline;
        padding: 0.6rem 0;
        border-bottom: 1px dashed var(--gray-200);
    }
    .receipt-row:last-child {
        border-bottom: none;
    }
    .receipt-row .label {
        font-size: 0.8rem;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-weight: 600;
    }
    .receipt-row .value {
        font-weight: 600;
        color: var(--text-darker);
        text-align: right;
    }
    .receipt-amount {
        font-size: 1.75rem;
        font-weight: 800;
        color: var(--brand-primary);
    }
    .receipt-footer {
        background: var(--surface-tint);
        padding: 1rem 1.75rem;
        text-align: center;
        font-size: 0.75rem;
        color: var(--text-muted);
    }
    @media print {
        .sidebar, .top-navbar, .footer, .no-print, .content-body > .animated-fade-in > .mb-4:first-child {
            display: none !important;
        }
        .app-container, .main-wrapper, .content-body, .animated-fade-in {
            display: block !important;
            margin: 0 !important;
            padding: 0 !important;
            width: 100% !important;
        }
        .receipt-container {
            border: 2px solid #000;
            box-shadow: none;
            max-width: 100%;
            margin: 2rem auto;
        }
        body {
            background: #fff !important;
        }
    }
</style>

<div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2 no-print">
    <div>
        <h3 class="m-0 text-navy-alt">Official Receipt</h3>
        <p class="text-muted small m-0">View and print the official receipt for a recorded payment.</p>
    </div>
    <div class="d-flex gap-2">
        <?php if ($payment): ?>
            <a href="receipts" class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-1">
                <i class="bi bi-arrow-left"></i> All Receipts
            </a>
        <?php endif; ?>
        <a href="enrollment_queue" class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-1">
            <i class="bi bi-person-lines-fill me-1"></i> Enrollment Queue
        </a>
        <a href="payment_history" class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-1">
            <i class="bi bi-clock-history me-1"></i> History
        </a>
    </div>
</div>

<?php if (!$payment): ?>
    <?php if ($paymentId > 0): ?>
        <div class="card card-premium shadow-sm">
            <div class="card-body card-body-premium text-center py-5">
                <i class="bi bi-receipt fs-1 text-muted d-block mb-3 opacity-50"></i>
                <h5 class="text-darker">Receipt Not Found</h5>
                <p class="text-muted mb-3">No payment record exists for ID #<?php echo $paymentId; ?>.</p>
                <a href="receipts" class="btn btn-brand-primary btn-sm px-4">
                    <i class="bi bi-arrow-left me-1"></i> Browse All Receipts
                </a>
            </div>
        </div>
    <?php else: ?>
        <!-- Interactive Receipt Selector Card -->
        <div class="card card-premium shadow-sm mb-4">
            <div class="card-body card-body-premium p-4">
                <div class="row align-items-center g-3">
                    <div class="col-12 col-md-5">
                        <div class="d-flex align-items-center gap-3">
                            <div class="p-2.5 rounded-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; background: rgba(13,155,150,0.12); color: #0b7a78;">
                                <i class="bi bi-receipt-cutoff fs-4"></i>
                            </div>
                            <div>
                                <h5 class="mb-0 fw-bold text-navy-alt">Select an Official Receipt</h5>
                                <p class="text-muted small mb-0">Choose from recently recorded payments or jump directly by OR number.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-7">
                        <select id="quickReceiptSelect" class="form-select" onchange="if(this.value) window.location.href='receipts?id=' + this.value;">
                            <option value="">-- Choose a Receipt to View / Print --</option>
                            <?php foreach ($recentReceipts as $r): ?>
                                <option value="<?php echo (int)$r['id']; ?>">
                                    <?php echo htmlspecialchars(($r['or_number'] ?: 'OR Pending') . ' — ' . $r['first_name'] . ' ' . $r['last_name'] . ' (₱' . number_format((float)$r['amount'], 2) . ' · ' . date('M j, Y', strtotime($r['payment_date'])) . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Official Receipts Table -->
        <div class="card card-premium shadow-sm">
            <div class="card-header card-header-premium d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h5 class="card-title m-0 fw-semibold text-navy-alt">
                        <i class="bi bi-clock-history me-1 text-teal"></i> Recent Official Receipts
                    </h5>
                    <small class="text-muted">Showing <?php echo count($recentReceipts); ?> latest recorded receipts</small>
                </div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <div class="input-group input-group-sm" style="width: 220px;">
                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" id="receiptSearchInput" class="form-control form-control-sm border-start-0 ps-0" placeholder="Filter receipts...">
                    </div>
                    <a href="payment_history" class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-1">
                        <i class="bi bi-search"></i> All History
                    </a>
                </div>
            </div>
            <div class="card-body card-body-premium p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="receiptsTable" style="width: 100%;">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4" tabulator-field="or_number" style="width: 140px;">OR Number</th>
                                <th tabulator-field="payment_date" style="width: 130px;">Payment Date</th>
                                <th tabulator-field="student">Student</th>
                                <th tabulator-field="amount" style="width: 130px;">Amount</th>
                                <th tabulator-field="method" style="width: 130px;">Method</th>
                                <th tabulator-field="status" style="width: 120px;">Status</th>
                                <th class="pe-4 text-end" tabulator-field="action" style="width: 140px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($recentReceipts)): ?>
                                <?php foreach ($recentReceipts as $r): ?>
                                    <tr>
                                        <td class="ps-4">
                                            <span class="fw-bold text-navy-alt">
                                                <?php echo !empty($r['or_number']) ? htmlspecialchars($r['or_number']) : '<span class="text-muted fst-italic">Pending OR</span>'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="small text-muted">
                                                <?php echo date('M j, Y', strtotime($r['payment_date'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="fw-semibold text-darker">
                                                <?php echo htmlspecialchars($r['first_name'] . ' ' . $r['last_name']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="fw-bold text-success">
                                                ₱<?php echo number_format((float)$r['amount'], 2); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-secondary border">
                                                <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string)($r['payment_method'] ?? 'cash')))); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php 
                                            $orStatus = strtolower((string)$r['or_status']);
                                            $badgeClass = 'bg-secondary-subtle text-secondary';
                                            if ($orStatus === 'validated') $badgeClass = 'bg-success-subtle text-success';
                                            elseif ($orStatus === 'pending') $badgeClass = 'bg-warning-subtle text-warning';
                                            elseif ($orStatus === 'voided') $badgeClass = 'bg-danger-subtle text-danger';
                                            ?>
                                            <span class="badge <?php echo $badgeClass; ?> text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.5px;">
                                                <?php echo htmlspecialchars($r['or_status']); ?>
                                            </span>
                                        </td>
                                        <td class="pe-4 text-end">
                                            <a href="receipts?id=<?php echo (int)$r['id']; ?>" class="btn btn-sm btn-brand-primary d-inline-flex align-items-center gap-1 px-3">
                                                <i class="bi bi-receipt"></i> View Receipt
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
<?php else: ?>
    <div class="d-flex justify-content-center mb-4 no-print">
        <button type="button" class="btn btn-brand-primary px-4 fw-semibold" onclick="window.print()">
            <i class="bi bi-printer me-1"></i> Print Receipt
        </button>
    </div>

    <div class="receipt-container shadow-sm">
        <div class="receipt-header">
            <div class="school-name">NCST Maritime Academy</div>
            <div class="receipt-label">Official Receipt</div>
        </div>

        <div class="receipt-body">
            <div class="receipt-row">
                <span class="label">OR Number</span>
                <span class="value fw-bold text-uppercase"><?php echo htmlspecialchars($payment['or_number']); ?></span>
            </div>
            <div class="receipt-row">
                <span class="label">Date</span>
                <span class="value"><?php echo date('F j, Y', strtotime($payment['payment_date'])); ?></span>
            </div>
            <div class="receipt-row">
                <span class="label">OR Status</span>
                <span class="value text-uppercase"><?php echo htmlspecialchars($payment['or_status']); ?></span>
            </div>
            <div class="receipt-row">
                <span class="label">Student</span>
                <span class="value"><?php echo htmlspecialchars($payment['first_name'] . ' ' . $payment['last_name']); ?></span>
            </div>
            <div class="receipt-row">
                <span class="label">Amount Paid</span>
                <span class="value receipt-amount">₱<?php echo number_format((float)$payment['amount'], 2); ?></span>
            </div>
            <div class="receipt-row">
                <span class="label">Payment Method</span>
                <span class="value"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string)($payment['payment_method'] ?? 'cash')))); ?></span>
            </div>
            <?php if (!empty($payment['payment_reference'])): ?>
            <div class="receipt-row">
                <span class="label">Reference</span>
                <span class="value"><?php echo htmlspecialchars($payment['payment_reference']); ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($payment['notes'])): ?>
            <div class="receipt-row">
                <span class="label">Particulars</span>
                <span class="value" style="max-width: 60%;"><?php echo htmlspecialchars($payment['notes']); ?></span>
            </div>
            <?php endif; ?>
            <div class="receipt-row">
                <span class="label">Cashier</span>
                <span class="value"><?php echo !empty($payment['cashier_username']) ? htmlspecialchars($payment['cashier_username']) : '<em class="text-muted">Pending validation</em>'; ?></span>
            </div>
            <div class="receipt-row">
                <span class="label">Receipt ID</span>
                <span class="value text-muted">#<?php echo (int)$payment['id']; ?></span>
            </div>
        </div>

        <div class="receipt-footer">
            &copy; <?php echo date('Y'); ?> NCST Maritime Academy &mdash; This is an official receipt.
        </div>
    </div>
<?php endif; ?>

<style>
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
    padding: 8px 4px;
}
.tabulator .tabulator-row {
    border-bottom: 1px solid #f1f5f9;
    min-height: 48px;
    background: #ffffff;
}
.tabulator .tabulator-row:hover {
    background-color: #f8fafc !important;
}
.tabulator .tabulator-row .tabulator-cell {
    padding: 10px 12px;
    border-right: none;
    vertical-align: middle;
    font-size: 0.86rem;
    display: inline-flex;
    align-items: center;
}
.tabulator-row.tabulator-responsive-collapse {
    background: #f8fafc !important;
    padding: 8px 16px !important;
}
.tabulator .tabulator-footer {
    background: #ffffff;
    border-top: 1px solid #e2e8f0;
    padding: 8px 16px;
}
.tabulator-responsive-collapse table {
    width: 100%;
    font-size: 0.85rem;
}
.tabulator-responsive-collapse td {
    padding: 4px 8px;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var receiptsTableEl = document.getElementById('receiptsTable');
    if (receiptsTableEl) {
        function stripHtml(html) {
            var tmp = document.createElement('div');
            tmp.innerHTML = html;
            return tmp.textContent || tmp.innerText || '';
        }

        var receiptsTable = new Tabulator("#receiptsTable", {
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
            placeholder: "<div class='text-center py-5 text-muted'><i class='bi bi-receipt fs-1 d-block mb-2 text-muted-light'></i>No receipts recorded yet.</div>",
            columns: [
                { title: "OR Number", field: "or_number", width: 140, formatter: "html" },
                { title: "Payment Date", field: "payment_date", width: 130, formatter: "html" },
                { title: "Student", field: "student", minWidth: 160, formatter: "html" },
                { 
                    title: "Amount", 
                    field: "amount", 
                    width: 130, 
                    formatter: "html",
                    sorter: function(a, b) {
                        var aNum = parseFloat(stripHtml(a).replace(/[^0-9.-]+/g, '')) || 0;
                        var bNum = parseFloat(stripHtml(b).replace(/[^0-9.-]+/g, '')) || 0;
                        return aNum - bNum;
                    }
                },
                { title: "Method", field: "method", width: 130, formatter: "html" },
                { title: "Status", field: "status", width: 120, formatter: "html" },
                { title: "Action", field: "action", width: 140, hozAlign: "center", headerSort: false, formatter: "html", responsive: 0 }
            ]
        });

        var searchInput = document.getElementById('receiptSearchInput');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                var term = this.value.trim().toLowerCase();
                if (!term) {
                    receiptsTable.clearFilter();
                } else {
                    receiptsTable.setFilter(function(data) {
                        return stripHtml(data.or_number).toLowerCase().includes(term) ||
                               stripHtml(data.student).toLowerCase().includes(term) ||
                               stripHtml(data.method).toLowerCase().includes(term) ||
                               stripHtml(data.status).toLowerCase().includes(term);
                    });
                }
            });
        }
    }
});
</script>

<?php
require_once '../includes/footer.php';
?>
