<?php
/**
 * Record Payment Portal
 * Record a payment for a student and issue an official receipt (OR).
 */

require_once '../includes/auth_check.php';
checkRole(['cashier', 'admin']);

require_once '../config/database.php';

$hasStudentParam = isset($_GET['student_id']) && trim((string)$_GET['student_id']) !== '';
$rawStudentId = $hasStudentParam ? trim((string)$_GET['student_id']) : '';

// 1. Edge case: non-numeric student_id (e.g. "abc")
if ($hasStudentParam && !ctype_digit($rawStudentId) && !is_numeric($rawStudentId)) {
    $_SESSION['flash'] = [
        'type' => 'warning',
        'message' => 'Invalid student ID specified. Please select a student from the Enrollment Queue.'
    ];
    header('Location: enrollment_queue');
    exit;
}

// 2. Opened without a student parameter
if (!$hasStudentParam) {
    $_SESSION['flash'] = [
        'type' => 'info',
        'message' => 'Select a student from the Enrollment Queue to record a payment.'
    ];
    header('Location: enrollment_queue');
    exit;
}

$selectedStudentId = (int)$rawStudentId;
$selectedStudent = null;

// Load selected student details
if ($selectedStudentId > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT s.id, s.first_name, s.last_name, s.application_status, s.enrollment_status, s.contact_number,
                   u.username, u.email
            FROM students s
            JOIN users u ON s.user_id = u.id
            WHERE s.id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $selectedStudentId]);
        $selectedStudent = $stmt->fetch();
    } catch (\PDOException $e) {
        error_log("Fetch selected student failed: " . $e->getMessage());
    }
}

// 3. Edge case: student_id does not exist
if (!$selectedStudent) {
    $_SESSION['flash'] = [
        'type' => 'warning',
        'message' => 'Student record not found. Please select a student from the Enrollment Queue.'
    ];
    header('Location: enrollment_queue');
    exit;
}

// 4. Edge case: student exists but has no assessment yet
$hasAssessment = false;
try {
    $assessmentCheck = $pdo->prepare("SELECT id FROM assessments WHERE student_id = :sid LIMIT 1");
    $assessmentCheck->execute(['sid' => $selectedStudentId]);
    $hasAssessment = (bool)$assessmentCheck->fetch();
} catch (\PDOException $e) {
    error_log("Assessment check failed: " . $e->getMessage());
}

if (!$hasAssessment) {
    $_SESSION['flash'] = [
        'type' => 'info',
        'message' => 'This student does not have an assessment yet. Please generate an assessment first.'
    ];
    header('Location: enrollment_queue');
    exit;
}

$paymentMethods = [];
try {
    $paymentMethods = $pdo->query("SELECT method_code, method_name, requires_reference, description FROM payment_methods WHERE is_active = 1 ORDER BY sort_order, method_name")->fetchAll();
} catch (\PDOException $e) {
    error_log('Payment methods fetch failed: ' . $e->getMessage());
    $paymentMethods = [
        ['method_code' => 'cash', 'method_name' => 'Cash', 'requires_reference' => 0, 'description' => 'Cash payment'],
        ['method_code' => 'bank_transfer', 'method_name' => 'Bank Transfer', 'requires_reference' => 1, 'description' => 'Online or bank transfer'],
        ['method_code' => 'check', 'method_name' => 'Check', 'requires_reference' => 1, 'description' => 'Check payment'],
    ];
}

$page_title = "Record Payment";
require_once '../includes/header.php';

if (!function_exists('statusBadgeClass')) {
    function statusBadgeClass(string $status): string {
        switch ($status) {
            case 'paid':
            case 'enrolled':
                return 'bg-success-subtle text-success';
            case 'approved':
                return 'bg-warning-subtle text-warning';
            case 'pending':
                return 'bg-secondary-subtle text-muted';
            case 'rejected':
                return 'bg-danger-subtle text-danger';
            default:
                return 'bg-light text-dark';
        }
    }
}
?>

<div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <div class="d-flex align-items-center gap-2 mb-2">
            <a href="enrollment_queue" class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-1">
                <i class="bi bi-arrow-left"></i> Back to Enrollment Queue
            </a>
        </div>
        <h3 class="m-0 text-navy-alt">Record Payment</h3>
        <p class="text-muted small m-0">Record a payment and issue an official receipt (OR) for this student.</p>
    </div>
    <a href="payment_history" class="btn btn-outline-secondary btn-sm d-flex align-items-center gap-1">
        <i class="bi bi-clock-history"></i> Payment History
    </a>
</div>

