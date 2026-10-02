<?php
/**
 * User Detail API
 *
 * Returns a single user record (with optional student profile) as JSON
 * for prepopulating the admin edit modal.
 *
 * This replaces the previous pattern of embedding every user's birthdate,
 * address, and contact number as inline JSON in each row's onclick attribute.
 *
 * Role-gated: admin only.
 * Method: GET
 * Param:  user_id (integer)
 */

require_once '../includes/auth_check.php';
checkRole(['admin']);

require_once '../config/database.php';

header('Content-Type: application/json');

// Reject direct browser navigation.
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden.']);
    exit;
}

$userId = (int)($_GET['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid user ID.']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT u.id, u.username, u.email, u.role, u.is_active,
               s.first_name, s.last_name, s.birthdate, s.address_street,
               s.contact_number, s.enrollment_status
        FROM users u
        LEFT JOIN students s ON u.id = s.user_id
        WHERE u.id = :user_id
        LIMIT 1
    ");
    $stmt->execute(['user_id' => $userId]);
    $user = $stmt->fetch();

    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User not found.']);
        exit;
    }

    echo json_encode(['success' => true, 'data' => $user]);

} catch (\PDOException $e) {
    error_log('User detail API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An internal error occurred.']);
}
exit;
