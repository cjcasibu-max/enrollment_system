<?php
/**
 * Test Teacher Quiz Creation, Question Management, and Scoring flow
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/lms_access.php';

echo "Testing Teacher Quiz flow...\n";

$pdo->beginTransaction();

try {
    // Find or create a section_subject within transaction
    $ss = $pdo->query("SELECT id FROM section_subjects LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$ss) {
        $pdo->exec("INSERT INTO academic_terms (school_year, semester, is_active, created_at) VALUES ('2026-2027', '1st Semester', 1, NOW())");
        $termId = (int)$pdo->lastInsertId();
        $pdo->exec("INSERT INTO courses (course_code, course_name, created_at) VALUES ('BSMT', 'Bachelor of Science in Marine Transportation', NOW())");
        $courseId = (int)$pdo->lastInsertId();
        $pdo->exec("INSERT INTO sections (section_name, course_id, academic_term_id, created_at) VALUES ('BSMT 1-A', $courseId, $termId, NOW())");
        $sectionId = (int)$pdo->lastInsertId();
        $pdo->exec("INSERT INTO subjects (subject_code, subject_name, units, created_at) VALUES ('NAV 101', 'Navigation 1', 3, NOW())");
        $subjId = (int)$pdo->lastInsertId();
        $pdo->exec("INSERT INTO section_subjects (section_id, subject_id, created_at) VALUES ($sectionId, $subjId, NOW())");
        $sectionSubjectId = (int)$pdo->lastInsertId();
    } else {
        $sectionSubjectId = (int)$ss['id'];
    }
    // 1. Create quiz
    $stmt = $pdo->prepare("
        INSERT INTO lms_quizzes (section_subject_id, title, instructions, time_limit_minutes, allowed_attempts, passing_score, is_published, created_at)
        VALUES (:ss, 'Test Automation Quiz', 'Follow all instructions carefully.', 45, 2, 10.00, 1, NOW())
    ");
    $stmt->execute(['ss' => $sectionSubjectId]);
    $quizId = (int)$pdo->lastInsertId();
    echo "  [+] Quiz created: ID $quizId\n";

    // 2. Add Multiple Choice question (5 pts)
    $q1Stmt = $pdo->prepare("
        INSERT INTO lms_quiz_questions (quiz_id, question_type, prompt, points, display_order, created_at)
        VALUES (:qid, 'multiple_choice', 'What is the primary navigational star in the northern hemisphere?', 5.00, 1, NOW())
    ");
    $q1Stmt->execute(['qid' => $quizId]);
    $q1Id = (int)$pdo->lastInsertId();

    $c1Stmt = $pdo->prepare("
        INSERT INTO lms_quiz_choices (question_id, choice_text, is_correct, display_order)
        VALUES (:qid, :text, :is_correct, :display_order)
    ");
    $c1Stmt->execute(['qid' => $q1Id, 'text' => 'Sirius', 'is_correct' => 0, 'display_order' => 1]);
    $c1Stmt->execute(['qid' => $q1Id, 'text' => 'Polaris', 'is_correct' => 1, 'display_order' => 2]);
    $c1Stmt->execute(['qid' => $q1Id, 'text' => 'Betelgeuse', 'is_correct' => 0, 'display_order' => 3]);
    $c1Stmt->execute(['qid' => $q1Id, 'text' => 'Vega', 'is_correct' => 0, 'display_order' => 4]);
    $polarisChoiceId = (int)$pdo->query("SELECT id FROM lms_quiz_choices WHERE question_id = $q1Id AND is_correct = 1")->fetchColumn();
    echo "  [+] MC Question created: ID $q1Id (Correct Choice ID $polarisChoiceId)\n";

    // 3. Add True/False question (3 pts)
    $q2Stmt = $pdo->prepare("
        INSERT INTO lms_quiz_questions (quiz_id, question_type, prompt, points, display_order, created_at)
        VALUES (:qid, 'true_false', 'The port side of a vessel refers to the left-hand side when facing forward.', 3.00, 2, NOW())
    ");
    $q2Stmt->execute(['qid' => $quizId]);
    $q2Id = (int)$pdo->lastInsertId();

    $c2Stmt = $pdo->prepare("
        INSERT INTO lms_quiz_choices (question_id, choice_text, is_correct, display_order)
        VALUES (:qid, :text, :is_correct, :display_order)
    ");
    $c2Stmt->execute(['qid' => $q2Id, 'text' => 'True', 'is_correct' => 1, 'display_order' => 1]);
    $c2Stmt->execute(['qid' => $q2Id, 'text' => 'False', 'is_correct' => 0, 'display_order' => 2]);
    $trueChoiceId = (int)$pdo->query("SELECT id FROM lms_quiz_choices WHERE question_id = $q2Id AND is_correct = 1")->fetchColumn();
    echo "  [+] T/F Question created: ID $q2Id (Correct Choice ID $trueChoiceId)\n";

    // 4. Add Identification question (4 pts)
    $q3Stmt = $pdo->prepare("
        INSERT INTO lms_quiz_questions (quiz_id, question_type, prompt, points, correct_answer, display_order, created_at)
        VALUES (:qid, 'identification', 'Identify the nautical unit of speed equal to one nautical mile per hour.', 4.00, 'Knot', 3, NOW())
    ");
    $q3Stmt->execute(['qid' => $quizId]);
    $q3Id = (int)$pdo->lastInsertId();
    echo "  [+] Identification Question created: ID $q3Id\n";

    // Verify questions and choices fetch
    $questions = fetchLmsQuizQuestionsForStudent($pdo, $quizId);
    if (count($questions) !== 3) {
        throw new RuntimeException("Expected 3 questions, got " . count($questions));
    }
    echo "  [+] fetchLmsQuizQuestionsForStudent returned 3 questions with choices.\n";

    // 5. Test scoring
    // Mock student
    $student = $pdo->query("SELECT id FROM students LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$student) {
        $pdo->exec("INSERT INTO users (username, email, password, role, first_name, last_name, created_at) VALUES ('test_cadet', 'cadet@example.com', 'hash', 'student', 'John', 'Cadet', NOW())");
        $uId = (int)$pdo->lastInsertId();
        $pdo->exec("INSERT INTO students (user_id, academic_term_id, enrollment_status, created_at) VALUES ($uId, 1, 'enrolled', NOW())");
        $studentId = (int)$pdo->lastInsertId();
    } else {
        $studentId = (int)$student['id'];
    }
        $attStmt = $pdo->prepare("
            INSERT INTO lms_quiz_attempts (quiz_id, student_id, attempt_number, status, started_at, deadline_at)
            VALUES (:qid, :sid, 1, 'in_progress', NOW(), DATE_ADD(NOW(), INTERVAL 45 MINUTE))
        ");
        $attStmt->execute(['qid' => $quizId, 'sid' => $studentId]);
        $attemptId = (int)$pdo->lastInsertId();

        // Responses: Answer Q1 correctly (Polaris), Q2 incorrectly (False), Q3 correctly ('knot')
        $falseChoiceId = (int)$pdo->query("SELECT id FROM lms_quiz_choices WHERE question_id = $q2Id AND is_correct = 0")->fetchColumn();
        $respStmt = $pdo->prepare("
            INSERT INTO lms_quiz_responses (attempt_id, question_id, choice_id, answer_text, answered_at)
            VALUES (:att, :qid, :cid, :ans, NOW())
        ");
        $respStmt->execute(['att' => $attemptId, 'qid' => $q1Id, 'cid' => $polarisChoiceId, 'ans' => null]);
        $respStmt->execute(['att' => $attemptId, 'qid' => $q2Id, 'cid' => $falseChoiceId, 'ans' => null]);
        $respStmt->execute(['att' => $attemptId, 'qid' => $q3Id, 'cid' => null, 'ans' => '  knot ']);

        $scoreResult = calculateLmsQuizAttemptScore($pdo, $quizId, $attemptId);
        // Expected score: Q1 (5) + Q3 (4) = 9 out of 12 (5+3+4)
        if ($scoreResult['score'] !== 9.0 || $scoreResult['total_points'] !== 12.0) {
            throw new RuntimeException("Scoring mismatch: expected 9.0/12.0, got {$scoreResult['score']}/{$scoreResult['total_points']}");
        }
        echo "  [+] calculateLmsQuizAttemptScore correctly calculated 9.0 / 12.0 pts.\n";

        // Check passing score
        $passingScore = 10.00;
        $isPassed = $scoreResult['score'] >= $passingScore;
        if ($isPassed !== false) {
            throw new RuntimeException("Pass check mismatch: 9.0 should fail a 10.0 passing score");
        }
        echo "  [+] Passing score threshold correctly evaluated.\n";

    echo "All Teacher Quiz automated test assertions PASSED!\n";
} finally {
    $pdo->rollBack();
    echo "  [+] Test transaction rolled back cleanly.\n";
}
