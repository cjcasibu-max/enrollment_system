<?php
/**
 * Student Detail API
 *
 * Returns a single student's full profile as JSON for the view modal.
 * This replaces the previous pattern of embedding all students as a JS
 * constant (const allStudents = [...]) in the page source, which exposed
 * every student's PII, guardian data, and SHS records via DevTools.
 *
 * Role-gated: registrar, admin only.
 * Method: GET
 * Param:  student_id (integer)
 */

require_once '../includes/auth_check.php';
checkRole(['registrar', 'admin']);

require_once '../config/database.php';

header('Content-Type: application/json');

// Reject direct browser navigation — only serve XHR/fetch calls.
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden.']);
    exit;
}

$studentId = (int)($_GET['student_id'] ?? 0);
if ($studentId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid student ID.']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT s.id, s.first_name, s.middle_name, s.last_name, s.suffix,
               s.contact_number,
               s.gender, s.birthdate, s.place_of_birth, s.civil_status, s.nationality, s.religion,
               s.address_street, s.address_barangay, s.address_city,
               s.address_province, s.address_zip_code,
               s.guardian_name, s.guardian_relationship, s.guardian_contact_number,
               s.shs_name, s.shs_track_strand, s.shs_type, s.year_graduated, s.general_average,
               s.program_applying_for, s.program_code, s.applicant_type,
               s.year_level, s.enrollment_status, s.application_status,
               s.payment_status, s.created_at,
               u.username, u.email
        FROM students s
        JOIN users u ON s.user_id = u.id
        WHERE s.id = :student_id
        LIMIT 1
    ");
    $stmt->execute(['student_id' => $studentId]);
    $student = $stmt->fetch();

    if (!$student) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Student not found.']);
        exit;
    }

    echo json_encode(['success' => true, 'data' => $student]);

} catch (\PDOException $e) {
    error_log('Student detail API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An internal error occurred.']);
}
exit;
