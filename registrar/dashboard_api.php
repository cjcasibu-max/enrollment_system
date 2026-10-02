<?php
/**
 * Dashboard Metrics API
 * Returns live registrar metrics and flash notifications as JSON
 * for realtime dashboard updates.
 */

require_once '../includes/auth_check.php';
checkRole(['registrar', 'admin']);

require_once '../config/database.php';

header('Content-Type: application/json');

$metrics = [
    'new_enrollments' => 0,
    'new_applications' => 0,
    'pending_verifications' => 0,
    'active_courses' => 0,
];

try {
    $stmt = $pdo->query("
        SELECT
            (SELECT COUNT(*)
             FROM enrollments e
             INNER JOIN academic_terms t ON t.id = e.academic_term_id
             WHERE t.is_active = 1 AND e.status = 'pending') AS new_enrollments,
            (SELECT COUNT(*)
             FROM students s
             INNER JOIN academic_terms t ON t.id = s.academic_term_id
             WHERE t.is_active = 1 AND s.application_status IN ('pending', 'under_review')) AS new_applications,
            (SELECT COUNT(*)
             FROM documents d
             INNER JOIN students s ON s.id = d.student_id
             INNER JOIN academic_terms t ON t.id = s.academic_term_id
             WHERE t.is_active = 1 AND d.status = 'pending') AS pending_verifications,
            (SELECT COUNT(DISTINCT sec.course_id)
             FROM sections sec
             INNER JOIN academic_terms t ON t.id = sec.academic_term_id
             WHERE t.is_active = 1) AS active_courses
    ");
    $row = $stmt->fetch();
    if ($row) {
        foreach ($metrics as $key => $value) {
            $metrics[$key] = isset($row[$key]) ? (int)$row[$key] : 0;
        }
    }
} catch (PDOException $e) {
    error_log('Dashboard API metrics failed: ' . $e->getMessage());
}

$flash = [
    'success' => $_SESSION['flash_success'] ?? null,
    'error' => $_SESSION['flash_error'] ?? null,
];

if ($flash['success'] !== null || $flash['error'] !== null) {
    unset($_SESSION['flash_success'], $_SESSION['flash_error']);
}

session_write_close();

echo json_encode([
    'metrics' => $metrics,
    'flash' => $flash,
]);
exit;
