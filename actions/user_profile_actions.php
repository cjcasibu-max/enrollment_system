<?php
/**
 * User Profile Actions Handler
 * Handles profile photo upload, photo removal, and generic user profile updates for all roles.
 */

require_once '../includes/auth_check.php';
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    http_response_code(403);
    exit('Access denied.');
}

$userId = (int)$_SESSION['user_id'];
$userRole = $_SESSION['role'];

/**
 * Helper to ensure redirects only use safe, relative web URLs and never filesystem paths.
 */
function sanitizeProfileRedirectUrl(?string $url, string $userRole): string {
    $default = "../{$userRole}/my_profile";
    if (empty($url)) {
        return $default;
    }
    $url = trim($url);
    // Reject Windows filesystem paths or backslashes
    if (str_contains($url, '\\') || preg_match('/^[a-zA-Z]:/', $url)) {
        return $default;
    }
    // Reject external protocols (http:, https:, javascript:, etc.)
    if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:/', $url)) {
        return $default;
    }
    // Reject protocol-relative URLs
    if (str_starts_with($url, '//')) {
        return $default;
    }
    // Strip direct .php extension if present
    $url = preg_replace('/\.php(?=[\?#]|$)/i', '', $url);
    return $url;
}

// CSRF validation
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCsrfToken($_POST['csrf_token'] ?? null)) {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Security token expired. Please refresh the page.']);
        exit;
    }
    $_SESSION['flash_error'] = 'Security validation failed. Please try again.';
    $redirectBack = sanitizeProfileRedirectUrl($_POST['redirect_url'] ?? null, $userRole);
    header("Location: {$redirectBack}");
    exit;
}

$action = trim($_POST['action'] ?? '');
$redirectBack = sanitizeProfileRedirectUrl($_POST['redirect_url'] ?? null, $userRole);

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') 
       || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'));

