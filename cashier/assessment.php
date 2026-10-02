<?php
require_once '../includes/auth_check.php';
checkRole(['cashier', 'admin', 'student']);
require_once '../config/database.php';
require_once '../includes/assessments.php';

$assessmentId = (int)($_GET['id'] ?? 0);
$studentId = (int)($_GET['student_id'] ?? 0);
$assessment = null;
$items = [];
$totals = null;
$error = null;
$isStudentViewer = ($_SESSION['role'] ?? '') === 'student';
try {
    if (!$assessmentId && $studentId > 0) {
        $studentStmt = $pdo->prepare('SELECT academic_term_id FROM students WHERE id = :id LIMIT 1');
        $studentStmt->execute(['id' => $studentId]);
        $studentTerm = $studentStmt->fetch();
        if ($studentTerm && $studentTerm['academic_term_id']) {
            $pdo->beginTransaction();
            $assessmentId = getOrCreateAssessment($pdo, $studentId, (int)$studentTerm['academic_term_id']);
            $pdo->commit();
        }
    }
    $stmt = $pdo->prepare(
        "SELECT a.id, a.reference_number, a.total_amount, a.discount_amount, a.status, a.generated_at, s.id AS student_id,
                s.first_name, s.last_name, s.program_applying_for, s.year_level,
                t.school_year, t.semester, t.payment_requirement_percent
         FROM assessments a
         JOIN students s ON s.id = a.student_id
         JOIN academic_terms t ON t.id = a.academic_term_id
                 WHERE a.id = :id
                     AND (:is_student_viewer = 0 OR s.user_id = :viewer_id)
                 LIMIT 1"
    );
    $stmt->execute(['id' => $assessmentId, 'is_student_viewer' => $isStudentViewer ? 1 : 0, 'viewer_id' => (int)$_SESSION['user_id']]);
    $assessment = $stmt->fetch();
    if ($assessment) {
        $itemStmt = $pdo->prepare('SELECT description, quantity, unit_amount, amount FROM assessment_items WHERE assessment_id = :id ORDER BY id');
        $itemStmt->execute(['id' => $assessmentId]);
        $items = $itemStmt->fetchAll();
        $totals = getAssessmentTotals($pdo, $assessmentId);
    }
} catch (Throwable $e) {
    error_log('Assessment view failed: ' . $e->getMessage());
    $error = 'Assessment information is temporarily unavailable.';
}

if ($assessment && isset($_GET['download']) && $_GET['download'] === '1') {
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="assessment-slip-' . (int)$assessment['id'] . '.html"');
}

$page_title = 'Student Assessment';
require_once '../includes/header.php';
?>
<div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div><h3 class="m-0 text-navy-alt">Student Assessment</h3><p class="text-muted small m-0">Itemized fees, scholarships or discounts, validated payments, and remaining balance.</p></div>
    <div class="d-flex gap-2">
        <a href="<?php echo !empty($assessment['student_id']) ? 'payments?student_id=' . (int)$assessment['student_id'] : 'enrollment_queue'; ?>" class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-1">
            <i class="bi bi-arrow-left"></i> <?php echo !empty($assessment['student_id']) ? 'Back to Payment' : 'Enrollment Queue'; ?>
        </a>
        <?php if (!$isStudentViewer && !empty($assessment['student_id'])): ?>
            <a href="payments?student_id=<?php echo (int)$assessment['student_id']; ?>" class="btn btn-brand-primary btn-sm d-inline-flex align-items-center gap-1">
                <i class="bi bi-credit-card me-1"></i> Record Payment
            </a>
        <?php endif; ?>
    </div>
</div>
<?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php elseif (!$assessment): ?><div class="alert alert-warning">Assessment not found.</div>
<?php else: ?>
<div class="row g-4">
    <div class="col-12 col-xl-8"><div class="card card-premium shadow-sm"><div class="card-header card-header-premium"><div class="d-flex justify-content-between align-items-center flex-wrap gap-2"><div><h5 class="m-0 fw-semibold text-navy-alt"><?php echo htmlspecialchars($assessment['first_name'] . ' ' . $assessment['last_name']); ?></h5><small class="text-muted"><?php echo htmlspecialchars($assessment['school_year'] . ' / ' . ucfirst($assessment['semester']) . ' Semester'); ?></small></div><div class="text-end"><div class="small text-muted">Reference</div><div class="fw-bold text-navy-alt"><?php echo htmlspecialchars($assessment['reference_number'] ?? 'Pending'); ?></div></div></div></div><div class="card-body card-body-premium p-0"><div class="table-responsive"><table class="table align-middle mb-0"><thead class="table-light"><tr><th class="ps-4">Fee Item</th><th>Quantity</th><th>Unit Amount</th><th class="pe-4 text-end">Amount</th></tr></thead><tbody><?php foreach ($items as $item): ?><tr><td class="ps-4"><?php echo htmlspecialchars($item['description']); ?></td><td><?php echo number_format((float)$item['quantity'], 2); ?></td><td>₱<?php echo number_format((float)$item['unit_amount'], 2); ?></td><td class="pe-4 text-end fw-semibold">₱<?php echo number_format((float)$item['amount'], 2); ?></td></tr><?php endforeach; ?></tbody></table></div></div></div></div>
    <div class="col-12 col-xl-4"><div class="card card-premium shadow-sm"><div class="card-body card-body-premium"><div class="text-muted small">Subtotal</div><div class="fs-4 fw-bold text-navy-alt mb-2">₱<?php echo number_format((float)$assessment['total_amount'] + (float)$totals['validated_paid'], 2); ?></div><div class="text-muted small">Discount Applied</div><div class="fs-4 fw-bold text-info mb-2">-₱<?php echo number_format((float)$assessment['discount_amount'], 2); ?></div><div class="text-muted small">Net Assessed</div><div class="fs-3 fw-bold text-navy-alt mb-3">₱<?php echo number_format((float)$assessment['total_amount'], 2); ?></div><div class="text-muted small">Validated Paid</div><div class="fs-4 fw-bold text-success mb-3">₱<?php echo number_format((float)$totals['validated_paid'], 2); ?></div><div class="text-muted small">Balance</div><div class="fs-4 fw-bold text-danger mb-3">₱<?php echo number_format((float)$totals['balance'], 2); ?></div><div class="border-top pt-3 small text-muted">Required before student activation: <?php echo number_format((float)$assessment['payment_requirement_percent'], 2); ?>% (₱<?php echo number_format((float)$assessment['total_amount'] * ((float)$assessment['payment_requirement_percent'] / 100), 2); ?>)</div></div></div></div>
</div>
<?php endif; ?>
<?php require_once '../includes/footer.php'; ?>
