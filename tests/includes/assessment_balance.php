<?php
require_once __DIR__ . '/../../includes/assessments.php';

$balanceCases = [
    'full assessment before downpayment' => [1000.00, 0.00, 1000.00],
    'balance after downpayment' => [1000.00, 300.00, 700.00],
    'balance after full payment' => [1000.00, 1000.00, 0.00],
    'balance after prior partial payment' => [1000.00, 450.00, 550.00],
];

$paymentStatusCases = [
    'downpayment remains partially paid' => [300.00, 1000.00, 'PARTIALLY PAID'],
    'full assessment payment is fully paid' => [1000.00, 1000.00, 'FULLY PAID'],
    'payment greater than assessment remains fully paid' => [1050.00, 1000.00, 'FULLY PAID'],
];

foreach ($paymentStatusCases as $name => [$paidAmount, $assessmentTotal, $expectedStatus]) {
    $actualStatus = getAssessmentPaymentStatus($paidAmount, $assessmentTotal);
    if ($actualStatus !== $expectedStatus) {
        throw new RuntimeException("Payment status failed for {$name}: expected {$expectedStatus}, got {$actualStatus}.");
    }
}

foreach ($balanceCases as $name => [$assessmentTotal, $validatedPaid, $expectedBalance]) {
    $actualBalance = calculateAssessmentBalance($assessmentTotal, $validatedPaid);
    if ($actualBalance !== $expectedBalance) {
        throw new RuntimeException("Assessment balance failed for {$name}: expected {$expectedBalance}, got {$actualBalance}.");
    }
}

$paymentCases = [
    'downpayment against full assessment' => [300.00, 1000.00, 0.00, false],
    'full payment against full assessment' => [1000.00, 1000.00, 0.00, false],
    'payment against remaining partial balance' => [500.00, 1000.00, 300.00, false],
    'payment one cent over remaining balance' => [700.01, 1000.00, 300.00, true],
];

foreach ($paymentCases as $name => [$paymentAmount, $assessmentTotal, $validatedPaid, $expectedExcess]) {
    $balance = calculateAssessmentBalance($assessmentTotal, $validatedPaid);
    $actualExcess = paymentExceedsAssessmentBalance($paymentAmount, $balance);
    if ($actualExcess !== $expectedExcess) {
        throw new RuntimeException("Cashier payment validation failed for {$name}.");
    }
}

$downpaymentCases = [
    'percentage requirement' => [1000.00, 100.00, 30.00, 300.00],
    'minimum amount requirement' => [1000.00, 350.00, 30.00, 350.00],
    'full payment satisfies minimum above total' => [1000.00, 1500.00, 30.00, 1000.00],
];

foreach ($downpaymentCases as $name => [$assessmentTotal, $minimum, $percentage, $expectedDownpayment]) {
    $actualDownpayment = calculateRequiredDownpayment($assessmentTotal, $minimum, $percentage);
    if ($actualDownpayment !== $expectedDownpayment) {
        throw new RuntimeException("Downpayment threshold failed for {$name}: expected {$expectedDownpayment}, got {$actualDownpayment}.");
    }
}

$eligibilityCases = [
    'qualifying downpayment' => [300.00, 1000.00, 100.00, 30.00, true],
    'amount below downpayment' => [299.99, 1000.00, 100.00, 30.00, false],
    'full payment with minimum above assessment' => [1000.00, 1000.00, 1500.00, 30.00, true],
];

foreach ($eligibilityCases as $name => [$paid, $assessmentTotal, $minimum, $percentage, $expected]) {
    if (hasMetRequiredDownpayment($paid, $assessmentTotal, $minimum, $percentage) !== $expected) {
        throw new RuntimeException("Downpayment eligibility failed for {$name}.");
    }
}

$applicationStatusCases = [
    'live registrar-approved status' => ['eligible_to_enroll', true],
    'legacy approved status' => ['approved', true],
    'unreviewed application' => ['pending', false],
    'revision-required application' => ['needs_revision', false],
];

foreach ($applicationStatusCases as $name => [$applicationStatus, $expected]) {
    if (isPaymentActivationEligibleApplicationStatus($applicationStatus) !== $expected) {
        throw new RuntimeException("Payment activation application status failed for {$name}.");
    }
}

$accountActivationCases = [
    'registrar-eligible applicant with validated downpayment' => ['eligible_to_enroll', 'walk_in_ready', 300.00, 1000.00, 100.00, 30.00, true],
    'legacy approved applicant with full payment' => ['approved', 'approved', 1000.00, 1000.00, 100.00, 30.00, true],
    'eligible applicant below downpayment' => ['eligible_to_enroll', 'walk_in_ready', 299.99, 1000.00, 100.00, 30.00, false],
    'unreviewed applicant with payment' => ['pending', 'walk_in_ready', 1000.00, 1000.00, 100.00, 30.00, false],
    'paid applicant without confirmed enrollment state' => ['eligible_to_enroll', 'pending', 300.00, 1000.00, 100.00, 30.00, false],
];

foreach ($accountActivationCases as $name => [$applicationStatus, $enrollmentStatus, $validatedPaid, $assessmentTotal, $minimum, $percentage, $expected]) {
    $actual = canActivateStudentAccount($applicationStatus, $enrollmentStatus, $validatedPaid, $assessmentTotal, $minimum, $percentage);
    if ($actual !== $expected) {
        throw new RuntimeException("Student activation gate failed for {$name}.");
    }
}

$recoveryCases = [
    'finalized amount restores a zero stored total' => [0.00, 1000.00, 0.00, 0.00, 1000.00],
    'calculated amount restores a zero stored total' => [0.00, null, 1000.00, 0.00, 1000.00],
    'item total restores a zero stored total' => [0.00, null, null, 1000.00, 1000.00],
];

foreach ($recoveryCases as $name => [$totalAmount, $finalizedAmount, $calculatedAmount, $itemTotal, $expectedTotal]) {
    $actualTotal = resolveAssessmentTotal($totalAmount, $finalizedAmount, $calculatedAmount, $itemTotal);
    if ($actualTotal !== $expectedTotal) {
        throw new RuntimeException("Assessment total recovery failed for {$name}: expected {$expectedTotal}, got {$actualTotal}.");
    }
}

echo "Assessment balance checks passed.\n";