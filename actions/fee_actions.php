<?php
require_once '../includes/auth_check.php';
checkRole(['admin']);
require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['flash_error'] = 'Invalid request method.';
    header('Location: ../admin/fee_setup');
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
    $_SESSION['flash_error'] = "Security validation failed. Please try again.";
    header('Location: ../admin/fee_setup');
    exit;
}

$action = trim($_POST['action'] ?? '');
try {
    if ($action === 'toggle') {
        $feeId = (int)($_POST['fee_id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE fee_configurations SET is_active = IF(is_active = 1, 0, 1) WHERE id = :id');
        $stmt->execute(['id' => $feeId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Fee rule was not found.');
        }
        $_SESSION['flash_success'] = 'Fee rule status updated.';
    } elseif ($action === 'toggle_component') {
        $compId = (int)($_POST['component_id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE fee_components SET is_required = IF(is_required = 1, 0, 1) WHERE id = :id');
        $stmt->execute(['id' => $compId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Fee component was not found.');
        }
        $_SESSION['flash_success'] = 'Fee component requirement status updated.';
    } elseif ($action === 'toggle_payment_method') {
        $methodId = (int)($_POST['method_id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE payment_methods SET is_active = IF(is_active = 1, 0, 1) WHERE id = :id');
        $stmt->execute(['id' => $methodId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Payment method was not found.');
        }
        $_SESSION['flash_success'] = 'Payment method active status updated.';
    } elseif ($action === 'create_component') {
        $componentName = trim($_POST['component_name'] ?? '');
        $componentCode = strtoupper(trim($_POST['component_code'] ?? ''));
        $description = trim($_POST['description'] ?? '');
        $isRequired = !empty($_POST['is_required']) ? 1 : 0;
        if ($componentName === '' || $componentCode === '') {
            throw new RuntimeException('Fee component name and code are required.');
        }
        $stmt = $pdo->prepare('INSERT INTO fee_components (component_name, component_code, description, is_required, sort_order) VALUES (:component_name, :component_code, :description, :is_required, COALESCE((SELECT MAX(sort_order)+1 FROM fee_components fc), 1))');
        $stmt->execute(['component_name' => $componentName, 'component_code' => $componentCode, 'description' => $description !== '' ? $description : null, 'is_required' => $isRequired]);
        $_SESSION['flash_success'] = 'Fee component added.';
    } elseif ($action === 'create_payment_method') {
        $methodName = trim($_POST['method_name'] ?? '');
        $methodCode = strtolower(trim($_POST['method_code'] ?? ''));
        $description = trim($_POST['description'] ?? '');
        $requiresReference = !empty($_POST['requires_reference']) ? 1 : 0;
        if ($methodName === '' || $methodCode === '') {
            throw new RuntimeException('Payment method name and code are required.');
        }
        $stmt = $pdo->prepare('INSERT INTO payment_methods (method_name, method_code, description, requires_reference, is_active, sort_order) VALUES (:method_name, :method_code, :description, :requires_reference, 1, COALESCE((SELECT MAX(sort_order)+1 FROM payment_methods pm), 1))');
        $stmt->execute(['method_name' => $methodName, 'method_code' => $methodCode, 'description' => $description !== '' ? $description : null, 'requires_reference' => $requiresReference]);
        $_SESSION['flash_success'] = 'Payment method added.';
    } elseif ($action === 'create') {
        $termId = (int)($_POST['academic_term_id'] ?? 0);
        $feeCode = strtoupper(trim($_POST['fee_code'] ?? ''));
        $feeName = trim($_POST['fee_name'] ?? '');
        $program = trim($_POST['program_applying_for'] ?? '') ?: null;
        $yearLevel = trim($_POST['year_level'] ?? '') ?: null;
        $method = trim($_POST['calculation_method'] ?? 'fixed');
        $amount = (float)($_POST['amount'] ?? 0);
        $discountType = trim($_POST['discount_type'] ?? 'none');
        $discountValue = (float)($_POST['discount_value'] ?? 0);
        if ($termId <= 0 || $feeCode === '' || $feeName === '' || !in_array($method, ['fixed', 'per_unit'], true) || $amount <= 0) {
            throw new RuntimeException('Complete the fee rule fields with a valid positive amount.');
        }
        if (!in_array($discountType, ['none', 'fixed', 'percent'], true)) {
            throw new RuntimeException('Invalid discount selection.');
        }
        if ($discountType !== 'none' && $discountValue < 0) {
            throw new RuntimeException('Discount value cannot be negative.');
        }
        if ($discountType === 'percent' && $discountValue > 100) {
            throw new RuntimeException('Percent discount cannot exceed 100%.');
        }
        $programCode = null;
        if ($program === 'BSMarE' || $program === 'BSMT') {
            $programCode = $program;
        }
        $feeScope = 'all';
        if ($programCode && $yearLevel) {
            $feeScope = 'program_year';
        } elseif ($programCode) {
            $feeScope = 'program';
        } elseif ($yearLevel) {
            $feeScope = 'year_level';
        }
        $stmt = $pdo->prepare(
            'INSERT INTO fee_configurations (academic_term_id, program_applying_for, program_code, year_level, fee_scope, fee_code, fee_name, calculation_method, amount, discount_type, discount_value)
             VALUES (:term_id, :program, :program_code, :year_level, :fee_scope, :fee_code, :fee_name, :method, :amount, :discount_type, :discount_value)'
        );
        $stmt->execute([
            'term_id' => $termId,
            'program' => $program,
            'program_code' => $programCode,
            'year_level' => $yearLevel,
            'fee_scope' => $feeScope,
            'fee_code' => $feeCode,
            'fee_name' => $feeName,
            'method' => $method,
            'amount' => round($amount, 2),
            'discount_type' => $discountType,
            'discount_value' => round($discountValue, 2),
        ]);
        $_SESSION['flash_success'] = 'Fee rule created.';
    } else {
        throw new RuntimeException('Invalid fee action.');
    }
} catch (RuntimeException $e) {
    $_SESSION['flash_error'] = $e->getMessage();
} catch (Throwable $e) {
    error_log('Fee action failed: ' . $e->getMessage());
    $_SESSION['flash_error'] = 'An unexpected error occurred. Please try again.';
}

header('Location: ../admin/fee_setup');
exit;
