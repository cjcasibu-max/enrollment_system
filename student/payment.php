<?php
require_once '../includes/auth_check.php';
checkRole(['student']);

require_once '../config/database.php';
require_once '../includes/academic_terms.php';
require_once '../includes/assessments.php';

$userId = (int)$_SESSION['user_id'];
$student = null;
$assessment = null;
$assessmentItems = [];
$paymentMethods = getAllowedPaymentMethods();
// Online payment only: keep only the three online methods in a fixed order.
// Cash, Credit Card, and Check are handled elsewhere (Cashier) and must not appear here.
$onlineMethodCodes = ['bank_transfer', 'gcash', 'maya'];
$filteredMethods = [];
foreach ($onlineMethodCodes as $_code) {
    $filteredMethods[$_code] = $paymentMethods[$_code]
        ?? (['bank_transfer' => 'Bank Transfer', 'gcash' => 'GCash', 'maya' => 'Maya'][$_code]);
}
$paymentMethods = $filteredMethods;
unset($filteredMethods, $onlineMethodCodes, $_code);
$error = null;

try {
    $studentStmt = $pdo->prepare("SELECT * FROM students WHERE user_id = :user_id LIMIT 1");
    $studentStmt->execute(['user_id' => $userId]);
    $student = $studentStmt->fetch();

    if ($student) {
        $_SESSION['student_id'] = (int)$student['id'];
        $termId = (int)($student['academic_term_id'] ?? 0);
        if ($termId <= 0) {
            $activeTerm = getActiveAcademicTerm($pdo);
            $termId = $activeTerm ? (int)$activeTerm['id'] : 0;
        }

        if ($termId > 0) {
            $assessmentId = getOrCreateAssessment($pdo, (int)$student['id'], $termId);
            $assessment = getAssessmentTotals($pdo, $assessmentId);
            $assessment['id'] = $assessmentId;
            $itemStmt = $pdo->prepare('SELECT description, quantity, unit_amount, amount FROM assessment_items WHERE assessment_id = :id ORDER BY id');
            $itemStmt->execute(['id' => $assessmentId]);
            $assessmentItems = $itemStmt->fetchAll();
        }
    }
} catch (Throwable $e) {
    error_log('Student payment page failed: ' . $e->getMessage());
    $error = 'Payment information is temporarily unavailable.';
}

$programLabel = !empty($student['program_applying_for']) ? $student['program_applying_for'] : (!empty($student['program_code']) ? $student['program_code'] : 'BSMT');
$rawYearLevel = trim((string)($student['year_level'] ?? ''));
$yearLevel = ($rawYearLevel !== '' && strtolower($rawYearLevel) !== 'year level') ? $rawYearLevel : '';
$grossAssessment = (float)($assessment['total_amount'] ?? 0) + (float)($assessment['discount_amount'] ?? 0);
$minimumDownpayment = 0.0;
if (!empty($student['academic_term_id'])) {
    $termStmt = $pdo->prepare('SELECT downpayment_percentage, minimum_downpayment FROM academic_terms WHERE id = :id LIMIT 1');
    $termStmt->execute(['id' => (int)$student['academic_term_id']]);
    $term = $termStmt->fetch();
    if ($term) {
        $minimumDownpayment = max((float)($term['minimum_downpayment'] ?? 0.00), ((float)($term['downpayment_percentage'] ?? 30.00) / 100) * (float)($assessment['total_amount'] ?? 0.00));
    }
}

