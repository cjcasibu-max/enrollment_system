<?php
/**
 * Secure Payment Proof Viewer
 * Verifies authentication and role permissions before streaming payment proof files.
 */

require_once '../includes/auth_check.php';
require_once '../includes/document_storage.php';

// Access is restricted to authenticated users only
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    http_response_code(403);
    die("Access denied: You must be logged in to view payment proofs.");
}

require_once '../config/database.php';
global $pdo;

if (!($pdo instanceof PDO)) {
    http_response_code(500);
    die('Database connection is not initialized.');
}

$paymentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($paymentId <= 0) {
    http_response_code(400);
    die("Bad request: Missing or invalid payment ID.");
}

try {
    $stmt = $pdo->prepare("
        SELECT p.id, p.proof_file, p.payment_reference, s.user_id 
        FROM payments p
        JOIN students s ON p.student_id = s.id
        WHERE p.id = :id
        LIMIT 1
    ");
    $stmt->execute(['id' => $paymentId]);
    $payment = $stmt->fetch();

    if (!$payment || empty($payment['proof_file'])) {
        http_response_code(404);
        die("Payment proof record not found.");
    }

    $currentUserRole = $_SESSION['role'];
    $currentUserId = (int)$_SESSION['user_id'];

    // Permission Guard:
    // - Admins, Cashiers, and Registrars can view any student's payment proof.
    // - Students and Enrollees can ONLY view their own payment proof.
    $isStaff = in_array($currentUserRole, ['admin', 'cashier', 'registrar'], true);
    $isOwner = ((int)$payment['user_id'] === $currentUserId);

    if (!$isStaff && !$isOwner) {
        http_response_code(403);
        die("Access denied: You do not have permission to view this payment proof.");
    }

    $relativePath = (string)$payment['proof_file'];
    $proofsBaseDir = realpath(__DIR__ . '/../private_uploads/payment_proofs');
    if (!$proofsBaseDir) {
        http_response_code(500);
        die("Payment proof directory is not configured.");
    }
    $proofsBaseDirWithSep = rtrim($proofsBaseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

    $projectRoot = realpath(__DIR__ . '/..');
    $fullPath = realpath($projectRoot . '/' . ltrim($relativePath, '/\\'));

    // Strict boundary check: file must exist and reside inside private_uploads/payment_proofs/
    if (!$fullPath || !file_exists($fullPath) || !str_starts_with($fullPath, $proofsBaseDirWithSep)) {
        http_response_code(404);
        die("Payment proof file does not exist on disk.");
    }

    // Determine MIME type securely
    $mimeType = 'application/octet-stream';
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->file($fullPath);
        if ($detectedMime) {
            $mimeType = $detectedMime;
        }
    } elseif (function_exists('mime_content_type')) {
        $detectedMime = mime_content_type($fullPath);
        if ($detectedMime) {
            $mimeType = $detectedMime;
        }
    }

    // Clear output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }

    $filename = basename($fullPath);
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . filesize($fullPath));
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=3600');

    readfile($fullPath);
    exit;
} catch (Throwable $e) {
    error_log("View payment proof failed: " . $e->getMessage());
    http_response_code(500);
    die("An error occurred while loading the payment proof.");
}
