<?php
/**
 * Secure Avatar / Profile Picture Viewer
 * Serves user profile photos with caching and authentication checks.
 */

require_once '../includes/auth_check.php';
require_once '../config/database.php';

// Access is restricted to authenticated users
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Access denied');
}

$targetUserId = isset($_GET['uid']) ? (int)$_GET['uid'] : (int)$_SESSION['user_id'];
if ($targetUserId <= 0) {
    $targetUserId = (int)$_SESSION['user_id'];
}

try {
    $stmt = $pdo->prepare("SELECT profile_picture FROM users WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $targetUserId]);
    $userRow = $stmt->fetch();

    $photoPath = $userRow['profile_picture'] ?? null;
    $fullPath = null;

    if (!empty($photoPath)) {
        // Resolve path safely inside uploads/profile_pictures
        $baseDir = realpath(__DIR__ . '/../uploads/profile_pictures');
        $resolved = realpath(__DIR__ . '/../' . ltrim($photoPath, '/'));
        if (!$resolved) {
            $resolved = realpath($photoPath);
        }

        if ($resolved && $baseDir && str_starts_with($resolved, $baseDir) && file_exists($resolved)) {
            $fullPath = $resolved;
        }
    }

    if ($fullPath) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $fullPath);
        finfo_close($finfo);

        $allowedMimes = [
            'image/jpeg' => 'image/jpeg',
            'image/jpg'  => 'image/jpeg',
            'image/png'  => 'image/png',
            'image/webp' => 'image/webp',
        ];

        if (isset($allowedMimes[$mime])) {
            $mtime = filemtime($fullPath);
            $etag = '"' . md5($fullPath . $mtime) . '"';

            header('Content-Type: ' . $allowedMimes[$mime]);
            header('Content-Length: ' . filesize($fullPath));
            header('Cache-Control: private, max-age=86400');
            header('ETag: ' . $etag);

            if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
                http_response_code(304);
                exit;
            }

            readfile($fullPath);
            exit;
        }
    }
} catch (\Throwable $e) {
    error_log("view_avatar error: " . $e->getMessage());
}

// Fallback: 404 so caller can show default initials or icon
http_response_code(404);
exit('Avatar not found');