$latestPayment = null;
if ($student) {
    $payStmt = $pdo->prepare("
        SELECT id, amount, payment_method, payment_reference, or_status, validation_notes, proof_file, created_at
        FROM payments
        WHERE student_id = :student_id
        ORDER BY id DESC
        LIMIT 1
    ");
    $payStmt->execute(['student_id' => (int)$student['id']]);
    $latestPayment = $payStmt->fetch(PDO::FETCH_ASSOC);
}
$isPaymentPending = ($latestPayment && ($latestPayment['or_status'] ?? '') === 'pending');
$isPaymentRejected = ($latestPayment && ($latestPayment['or_status'] ?? '') === 'rejected');

$validatedPaid = (float)($assessment['validated_paid'] ?? 0);
$remainingDownpayment = max(0, round($minimumDownpayment - $validatedPaid, 2));

$page_title = 'Payment Center';
require_once '../includes/header.php';
?>

<div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h3 class="m-0 text-navy-alt">Payment Center</h3>
        <p class="text-muted small m-0">Review your current assessment, remaining balance, and payment method options.</p>
    </div>
    <a href="dashboard" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i> Back to Dashboard
    </a>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php elseif (!$student): ?>
    <div class="alert alert-warning">Your student profile was not found. Please contact the registrar.</div>
<?php else: ?>
    <?php if ($isPaymentPending): ?>
        <div class="alert alert-warning border border-warning-subtle shadow-sm p-3 mb-4 rounded-3 d-flex align-items-start gap-3" style="background:#fffbeb;">
            <div class="rounded-circle bg-warning text-dark p-2 d-flex align-items-center justify-content-center flex-shrink-0" style="width:42px;height:42px;">
                <i class="bi bi-hourglass-split fs-4"></i>
            </div>
            <div class="flex-fill">
                <h5 class="fw-bold text-dark mb-1">Online Payment Submitted &mdash; Under Cashier Review</h5>
                <p class="small text-muted mb-2">
                    Your online payment submission of <strong>₱<?php echo number_format((float)$latestPayment['amount'], 2); ?></strong> 
                    (Method: <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $latestPayment['payment_method'] ?? ''))); ?>, 
                    Ref: <code><?php echo htmlspecialchars($latestPayment['payment_reference'] ?: 'None'); ?></code>) 
                    submitted on <strong><?php echo date('M j, Y g:i A', strtotime($latestPayment['created_at'])); ?></strong> is currently awaiting validation by the Cashier's Office.
                </p>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-warning text-dark px-2.5 py-1.5 fw-bold"><i class="bi bi-clock me-1"></i> Under Review</span>
                    <?php if (!empty($latestPayment['proof_file'])): ?>
                        <a href="../actions/view_payment_proof?id=<?php echo (int)$latestPayment['id']; ?>" target="_blank" class="btn btn-outline-secondary btn-sm py-0 px-2" style="font-size:0.75rem;">
                            <i class="bi bi-file-earmark-image me-1"></i> View Submitted Proof
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php elseif ($isPaymentRejected): ?>
        <div class="alert alert-danger border border-danger-subtle shadow-sm p-3 mb-4 rounded-3 d-flex align-items-start gap-3" style="background:#fef2f2;">
            <div class="rounded-circle bg-danger text-white p-2 d-flex align-items-center justify-content-center flex-shrink-0" style="width:42px;height:42px;">
                <i class="bi bi-x-circle-fill fs-4"></i>
            </div>
            <div class="flex-fill">
                <h5 class="fw-bold text-danger mb-1">Payment Submission Rejected</h5>
                <p class="small text-dark mb-2">
                    Your previous payment submission of <strong>₱<?php echo number_format((float)$latestPayment['amount'], 2); ?></strong> was reviewed and rejected by the Cashier.
                </p>
                <div class="p-2.5 rounded bg-white text-danger fw-semibold small border border-danger-subtle mb-2">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i> Cashier Reason: <?php echo htmlspecialchars($latestPayment['validation_notes'] ?: 'No reason specified.'); ?>
                </div>
                <p class="small text-muted mb-0">Please review the reason above and resubmit your payment below with the corrected details and receipt proof.</p>
            </div>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-12 col-lg-8">
            <div class="card card-premium shadow-sm">
                <div class="card-header card-header-premium">
                    <h5 class="m-0 fw-semibold text-navy-alt"><i class="bi bi-calculator me-1"></i> Assessment Breakdown</h5>
                </div>
                <div class="card-body card-body-premium p-0">
                    <?php if ($assessment): ?>

                    <?php
                        $abPaid    = (float)($assessment['validated_paid'] ?? 0);
                        $abTotal   = (float)($assessment['total_amount'] ?? 0);
                        $abPct     = ($abTotal > 0) ? min(100, round($abPaid / $abTotal * 100)) : 0;
                        $abIsFull  = $abPaid >= $abTotal && $abTotal > 0;
                        $abStatus  = getAssessmentPaymentStatus($abPaid, $abTotal);
                        $abBadge   = $abIsFull ? 'success' : (($abPaid > 0) ? 'warning' : 'secondary');
                    ?>

                    <!-- Program Header -->
                    <div class="d-flex align-items-center gap-2 px-4 py-3" style="background:var(--surface-tint);border-bottom:1px solid var(--gray-200);">
                        <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width:34px;height:34px;background:var(--brand-primary-soft);color:var(--brand-primary);">
                            <i class="bi bi-mortarboard-fill" style="font-size:0.85rem;"></i>
                        </div>
                        <div>
                            <div class="fw-bold text-navy-alt" style="font-size:0.9rem;line-height:1.25;"><?php echo htmlspecialchars($programLabel); ?></div>
                            <?php if ($yearLevel !== ''): ?>
                            <div class="text-muted" style="font-size:0.73rem;"><?php echo htmlspecialchars($yearLevel); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Fee Components Section -->
                    <div class="px-4 pt-3 pb-1">
                        <div class="text-uppercase fw-bold" style="font-size:0.65rem;letter-spacing:.07em;color:var(--text-muted);margin-bottom:.5rem;">Fee Components</div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-borderless align-middle mb-0" style="font-size:0.87rem;">
                            <tbody>
                                <?php foreach ($assessmentItems as $idx => $item): ?>
                                <tr style="<?php echo ($idx % 2 === 0) ? 'background:#f8fafc;' : ''; ?>">
                                    <td class="ps-4 py-2 text-slate"><?php echo htmlspecialchars($item['description']); ?></td>
                                    <td class="pe-4 py-2 text-end fw-medium text-dark" style="font-variant-numeric:tabular-nums;">&#8369;<?php echo number_format((float)$item['amount'], 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Summary Section -->
                    <div style="border-top:2px solid var(--gray-200);">
                        <div class="px-4 pt-3 pb-1">
                            <div class="text-uppercase fw-bold" style="font-size:0.65rem;letter-spacing:.07em;color:var(--text-muted);margin-bottom:.5rem;">Summary</div>
                        </div>
                        <table class="table table-borderless align-middle mb-0" style="font-size:0.87rem;">
                            <tbody>
                                <tr>
                                    <td class="ps-4 py-2 fw-semibold text-darker">Gross Assessment</td>
                                    <td class="pe-4 py-2 text-end fw-semibold" style="font-variant-numeric:tabular-nums;">&#8369;<?php echo number_format($grossAssessment, 2); ?></td>
                                </tr>
                                <tr>
                                    <td class="ps-4 py-2 text-muted">Discount</td>
                                    <td class="pe-4 py-2 text-end fw-semibold text-success" style="font-variant-numeric:tabular-nums;">-&#8369;<?php echo number_format((float)($assessment['discount_amount'] ?? 0), 2); ?></td>
                                </tr>
                                <!-- Net Assessment highlighted row -->
                                <tr style="background:var(--surface-tint);border-top:1px solid var(--gray-200);border-bottom:1px solid var(--gray-200);">
                                    <td class="ps-4 py-2">
                                        <div class="fw-bold text-navy-alt" style="font-size:0.95rem;">Net Assessment</div>
                                    </td>
                                    <td class="pe-4 py-2 text-end">
                                        <span class="fw-bold text-navy-alt" style="font-size:1.05rem;font-variant-numeric:tabular-nums;">&#8369;<?php echo number_format((float)($assessment['total_amount'] ?? 0), 2); ?></span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Payment Progress Section -->
                    <div style="border-top:2px solid var(--gray-200);">
                        <div class="px-4 pt-3 pb-1">
                            <div class="text-uppercase fw-bold" style="font-size:0.65rem;letter-spacing:.07em;color:var(--text-muted);margin-bottom:.5rem;">Payment Progress</div>
                        </div>
                        <?php if ($abTotal > 0): ?>
                        <div class="px-4 pb-2">
                            <div class="d-flex justify-content-between align-items-center mb-1" style="font-size:0.73rem;color:var(--text-muted);">
                                <span>&#8369;<?php echo number_format($abPaid, 2); ?> paid</span>
                                <span><?php echo $abPct; ?>% of &#8369;<?php echo number_format($abTotal, 2); ?></span>
                            </div>
                            <div style="height:6px;background:var(--gray-200);border-radius:999px;overflow:hidden;">
                                <div style="height:100%;width:<?php echo $abPct; ?>%;background:<?php echo $abIsFull ? '#22c55e' : ($abPaid > 0 ? '#f59e0b' : 'var(--gray-300)'); ?>;border-radius:999px;transition:width .4s;"></div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <table class="table table-borderless align-middle mb-0" style="font-size:0.87rem;">
                            <tbody>
                                <tr>
                                    <td class="ps-4 py-2 text-muted">Minimum Downpayment</td>
                                    <td class="pe-4 py-2 text-end fw-semibold" style="color:var(--brand-primary);font-variant-numeric:tabular-nums;">&#8369;<?php echo number_format($minimumDownpayment, 2); ?></td>
                                </tr>
                                <tr>
                                    <td class="ps-4 py-2 text-muted">Total Paid</td>
                                    <td class="pe-4 py-2 text-end fw-semibold text-success" style="font-variant-numeric:tabular-nums;">&#8369;<?php echo number_format($abPaid, 2); ?></td>
                                </tr>
                                <!-- Remaining Balance — most prominent row -->
                                <tr style="background:<?php echo ((float)($assessment['balance'] ?? 0) > 0) ? 'rgba(239,68,68,.05)' : 'rgba(34,197,94,.05)'; ?>;border-top:1px solid var(--gray-200);">
                                    <td class="ps-4 py-2">
                                        <div class="fw-bold" style="font-size:0.9rem;color:<?php echo ((float)($assessment['balance'] ?? 0) > 0) ? 'var(--color-danger)' : 'var(--color-success)'; ?>">Remaining Balance</div>
                                    </td>
                                    <td class="pe-4 py-2 text-end">
                                        <span class="fw-bold" style="font-size:1rem;font-variant-numeric:tabular-nums;color:<?php echo ((float)($assessment['balance'] ?? 0) > 0) ? 'var(--color-danger)' : 'var(--color-success)'; ?>">&#8369;<?php echo number_format((float)($assessment['balance'] ?? 0), 2); ?></span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <!-- Payment Status Badge -->
                        <div class="px-4 pt-2 pb-3 d-flex align-items-center gap-2">
                            <span class="badge bg-<?php echo $abBadge; ?>-subtle text-<?php echo $abBadge; ?> border border-<?php echo $abBadge; ?>-subtle px-2 py-1" style="font-size:0.72rem;font-weight:700;letter-spacing:.04em;">
                                <?php echo htmlspecialchars($abStatus); ?>
                            </span>
                            <?php if (!$abIsFull && $abTotal > 0): ?>
                            <span class="text-muted" style="font-size:0.72rem;">&#8369;<?php echo number_format((float)($assessment['balance'] ?? 0), 2); ?> remaining</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php else: ?>
                        <div class="p-4 text-muted">No assessment summary is available yet.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-4">
            <div class="card card-premium shadow-sm">
                <div class="card-header card-header-premium">
                    <h5 class="m-0 fw-semibold text-navy-alt"><i class="bi bi-wallet2 me-1"></i> <?php echo $isPaymentPending ? 'Payment Status' : 'Payment Input'; ?></h5>
                </div>
                <div class="card-body card-body-premium">
                    <?php if ($isPaymentPending): ?>
                        <div class="text-center p-3">
                            <div class="rounded-circle bg-warning-subtle text-warning p-3 mx-auto mb-3 d-inline-flex">
                                <i class="bi bi-hourglass-split fs-2"></i>
                            </div>
                            <h6 class="fw-bold text-navy-alt mb-1">Payment Under Review</h6>
                            <p class="small text-muted mb-3">
                                You already have an online payment of <strong>₱<?php echo number_format((float)$latestPayment['amount'], 2); ?></strong> awaiting validation by the Cashier.
                            </p>
                            <div class="alert alert-light border small text-muted text-start mb-0">
                                <i class="bi bi-info-circle me-1 text-primary"></i> New submissions are paused while your transaction is under review. Your status will update once validated.
                            </div>
                        </div>
                    <?php else: ?>
                        <form action="../actions/payment_actions" method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                            <input type="hidden" name="action" value="record_student_payment">
                            
                            <div class="mb-3">
                                <label class="form-label fw-medium">Amount to Pay <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text">₱</span>
                                    <input type="number" name="amount" class="form-control" 
                                           min="<?php echo $remainingDownpayment > 0 ? $remainingDownpayment : '0.01'; ?>" 
                                           max="<?php echo (float)($assessment['balance'] ?? 0); ?>" 
                                           step="0.01" 
                                           value="<?php echo $remainingDownpayment > 0 ? number_format($remainingDownpayment, 2, '.', '') : ''; ?>" 
                                           required>
                                </div>
                                <?php if ($remainingDownpayment > 0): ?>
                                    <div class="form-text text-primary small fw-semibold">
                                        <i class="bi bi-info-circle me-1"></i> Minimum downpayment required: ₱<?php echo number_format($remainingDownpayment, 2); ?>
                                    </div>
                                <?php else: ?>
                                    <div class="form-text text-success small fw-semibold">
                                        <i class="bi bi-check-circle me-1"></i> Max payable balance: ₱<?php echo number_format((float)($assessment['balance'] ?? 0), 2); ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-medium">Payment Method <span class="text-danger">*</span></label>
                                <select name="payment_method" class="form-select" required>
                                    <option value="">Select method</option>
                                    <?php foreach ($paymentMethods as $code => $label): ?>
                                        <option value="<?php echo htmlspecialchars($code); ?>"><?php echo htmlspecialchars($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-medium">Reference Code / Transaction ID <span class="text-danger">*</span></label>
                                <input type="text" name="payment_reference" class="form-control" maxlength="100" placeholder="e.g. GCash Ref No. / Bank Ref" required>
                                <div class="form-text" style="font-size:0.75rem;">Required to match online transaction.</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-medium">Proof of Payment <span class="text-danger">*</span></label>
                                <input type="file" name="proof_file" class="form-control" accept="image/jpeg,image/png,application/pdf" required>
                                <div class="form-text" style="font-size:0.75rem;">Upload screenshot or deposit slip (JPG, PNG, PDF up to 5MB).</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-medium">Notes <span class="text-muted fw-normal">(Optional)</span></label>
                                <input type="text" name="notes" class="form-control" maxlength="255" placeholder="Optional notes for cashier">
                            </div>

                            <button type="submit" class="btn btn-brand-primary w-100 fw-semibold">
                                <i class="bi bi-send me-1"></i> Submit Payment for Review
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
