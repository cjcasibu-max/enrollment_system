<?php
/**
 * Walk-in approval utilities (safe to include in CLI tests).
 * Contains business logic for registrar final approval without session/header side-effects.
 */

if (session_status() === PHP_SESSION_NONE) {
    // Do not start session here when included from CLI/test harness
}

require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/user_roles.php';

/**
 * Approve a paid walk-in student. Extracted from actions/walk_in_actions.php
 * so it can be called in tests/verifiers without HTTP/session guards.
 *
 * @param PDO $pdo
 * @param int $registrarId
 * @param int $studentId
 * @param string $notes
 * @throws RuntimeException
 */
function approvePaidWalkIn(PDO $pdo, int $registrarId, int $studentId, string $notes = ''): void
{
    if ($registrarId <= 0) {
        throw new RuntimeException('A valid registrar account is required.');
    }

    if ($studentId <= 0) {
        throw new RuntimeException('A valid student is required.');
    }

    $studentStmt = $pdo->prepare("SELECT id, user_id, enrollment_status, walk_in_notes FROM students WHERE id = :id LIMIT 1 FOR UPDATE");
    $studentStmt->execute(['id' => $studentId]);
    $student = $studentStmt->fetch();

    if (!$student) {
        throw new RuntimeException('Student record not found.');
    }

    if (($student['enrollment_status'] ?? '') !== 'paid') {
        throw new RuntimeException('Only paid walk-in students can be approved.');
    }

    $finalNotes = trim($notes);
    if ($finalNotes === '' && trim((string)($student['walk_in_notes'] ?? '')) !== '') {
        $finalNotes = trim((string)$student['walk_in_notes']);
    }

    $startedTransaction = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTransaction = true;
    }

    try {
        $updateStmt = $pdo->prepare(
            "UPDATE students
             SET enrollment_status = 'enrolled',
                 walk_in_validated_at = NOW(),
                 walk_in_validated_by = :validated_by,
                 walk_in_notes = :notes
             WHERE id = :id AND enrollment_status = 'paid'"
        );
        $updateStmt->execute([
            'validated_by' => $registrarId,
            'notes' => $finalNotes !== '' ? $finalNotes : null,
            'id' => $studentId,
        ]);

        if ($updateStmt->rowCount() !== 1) {
            throw new RuntimeException('The student could not be marked as enrolled.');
        }

        $enrollmentUpdateStmt = $pdo->prepare(
            "UPDATE enrollments
             SET status = 'enrolled'
             WHERE student_id = :student_id
               AND status IN ('pending', 'approved', 'section_chosen', 'enrolled')
             ORDER BY id DESC
             LIMIT 1"
        );
        $enrollmentUpdateStmt->execute(['student_id' => $studentId]);

        $studentUserId = (int)($student['user_id'] ?? 0);
        if ($studentUserId > 0) {
            createNotification(
                $pdo,
                $studentUserId,
                'Enrollment approved',
                'Your enrollment has been verified by the registrar and your status is now enrolled.',
                'success'
            );
            // Promote user role to student if applicable. Do NOT modify the
            // active registrar session by syncing the student's role here.
            try {
                promoteUserToStudent($pdo, $studentUserId);
            } catch (Throwable $e) {
                // Non-fatal for the approval flow; log for visibility
                error_log('Role promotion failed during walk-in approval: ' . $e->getMessage());
            }
        }

        if ($startedTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
