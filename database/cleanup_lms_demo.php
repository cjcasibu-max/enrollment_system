<?php
/**
 * Cleanup / Reset Script for LMS Demo Data
 * 
 * Safely removes ONLY demo data tagged with '@demo.com' or demo section identifiers.
 * Leaves all existing real records and pre-existing accounts completely untouched.
 * 
 * Guard: CLI or Localhost only.
 */

$isCli = (PHP_SAPI === 'cli');
$isLocalhost = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', 'localhost'], true);

if (!$isCli && !$isLocalhost) {
    http_response_code(403);
    die("Access denied: This script can only be run via CLI or localhost.\n");
}

require_once __DIR__ . '/../config/database.php';

echo "=== LMS DEMO DATA CLEANUP ===\n";

try {
    $pdo->beginTransaction();

    // 1. Identify demo user IDs
    $demoUsersStmt = $pdo->query("SELECT id FROM users WHERE email LIKE '%@demo.com' OR username LIKE 'demo_%'");
    $demoUserIds = $demoUsersStmt->fetchAll(PDO::FETCH_COLUMN);

    // 2. Identify demo student IDs
    $demoStudentIds = [];
    if (!empty($demoUserIds)) {
        $inUsers = implode(',', array_map('intval', $demoUserIds));
        $demoStudentsStmt = $pdo->query("SELECT id FROM students WHERE user_id IN ($inUsers)");
        $demoStudentIds = $demoStudentsStmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // 3. Identify demo sections
    $demoSectionsStmt = $pdo->query("SELECT id FROM sections WHERE section_name IN ('BSMT-1A', 'BSMT-1B', 'BSMarE-1A')");
    $demoSectionIds = $demoSectionsStmt->fetchAll(PDO::FETCH_COLUMN);

    // 4. Identify demo section_subjects
    $demoSsIds = [];
    if (!empty($demoSectionIds)) {
        $inSections = implode(',', array_map('intval', $demoSectionIds));
        $demoSsStmt = $pdo->query("SELECT id FROM section_subjects WHERE section_id IN ($inSections)");
        $demoSsIds = $demoSsStmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // 5. Delete LMS activity data tied to demo students or demo section_subjects
    if (!empty($demoSsIds)) {
        $inSs = implode(',', array_map('intval', $demoSsIds));

        // Delete quiz responses and attempts
        $quizStmt = $pdo->query("SELECT id FROM lms_quizzes WHERE section_subject_id IN ($inSs)");
        $quizIds = $quizStmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($quizIds)) {
            $inQ = implode(',', array_map('intval', $quizIds));
            $pdo->exec("DELETE qr FROM lms_quiz_responses qr JOIN lms_quiz_attempts qa ON qa.id = qr.attempt_id WHERE qa.quiz_id IN ($inQ)");
            $pdo->exec("DELETE FROM lms_quiz_attempts WHERE quiz_id IN ($inQ)");
            $pdo->exec("DELETE FROM lms_quiz_choices WHERE question_id IN (SELECT id FROM lms_quiz_questions WHERE quiz_id IN ($inQ))");
            $pdo->exec("DELETE FROM lms_quiz_questions WHERE quiz_id IN ($inQ)");
            $pdo->exec("DELETE FROM lms_quizzes WHERE id IN ($inQ)");
        }

        // Delete assignment submissions & assignments
        $assignStmt = $pdo->query("SELECT id FROM lms_assignments WHERE section_subject_id IN ($inSs)");
        $assignIds = $assignStmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($assignIds)) {
            $inA = implode(',', array_map('intval', $assignIds));
            $pdo->exec("DELETE FROM lms_assignment_submissions WHERE assignment_id IN ($inA)");
            $pdo->exec("DELETE FROM lms_assignment_files WHERE assignment_id IN ($inA)");
            $pdo->exec("DELETE FROM lms_assignments WHERE id IN ($inA)");
        }

        // Delete materials
        $pdo->exec("DELETE FROM lms_materials WHERE section_subject_id IN ($inSs)");

        // Delete announcements
        $pdo->exec("DELETE FROM lms_announcements WHERE section_subject_id IN ($inSs)");

        // Delete modules & lessons if any
        $pdo->exec("DELETE l FROM lms_lessons l JOIN lms_modules m ON m.id = l.module_id WHERE m.section_subject_id IN ($inSs)");
        $pdo->exec("DELETE FROM lms_modules WHERE section_subject_id IN ($inSs)");

        // Delete section_subjects
        $pdo->exec("DELETE FROM section_subjects WHERE id IN ($inSs)");
    }

    // 6. Delete student enrollments, payments, assessments
    if (!empty($demoStudentIds)) {
        $inSt = implode(',', array_map('intval', $demoStudentIds));
        $pdo->exec("DELETE FROM payment_allocations WHERE payment_id IN (SELECT id FROM payments WHERE student_id IN ($inSt))");
        $pdo->exec("DELETE FROM assessment_items WHERE assessment_id IN (SELECT id FROM assessments WHERE student_id IN ($inSt))");
        $pdo->exec("DELETE FROM enrollments WHERE student_id IN ($inSt)");
        $pdo->exec("DELETE FROM payments WHERE student_id IN ($inSt)");
        $pdo->exec("DELETE FROM assessments WHERE student_id IN ($inSt)");
        $pdo->exec("DELETE FROM students WHERE id IN ($inSt)");
    }

    // 7. Delete sections
    if (!empty($demoSectionIds)) {
        $inSec = implode(',', array_map('intval', $demoSectionIds));
        $pdo->exec("DELETE FROM sections WHERE id IN ($inSec)");
    }

    // 8. Delete demo users
    if (!empty($demoUserIds)) {
        $inU = implode(',', array_map('intval', $demoUserIds));
        $pdo->exec("DELETE FROM users WHERE id IN ($inU)");
    }

    $pdo->commit();
    echo "[OK] Successfully cleaned up all demo records.\n";
    echo "  - Removed " . count($demoUserIds) . " demo user(s)\n";
    echo "  - Removed " . count($demoStudentIds) . " demo student(s)\n";
    echo "  - Removed " . count($demoSectionIds) . " demo section(s)\n";
    echo "  - Removed " . count($demoSsIds) . " demo timetable/subject assignment(s)\n";

} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "[ERROR] Cleanup failed: " . $e->getMessage() . "\n";
    exit(1);
}
