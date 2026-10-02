<?php
putenv('MAIL_PASSWORD=your_app_password_here');
$_POST['action'] = '';

require_once __DIR__ . '/../../actions/payment_actions.php';
require_once __DIR__ . '/../../includes/lms_access.php';

$pdo->beginTransaction();
$originalSession = $_SESSION;

try {
    $token = strtoupper(bin2hex(random_bytes(5)));
    $yearStart = random_int(2100, 2800);
    $schoolYear = $yearStart . '-' . ($yearStart + 1);

    $termStmt = $pdo->prepare(
        "INSERT INTO academic_terms (school_year, semester, downpayment_percentage, minimum_downpayment, is_active)
         VALUES (:school_year, 'summer', 30.00, 0.00, 0)"
    );
    $termStmt->execute(['school_year' => $schoolYear]);
    $termId = (int)$pdo->lastInsertId();

    $userStmt = $pdo->prepare(
        "INSERT INTO users (username, password_hash, role, email, first_name, last_name)
         VALUES (:username, :password_hash, 'enrollee', :email, 'Flow', 'Test')"
    );
    $userStmt->execute([
        'username' => 'flow_' . strtolower($token),
        'password_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT),
        'email' => 'flow_' . strtolower($token) . '@example.invalid',
    ]);
    $studentUserId = (int)$pdo->lastInsertId();

    $cashierStmt = $pdo->prepare(
        "INSERT INTO users (username, password_hash, role, email, first_name, last_name)
         VALUES (:username, :password_hash, 'cashier', :email, 'Test', 'Cashier')"
    );
    $cashierStmt->execute([
        'username' => 'flow_cashier_' . strtolower($token),
        'password_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT),
        'email' => 'flow_cashier_' . strtolower($token) . '@example.invalid',
    ]);
    $cashierId = (int)$pdo->lastInsertId();

    $studentStmt = $pdo->prepare(
        "INSERT INTO students (user_id, academic_term_id, first_name, last_name, program_code, year_level,
                              application_status, admission_status, enrollment_status)
         VALUES (:user_id, :term_id, 'Flow', 'Test', 'BSMT', '1st Year',
                 'eligible_to_enroll', 'approved', 'walk_in_ready')"
    );
    $studentStmt->execute(['user_id' => $studentUserId, 'term_id' => $termId]);
    $studentId = (int)$pdo->lastInsertId();

    $assessmentStmt = $pdo->prepare(
        "INSERT INTO assessments (student_id, academic_term_id, reference_number, total_amount, calculated_amount,
                                 finalized_amount, is_finalized, status)
         VALUES (:student_id, :term_id, :reference, 1000.00, 1000.00, 1000.00, 1, 'open')"
    );
    $referenceNumber = 'AS-FLOW-' . $token;
    $assessmentStmt->execute([
        'student_id' => $studentId,
        'term_id' => $termId,
        'reference' => $referenceNumber,
    ]);
    $assessmentId = (int)$pdo->lastInsertId();

    $itemStmt = $pdo->prepare(
        'INSERT INTO assessment_items (assessment_id, description, quantity, unit_amount, amount)
         VALUES (:assessment_id, :description, 1, 1000.00, 1000.00)'
    );
    $itemStmt->execute(['assessment_id' => $assessmentId, 'description' => 'LMS flow test assessment']);

    $downPaymentResult = processCashierPayment(
        $pdo,
        $cashierId,
        $studentId,
        300.00,
        'OR-FLOW-DOWN-' . $token,
        'cash'
    );

    $paymentStmt = $pdo->prepare('SELECT or_status, cashier_id, validated_by FROM payments WHERE id = :id');
    $paymentStmt->execute(['id' => (int)$downPaymentResult['payment_id']]);
    $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);
    if (!$payment || $payment['or_status'] !== 'validated' || (int)$payment['cashier_id'] !== $cashierId || (int)$payment['validated_by'] !== $cashierId) {
        throw new RuntimeException('Downpayment receipt was not persisted as validated with cashier attribution.');
    }

    $totals = getAssessmentTotals($pdo, $assessmentId);
    if ((float)$totals['balance'] !== 700.00 || (float)$totals['validated_paid'] !== 300.00) {
        throw new RuntimeException('Downpayment did not reduce the assessment balance to 700.00.');
    }

    $studentCheckStmt = $pdo->prepare('SELECT payment_status, enrollment_status, outstanding_balance FROM students WHERE id = :id');
    $studentCheckStmt->execute(['id' => $studentId]);
    $student = $studentCheckStmt->fetch(PDO::FETCH_ASSOC);
    if (!$student || $student['payment_status'] !== 'partially_paid' || $student['enrollment_status'] !== 'paid' || (float)$student['outstanding_balance'] !== 700.00) {
        throw new RuntimeException('Downpayment did not update the student account to partially paid with a 700.00 balance.');
    }

    $sectionStmt = $pdo->prepare(
        "INSERT INTO sections (section_name, academic_term_id, schedule, program, year_level)
         VALUES (:section_name, :term_id, 'Mon 08:00-09:00', 'BSMT', '1st Year')"
    );
    $sectionStmt->execute(['section_name' => 'FLOW-' . $token, 'term_id' => $termId]);
    $sectionId = (int)$pdo->lastInsertId();

    $enrollmentStmt = $pdo->prepare(
        "INSERT INTO enrollments (student_id, section_id, academic_term_id, school_year, semester, status)
         VALUES (:student_id, :section_id, :term_id, :school_year, 'summer', 'enrolled')"
    );
    $enrollmentStmt->execute([
        'student_id' => $studentId,
        'section_id' => $sectionId,
        'term_id' => $termId,
        'school_year' => $schoolYear,
    ]);
    $pdo->prepare("UPDATE students SET enrollment_status = 'enrolled' WHERE id = :id")
        ->execute(['id' => $studentId]);

    $_SESSION['user_id'] = $studentUserId;
    $_SESSION['role'] = 'enrollee';
    $roleStmt = $pdo->prepare('SELECT role FROM users WHERE id = :id');
    $roleStmt->execute(['id' => $studentUserId]);
    if ($roleStmt->fetchColumn() !== 'student') {
        throw new RuntimeException('Cashier payment did not promote the user role to student.');
    }

    $accessRecord = fetchLmsStudentAccessRecord($pdo, $studentUserId);
    if (!$accessRecord || !lmsStudentMeetsAccessRequirements($accessRecord)) {
        throw new RuntimeException('LMS access prerequisites failed after payment and enrollment: ' . json_encode($accessRecord));
    }

    checkRole(['enrollee', 'student']);
    if (($_SESSION['role'] ?? '') !== 'student') {
        $roleStmt->execute(['id' => $studentUserId]);
        $databaseRole = $roleStmt->fetchColumn();
        throw new RuntimeException('The active session role did not refresh to student from the database (session=' . ($_SESSION['role'] ?? 'unset') . ', database=' . ($databaseRole ?: 'missing') . ').');
    }

    $access = requireLmsAccess(['student']);

    if (($_SESSION['role'] ?? '') !== 'student'
        || ($access['role'] ?? '') !== 'student'
        || (int)($access['student_id'] ?? 0) !== $studentId
        || !lmsStudentMeetsAccessRequirements($access)) {
        throw new RuntimeException('LMS access was not granted after downpayment and section enrollment.');
    }

    $remainingPaymentResult = processCashierPayment(
        $pdo,
        $cashierId,
        $studentId,
        700.00,
        'OR-FLOW-REMAIN-' . $token,
        'cash'
    );
    $paymentStmt->execute(['id' => (int)$remainingPaymentResult['payment_id']]);
    $remainingPayment = $paymentStmt->fetch(PDO::FETCH_ASSOC);
    if (!$remainingPayment || $remainingPayment['or_status'] !== 'validated' || (int)$remainingPayment['cashier_id'] !== $cashierId) {
        throw new RuntimeException('Remaining-balance receipt was not persisted as validated.');
    }

    $totals = getAssessmentTotals($pdo, $assessmentId);
    if ((float)$totals['balance'] !== 0.00 || (float)$totals['validated_paid'] !== 1000.00) {
        throw new RuntimeException('Remaining payment did not reduce the assessment balance to 0.00.');
    }

    $studentCheckStmt->execute(['id' => $studentId]);
    $student = $studentCheckStmt->fetch(PDO::FETCH_ASSOC);
    if (!$student || $student['payment_status'] !== 'fully_paid' || $student['enrollment_status'] !== 'enrolled' || (float)$student['outstanding_balance'] !== 0.0) {
        throw new RuntimeException('Full payment did not leave the linked enrolled student fully paid with zero balance.');
    }

    $access = requireLmsAccess(['student']);
    if (($access['role'] ?? '') !== 'student' || (int)($access['student_id'] ?? 0) !== $studentId) {
        throw new RuntimeException('LMS access was lost after full payment.');
    }

    echo "Cashier downpayment, remaining-balance, role-sync, and LMS flow passed.\n";
} finally {
    $_SESSION = $originalSession;
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}