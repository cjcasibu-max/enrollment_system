<?php
/**
 * Secure Document Viewer
 * Verifies authentication and role permissions before streaming student documents.
 */

require_once '../includes/auth_check.php';
require_once '../includes/document_storage.php';

// Access is restricted to authenticated users only
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    http_response_code(403);
    die("Access denied: You must be logged in to view documents.");
}

require_once '../config/database.php';
global $pdo;

if (!($pdo instanceof PDO)) {
    http_response_code(500);
    die('Database connection is not initialized.');
}

$documentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($documentId <= 0) {
    http_response_code(400);
    die("Bad request: Missing or invalid document ID.");
}

try {
    $stmt = $pdo->prepare("
        SELECT d.id, d.file_path, d.original_filename, s.user_id 
        FROM documents d
        JOIN students s ON d.student_id = s.id
        WHERE d.id = :id
        LIMIT 1
    ");
    $stmt->execute(['id' => $documentId]);
    $doc = $stmt->fetch();

    if (!$doc) {
        http_response_code(404);
        die("Document record not found.");
    }

    $currentUserRole = $_SESSION['role'];
    $currentUserId = (int)$_SESSION['user_id'];

    // Permission Guard:
    // - Admins and Registrars can view any student's document.
    // - Students and Enrollees can ONLY view their own document.
    $isStaff = in_array($currentUserRole, ['admin', 'registrar']);
    $isOwner = ((int)$doc['user_id'] === $currentUserId);

    if (!$isStaff && !$isOwner) {
        http_response_code(403);
        die("Access denied: You do not have permission to view this document.");
    }

    $filePath = $doc['file_path'];
    $allowedDocumentRoots = array_values(array_unique(array_filter(
        array_map('realpath', getDocumentStorageRoots()),
        fn($path) => $path !== false
    )));
    if (empty($allowedDocumentRoots)) {
        http_response_code(500);
        die("Document storage directories are not configured on the server.");
    }

    $resolvedPath = realpath($filePath);
    if ($resolvedPath === false) {
        // Try the absolute path as stored in DB first (supports new nested structure)
        foreach ($allowedDocumentRoots as $root) {
            // New structure: path is absolute — try it directly
            if (file_exists($filePath)) {
                $resolvedPath = realpath($filePath);
                break;
            }
            // Legacy flat fallback: file stored with basename only
            $candidate = $root . DIRECTORY_SEPARATOR . basename($filePath);
            if (file_exists($candidate)) {
                $resolvedPath = realpath($candidate);
                break;
            }
        }
    }

    if ($resolvedPath === false || !is_file($resolvedPath)) {
        http_response_code(404);
        die("The physical file is not found on the server.");
    }

    $allowed = false;
    foreach ($allowedDocumentRoots as $root) {
        if ($root !== false && str_starts_with($resolvedPath, $root . DIRECTORY_SEPARATOR)) {
            $allowed = true;
            break;
        }
    }

    if (!$allowed) {
        http_response_code(403);
        die("Access denied: document path is not within the approved storage location.");
    }


    $filePath = $resolvedPath;

    // Determine clean content type
    $mimeType = '';
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = (string)$finfo->file($filePath);
    } elseif (function_exists('mime_content_type')) {
        $mimeType = (string)mime_content_type($filePath);
    }

    if (!$mimeType) {
        $mimeType = 'application/octet-stream';
    }

    // Output headers for inline file streaming
    $safeFilename = preg_replace('/[^\x20-\x7E]/', '', (string)($doc['original_filename'] ?? 'document'));
    $safeFilename = str_replace(['"', '\\', "\r", "\n"], '', $safeFilename);
    if ($safeFilename === '') {
        $safeFilename = 'document';
    }
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: inline; filename="' . $safeFilename . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: private, max-age=3600');
    header('Pragma: public');

    // Clean buffer to prevent output contamination
    if (ob_get_level()) {
        ob_end_clean();
    }

    readfile($filePath);
    exit;

} catch (\Throwable $e) {
    error_log("Secure file view exception: " . $e->getMessage());
    http_response_code(500);
    die("Internal server error: Unable to load document.");
}
