<?php
/**
 * Student Payment History
 * Displays payment records belonging only to the authenticated student.
 */

require_once '../includes/auth_check.php';
checkRole(['student']);

require_once '../config/database.php';
require_once '../includes/assessments.php';

$studentId = isset($_SESSION['student_id']) ? (int)$_SESSION['student_id'] : 0;
$payments = [];
$totalPaid = 0.00;
$paymentError = null;
$assessment = null;

try {
    // Recover the profile ID if it is not available in the current session.
    if ($studentId <= 0) {
        $profileStmt = $pdo->prepare("SELECT id FROM students WHERE user_id = :user_id LIMIT 1");
        $profileStmt->execute(['user_id' => (int)$_SESSION['user_id']]);
        $studentId = (int)($profileStmt->fetchColumn() ?: 0);
        if ($studentId > 0) {
            $_SESSION['student_id'] = $studentId;
        }
    }

    if ($studentId > 0) {
        $assessmentStmt = $pdo->prepare("SELECT id FROM assessments WHERE student_id = :student_id AND academic_term_id = (SELECT academic_term_id FROM students WHERE id = :student_id_term LIMIT 1) AND status != 'cancelled' LIMIT 1");
        $assessmentStmt->execute(['student_id' => $studentId, 'student_id_term' => $studentId]);
        $assessmentId = (int)($assessmentStmt->fetchColumn() ?: 0);
        if ($assessmentId > 0) {
            $assessment = getAssessmentTotals($pdo, $assessmentId);
            $assessment['id'] = $assessmentId;
        }
            $paymentStmt = $pdo->prepare("
            SELECT p.id, p.amount, p.or_number, p.payment_date, p.or_status, p.notes, p.validation_notes, p.created_at,
                   u.username AS cashier_username
            FROM payments p
            LEFT JOIN users u ON u.id = p.cashier_id
            WHERE p.student_id = :student_id
            ORDER BY p.payment_date DESC, p.id DESC
        ");
        $paymentStmt->execute(['student_id' => $studentId]);
        $payments = $paymentStmt->fetchAll();

        $validatedPaidTotal = 0.00;
        $pendingPaidTotal = 0.00;
        foreach ($payments as $payment) {
            if (($payment['or_status'] ?? '') === 'validated') {
                $validatedPaidTotal += (float)$payment['amount'];
            } elseif (($payment['or_status'] ?? '') === 'pending') {
                $pendingPaidTotal += (float)$payment['amount'];
            }
        }
        $totalPaid = $validatedPaidTotal;
    }
} catch (\PDOException $e) {
    error_log('Student payment history failed: ' . $e->getMessage());
    $paymentError = 'Payment history is temporarily unavailable. Please try again later.';
}

$page_title = 'My Payments';
require_once '../includes/header.php';
?>

<style>
/* Custom Styled Badges & Actions for My Payments */
.or-badge {
    display: inline-block;
    font-family: var(--bs-font-monospace, monospace);
    font-size: 0.76rem;
    font-weight: 700;
    padding: 3px 8px;
    background: #f8fafc;
    color: #0f172a;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    word-break: break-all;
    line-height: 1.3;
}
.btn-receipt-action {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 11px;
    font-size: 0.74rem;
    font-weight: 600;
    color: #0b9b98;
    background-color: #f0fdfa;
    border: 1px solid #99f6e4;
    border-radius: 6px;
    text-decoration: none !important;
    transition: all 0.15s ease-in-out;
    white-space: nowrap;
}
.btn-receipt-action:hover {
    background-color: #0b9b98;
    color: #ffffff;
    border-color: #0b9b98;
    box-shadow: 0 2px 6px rgba(11, 155, 152, 0.25);
}
.payment-table-container {
    width: 100%;
    overflow-x: hidden;
}

/* Tabulator Styling */
.tabulator {
    font-family: inherit;
    border: none;
    background-color: transparent;
    width: 100% !important;
    min-width: 0 !important;
}
.tabulator .tabulator-header {
    background: #f0fdfa !important;
    border-bottom: 2px solid #ccfbf1 !important;
    color: #0f766e !important;
    font-weight: 700;
    font-size: 0.74rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.tabulator .tabulator-header .tabulator-col {
    background: transparent !important;
    border-right: none !important;
    padding: 10px 8px !important;
}
.tabulator .tabulator-header .tabulator-col .tabulator-col-content {
    padding: 0 !important;
}
.tabulator .tabulator-header .tabulator-col .tabulator-col-content .tabulator-col-title {
    white-space: normal !important;
    overflow: visible !important;
    text-overflow: clip !important;
    color: #0f766e !important;
}
.tabulator .tabulator-row {
    border-bottom: 1px solid #f1f5f9;
    min-height: 52px;
    background: #ffffff;
    transition: background-color 0.15s ease-in-out;
}
.tabulator .tabulator-row:hover {
    background-color: #f8fafc !important;
}
.tabulator .tabulator-row .tabulator-cell {
    padding: 12px 10px !important;
    border-right: none !important;
    vertical-align: middle;
    font-size: 0.86rem;
    display: inline-flex;
    align-items: center;
    white-space: normal !important;
    overflow: visible !important;
    text-overflow: clip !important;
}
.tabulator .tabulator-cell[tabulator-field="or_number"] {
    word-break: break-all !important;
}
.tabulator .tabulator-cell[tabulator-field="amount"] {
    justify-content: flex-end;
}
.tabulator .tabulator-cell[tabulator-field="actions"] {
    justify-content: center;
}
.tabulator .tabulator-footer {
    background: #ffffff;
    border-top: 1px solid #e2e8f0;
    padding: 10px 16px;
}
.tabulator .tabulator-page {
    border-radius: 6px !important;
    border: 1px solid #e2e8f0 !important;
    background: #ffffff !important;
    color: #475569 !important;
    font-size: 0.8rem !important;
    padding: 4px 10px !important;
    margin: 0 2px !important;
}
.tabulator .tabulator-page.active {
    background: var(--brand-primary, #0b9b98) !important;
    border-color: var(--brand-primary, #0b9b98) !important;
    color: #ffffff !important;
    font-weight: 700 !important;
}

/* Mobile Stacked Card View */
@media (max-width: 768px) {
    .tabulator .tabulator-header {
        display: none !important;
    }
    .tabulator .tabulator-tableholder {
        overflow: visible !important;
        height: auto !important;
        max-height: none !important;
    }
    .tabulator .tabulator-table {
        display: block !important;
        width: 100% !important;
    }
    .tabulator .tabulator-row {
        display: block !important;
        width: 100% !important;
        height: auto !important;
        background: #ffffff !important;
        border: 1px solid #e2e8f0 !important;
        border-radius: 12px !important;
        margin-bottom: 12px !important;
        padding: 12px 14px !important;
        box-shadow: 0 2px 8px rgba(0,0,0,0.04) !important;
    }
    .tabulator .tabulator-row .tabulator-cell {
        display: flex !important;
        width: 100% !important;
        height: auto !important;
        min-height: 36px !important;
        padding: 6px 0 !important;
        justify-content: space-between !important;
        align-items: center !important;
        border-bottom: 1px dashed #f1f5f9 !important;
        font-size: 0.84rem !important;
    }
    .tabulator .tabulator-row .tabulator-cell:last-child {
        border-bottom: none !important;
        padding-top: 10px !important;
        justify-content: center !important;
    }
    .tabulator .tabulator-cell[tabulator-field="payment_date"]::before {
        content: "Date";
        font-weight: 700;
        font-size: 0.72rem;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }
    .tabulator .tabulator-cell[tabulator-field="or_number"]::before {
        content: "OR Number";
        font-weight: 700;
        font-size: 0.72rem;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }
    .tabulator .tabulator-cell[tabulator-field="particulars"]::before {
        content: "Particulars";
        font-weight: 700;
        font-size: 0.72rem;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }
    .tabulator .tabulator-cell[tabulator-field="cashier"]::before {
        content: "Cashier";
        font-weight: 700;
        font-size: 0.72rem;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }
    .tabulator .tabulator-cell[tabulator-field="status"]::before {
        content: "OR Status";
        font-weight: 700;
        font-size: 0.72rem;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }
    .tabulator .tabulator-cell[tabulator-field="amount"]::before {
        content: "Amount";
        font-weight: 700;
        font-size: 0.72rem;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }
    .tabulator .tabulator-cell[tabulator-field="actions"]::before {
        content: "Official Receipt";
        font-weight: 700;
        font-size: 0.72rem;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }
}
</style>

<!-- Page Heading -->
<div class="page-heading mb-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
    <div>
        <div class="page-eyebrow"><i class="bi bi-wallet2 me-1"></i> Financial Overview</div>
        <h3 class="m-0 text-navy-alt fw-bold">My Payments</h3>
        <p class="text-muted small m-0">Review your verified transactions, official receipts, and current assessment balance.</p>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <a href="payment" class="btn btn-sm btn-brand-primary d-inline-flex align-items-center gap-1.5 shadow-sm">
            <i class="bi bi-credit-card"></i> Payment Center
        </a>
        <a href="dashboard" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1.5">
            <i class="bi bi-arrow-left"></i> Dashboard
        </a>
    </div>
</div>

<?php if ($paymentError): ?>
    <div class="alert alert-danger d-flex align-items-center gap-2" role="alert">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <span><?php echo htmlspecialchars($paymentError); ?></span>
    </div>
<?php elseif ($studentId <= 0): ?>
    <div class="card card-premium shadow-sm">
        <div class="card-body card-body-premium text-center py-5">
            <i class="bi bi-person-x fs-1 text-muted d-block mb-3 opacity-50"></i>
            <h5 class="text-darker">Student profile not found</h5>
            <p class="text-muted mb-0">Your account is not currently linked to a student profile. Please contact the registrar.</p>
        </div>
    </div>
<?php else: ?>
    <!-- Quick Statistics Row -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-md-6">
            <div class="card card-premium shadow-sm border-start border-4 border-success h-100" style="border-radius: 12px;">
                <div class="card-body p-3.5 d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted text-uppercase fw-bold" style="font-size: 0.72rem; letter-spacing: 0.5px;">Total Paid (Validated)</span>
                        <h3 class="fw-extrabold text-navy-alt mt-1.5 mb-0">₱<?php echo number_format($totalPaid, 2); ?></h3>
                        <?php if ($pendingPaidTotal > 0): ?>
                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning mt-2 d-inline-flex align-items-center gap-1" style="font-size: 0.72rem; border-radius: 999px;">
                                <i class="bi bi-clock-history"></i> +₱<?php echo number_format($pendingPaidTotal, 2); ?> pending verification
                            </span>
                        <?php else: ?>
                            <small class="text-muted d-block mt-1"><i class="bi bi-shield-check text-success me-1"></i>Verified by cashier</small>
                        <?php endif; ?>
                    </div>
                    <div class="fs-1 text-success opacity-25"><i class="bi bi-cash-stack"></i></div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6">
            <div class="card card-premium shadow-sm border-start border-4 border-brand-primary h-100" style="border-radius: 12px;">
                <div class="card-body p-3.5 d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted text-uppercase fw-bold" style="font-size: 0.72rem; letter-spacing: 0.5px;">Payment Records</span>
                        <h3 class="fw-extrabold text-navy-alt mt-1.5 mb-0"><?php echo count($payments); ?> <span class="fs-6 text-muted fw-normal">Transactions</span></h3>
                        <small class="text-muted d-block mt-1"><i class="bi bi-receipt text-brand-primary me-1"></i>All official receipts & submissions</small>
                    </div>
                    <div class="fs-1 text-brand-primary opacity-25"><i class="bi bi-receipt-cutoff"></i></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Current Assessment Balance Card -->
    <?php if ($assessment): 
        $totalAssessed = (float)($assessment['total_amount'] ?? 0);
        $valPaid = (float)($assessment['validated_paid'] ?? 0);
        $balanceAmt = (float)($assessment['balance'] ?? 0);
        $percentPaid = $totalAssessed > 0 ? min(100, max(0, round(($valPaid / $totalAssessed) * 100, 1))) : 0;
    ?>
    <div class="card border-0 shadow-sm mb-4" style="border-radius: 14px; border: 1px solid #e2e8f0 !important; background: #ffffff;">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3">
                <div>
                    <div class="text-uppercase fw-bold text-muted small" style="font-size: 0.72rem; letter-spacing: 0.5px;">
                        Current Assessment Balance
                    </div>
                    <div class="d-flex align-items-baseline gap-2 mt-1">
                        <h3 class="m-0 fw-bold <?php echo $balanceAmt > 0 ? 'text-danger' : 'text-success'; ?>">
                            ₱<?php echo number_format($balanceAmt, 2); ?>
                        </h3>
                        <?php if ($balanceAmt <= 0): ?>
                            <span class="badge bg-success-subtle text-success border border-success px-2 py-0.5" style="font-size: 0.72rem; border-radius: 999px;">
                                <i class="bi bi-check-circle-fill me-1"></i>Fully Settled
                            </span>
                        <?php else: ?>
                            <span class="badge bg-danger-subtle text-danger border border-danger px-2 py-0.5" style="font-size: 0.72rem; border-radius: 999px;">
                                Outstanding Balance
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="text-muted small mt-1">
                        Assessed Total: <strong class="text-dark">₱<?php echo number_format($totalAssessed, 2); ?></strong> · Validated Paid: <strong class="text-dark">₱<?php echo number_format($valPaid, 2); ?></strong>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <a href="../cashier/assessment?id=<?php echo (int)$assessment['id']; ?>" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1.5">
                        <i class="bi bi-file-earmark-text"></i> View Assessment
                    </a>
                    <a href="../cashier/assessment?id=<?php echo (int)$assessment['id']; ?>&download=1" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1.5">
                        <i class="bi bi-download"></i> Download Slip
                    </a>
                </div>
            </div>
            
            <!-- Progress Bar -->
            <div>
                <div class="d-flex justify-content-between align-items-center mb-1 text-muted" style="font-size: 0.72rem;">
                    <span>Payment Progress</span>
                    <span class="fw-bold <?php echo $percentPaid >= 100 ? 'text-success' : 'text-brand-primary'; ?>"><?php echo $percentPaid; ?>% Complete</span>
                </div>
                <div class="progress" style="height: 7px; border-radius: 999px; background: #f1f5f9;">
                    <div class="progress-bar <?php echo $percentPaid >= 100 ? 'bg-success' : 'bg-brand-primary'; ?>" role="progressbar" style="width: <?php echo $percentPaid; ?>%; border-radius: 999px;" aria-valuenow="<?php echo $percentPaid; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Payment History Card -->
    <div class="card border-0 shadow-sm" style="border-radius: 14px; overflow: hidden; border: 1px solid #e2e8f0 !important;">
        <div class="card-header bg-white px-4 py-3 d-flex justify-content-between align-items-center flex-wrap gap-2 border-bottom">
            <div>
                <h5 class="m-0 fw-bold text-navy-alt d-flex align-items-center gap-2" style="font-size: 0.98rem;">
                    <i class="bi bi-clock-history text-brand-primary"></i> Payment History
                </h5>
                <p class="text-muted small m-0">Ledger of all submitted, validated, and recorded payments.</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <div class="input-group input-group-sm" style="width: 220px;">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" id="myPaymentsSearch" class="form-control form-control-sm border-start-0 ps-0" placeholder="Filter payments...">
                </div>
                <span class="badge" style="background: rgba(11, 155, 152, 0.1); color: var(--brand-primary); font-size: 0.75rem; font-weight: 700; border-radius: 999px; padding: 6px 12px;">
                    Student ID #<?php echo $studentId; ?>
                </span>
            </div>
        </div>
        <div class="card-body p-0" style="overflow-x: hidden;">
            <div class="payment-table-container">
                <table class="table table-hover align-middle mb-0" id="myPaymentsTable" style="width: 100%;">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3" tabulator-field="payment_date" style="width: 12%;">Date</th>
                            <th tabulator-field="or_number" style="width: 22%;">OR Number</th>
                            <th tabulator-field="particulars" style="width: 20%;">Particulars</th>
                            <th tabulator-field="cashier" style="width: 12%;">Cashier</th>
                            <th tabulator-field="status" style="width: 12%;">OR Status</th>
                            <th class="text-end" tabulator-field="amount" style="width: 14%;">Amount</th>
                            <th class="text-center pe-3" tabulator-field="actions" style="width: 10%;">Receipt</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($payments)): ?>
                            <?php foreach ($payments as $payment): ?>
                                <tr>
                                    <td class="ps-3 text-nowrap">
                                        <span class="fw-medium text-dark"><?php echo htmlspecialchars(date('M j, Y', strtotime($payment['payment_date']))); ?></span>
                                    </td>
                                    <td>
                                        <span class="or-badge"><?php echo htmlspecialchars($payment['or_number']); ?></span>
                                    </td>
                                    <td>
                                        <?php echo $payment['notes'] !== null && trim($payment['notes']) !== ''
                                            ? htmlspecialchars($payment['notes'])
                                            : '<span class="text-muted fst-italic">Assessment Payment</span>'; ?>
                                    </td>
                                    <td>
                                        <span class="text-dark small"><i class="bi bi-person me-1 text-muted"></i><?php echo htmlspecialchars($payment['cashier_username'] ?? 'Cashier'); ?></span>
                                    </td>
                                    <td>
                                        <?php
                                        $pStatus = $payment['or_status'] ?? 'pending';
                                        $badgeClass = match($pStatus) {
                                            'validated' => 'bg-success-subtle text-success border border-success',
                                            'rejected', 'voided' => 'bg-danger-subtle text-danger border border-danger',
                                            default => 'bg-warning-subtle text-warning-emphasis border border-warning',
                                        };
                                        $badgeIcon = match($pStatus) {
                                            'validated' => 'bi-check-circle-fill',
                                            'rejected', 'voided' => 'bi-x-circle-fill',
                                            default => 'bi-clock-history',
                                        };
                                        ?>
                                        <span class="badge <?php echo $badgeClass; ?> d-inline-flex align-items-center gap-1 px-2.5 py-1" style="font-size: 0.68rem; font-weight: 700; border-radius: 999px;">
                                            <i class="bi <?php echo $badgeIcon; ?>"></i> <?php echo htmlspecialchars(ucfirst($pStatus)); ?>
                                        </span>
                                        <?php if ($pStatus === 'rejected' && !empty($payment['validation_notes'])): ?>
                                            <div class="text-danger small mt-1" style="font-size:0.75rem;">
                                                <i class="bi bi-info-circle me-1"></i><?php echo htmlspecialchars($payment['validation_notes']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <span class="fw-bold text-navy-alt font-monospace" style="font-size: 0.92rem;">₱<?php echo number_format((float)$payment['amount'], 2); ?></span>
                                    </td>
                                    <td class="text-center pe-3">
                                        <?php if ($pStatus === 'validated'): ?>
                                            <a href="../cashier/receipts?id=<?php echo (int)$payment['id']; ?>" class="btn-receipt-action" title="View Official Receipt">
                                                <i class="bi bi-receipt"></i><span>Receipt</span>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted small" title="Receipt available upon validation">—</span>
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
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var myPaymentsTableEl = document.getElementById('myPaymentsTable');
    if (myPaymentsTableEl) {
        function stripHtml(html) {
            var tmp = document.createElement('div');
            tmp.innerHTML = html;
            return tmp.textContent || tmp.innerText || '';
        }

        var myPaymentsTable = new Tabulator("#myPaymentsTable", {
            layout: "fitColumns",
            pagination: "local",
            paginationSize: 25,
            paginationSizeSelector: [10, 25, 50, 100],
            placeholder: "<div class='text-center py-5'><div class='mb-3 text-muted opacity-50' style='font-size: 3rem;'><i class='bi bi-receipt-cutoff'></i></div><h5 class='fw-bold text-navy-alt mb-1'>No Payments Recorded Yet</h5><p class='text-muted small mx-auto mb-3' style='max-width: 360px;'>Your official payment records will appear here as soon as cashier transactions are validated.</p><a href='payment' class='btn btn-sm btn-brand-primary shadow-sm'><i class='bi bi-wallet2 me-1'></i> Go to Payment Center</a></div>",
            initialSort: [
                { column: "payment_date", dir: "desc" }
            ],
            columns: [
                { 
                    title: "Date", 
                    field: "payment_date", 
                    widthGrow: 1.2, 
                    minWidth: 95, 
                    formatter: "html",
                    sorter: function(a, b) {
                        var aDate = Date.parse(stripHtml(a)) || 0;
                        var bDate = Date.parse(stripHtml(b)) || 0;
                        return aDate - bDate;
                    }
                },
                { 
                    title: "OR Number", 
                    field: "or_number", 
                    widthGrow: 2.2, 
                    minWidth: 155, 
                    formatter: "html" 
                },
                { 
                    title: "Particulars", 
                    field: "particulars", 
                    widthGrow: 2.0, 
                    minWidth: 140, 
                    formatter: "html" 
                },
                { 
                    title: "Cashier", 
                    field: "cashier", 
                    widthGrow: 1.2, 
                    minWidth: 90, 
                    formatter: "html" 
                },
                { 
                    title: "OR Status", 
                    field: "status", 
                    widthGrow: 1.2, 
                    minWidth: 95, 
                    formatter: "html" 
                },
                { 
                    title: "Amount", 
                    field: "amount", 
                    widthGrow: 1.4, 
                    minWidth: 110, 
                    hozAlign: "right", 
                    headerHozAlign: "right",
                    formatter: "html",
                    sorter: function(a, b) {
                        var aNum = parseFloat(stripHtml(a).replace(/[^0-9.-]+/g, '')) || 0;
                        var bNum = parseFloat(stripHtml(b).replace(/[^0-9.-]+/g, '')) || 0;
                        return aNum - bNum;
                    }
                },
                { 
                    title: "Receipt", 
                    field: "actions", 
                    widthGrow: 1.0, 
                    minWidth: 85, 
                    hozAlign: "center", 
                    headerHozAlign: "center",
                    headerSort: false, 
                    formatter: "html" 
                }
            ]
        });

        var searchInput = document.getElementById('myPaymentsSearch');
        if (searchInput) {
            searchInput.addEventListener('input', function () {
                var term = this.value.trim().toLowerCase();
                if (!term) {
                    myPaymentsTable.clearFilter();
                } else {
                    myPaymentsTable.setFilter(function (data) {
                        return stripHtml(data.payment_date).toLowerCase().includes(term) ||
                               stripHtml(data.or_number).toLowerCase().includes(term) ||
                               stripHtml(data.particulars).toLowerCase().includes(term) ||
                               stripHtml(data.cashier).toLowerCase().includes(term) ||
                               stripHtml(data.status).toLowerCase().includes(term) ||
                               stripHtml(data.amount).toLowerCase().includes(term);
                    });
                }
            });
        }
    }
});
</script>

<?php require_once '../includes/footer.php'; ?>