<div class="row justify-content-center">
    <!-- Payment Form Panel -->
    <div class="col-12 col-xl-10">
        <div class="card card-premium shadow-sm border-start border-4" style="border-color: var(--brand-primary) !important;">
            <div class="card-header card-header-premium d-flex justify-content-between align-items-center">
                <h5 class="card-title m-0 fw-semibold text-navy-alt">
                    <i class="bi bi-credit-card me-1"></i> Payment Details
                </h5>
                <span class="badge bg-light text-navy border px-2 py-1">
                    ID #<?php echo (int)$selectedStudent['id']; ?>
                </span>
            </div>
            <div class="card-body card-body-premium">
                <!-- Selected Student Summary -->
                <div class="rounded-3 p-3 mb-4" style="background: var(--surface-tint);">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                        <div>
                            <div class="text-muted small text-uppercase fw-bold mb-1" style="font-size: 0.7rem; letter-spacing: 0.5px;">Payor</div>
                            <h5 class="m-0 fw-bold text-darker">
                                <?php echo htmlspecialchars($selectedStudent['first_name'] . ' ' . $selectedStudent['last_name']); ?>
                            </h5>
                            <div class="text-muted small mt-1">
                                <?php echo htmlspecialchars($selectedStudent['email']); ?>
                                <?php if ($selectedStudent['contact_number']): ?>
                                    &middot; <?php echo htmlspecialchars($selectedStudent['contact_number']); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <span class="badge <?php echo statusBadgeClass($selectedStudent['enrollment_status']); ?> border px-2.5 py-1 text-uppercase fw-semibold" style="font-size: 0.7rem;">
                            <?php echo htmlspecialchars($selectedStudent['enrollment_status']); ?>
                        </span>
                    </div>
                    <div class="mt-3">
                        <a href="assessment?student_id=<?php echo (int)$selectedStudent['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-calculator me-1"></i>View or Generate Assessment</a>
                    </div>
                    <?php if ($selectedStudent['application_status'] === 'approved' && $selectedStudent['enrollment_status'] === 'approved'): ?>
                        <div class="alert alert-info border-0 small py-2 px-3 mt-3 mb-0 d-flex align-items-center gap-2">
                            <i class="bi bi-info-circle-fill"></i>
                            Recording payment will update this student's status to <strong>Paid</strong>.
                        </div>
                    <?php endif; ?>
                    <?php
                    $pendingPaymentForStudent = null;
                    if (!empty($selectedStudent['id'])) {
                        $ppStmt = $pdo->prepare("SELECT id, amount, payment_method, payment_reference, or_number, created_at FROM payments WHERE student_id = :student_id AND or_status = 'pending' LIMIT 1");
                        $ppStmt->execute(['student_id' => (int)$selectedStudent['id']]);
                        $pendingPaymentForStudent = $ppStmt->fetch(PDO::FETCH_ASSOC);
                    }
                    ?>
                    <?php if ($pendingPaymentForStudent): ?>
                        <div class="alert alert-warning border border-warning-subtle d-flex align-items-center justify-content-between p-3 mt-3 mb-0 shadow-sm" style="border-radius:10px;">
                            <div>
                                <div class="fw-bold text-dark"><i class="bi bi-exclamation-triangle-fill me-1 text-warning"></i> Pending Online Payment Awaiting Review</div>
                                <div class="small text-muted mt-1">This student has a pending online payment of <strong>₱<?php echo number_format((float)$pendingPaymentForStudent['amount'], 2); ?></strong> (Ref: <code><?php echo htmlspecialchars($pendingPaymentForStudent['payment_reference'] ?? 'N/A'); ?></code>) waiting for review.</div>
                            </div>
                            <a href="enrollment_queue?filter_pending=1" class="btn btn-sm btn-warning fw-semibold text-nowrap ms-2">Review in Queue</a>
                        </div>
                    <?php endif; ?>
                </div>

                <form action="../actions/payment_actions" method="POST" class="needs-validation" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                    <input type="hidden" name="action" value="create">
                    <input type="hidden" name="student_id" value="<?php echo (int)$selectedStudent['id']; ?>">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="amount" class="form-label fw-medium">Amount (₱) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">₱</span>
                                <?php
                                    $envMax = getenv('PAYMENT_MAX');
                                    $paymentMaxAttr = is_numeric($envMax) ? ' max="' . number_format((float)$envMax, 2, '.', '') . '"' : '';
                                ?>
                                <input type="number" name="amount" id="amount" class="form-control"
                                       min="0.01" step="0.01" placeholder="0.00" required<?php echo $paymentMaxAttr; ?>>
                                <div class="invalid-feedback">Please enter a valid payment amount.</div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label for="or_number" class="form-label fw-medium">Official Receipt (OR) No. <span class="text-danger">*</span></label>
                            <input type="text" name="or_number" id="or_number" class="form-control text-uppercase"
                                   placeholder="e.g. OR-2025-0002" maxlength="50" required autocomplete="off">
                            <div class="invalid-feedback">OR number is required and must be unique.</div>
                        </div>

                        <div class="col-md-6">
                            <label for="payment_method" class="form-label fw-medium">Payment Method <span class="text-danger">*</span></label>
                            <select name="payment_method" id="payment_method" class="form-select" required>
                                <option value="">Select method</option>
                                <?php foreach ($paymentMethods as $method): ?>
                                    <option value="<?php echo htmlspecialchars($method['method_code']); ?>"><?php echo htmlspecialchars($method['method_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label for="payment_reference" class="form-label fw-medium">Reference / Transaction Code</label>
                            <input type="text" name="payment_reference" id="payment_reference" class="form-control text-uppercase"
                                   placeholder="e.g. GCASH-12345, REF-2025-001" maxlength="100" autocomplete="off">
                        </div>

                        <div class="col-md-6">
                            <label for="bank_name" class="form-label fw-medium">Bank / E-wallet</label>
                            <input type="text" name="bank_name" id="bank_name" class="form-control"
                                   placeholder="BDO, BPI, GCash, Maya" maxlength="100" autocomplete="off">
                        </div>

                        <div class="col-md-6">
                            <label for="check_number" class="form-label fw-medium">Check Number</label>
                            <input type="text" name="check_number" id="check_number" class="form-control text-uppercase"
                                   placeholder="If payment by check" maxlength="50" autocomplete="off">
                        </div>

                        <div class="col-12">
                            <label for="notes" class="form-label fw-medium">Notes <span class="text-muted fw-normal">(optional)</span></label>
                            <input type="text" name="notes" id="notes" class="form-control"
                                   placeholder="e.g. Tuition down payment, miscellaneous fees..." maxlength="255">
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4 pt-2 border-top">
                        <button type="submit" class="btn btn-brand-primary px-4 fw-semibold">
                            <i class="bi bi-check-circle me-1"></i> Record Payment
                        </button>
                        <a href="enrollment_queue" class="btn btn-outline-secondary px-3">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
require_once '../includes/footer.php';
?>