if ($action === 'upload_photo') {
    try {
        if (!isset($_FILES['profile_photo']) || $_FILES['profile_photo']['error'] === UPLOAD_ERR_NO_FILE) {
            throw new \RuntimeException('No photo was selected for upload.');
        }

        $file = $_FILES['profile_photo'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Upload failed with server error code: ' . $file['error']);
        }

        // Max 2 MB (2,097,152 bytes)
        $maxBytes = 2 * 1024 * 1024;
        if ($file['size'] > $maxBytes) {
            throw new \RuntimeException('Profile photo must not exceed 2 MB. Please select a smaller image.');
        }

        // Verify real MIME type on server
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $allowedMimes = [
            'image/jpeg' => 'jpg',
            'image/jpg'  => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
        ];

        if (!isset($allowedMimes[$mime])) {
            throw new \RuntimeException('Invalid image format. Only JPG, PNG, and WEBP files are allowed.');
        }

        // Double check image validity using getimagesize
        $imgInfo = @getimagesize($file['tmp_name']);
        if (!$imgInfo) {
            throw new \RuntimeException('The uploaded file is not a valid image.');
        }

        $extension = $allowedMimes[$mime];
        $uploadDir = realpath(__DIR__ . '/../uploads/profile_pictures');
        if (!$uploadDir || !is_dir($uploadDir)) {
            $created = @mkdir(__DIR__ . '/../uploads/profile_pictures', 0755, true);
            $uploadDir = realpath(__DIR__ . '/../uploads/profile_pictures');
            if (!$uploadDir) {
                throw new \RuntimeException('Upload directory could not be created on server.');
            }
        }

        // Generate safe unique random filename (never use original filename)
        $randomHex = bin2hex(random_bytes(10));
        $newFilename = "avatar_{$userId}_" . time() . "_{$randomHex}.{$extension}";
        $destination = $uploadDir . DIRECTORY_SEPARATOR . $newFilename;
        $dbPath = "uploads/profile_pictures/{$newFilename}";

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new \RuntimeException('Failed to save uploaded photo to storage.');
        }

        // Fetch old photo to delete
        $oldStmt = $pdo->prepare("SELECT profile_picture FROM users WHERE id = :id LIMIT 1");
        $oldStmt->execute(['id' => $userId]);
        $oldPhoto = $oldStmt->fetchColumn();

        if (!empty($oldPhoto)) {
            $oldResolved = realpath(__DIR__ . '/../' . ltrim($oldPhoto, '/'));
            if ($oldResolved && str_starts_with($oldResolved, $uploadDir) && file_exists($oldResolved)) {
                @unlink($oldResolved);
            }
        }

        // Update database
        $upStmt = $pdo->prepare("UPDATE users SET profile_picture = :photo WHERE id = :id");
        $upStmt->execute(['photo' => $dbPath, 'id' => $userId]);

        // Update session
        $_SESSION['profile_picture'] = $dbPath;
        $_SESSION['avatar_version'] = time();

        $avatarUrl = "../actions/view_avatar?uid={$userId}&v=" . time();

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'message' => 'Profile photo updated successfully.',
                'avatar_url' => $avatarUrl,
            ]);
            exit;
        }

        $_SESSION['flash_success'] = 'Profile photo updated successfully.';
        header("Location: {$redirectBack}");
        exit;

    } catch (\Throwable $e) {
        error_log("Upload profile photo error: " . $e->getMessage());
        if ($isAjax) {
            header('Content-Type: application/json');
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
        $_SESSION['flash_error'] = $e->getMessage();
        header("Location: {$redirectBack}");
        exit;
    }
} elseif ($action === 'remove_photo') {
    try {
        $uploadDir = realpath(__DIR__ . '/../uploads/profile_pictures');

        $oldStmt = $pdo->prepare("SELECT profile_picture FROM users WHERE id = :id LIMIT 1");
        $oldStmt->execute(['id' => $userId]);
        $oldPhoto = $oldStmt->fetchColumn();

        if (!empty($oldPhoto) && $uploadDir) {
            $oldResolved = realpath(__DIR__ . '/../' . ltrim($oldPhoto, '/'));
            if ($oldResolved && str_starts_with($oldResolved, $uploadDir) && file_exists($oldResolved)) {
                @unlink($oldResolved);
            }
        }

        $upStmt = $pdo->prepare("UPDATE users SET profile_picture = NULL WHERE id = :id");
        $upStmt->execute(['id' => $userId]);

        unset($_SESSION['profile_picture']);
        $_SESSION['avatar_version'] = time();

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'message' => 'Profile photo removed. Restored to default avatar.',
            ]);
            exit;
        }

        $_SESSION['flash_success'] = 'Profile photo removed.';
        header("Location: {$redirectBack}");
        exit;

    } catch (\Throwable $e) {
        error_log("Remove profile photo error: " . $e->getMessage());
        if ($isAjax) {
            header('Content-Type: application/json');
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Failed to remove photo.']);
            exit;
        }
        $_SESSION['flash_error'] = 'Failed to remove profile photo.';
        header("Location: {$redirectBack}");
        exit;
    }
} elseif ($action === 'update_staff_profile') {
    try {
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');

        if ($firstName === '' || $lastName === '') {
            throw new \RuntimeException('First name and Last name are required.');
        }

        if (!preg_match("/^[a-zA-Z\s]+$/", $firstName) || !preg_match("/^[a-zA-Z\s]+$/", $lastName)) {
            throw new \RuntimeException('First name and Last name must contain only letters and spaces.');
        }

        $updateStmt = $pdo->prepare("UPDATE users SET first_name = :first_name, last_name = :last_name WHERE id = :id");
        $updateStmt->execute([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'id' => $userId,
        ]);

        $_SESSION['flash_success'] = 'Profile information updated successfully.';
        header("Location: {$redirectBack}");
        exit;
    } catch (\Throwable $e) {
        error_log("Update staff profile error: " . $e->getMessage());
        $_SESSION['flash_error'] = $e->getMessage();
        header("Location: {$redirectBack}");
        exit;
    }
}

// Default fallback
header("Location: {$redirectBack}");
exit;
