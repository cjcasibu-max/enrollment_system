<?php
/**
 * Test Teacher LMS Consolidated Submissions View (Phase 9.1)
 *
 * Verifies:
 *   1. Consolidated retrieval of both assignment submissions and quiz attempts.
 *   2. Strict subject isolation: submissions for unassigned subjects are excluded.
 *   3. Accurate status computation (pending vs. graded/completed).
 *   4. Direct grading of assignment submissions and immediate score/feedback update.
 *   5. Idempotent execution within rolled-back transaction.
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/lms_access.php';

echo "Testing Teacher LMS Consolidated Submissions flow...\n";

$pdo->beginTransaction();

try {
    // 1. Setup mock term, courses, sections, subjects
    $pdo->exec("INSERT INTO academic_terms (school_year, semester, is_active, created_at) VALUES ('2026-2027', '1st Semester', 1, NOW())");
    $termId = (int)$pdo->lastInsertId();

    $pdo->exec("INSERT INTO courses (course_code, course_name, created_at) VALUES ('BSMT-SUBM', 'BS Marine Transportation Submissions Test', NOW())");
    $courseId = (int)$pdo->lastInsertId();

    // Teacher 1 (assigned to Subject 1)
    $pdo->exec("INSERT INTO users (username, email, password_hash, role, first_name, last_name, created_at) VALUES ('subm_teacher1', 't1_subm@example.com', 'hash', 'faculty', 'Capt', 'Nelson', NOW())");
    $teacherId1 = (int)$pdo->lastInsertId();

    // Teacher 2 (assigned to Subject 2)
    $pdo->exec("INSERT INTO users (username, email, password_hash, role, first_name, last_name, created_at) VALUES ('subm_teacher2', 't2_subm@example.com', 'hash', 'faculty', 'Capt', 'Cook', NOW())");
    $teacherId2 = (int)$pdo->lastInsertId();

    // Sections
    $pdo->exec("INSERT INTO sections (section_name, course_id, academic_term_id, teacher_id, created_at) VALUES ('BSMT 1-SUBM', $courseId, $termId, $teacherId1, NOW())");
    $sectionId1 = (int)$pdo->lastInsertId();

    $pdo->exec("INSERT INTO sections (section_name, course_id, academic_term_id, teacher_id, created_at) VALUES ('BSMT 2-SUBM', $courseId, $termId, $teacherId2, NOW())");
    $sectionId2 = (int)$pdo->lastInsertId();

    // Subjects
    $pdo->exec("INSERT INTO subjects (subject_code, subject_name, units, created_at) VALUES ('NAV-101', 'Celestial Navigation', 3, NOW())");
    $subjectId1 = (int)$pdo->lastInsertId();

    $pdo->exec("INSERT INTO subjects (subject_code, subject_name, units, created_at) VALUES ('SEAM-101', 'Seamanship 1', 3, NOW())");
    $subjectId2 = (int)$pdo->lastInsertId();

    // Section Subjects
    $pdo->exec("INSERT INTO section_subjects (section_id, subject_id, instructor_id, created_at) VALUES ($sectionId1, $subjectId1, $teacherId1, NOW())");
    $sectionSubjectId1 = (int)$pdo->lastInsertId();

    $pdo->exec("INSERT INTO section_subjects (section_id, subject_id, instructor_id, created_at) VALUES ($sectionId2, $subjectId2, $teacherId2, NOW())");
    $sectionSubjectId2 = (int)$pdo->lastInsertId();

    // Students
    $pdo->exec("INSERT INTO users (username, email, password_hash, role, first_name, last_name, created_at) VALUES ('cadet_subm1', 'cadet1_subm@example.com', 'hash', 'student', 'Ethan', 'Hunt', NOW())");
    $studentUser1 = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO students (user_id, academic_term_id, enrollment_status, created_at) VALUES ($studentUser1, $termId, 'enrolled', NOW())");
    $studentId1 = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO enrollments (student_id, section_id, academic_term_id, status, created_at) VALUES ($studentId1, $sectionId1, $termId, 'enrolled', NOW())");

    $pdo->exec("INSERT INTO users (username, email, password_hash, role, first_name, last_name, created_at) VALUES ('cadet_subm2', 'cadet2_subm@example.com', 'hash', 'student', 'James', 'Bond', NOW())");
    $studentUser2 = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO students (user_id, academic_term_id, enrollment_status, created_at) VALUES ($studentUser2, $termId, 'enrolled', NOW())");
    $studentId2 = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO enrollments (student_id, section_id, academic_term_id, status, created_at) VALUES ($studentId2, $sectionId2, $termId, 'enrolled', NOW())");

    echo "  [+] Mock environment initialized: Teacher 1 -> SS1 ($sectionSubjectId1), Teacher 2 -> SS2 ($sectionSubjectId2)\n";

    // 2. Create coursework items
    // Teacher 1 Assignment
    $pdo->exec("INSERT INTO lms_assignments (section_subject_id, assignment_type, title, instructions, max_score, is_published, created_at) VALUES ($sectionSubjectId1, 'assignment', 'Chart Plotting Lab 1', 'Plot bearings', 100.00, 1, NOW())");
    $assignmentId1 = (int)$pdo->lastInsertId();

    // Teacher 1 Quiz
    $pdo->exec("INSERT INTO lms_quizzes (section_subject_id, title, instructions, time_limit_minutes, allowed_attempts, passing_score, is_published, created_at) VALUES ($sectionSubjectId1, 'Colregs Rules Quiz', 'Answer all items', 30, 2, 75.00, 1, NOW())");
    $quizId1 = (int)$pdo->lastInsertId();

    // Teacher 2 Assignment
    $pdo->exec("INSERT INTO lms_assignments (section_subject_id, assignment_type, title, instructions, max_score, is_published, created_at) VALUES ($sectionSubjectId2, 'assignment', 'Knot Tying Exercise', 'Submit video', 50.00, 1, NOW())");
    $assignmentId2 = (int)$pdo->lastInsertId();

    // 3. Create Student Submissions
    // Student 1 submits Assignment 1 (Pending review)
    $pdo->exec("INSERT INTO lms_assignment_submissions (assignment_id, student_id, file_name, file_path, submitted_at, created_at) VALUES ($assignmentId1, $studentId1, 'chart1_hunt.pdf', 'private_uploads/lms_assignment_submissions/1/chart1.pdf', NOW(), NOW())");
    $asgnSubmId1 = (int)$pdo->lastInsertId();

    // Student 1 completes Quiz 1 (Auto-scored)
    $pdo->exec("INSERT INTO lms_quiz_attempts (quiz_id, student_id, attempt_number, status, started_at, deadline_at, submitted_at, score, total_points, created_at) VALUES ($quizId1, $studentId1, 1, 'submitted', NOW(), DATE_ADD(NOW(), INTERVAL 30 MINUTE), NOW(), 85.00, 100.00, NOW())");
    $quizAttemptId1 = (int)$pdo->lastInsertId();

    // Student 2 submits Assignment 2 (belonging to Teacher 2)
    $pdo->exec("INSERT INTO lms_assignment_submissions (assignment_id, student_id, file_name, file_path, submitted_at, created_at) VALUES ($assignmentId2, $studentId2, 'knots_bond.mp4', 'private_uploads/lms_assignment_submissions/2/knots.mp4', NOW(), NOW())");
    $asgnSubmId2 = (int)$pdo->lastInsertId();

    echo "  [+] Created coursework and submissions across Teacher 1 and Teacher 2 subjects\n";

    // 4. Verify Consolidated Submissions Query for Teacher 1
    $t1Subjects = fetchLmsTeacherSubjects($pdo, $teacherId1);
    $t1SsIds = array_column($t1Subjects, 'section_subject_id');

    if (empty($t1SsIds)) {
        throw new RuntimeException("Teacher 1 has no assigned subjects returned.");
    }

    $inPh = implode(',', array_fill(0, count($t1SsIds), '?'));
    $sql = "
        SELECT 'assignment' AS submission_kind, subm.id AS submission_id, a.title AS item_title,
               subm.score, subm.feedback, subm.graded_at,
               CASE WHEN subm.graded_at IS NOT NULL OR subm.score IS NOT NULL THEN 'graded' ELSE 'pending' END AS evaluation_status
        FROM lms_assignment_submissions subm
        JOIN lms_assignments a ON a.id = subm.assignment_id
        WHERE a.section_subject_id IN ($inPh)

        UNION ALL

        SELECT 'quiz' AS submission_kind, qa.id AS submission_id, q.title AS item_title,
               qa.score, qa.feedback, qa.submitted_at AS graded_at,
               'graded' AS evaluation_status
        FROM lms_quiz_attempts qa
        JOIN lms_quizzes q ON q.id = qa.quiz_id
        WHERE q.section_subject_id IN ($inPh) AND qa.status IN ('submitted', 'interrupted', 'timed_out')
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($t1SsIds, $t1SsIds));
    $t1Submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "  [+] Retrieved " . count($t1Submissions) . " consolidated submissions for Teacher 1\n";

    // Assert Teacher 1 sees their assignment submission
    $foundAsgn1 = false;
    $foundQuiz1 = false;
    foreach ($t1Submissions as $row) {
        if ($row['submission_kind'] === 'assignment' && (int)$row['submission_id'] === $asgnSubmId1) {
            $foundAsgn1 = true;
            if ($row['evaluation_status'] !== 'pending') {
                throw new RuntimeException("Assignment submission should be 'pending' before grading.");
            }
        }
        if ($row['submission_kind'] === 'quiz' && (int)$row['submission_id'] === $quizAttemptId1) {
            $foundQuiz1 = true;
            if ($row['evaluation_status'] !== 'graded') {
                throw new RuntimeException("Quiz attempt should be 'graded'.");
            }
        }
        // Assert Teacher 1 NEVER sees Teacher 2's submission (Strict subject boundary)
        if ($row['submission_kind'] === 'assignment' && (int)$row['submission_id'] === $asgnSubmId2) {
            throw new RuntimeException("Data leak! Teacher 1 can see submission for Teacher 2's subject.");
        }
    }

    if (!$foundAsgn1) {
        throw new RuntimeException("Teacher 1 failed to retrieve Student 1's assignment submission.");
    }
    if (!$foundQuiz1) {
        throw new RuntimeException("Teacher 1 failed to retrieve Student 1's quiz attempt.");
    }
    echo "  [+] Consolidated query verified: contains both assignment & quiz items, strict subject isolation confirmed.\n";

    // 5. Test Teacher 1 Grading the Pending Submission
    $gradeScore = 92.50;
    $gradeFeedback = 'Excellent chart plotting with accurate compass error corrections.';
    $upd = $pdo->prepare("UPDATE lms_assignment_submissions SET score = :score, feedback = :fb, graded_at = NOW() WHERE id = :id");
    $upd->execute(['score' => $gradeScore, 'fb' => $gradeFeedback, 'id' => $asgnSubmId1]);

    // Re-verify evaluation status is now 'graded'
    $ck = $pdo->prepare("SELECT score, feedback, graded_at FROM lms_assignment_submissions WHERE id = :id");
    $ck->execute(['id' => $asgnSubmId1]);
    $gradedRow = $ck->fetch(PDO::FETCH_ASSOC);

    if ((float)$gradedRow['score'] !== $gradeScore || $gradedRow['feedback'] !== $gradeFeedback || empty($gradedRow['graded_at'])) {
        throw new RuntimeException("Grading did not persist properly in lms_assignment_submissions.");
    }
    echo "  [+] Direct grading verified: score and feedback saved successfully.\n";

    echo "\n>>> ALL TEACHER CONSOLIDATED SUBMISSIONS TESTS PASSED SUCCESSFULLY! <<<\n";

} finally {
    $pdo->rollBack();
    echo "Transaction rolled back (database clean).\n";
}
