<?php
/**
 * Payment Actions Processor
 * Records cashier payments, validates unique OR numbers, and updates student enrollment status.
 */

require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/academic_terms.php';
require_once __DIR__ . '/../includes/assessments.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/user_roles.php';
require_once __DIR__ . '/../includes/document_storage.php';

function processCashierAssessmentPayment(PDO $pdo, int $cashierId, string $referenceNumber, string $methodCode, string $orNumber, array $options = []): array
{
    $referenceNumber = trim($referenceNumber);
    $methodCode = strtolower(trim($methodCode));
    $orNumber = trim($orNumber);

    if ($cashierId <= 0) {
        throw new RuntimeException('A valid cashier account is required to process payment.');
    }

    if ($referenceNumber === '') {
        throw new RuntimeException('Assessment reference number is required.');
    }

    if ($orNumber === '') {
        throw new RuntimeException('Official receipt number is required.');
    }

    if (!in_array($methodCode, array_keys(getAllowedPaymentMethods()), true)) {
        throw new RuntimeException('Invalid payment method selected.');
    }

    $assessmentStmt = $pdo->prepare(
        "SELECT a.id AS assessment_id, a.reference_number, a.total_amount, a.discount_amount, a.status,
                s.id AS student_id, s.first_name, s.last_name, s.enrollment_status, s.payment_status,
                s.academic_term_id
         FROM assessments a
         JOIN students s ON s.id = a.student_id
         WHERE a.reference_number = :reference_number
         LIMIT 1"
    );
    $assessmentStmt->execute(['reference_number' => $referenceNumber]);
    $assessment = $assessmentStmt->fetch();

    if (!$assessment) {
        throw new RuntimeException('Assessment reference number not found.');
    }

    $payableStatuses = ['walk_in_ready', 'section_chosen', 'approved', 'paid', 'enrolled'];
    if (!in_array($assessment['enrollment_status'], $payableStatuses, true)) {
        throw new RuntimeException('This student is not yet at the payment stage (current status: ' . htmlspecialchars($assessment['enrollment_status']) . ').');
    }

    if (($assessment['status'] ?? 'open') === 'paid') {
        throw new RuntimeException('This assessment has already been marked paid and cannot be processed again.');
    }

    $assessmentId = (int)$assessment['assessment_id'];
    $totalDue = round((float)($assessment['total_amount'] ?? 0.00), 2);
    if ($totalDue <= 0) {
        throw new RuntimeException('This assessment has no amount due to process.');
    }

    $enteredAmount = isset($options['amount']) ? (float)$options['amount'] : $totalDue;
    if ($enteredAmount <= 0) {
        throw new RuntimeException('Payment amount must be greater than zero.');
    }

    if (abs(round($enteredAmount, 2) - $totalDue) > 0.01) {
        throw new RuntimeException('Cashier payment must match the full assessment due amount of ₱' . number_format($totalDue, 2) . '.');
    }

    $existingOrCheck = $pdo->prepare('SELECT id FROM payments WHERE or_number = :or_number LIMIT 1');
    $existingOrCheck->execute(['or_number' => $orNumber]);
    if ($existingOrCheck->fetch()) {
        throw new RuntimeException("Official receipt number '{$orNumber}' is already registered.");
    }

    $studentId = (int)$assessment['student_id'];
    $termId = (int)$assessment['academic_term_id'];

    // Check if any online payments are pending cashier review for this student
    $pendingStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE student_id = :sid AND academic_term_id = :term_id AND or_status = 'pending'");
    $pendingStmt->execute(['sid' => $studentId, 'term_id' => $termId]);
    $pendingOnlineTotal = round((float)$pendingStmt->fetchColumn(), 2);
    if ($pendingOnlineTotal > 0) {
        $totals = getAssessmentTotals($pdo, $assessmentId);
        $balance = round((float)$totals['balance'], 2);
        $unreservedBalance = max(0.00, round($balance - $pendingOnlineTotal, 2));
        if ($enteredAmount > ($unreservedBalance + 0.0001)) {
            throw new RuntimeException("A pending online payment of ₱" . number_format($pendingOnlineTotal, 2) . " is reserving part of the balance and needs review first. Available unreserved balance is ₱" . number_format($unreservedBalance, 2) . ".");
        }
    }

    $paymentDate = date('Y-m-d');
    $notes = isset($options['notes']) ? trim((string)$options['notes']) : 'Cashier assessment payment';
    $paymentReference = isset($options['payment_reference']) ? trim((string)$options['payment_reference']) : null;
    $bankName = isset($options['bank_name']) ? trim((string)$options['bank_name']) : null;
    $checkNumber = isset($options['check_number']) ? trim((string)$options['check_number']) : null;

    $alreadyInTransaction = $pdo->inTransaction();
    if (!$alreadyInTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $insertStmt = $pdo->prepare(
            "INSERT INTO payments (student_id, academic_term_id, amount, payment_method, payment_reference, bank_name, check_number, or_number, payment_date, issue_date, cashier_id, notes, or_status, validated_at, validated_by)
             VALUES (:student_id, :academic_term_id, :amount, :payment_method, :payment_reference, :bank_name, :check_number, :or_number, :payment_date, :issue_date, :cashier_id, :notes, 'validated', CURRENT_TIMESTAMP, :validated_by)"
        );
        $insertStmt->execute([
            'student_id' => $studentId,
            'academic_term_id' => (int)$assessment['academic_term_id'],
            'amount' => round($enteredAmount, 2),
            'payment_method' => $methodCode,
            'payment_reference' => $paymentReference !== '' ? $paymentReference : null,
            'bank_name' => $bankName !== '' ? $bankName : null,
            'check_number' => $checkNumber !== '' ? $checkNumber : null,
            'or_number' => $orNumber,
            'payment_date' => $paymentDate,
            'issue_date' => $paymentDate,
            'cashier_id' => $cashierId,
            'notes' => $notes !== '' ? $notes : 'Cashier assessment payment',
            'validated_by' => $cashierId,
        ]);
        $paymentId = (int)$pdo->lastInsertId();
        allocatePayment($pdo, $paymentId, $assessmentId, $enteredAmount);
        updateStudentPaymentSummary($pdo, $studentId);
        $assessmentUpdateStmt = $pdo->prepare('UPDATE assessments SET status = :status WHERE id = :id');
        $assessmentUpdateStmt->execute(['status' => 'paid', 'id' => $assessmentId]);
        
        $studentUpdateStmt = $pdo->prepare("
            UPDATE students 
            SET enrollment_status = CASE 
                    WHEN enrollment_status IN ('walk_in_ready', 'section_chosen', 'approved') THEN 'paid' 
                    ELSE enrollment_status 
                END,
                admission_status = 'admitted',
                payment_status = 'fully_paid', 
                outstanding_balance = 0 
            WHERE id = :id
        ");
        $studentUpdateStmt->execute(['id' => $studentId]);

        $studentUserStmt = $pdo->prepare('SELECT s.user_id, s.first_name, s.last_name, u.role FROM students s JOIN users u ON u.id = s.user_id WHERE s.id = :id LIMIT 1');
        $studentUserStmt->execute(['id' => $studentId]);
        $studentUser = $studentUserStmt->fetch();
        $studentUserId = (int)($studentUser['user_id'] ?? 0);

        if ($studentUser && $studentUser['role'] === 'enrollee') {
            if (!promoteUserToStudent($pdo, $studentUserId)) {
                throw new RuntimeException('The account could not be activated as a student.');
            }
            $studentFullName = trim(($studentUser['first_name'] ?? '') . ' ' . ($studentUser['last_name'] ?? ''));
            notifyRegistrarsPaymentReceived($pdo, $studentFullName);
        } elseif (!$studentUser || $studentUser['role'] !== 'student') {
            throw new RuntimeException('The student account could not be verified after payment.');
        }

        if ($studentUserId > 0) {
            createNotification($pdo, $studentUserId, 'Payment processed', 'Your assessment ' . $referenceNumber . ' has been processed and marked as paid. Official receipt OR #' . $orNumber . ' generated.', 'success');
        }
        if (!$alreadyInTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if (!$alreadyInTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'assessment_id' => $assessmentId,
        'student_id' => $studentId,
        'payment_id' => $paymentId,
        'reference_number' => $referenceNumber,
        'amount' => round($enteredAmount, 2),
        'or_number' => $orNumber,
        'method' => $methodCode,
    ];
}

function processCashierPayment(
    PDO $pdo,
    int $cashierId,
    int $studentId,
    float $amount,
    string $orNumber,
    string $paymentMethod,
    array $options = []
): array {
    $orNumber = trim($orNumber);
    $paymentMethod = strtolower(trim($paymentMethod));
    $amount = round($amount, 2);

    if ($cashierId <= 0 || $studentId <= 0 || $amount <= 0 || $orNumber === '') {
        throw new RuntimeException('Cashier, student, amount, and OR number are required.');
    }
    if (!in_array($paymentMethod, array_keys(getAllowedPaymentMethods($pdo)), true)) {
        throw new RuntimeException('Invalid payment method selected.');
    }

    $studentStmt = $pdo->prepare(
        'SELECT s.id, s.user_id, s.academic_term_id, s.application_status, s.enrollment_status,
                s.first_name, s.last_name, u.role
         FROM students s
         JOIN users u ON u.id = s.user_id
         WHERE s.id = :id
         LIMIT 1'
    );
    $studentStmt->execute(['id' => $studentId]);
    $student = $studentStmt->fetch();
    if (!$student) {
        throw new RuntimeException('Selected student was not found.');
    }
    if (empty($student['academic_term_id'])) {
        throw new RuntimeException('The selected account is not linked to an academic term.');
    }

    $payableEnrollmentStatuses = ['approved', 'section_chosen', 'walk_in_ready', 'paid', 'enrolled'];
    if (!in_array($student['enrollment_status'], $payableEnrollmentStatuses, true)) {
        throw new RuntimeException('This student is not yet at the payment stage.');
    }

    $alreadyInTransaction = $pdo->inTransaction();
    if (!$alreadyInTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $assessmentId = getOrCreateAssessment($pdo, $studentId, (int)$student['academic_term_id']);
        $totals = getAssessmentTotals($pdo, $assessmentId);
        $balance = round((float)$totals['balance'], 2);

        // Check if any online payments are pending cashier review for this student
        $pendingStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE student_id = :sid AND academic_term_id = :term_id AND or_status = 'pending'");
        $pendingStmt->execute(['sid' => $studentId, 'term_id' => (int)$student['academic_term_id']]);
        $pendingOnlineTotal = round((float)$pendingStmt->fetchColumn(), 2);
        $unreservedBalance = max(0.00, round($balance - $pendingOnlineTotal, 2));

        if ($pendingOnlineTotal > 0 && $amount > ($unreservedBalance + 0.0001)) {
            throw new RuntimeException("A pending online payment of ₱" . number_format($pendingOnlineTotal, 2) . " is reserving part of the balance and needs review first. Available unreserved balance is ₱" . number_format($unreservedBalance, 2) . ".");
        }

        if (paymentExceedsAssessmentBalance($amount, $balance)) {
            throw new RuntimeException('Amount exceeds outstanding assessment balance of ₱' . number_format($balance, 2) . '.');
        }

        $paymentDate = date('Y-m-d');
        $paymentReference = trim((string)($options['payment_reference'] ?? ''));
        $bankName = trim((string)($options['bank_name'] ?? ''));
        $checkNumber = trim((string)($options['check_number'] ?? ''));
        $notes = trim((string)($options['notes'] ?? ''));
        $insertStmt = $pdo->prepare(
            "INSERT INTO payments (student_id, academic_term_id, amount, payment_method, payment_reference, bank_name, check_number, or_number, payment_date, issue_date, cashier_id, notes, or_status, validated_at, validated_by)
             VALUES (:student_id, :academic_term_id, :amount, :payment_method, :payment_reference, :bank_name, :check_number, :or_number, :payment_date, :issue_date, :cashier_id, :notes, 'validated', CURRENT_TIMESTAMP, :validated_by)"
        );
        $insertStmt->execute([
            'student_id' => $studentId,
            'academic_term_id' => (int)$student['academic_term_id'],
            'amount' => $amount,
            'payment_method' => $paymentMethod,
            'payment_reference' => $paymentReference !== '' ? $paymentReference : null,
            'bank_name' => $bankName !== '' ? $bankName : null,
            'check_number' => $checkNumber !== '' ? $checkNumber : null,
            'or_number' => $orNumber,
            'payment_date' => $paymentDate,
            'issue_date' => $paymentDate,
            'cashier_id' => $cashierId,
            'notes' => $notes !== '' ? $notes : null,
            'validated_by' => $cashierId,
        ]);
        $paymentId = (int)$pdo->lastInsertId();
        allocatePayment($pdo, $paymentId, $assessmentId, $amount);
        updateStudentPaymentSummary($pdo, $studentId);

        $totals = getAssessmentTotals($pdo, $assessmentId);
        $assessmentStatus = (float)$totals['validated_paid'] >= (float)$totals['total_amount'] ? 'paid' : 'open';
        $pdo->prepare('UPDATE assessments SET status = :status WHERE id = :id')
            ->execute(['status' => $assessmentStatus, 'id' => $assessmentId]);

        $remainingBalance = round((float)$totals['balance'], 2);
        $paymentStatus = strtolower(str_replace(' ', '_', getAssessmentPaymentStatus(
            (float)$totals['validated_paid'],
            (float)$totals['total_amount']
        )));
        $termStmt = $pdo->prepare('SELECT minimum_downpayment, downpayment_percentage FROM academic_terms WHERE id = :id LIMIT 1');
        $termStmt->execute(['id' => (int)$student['academic_term_id']]);
        $term = $termStmt->fetch() ?: [];
        $isEligibleForStudentAccess = canActivateStudentAccount(
            (string)$student['application_status'],
            (string)$student['enrollment_status'],
            (float)$totals['validated_paid'],
            (float)$totals['total_amount'],
            (float)($term['minimum_downpayment'] ?? 0.00),
            (float)($term['downpayment_percentage'] ?? 30.00)
        );

        $studentUpdateStmt = $pdo->prepare(
            "UPDATE students
             SET payment_status = :payment_status,
                 outstanding_balance = :balance,
                 admission_status = CASE WHEN :activate_admission = 1 THEN 'admitted' ELSE admission_status END,
                 enrollment_status = CASE
                     WHEN :activate_enrollment = 1 AND enrollment_status != 'enrolled' THEN 'paid'
                     ELSE enrollment_status
                 END
             WHERE id = :id"
        );
        $studentUpdateStmt->execute([
            'payment_status' => $paymentStatus,
            'balance' => $remainingBalance,
            'activate_admission' => $isEligibleForStudentAccess ? 1 : 0,
            'activate_enrollment' => $isEligibleForStudentAccess ? 1 : 0,
            'id' => $studentId,
        ]);

        $wasAlreadyPaid = in_array((string)$student['enrollment_status'], ['paid', 'enrolled'], true);

        if ($isEligibleForStudentAccess && $student['role'] === 'enrollee') {
            if (!promoteUserToStudent($pdo, (int)$student['user_id'])) {
                throw new RuntimeException('The account could not be activated as a student.');
            }
        } elseif ($isEligibleForStudentAccess && $student['role'] !== 'student') {
            throw new RuntimeException('The student account could not be verified after payment.');
        }

        if ($isEligibleForStudentAccess && !$wasAlreadyPaid) {
            $studentFullName = trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''));
            notifyRegistrarsPaymentReceived($pdo, $studentFullName);
        }

        if (!$alreadyInTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if (!$alreadyInTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'payment_id' => $paymentId,
        'assessment_id' => $assessmentId,
        'student_id' => $studentId,
        'student_name' => trim($student['first_name'] . ' ' . $student['last_name']),
        'amount' => $amount,
        'or_number' => $orNumber,
    ];
}

if (PHP_SAPI !== 'cli') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $_SESSION['flash_error'] = "Invalid request method.";
        header("Location: ../cashier/enrollment_queue");
        exit;
    }

    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash_error'] = "Security validation failed. Please try again.";
        header("Location: ../cashier/enrollment_queue");
        exit;
    }
}

