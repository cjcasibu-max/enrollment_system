<?php
/**
 * Transferee Credit Evaluation Actions
 * Handles previous subject credit evaluation, equivalent subject mapping, and status tracking.
 */

require_once '../includes/auth_check.php';
checkRole(['registrar', 'admin']);

require_once '../config/database.php';
require_once '../includes/notifications.php';
global $pdo;

$action = $_POST['action'] ?? '';

if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
    $_SESSION['flash_error'] = 'Invalid request token. Please try again.';
    $redirectStudentId = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
    header('Location: ../registrar/credit_evaluation' . ($redirectStudentId > 0 ? '?student_id=' . $redirectStudentId : ''));
    exit;
}

switch ($action) {
    case 'add_evaluation':
        $studentId = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
        $prevSchool = trim((string)($_POST['previous_school'] ?? ''));
        $prevCode = trim((string)($_POST['previous_subject_code'] ?? ''));
        $prevTitle = trim((string)($_POST['previous_subject_title'] ?? ''));
        $prevUnits = isset($_POST['previous_units']) && is_numeric($_POST['previous_units']) ? (float)$_POST['previous_units'] : 3.0;
        $prevGrade = trim((string)($_POST['previous_grade'] ?? ''));
        $equivSubjectId = !empty($_POST['equivalent_subject_id']) ? (int)$_POST['equivalent_subject_id'] : null;
        $status = in_array($_POST['status'] ?? '', ['pending', 'credited', 'rejected'], true) ? $_POST['status'] : 'pending';
        $remarks = trim((string)($_POST['remarks'] ?? ''));

        if ($studentId <= 0 || $prevCode === '' || $prevTitle === '') {
            $_SESSION['flash_error'] = 'Student, previous subject code, and subject title are required.';
            header("Location: ../registrar/credit_evaluation?student_id={$studentId}");
            exit;
        }

        try {
            // Verify student is transferee
            $stuStmt = $pdo->prepare("SELECT id, user_id, applicant_type FROM students WHERE id = :id LIMIT 1");
            $stuStmt->execute(['id' => $studentId]);
            $student = $stuStmt->fetch();

            if (!$student) {
                $_SESSION['flash_error'] = 'Student profile was not found.';
                header('Location: ../registrar/credit_evaluation');
                exit;
            }

            $evaluatorId = (int)$_SESSION['user_id'];

            $insStmt = $pdo->prepare("
                INSERT INTO transferee_evaluations (
                    student_id, previous_school, previous_subject_code, previous_subject_title,
                    previous_units, previous_grade, equivalent_subject_id, status, remarks,
                    evaluated_by, evaluated_at
                ) VALUES (
                    :student_id, :previous_school, :previous_subject_code, :previous_subject_title,
                    :previous_units, :previous_grade, :equivalent_subject_id, :status, :remarks,
                    :evaluated_by, NOW()
                )
            ");
            $insStmt->execute([
                'student_id' => $studentId,
                'previous_school' => $prevSchool !== '' ? $prevSchool : null,
                'previous_subject_code' => $prevCode,
                'previous_subject_title' => $prevTitle,
                'previous_units' => $prevUnits,
                'previous_grade' => $prevGrade !== '' ? $prevGrade : null,
                'equivalent_subject_id' => $equivSubjectId ?: null,
                'status' => $status,
                'remarks' => $remarks !== '' ? $remarks : null,
                'evaluated_by' => $evaluatorId,
            ]);

            // Notify student
            if (!empty($student['user_id'])) {
                $statusLabel = ucfirst($status);
                createNotification(
                    $pdo,
                    (int)$student['user_id'],
                    'Subject Credit Evaluation Updated',
                    "Your previous subject {$prevCode} ({$prevTitle}) has been marked as {$statusLabel} by the Registrar.",
                    $status === 'credited' ? 'success' : 'info'
                );
            }

            $_SESSION['flash_success'] = 'Transferee subject evaluation recorded successfully.';
            header("Location: ../registrar/credit_evaluation?student_id={$studentId}");
            exit;

        } catch (\Throwable $e) {
            error_log('Add evaluation error: ' . $e->getMessage());
            $_SESSION['flash_error'] = 'Database error while saving subject credit evaluation.';
            header("Location: ../registrar/credit_evaluation?student_id={$studentId}");
            exit;
        }

    case 'update_evaluation':
        $evalId = isset($_POST['evaluation_id']) ? (int)$_POST['evaluation_id'] : 0;
        $studentId = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
        $status = in_array($_POST['status'] ?? '', ['pending', 'credited', 'rejected'], true) ? $_POST['status'] : 'pending';
        $equivSubjectId = !empty($_POST['equivalent_subject_id']) ? (int)$_POST['equivalent_subject_id'] : null;
        $remarks = trim((string)($_POST['remarks'] ?? ''));

        if ($evalId <= 0 || $studentId <= 0) {
            $_SESSION['flash_error'] = 'Invalid evaluation record.';
            header('Location: ../registrar/credit_evaluation');
            exit;
        }

        try {
            $updateStmt = $pdo->prepare("
                UPDATE transferee_evaluations
                SET status = :status,
                    equivalent_subject_id = :equiv_id,
                    remarks = :remarks,
                    evaluated_by = :eval_by,
                    evaluated_at = NOW()
                WHERE id = :id AND student_id = :sid
            ");
            $updateStmt->execute([
                'status' => $status,
                'equiv_id' => $equivSubjectId ?: null,
                'remarks' => $remarks !== '' ? $remarks : null,
                'eval_by' => (int)$_SESSION['user_id'],
                'id' => $evalId,
                'sid' => $studentId,
            ]);

            $_SESSION['flash_success'] = 'Evaluation status updated successfully.';
            header("Location: ../registrar/credit_evaluation?student_id={$studentId}");
            exit;

        } catch (\Throwable $e) {
            error_log('Update evaluation error: ' . $e->getMessage());
            $_SESSION['flash_error'] = 'Database error while updating evaluation.';
            header("Location: ../registrar/credit_evaluation?student_id={$studentId}");
            exit;
        }

    case 'delete_evaluation':
        $evalId = isset($_POST['evaluation_id']) ? (int)$_POST['evaluation_id'] : 0;
        $studentId = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;

        if ($evalId <= 0 || $studentId <= 0) {
            $_SESSION['flash_error'] = 'Invalid evaluation ID.';
            header('Location: ../registrar/credit_evaluation');
            exit;
        }

        try {
            $delStmt = $pdo->prepare("DELETE FROM transferee_evaluations WHERE id = :id AND student_id = :sid");
            $delStmt->execute(['id' => $evalId, 'sid' => $studentId]);

            $_SESSION['flash_success'] = 'Subject evaluation entry removed.';
            header("Location: ../registrar/credit_evaluation?student_id={$studentId}");
            exit;
        } catch (\Throwable $e) {
            error_log('Delete evaluation error: ' . $e->getMessage());
            $_SESSION['flash_error'] = 'Database error while deleting evaluation entry.';
            header("Location: ../registrar/credit_evaluation?student_id={$studentId}");
            exit;
        }

    default:
        $_SESSION['flash_error'] = 'Invalid action.';
        header('Location: ../registrar/credit_evaluation');
        exit;
}
