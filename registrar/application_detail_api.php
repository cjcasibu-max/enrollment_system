<?php
/**
 * Application Detail API
 *
 * Returns a single applicant's full profile + document list as JSON.
 * This replaces the previous pattern of embedding the full $detailPayload
 * as an HTML data-attribute on every table row, which exposed all
 * applicants' PII and server-side file_path values in the page source.
 *
 * Role-gated: registrar, admin only.
 * Method: GET
 * Param:  student_id (integer)
 */

require_once '../includes/auth_check.php';
checkRole(['registrar', 'admin']);

require_once '../config/database.php';

header('Content-Type: application/json');

// Only accept XMLHttpRequest fetch calls — reject direct browser navigation.
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
        SELECT
            s.id,
            s.user_id,
            s.first_name,
            s.middle_name,
            s.last_name,
            s.suffix,
            s.birthdate,
            s.age,
            s.place_of_birth,
            s.gender,
            s.civil_status,
            s.nationality,
            s.religion,
            s.contact_number,
            s.address_street,
            s.address_barangay,
            s.address_city,
            s.address_province,
            s.address_zip_code,
            s.guardian_name,
            s.guardian_relationship,
            s.guardian_contact_number,
            s.guardian_address,
            s.shs_track_strand,
            s.shs_name,
            s.shs_type,
            s.year_graduated,
            s.general_average,
            s.program_applying_for,
            s.applicant_type,
            s.year_level,
            s.application_status,
            s.enrollment_status,
            s.ack_submit_without_docs,
            s.created_at,
            u.username,
            u.email
        FROM students s
        JOIN users u ON u.id = s.user_id
        WHERE s.id = :student_id
        LIMIT 1
    ");
    $stmt->execute(['student_id' => $studentId]);
    $app = $stmt->fetch();

    if (!$app) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Applicant not found.']);
        exit;
    }

    // Fetch documents — return file_url but NOT the raw server-side file_path.
    $docStmt = $pdo->prepare(
        "SELECT id, student_id, document_type, original_filename, status, rejection_reason, uploaded_at AS created_at, uploaded_at
         FROM documents
         WHERE student_id = :student_id
         ORDER BY id ASC"
    );
    $docStmt->execute(['student_id' => $studentId]);
    $rawDocs = $docStmt->fetchAll();

    $documents = array_map(static function (array $doc): array {
        // Build the viewer URL from the document id only — never expose file_path.
        $doc['file_url'] = '../actions/view_document?id=' . (int)$doc['id'];
        return $doc;
    }, $rawDocs);

    // Determine required document types (marriage cert only for married applicants).
    $requiredDocTypes = [
        'form_137', 'shs_diploma', 'good_moral',
        'birth_certificate', 'medical_clearance', 'id_photo',
    ];
    if ($app['civil_status'] === 'Married') {
        $requiredDocTypes[] = 'marriage_certificate';
    }

    // Compute verification counts.
    $verifiedCount = 0;
    $allVerified   = true;
    $uploadedTypes = [];
    foreach ($documents as $doc) {
        $uploadedTypes[$doc['document_type']] = $doc['status'];
        if ($doc['status'] === 'verified') {
            $verifiedCount++;
        }
    }
    foreach ($requiredDocTypes as $reqType) {
        if (!isset($uploadedTypes[$reqType]) || $uploadedTypes[$reqType] !== 'verified') {
            $allVerified = false;
        }
    }

    $payload = $app;
    $payload['documents']               = $documents;
    $payload['required_documents']      = $requiredDocTypes;
    $payload['verified_count']          = $verifiedCount;
    $payload['all_verified']            = $allVerified;
    $payload['ack_submit_without_docs'] = !empty($app['ack_submit_without_docs']) ? 1 : 0;
    $payload['follow_up_document_note'] = (!empty($app['ack_submit_without_docs']) && !$allVerified)
        ? 'Applicant chose to submit this application with follow-up documents to be provided later.'
        : '';

    echo json_encode(['success' => true, 'data' => $payload]);

} catch (\PDOException $e) {
    error_log('Application detail API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An internal error occurred.']);
}
exit;