$action = trim($_POST['action'] ?? '');

if ($action !== '') {
    switch ($action) {
    case 'record_student_payment':
        checkRole(['student', 'enrollee']);

        $userRole = $_SESSION['role'] ?? 'student';
        $paymentPage = ($userRole === 'enrollee') ? '../enrollee/payment' : '../student/payment';
        $dashboardPage = ($userRole === 'enrollee') ? '../enrollee/dashboard' : '../student/dashboard';

        $userId = (int)($_SESSION['user_id'] ?? 0);
        $amount = isset($_POST['amount']) ? round((float)$_POST['amount'], 2) : 0.0;
        $methodCode = strtolower(trim((string)($_POST['payment_method'] ?? '')));
        $notes = trim((string)($_POST['notes'] ?? 'Student payment record'));
        $reference = trim((string)($_POST['payment_reference'] ?? ''));

        if ($userId <= 0) {
            $_SESSION['flash_error'] = 'Your session is no longer valid. Please log in again.';
            header('Location: ../auth/login');
            exit;
        }

        $studentStmt = $pdo->prepare("SELECT * FROM students WHERE user_id = :user_id LIMIT 1");
        $studentStmt->execute(['user_id' => $userId]);
        $student = $studentStmt->fetch();
        if (!$student) {
            $_SESSION['flash_error'] = 'Your student profile was not found.';
            header('Location: ' . $dashboardPage);
            exit;
        }

        // SECURITY GATE: Payment is only allowed AFTER the registrar has validated the
        // walk-in and finalized the subject list. 'section_chosen' means the enrollee
        // picked a section online but the registrar has NOT yet reviewed it.
        $payableEnrollmentStatuses = ['walk_in_ready', 'approved', 'paid', 'enrolled'];
        if (!in_array($student['enrollment_status'] ?? '', $payableEnrollmentStatuses, true)) {
            $_SESSION['flash_error'] = 'Payment is not yet available. Please visit the Registrar\'s Office to have your section and subjects validated before proceeding to payment.';
            header('Location: ' . $paymentPage);
            exit;
        }

        $termId = (int)($student['academic_term_id'] ?? 0);
        if ($termId <= 0) {
            $activeTerm = getActiveAcademicTerm($pdo);
            if (!$activeTerm) {
                $_SESSION['flash_error'] = 'There is no active academic term configured for payment.';
                header('Location: ' . $paymentPage);
                exit;
            }
            $termId = (int)$activeTerm['id'];
        }

        $validMethods = array_keys(getAllowedPaymentMethods());
        if (!in_array($methodCode, $validMethods, true)) {
            $_SESSION['flash_error'] = 'Invalid payment method selected.';
            header('Location: ' . $paymentPage);
            exit;
        }

        if ($amount <= 0.00) {
            $_SESSION['flash_error'] = 'Payment amount must be greater than zero.';
            header('Location: ' . $paymentPage);
            exit;
        }

        // Online payment methods strictly require reference / transaction code
        // Online payment methods strictly require reference / transaction code
        $requiresReference = in_array($methodCode, ['gcash', 'maya', 'bank_transfer', 'online_transfer', 'credit_card', 'check'], true);
        if ($requiresReference && $reference === '') {
            $_SESSION['flash_error'] = 'A reference number or transaction code is required for ' . htmlspecialchars(getPaymentMethodLabel($methodCode, $pdo)) . '.';
            header('Location: ' . $paymentPage);
            exit;
        }

        // Online payment methods strictly require proof of payment upload
        $isOnlineMethod = in_array($methodCode, ['gcash', 'maya', 'bank_transfer', 'online_transfer', 'credit_card'], true);
        $hasProofFile = (isset($_FILES['proof_file']) && $_FILES['proof_file']['error'] !== UPLOAD_ERR_NO_FILE);
        if ($isOnlineMethod && !$hasProofFile) {
            $_SESSION['flash_error'] = 'Proof of payment receipt upload is required for online payments.';
            header('Location: ' . $paymentPage);
            exit;
        }

        // Proof of payment file validation (MIME check, size limit, sanitized upload)
        $proofFilePath = null;
        if (isset($_FILES['proof_file']) && $_FILES['proof_file']['error'] !== UPLOAD_ERR_NO_FILE) {
            $file = $_FILES['proof_file'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $_SESSION['flash_error'] = 'Error uploading proof of payment. Please try again.';
                header('Location: ' . $paymentPage);
                exit;
            }
            $maxSize = 5 * 1024 * 1024; // 5MB
            if ($file['size'] > $maxSize) {
                $_SESSION['flash_error'] = 'Proof of payment file exceeds the maximum allowed size of 5MB.';
                header('Location: ' . $paymentPage);
                exit;
            }
            $finfo = class_exists('finfo') ? new finfo(FILEINFO_MIME_TYPE) : null;
            $mimeType = $finfo ? (string)$finfo->file($file['tmp_name']) : (string)mime_content_type($file['tmp_name']);
            $allowedMimes = ['image/jpeg', 'image/png', 'image/jpg', 'image/pjpeg', 'application/pdf'];
            $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
            $allowedExts = ['jpg', 'jpeg', 'png', 'pdf'];
            if (!in_array($mimeType, $allowedMimes, true) || !in_array($ext, $allowedExts, true)) {
                $_SESSION['flash_error'] = 'Invalid proof of payment format. Only JPG, PNG, and PDF files are allowed.';
                header('Location: ' . $paymentPage);
                exit;
            }

            $projectRoot = realpath(__DIR__ . '/..');
            $targetDir = $projectRoot . '/private_uploads/payment_proofs/' . (int)$student['id'];
            if (!is_dir($targetDir)) {
                @mkdir($targetDir, 0755, true);
            }
            $proofHtaccess = $projectRoot . '/private_uploads/payment_proofs/.htaccess';
            if (!file_exists($proofHtaccess)) {
                @file_put_contents($proofHtaccess, "Options -Indexes\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n");
            }
            $fileName = (int)$student['id'] . '_proof_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            $destPath = $targetDir . '/' . $fileName;
            if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                $_SESSION['flash_error'] = 'Failed to save uploaded proof of payment. Please try again.';
                header('Location: ' . $paymentPage);
                exit;
            }
            $proofFilePath = 'private_uploads/payment_proofs/' . (int)$student['id'] . '/' . $fileName;
        }

        try {
            $pdo->beginTransaction();

            // Row-level lock to prevent concurrent duplicate submissions
            $lockStmt = $pdo->prepare("SELECT id FROM students WHERE id = :id FOR UPDATE");
            $lockStmt->execute(['id' => (int)$student['id']]);

            // DUPLICATE-PENDING CHECK: Block if a payment is already pending cashier review
            $pendingStmt = $pdo->prepare("SELECT id, or_number, amount FROM payments WHERE student_id = :student_id AND or_status = 'pending' LIMIT 1 FOR UPDATE");
            $pendingStmt->execute(['student_id' => (int)$student['id']]);
            $existingPending = $pendingStmt->fetch();
            if ($existingPending) {
                throw new \RuntimeException('You already have a payment of ₱' . number_format((float)$existingPending['amount'], 2) . ' (#' . $existingPending['or_number'] . ') awaiting cashier review. Please wait for it to be decided.');
            }

            $assessmentId = getOrCreateAssessment($pdo, (int)$student['id'], $termId);
            $totals = getAssessmentTotals($pdo, $assessmentId);
            if (empty($totals['is_finalized']) || (float)($totals['total_amount'] ?? 0.00) <= 0.00) {
                throw new \RuntimeException('No finalized tuition assessment was found. Please wait for the Registrar to finalize your assessment before submitting payment.');
            }
            $netAssessment = round((float)($totals['total_amount'] ?? 0.00), 2);
            $paidAmount = round((float)($totals['validated_paid'] ?? 0.00), 2);
            $remainingBalance = max(0.00, round($netAssessment - $paidAmount, 2));

            $termStmt = $pdo->prepare('SELECT downpayment_percentage, minimum_downpayment FROM academic_terms WHERE id = :id LIMIT 1');
            $termStmt->execute(['id' => $termId]);
            $term = $termStmt->fetch();
            $requiredDownpayment = $term
                ? calculateRequiredDownpayment(
                    $netAssessment,
                    (float)($term['minimum_downpayment'] ?? 0.00),
                    (float)($term['downpayment_percentage'] ?? 30.00)
                )
                : 0.00;
            $remainingDownpayment = max(0.00, round($requiredDownpayment - $paidAmount, 2));


            // REMAINING BALANCE ENFORCEMENT
            if ($amount > $remainingBalance) {
                throw new \RuntimeException('Payment exceeds the remaining balance of ₱' . number_format($remainingBalance, 2) . '.');
            }

            $maxAttempts = 5;
            $attempt = 0;
            $inserted = false;

            while (!$inserted && $attempt < $maxAttempts) {
                $attempt++;
                $orNumber = 'ST-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(8)));

                try {
                    $insertStmt = $pdo->prepare(
                        "INSERT INTO payments (student_id, academic_term_id, amount, payment_method, payment_reference, bank_name, check_number, or_number, payment_date, issue_date, cashier_id, notes, proof_file, or_status)
                         VALUES (:student_id, :academic_term_id, :amount, :payment_method, :payment_reference, NULL, NULL, :or_number, CURDATE(), CURDATE(), :cashier_id, :notes, :proof_file, 'pending')"
                    );
                    $insertStmt->execute([
                        'student_id' => (int)$student['id'],
                        'academic_term_id' => $termId,
                        'amount' => $amount,
                        'payment_method' => $methodCode,
                        'payment_reference' => $reference !== '' ? $reference : null,
                        'or_number' => $orNumber,
                        'cashier_id' => null,
                        'notes' => $notes !== '' ? $notes : 'Student payment record',
                        'proof_file' => $proofFilePath,
                    ]);

                    $paymentId = (int)$pdo->lastInsertId();
                    allocatePayment($pdo, $paymentId, $assessmentId, $amount);
                    updateStudentPaymentSummary($pdo, (int)$student['id']);
                    $inserted = true;
                } catch (\PDOException $e) {
                    $isDuplicateOr = ($e->getCode() === '23000' || ($e->errorInfo[1] ?? 0) === 1062);
                    if ($isDuplicateOr && $attempt < $maxAttempts) {
                        continue;
                    }
                    throw $e;
                }
            }

            // Notify Cashiers and Admins of the new pending submission
            $studentFullName = trim($student['first_name'] . ' ' . $student['last_name']);
            $cashiers = $pdo->query("SELECT id FROM users WHERE role IN ('cashier', 'admin') AND is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($cashiers as $cId) {
                createNotification(
                    $pdo,
                    (int)$cId,
                    'New Online Payment',
                    "Student {$studentFullName} submitted an online payment of ₱" . number_format($amount, 2) . " (" . strtoupper($methodCode) . ") awaiting cashier review.",
                    'info'
                );
            }

            $pdo->commit();
            $_SESSION['flash_success'] = 'Payment of ₱' . number_format($amount, 2) . ' recorded successfully. Tracking #: ' . $orNumber . '. Awaiting cashier validation.';
            header('Location: ' . $paymentPage);
            exit;
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['flash_error'] = $e->getMessage();
            header('Location: ' . $paymentPage);
            exit;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Student payment recording failed: ' . $e->getMessage());
            $_SESSION['flash_error'] = 'An unexpected error occurred. Please try again.';
            header('Location: ' . $paymentPage);
            exit;
        }
        break;

    case 'cashier_process_assessment_payment':
        checkRole(['cashier', 'admin']);
        $referenceNumber = trim((string)($_POST['reference_number'] ?? ''));
        $methodCode = strtolower(trim((string)($_POST['payment_method'] ?? '')));
        $orNumber = trim((string)($_POST['or_number'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $paymentReference = trim((string)($_POST['payment_reference'] ?? ''));
        $bankName = trim((string)($_POST['bank_name'] ?? ''));
        $checkNumber = trim((string)($_POST['check_number'] ?? ''));

        try {
            $result = processCashierAssessmentPayment($pdo, (int)($_SESSION['user_id'] ?? 0), $referenceNumber, $methodCode, $orNumber, [
                'notes' => $notes,
                'payment_reference' => $paymentReference,
                'bank_name' => $bankName,
                'check_number' => $checkNumber,
            ]);
            $_SESSION['flash_success'] = 'Assessment ' . $referenceNumber . ' was processed successfully. Payment receipt OR #' . htmlspecialchars($orNumber, ENT_QUOTES, 'UTF-8') . ' recorded and student marked as paid.';
            header('Location: ../cashier/payments?reference_number=' . urlencode($referenceNumber));
            exit;
        } catch (\RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            header('Location: ../cashier/payments?reference_number=' . urlencode($referenceNumber));
            exit;
        } catch (\Throwable $e) {
            error_log('Cashier assessment payment failed: ' . $e->getMessage());
            $_SESSION['flash_error'] = 'An unexpected error occurred. Please try again.';
            header('Location: ../cashier/payments?reference_number=' . urlencode($referenceNumber));
            exit;
        }
        break;

    case 'generate_assessment':
        checkRole(['cashier', 'admin']);
        $studentId = (int)($_POST['student_id'] ?? 0);
        try {
            $studentStmt = $pdo->prepare('SELECT academic_term_id FROM students WHERE id = :id LIMIT 1');
            $studentStmt->execute(['id' => $studentId]);
            $student = $studentStmt->fetch();
            if (!$student || empty($student['academic_term_id'])) {
                throw new \RuntimeException('Student term record was not found.');
            }
            $pdo->beginTransaction();
            $assessmentId = getOrCreateAssessment($pdo, $studentId, (int)$student['academic_term_id']);
            $pdo->commit();
            $_SESSION['flash_success'] = 'Assessment generated successfully.';
            header("Location: ../cashier/assessment?id={$assessmentId}");
            exit;
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['flash_error'] = $e->getMessage();
            header("Location: ../cashier/payments?student_id={$studentId}");
            exit;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Assessment generation failed: ' . $e->getMessage());
            $_SESSION['flash_error'] = 'An unexpected error occurred. Please try again.';
            header("Location: ../cashier/payments?student_id={$studentId}");
            exit;
        }
        break;

    case 'create':
        checkRole(['cashier', 'admin']);
        $studentId = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
        $amount = isset($_POST['amount']) ? trim($_POST['amount']) : '';
        $orNumber = isset($_POST['or_number']) ? trim($_POST['or_number']) : '';
        $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
        $paymentMethod = isset($_POST['payment_method']) ? trim($_POST['payment_method']) : '';
        $paymentReference = isset($_POST['payment_reference']) ? trim($_POST['payment_reference']) : '';
        $bankName = isset($_POST['bank_name']) ? trim($_POST['bank_name']) : '';
        $checkNumber = isset($_POST['check_number']) ? trim($_POST['check_number']) : '';
        $cashierId = (int)$_SESSION['user_id'];

        if (empty($studentId) || $amount === '' || empty($orNumber) || $paymentMethod === '') {
            $_SESSION['flash_error'] = "Student, amount, payment method, and OR number are required.";
            header("Location: " . ($studentId ? "../cashier/payments?student_id={$studentId}" : "../cashier/enrollment_queue"));
            exit;
        }

        if (!is_numeric($amount) || (float)$amount <= 0) {
            $_SESSION['flash_error'] = "Amount must be a positive number.";
            header("Location: ../cashier/payments?student_id={$studentId}");
            exit;
        }

        $amount = round((float)$amount, 2);

        $requiresReference = in_array($paymentMethod, ['bank_transfer', 'online_transfer', 'gcash', 'paymaya', 'credit_card', 'check'], true);
        if ($requiresReference && $paymentReference === '') {
            $_SESSION['flash_error'] = 'This payment method requires a reference number or transaction code.';
            header("Location: ../cashier/payments?student_id={$studentId}");
            exit;
        }

        if ($paymentMethod === 'check' && $checkNumber === '') {
            $_SESSION['flash_error'] = 'Please enter the check number for this payment.';
            header("Location: ../cashier/payments?student_id={$studentId}");
            exit;
        }

        $envMax = getenv('PAYMENT_MAX');
        $paymentMax = is_numeric($envMax) ? (float)$envMax : 1000000.00;
        if ($amount > $paymentMax) {
            $_SESSION['flash_error'] = "Amount exceeds allowed maximum of ₱" . number_format($paymentMax, 2) . ".";
            header("Location: ../cashier/payments?student_id={$studentId}");
            exit;
        }

        if (strlen($orNumber) > 50 || strlen($orNumber) < 1) {
            $_SESSION['flash_error'] = "OR number is required and must not exceed 50 characters.";
            header("Location: ../cashier/payments?student_id={$studentId}");
            exit;
        }

        try {
            $result = processCashierPayment($pdo, $cashierId, $studentId, $amount, $orNumber, $paymentMethod, [
                'notes' => $notes,
                'payment_reference' => $paymentReference,
                'bank_name' => $bankName,
                'check_number' => $checkNumber,
            ]);
            $_SESSION['flash_success'] = "Payment of ₱" . number_format($amount, 2) . " recorded and validated for {$result['student_name']}. OR #{$orNumber}.";
            header("Location: ../cashier/receipts?id={$result['payment_id']}");
            exit;
        } catch (\Exception $e) {
            error_log("Record payment failed: " . $e->getMessage());

            if ($e->getCode() == 23000) {
                $_SESSION['flash_error'] = "OR number '{$orNumber}' is already registered. Please use a unique official receipt number.";
            } else {
                $_SESSION['flash_error'] = "Failed to record payment: " . $e->getMessage();
            }
            header("Location: ../cashier/payments?student_id={$studentId}");
            exit;
        }
        break;

    case 'validate':
        if (defined('TEST_INSTRUMENT') && TEST_INSTRUMENT === true) {
            // Instrumentation for CLI verifiers: indicate we entered the validate action
            echo "INSTR|validate_entry" . PHP_EOL;
        }
        checkRole(['cashier', 'admin']);
        $paymentId = (int)($_POST['payment_id'] ?? 0);
        $customOrNumber = trim((string)($_POST['or_number'] ?? ''));
        $cashierNotes = trim((string)($_POST['validation_notes'] ?? ''));

        if ($paymentId <= 0) {
            $_SESSION['flash_error'] = 'Invalid payment record specified.';
            $redirect = !empty($_POST['redirect_to']) ? $_POST['redirect_to'] : '../cashier/enrollment_queue';
            header('Location: ' . $redirect);
            exit;
        }

        try {
            $pdo->beginTransaction();
            $paymentStmt = $pdo->prepare(
                "SELECT p.id, p.student_id, p.academic_term_id, p.amount, p.or_number, p.or_status, p.validation_notes,
                        s.user_id, s.first_name, s.last_name, s.application_status, s.enrollment_status, s.academic_term_id AS student_term_id, u.role,
                        a.id AS assessment_id, t.minimum_downpayment, t.downpayment_percentage
                 FROM payments p
                 JOIN students s ON s.id = p.student_id
                 JOIN users u ON u.id = s.user_id
                 LEFT JOIN assessments a ON a.student_id = s.id AND a.academic_term_id = p.academic_term_id
                 JOIN academic_terms t ON t.id = p.academic_term_id
                 WHERE p.id = :id
                 LIMIT 1 FOR UPDATE"
            );
            $paymentStmt->execute(['id' => $paymentId]);
            $payment = $paymentStmt->fetch();
            if (!$payment || $payment['or_status'] !== 'pending') {
                throw new \RuntimeException('This payment is no longer pending or has already been processed.');
            }

            if (empty($payment['assessment_id'])) {
                $assessmentId = getOrCreateAssessment($pdo, (int)$payment['student_id'], (int)$payment['academic_term_id']);
                $payment['assessment_id'] = $assessmentId;
            }

            // Balance guard: Re-check that approving this payment does not exceed the remaining balance
            $currentTotals = getAssessmentTotals($pdo, (int)$payment['assessment_id']);
            $currentBalance = round((float)$currentTotals['balance'], 2);
            if ((float)$payment['amount'] > $currentBalance + 0.0001) {
                throw new \RuntimeException('Approving this payment of ₱' . number_format((float)$payment['amount'], 2) . ' would exceed the student\'s remaining balance of ₱' . number_format($currentBalance, 2) . '.');
            }

            $finalOrNumber = $payment['or_number'];
            $finalValidationNotes = trim((string)($payment['validation_notes'] ?? ''));

            if ($customOrNumber !== '' && $customOrNumber !== $payment['or_number']) {
                $checkOr = $pdo->prepare("SELECT id FROM payments WHERE or_number = :or_number AND id != :id LIMIT 1");
                $checkOr->execute(['or_number' => $customOrNumber, 'id' => $paymentId]);
                if ($checkOr->fetch()) {
                    throw new \RuntimeException("Official receipt number '{$customOrNumber}' is already registered. Please enter a unique OR number.");
                }
                $cashierUserId = (int)$_SESSION['user_id'];
                $noteAudit = "OR # updated from {$payment['or_number']} to {$customOrNumber} by cashier #{$cashierUserId}";
                $finalValidationNotes = $finalValidationNotes !== '' ? $finalValidationNotes . " | " . $noteAudit : $noteAudit;
                $finalOrNumber = $customOrNumber;
            }

            if ($cashierNotes !== '') {
                $finalValidationNotes = $finalValidationNotes !== '' ? $finalValidationNotes . " | " . $cashierNotes : $cashierNotes;
            }

            $cashierId = (int)$_SESSION['user_id'];
            $validateStmt = $pdo->prepare(
                "UPDATE payments
                 SET or_status = 'validated',
                     or_number = :or_number,
                     cashier_id = :cashier_id,
                     validated_at = CURRENT_TIMESTAMP,
                     validated_by = :validated_by,
                     validation_notes = :validation_notes
                 WHERE id = :id AND or_status = 'pending'"
            );
            $validateStmt->execute([
                'or_number' => $finalOrNumber,
                'cashier_id' => $cashierId,
                'validated_by' => $cashierId,
                'validation_notes' => $finalValidationNotes !== '' ? $finalValidationNotes : null,
                'id' => $paymentId,
            ]);
            if ($validateStmt->rowCount() !== 1) {
                throw new \RuntimeException('This payment has already been processed by another cashier.');
            }

            $totals = getAssessmentTotals($pdo, (int)$payment['assessment_id']);
            $remainingBalance = round((float)$totals['balance'], 2);
            $paymentStatus = strtolower(str_replace(' ', '_', getAssessmentPaymentStatus(
                (float)$totals['validated_paid'],
                (float)$totals['total_amount']
            )));
            $assessmentStatus = (float)$totals['validated_paid'] >= (float)$totals['total_amount'] ? 'paid' : 'open';
            $assessmentStmt = $pdo->prepare("UPDATE assessments SET status = :status WHERE id = :id");
            $assessmentStmt->execute(['status' => $assessmentStatus, 'id' => (int)$payment['assessment_id']]);

            $paymentSummaryStmt = $pdo->prepare('UPDATE students SET payment_status = :payment_status, outstanding_balance = :balance WHERE id = :id');
            $paymentSummaryStmt->execute([
                'payment_status' => $paymentStatus,
                'balance' => $remainingBalance,
                'id' => (int)$payment['student_id'],
            ]);

            $wasAlreadyPaid = in_array((string)$payment['enrollment_status'], ['paid', 'enrolled'], true);
            $activated = false;
            if (canActivateStudentAccount(
                (string)$payment['application_status'],
                (string)$payment['enrollment_status'],
                (float)$totals['validated_paid'],
                (float)$totals['total_amount'],
                (float)($payment['minimum_downpayment'] ?? 0.00),
                (float)($payment['downpayment_percentage'] ?? 30.00)
            )) {
                $statusStmt = $pdo->prepare("UPDATE students SET admission_status = 'admitted', enrollment_status = CASE WHEN enrollment_status = 'enrolled' THEN 'enrolled' ELSE 'paid' END WHERE id = :id");
                $statusStmt->execute([
                    'id' => (int)$payment['student_id'],
                ]);
                $wasEnrollee = ($payment['role'] === 'enrollee');
                if ($wasEnrollee) {
                    $promoted = promoteUserToStudent($pdo, (int)$payment['user_id']);
                    if (!$promoted) {
                        throw new \RuntimeException('The account could not be activated as a student.');
                    }
                } elseif ($payment['role'] === 'student') {
                    // Already a student (e.g. continuing student paying term assessment) — skip promotion
                } else {
                    throw new \RuntimeException('The account could not be activated as a student.');
                }
                $activated = true;

                $approvalMsg = $wasEnrollee
                    ? 'Your online payment of ₱' . number_format((float)$payment['amount'], 2) . ' (OR #' . $finalOrNumber . ') was approved. Student access is now active. Remaining balance: ₱' . number_format($remainingBalance, 2) . '.'
                    : 'Your online payment of ₱' . number_format((float)$payment['amount'], 2) . ' (OR #' . $finalOrNumber . ') was approved. Remaining balance: ₱' . number_format($remainingBalance, 2) . '.';

                createNotification(
                    $pdo,
                    (int)$payment['user_id'],
                    'Payment Approved',
                    $approvalMsg,
                    'success'
                );

                // Notify registrars ONLY when this payment causes the student to become 'paid' (first activation)
                if (!$wasAlreadyPaid) {
                    $studentFullName = trim(($payment['first_name'] ?? '') . ' ' . ($payment['last_name'] ?? ''));
                    notifyRegistrarsPaymentReceived($pdo, $studentFullName);
                }
            } elseif (isPaymentActivationEligibleApplicationStatus((string)$payment['application_status'])) {
                $statusStmt = $pdo->prepare("UPDATE students SET admission_status = 'approved' WHERE id = :id");
                $statusStmt->execute(['id' => (int)$payment['student_id']]);
                createNotification(
                    $pdo,
                    (int)$payment['user_id'],
                    'Payment Approved',
                    'Your online payment of ₱' . number_format((float)$payment['amount'], 2) . ' (OR #' . $finalOrNumber . ') was approved. Additional payment is required to meet the required downpayment. Remaining balance: ₱' . number_format($remainingBalance, 2) . '.',
                    'info'
                );
            }

            $pdo->commit();
            $_SESSION['flash_success'] = 'Official receipt OR #' . htmlspecialchars($finalOrNumber, ENT_QUOTES, 'UTF-8') . ' validated successfully.';
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['flash_error'] = $e->getMessage();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Payment validation failed: ' . $e->getMessage());
            $_SESSION['flash_error'] = 'An unexpected error occurred. Please try again.';
        }
        $redirect = !empty($_POST['redirect_to']) ? $_POST['redirect_to'] : '../cashier/enrollment_queue';
        header('Location: ' . $redirect);
        exit;
        break;

    case 'reject_payment':
        checkRole(['cashier', 'admin']);
        $paymentId = (int)($_POST['payment_id'] ?? 0);
        $rejectionReason = trim((string)($_POST['rejection_reason'] ?? ''));

        if ($paymentId <= 0) {
            $_SESSION['flash_error'] = 'Invalid payment record specified.';
            $redirect = !empty($_POST['redirect_to']) ? $_POST['redirect_to'] : '../cashier/enrollment_queue';
            header('Location: ' . $redirect);
            exit;
        }

        if ($rejectionReason === '') {
            $_SESSION['flash_error'] = 'A rejection reason is required.';
            $redirect = !empty($_POST['redirect_to']) ? $_POST['redirect_to'] : '../cashier/enrollment_queue';
            header('Location: ' . $redirect);
            exit;
        }

        if (strlen($rejectionReason) > 500) {
            $_SESSION['flash_error'] = 'Rejection reason must not exceed 500 characters.';
            $redirect = !empty($_POST['redirect_to']) ? $_POST['redirect_to'] : '../cashier/enrollment_queue';
            header('Location: ' . $redirect);
            exit;
        }

        try {
            $pdo->beginTransaction();
            $paymentStmt = $pdo->prepare(
                "SELECT p.id, p.student_id, p.amount, p.or_number, p.payment_reference, p.or_status, s.user_id, s.first_name, s.last_name
                 FROM payments p
                 JOIN students s ON s.id = p.student_id
                 WHERE p.id = :id
                 LIMIT 1 FOR UPDATE"
            );
            $paymentStmt->execute(['id' => $paymentId]);
            $payment = $paymentStmt->fetch();

            if (!$payment || $payment['or_status'] !== 'pending') {
                throw new \RuntimeException('This payment is no longer pending and cannot be rejected.');
            }

            $cashierId = (int)$_SESSION['user_id'];
            $rejectStmt = $pdo->prepare(
                "UPDATE payments
                 SET or_status = 'rejected',
                     cashier_id = :cashier_id,
                     validated_at = CURRENT_TIMESTAMP,
                     validated_by = :validated_by,
                     validation_notes = :validation_notes
                 WHERE id = :id AND or_status = 'pending'"
            );
            $rejectStmt->execute([
                'cashier_id' => $cashierId,
                'validated_by' => $cashierId,
                'validation_notes' => $rejectionReason,
                'id' => $paymentId,
            ]);

            if ($rejectStmt->rowCount() !== 1) {
                throw new \RuntimeException('This payment has already been processed by another cashier.');
            }

            // Note: Per user decision, do NOT delete payment_allocations rows (kept for audit trail).
            // allocatePayment() and getAssessmentTotals() exclude 'rejected' payments, preserving full fee capacity.

            $studentUserId = (int)$payment['user_id'];
            if ($studentUserId > 0) {
                $cleanReason = rtrim($rejectionReason, '.');
                createNotification(
                    $pdo,
                    $studentUserId,
                    'Payment Rejected',
                    'Your online payment of ₱' . number_format((float)$payment['amount'], 2) . ' was rejected by the cashier. Reason: ' . $cleanReason . '. Please review your payment details and submit again.',
                    'danger'
                );
            }

            $pdo->commit();
            $_SESSION['flash_success'] = "Payment #{$payment['or_number']} was rejected. The applicant was notified of the reason.";
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['flash_error'] = $e->getMessage();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Reject payment failed: ' . $e->getMessage());
            $_SESSION['flash_error'] = 'An unexpected error occurred while rejecting the payment.';
        }

        $redirect = !empty($_POST['redirect_to']) ? $_POST['redirect_to'] : '../cashier/enrollment_queue';
        header('Location: ' . $redirect);
        exit;
        break;

    default:
        $_SESSION['flash_error'] = "Invalid payment action specified.";
        header("Location: ../cashier/enrollment_queue");
        exit;
    }
}
