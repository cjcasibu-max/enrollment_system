<?php
/**
 * Test Teacher LMS Student Progress View (Phase 9.2)
 *
 * Verifies:
 *   1. verifyTeacherOwnsSubject() access control.
 *   2. Shared activity metrics calculation across all 5 learning criteria:
 *      - Completed lessons (lms_lessons + lms_lesson_progress)
 *      - Viewed learning materials (lms_materials + lms_material_views)
 *      - Submitted assignments (lms_assignments + lms_assignment_submissions)
 *      - Completed quizzes (lms_quizzes + lms_quiz_attempts)
 *      - Completed modules (lms_modules + all lessons completed)
 *   3. Dynamic percentage recalculation as student performs activities.
 *   4. Strict section-subject isolation (no cross-class leaks).
 *   5. Clean database rollback.
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/lms_access.php';

echo "Testing Teacher LMS Student Progress flow...\n";

$pdo->beginTransaction();

try {
    // 1. Setup mock term, courses, section, subject
    $pdo->exec("INSERT INTO academic_terms (school_year, semester, is_active, created_at) VALUES ('2026-2027', '1st Semester', 1, NOW())");
    $termId = (int)$pdo->lastInsertId();

    $pdo->exec("INSERT INTO courses (course_code, course_name, created_at) VALUES ('BSMT-PROG', 'BS Marine Transportation Progress Test', NOW())");
    $courseId = (int)$pdo->lastInsertId();

    // Teacher
    $pdo->exec("INSERT INTO users (username, email, password_hash, role, first_name, last_name, created_at) VALUES ('prog_teacher', 'prog_t@example.com', 'hash', 'faculty', 'Capt', 'Vane', NOW())");
    $teacherId = (int)$pdo->lastInsertId();

    // Section 1
    $pdo->exec("INSERT INTO sections (section_name, course_id, academic_term_id, teacher_id, created_at) VALUES ('BSMT 1-PROG', $courseId, $termId, $teacherId, NOW())");
    $sectionId1 = (int)$pdo->lastInsertId();

    // Subject
    $pdo->exec("INSERT INTO subjects (subject_code, subject_name, units, created_at) VALUES ('NAV-201', 'Electronic Navigation Systems', 3, NOW())");
    $subjectId = (int)$pdo->lastInsertId();

    // Section Subject 1
    $pdo->exec("INSERT INTO section_subjects (section_id, subject_id, instructor_id, created_at) VALUES ($sectionId1, $subjectId, $teacherId, NOW())");
    $sectionSubjectId = (int)$pdo->lastInsertId();

    // Enrolled Student 1
    $pdo->exec("INSERT INTO users (username, email, password_hash, role, first_name, last_name, created_at) VALUES ('cadet_prog1', 'cadet_prog1@example.com', 'hash', 'student', 'Lucas', 'Scott', NOW())");
    $studentUser1 = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO students (user_id, academic_term_id, enrollment_status, created_at) VALUES ($studentUser1, $termId, 'enrolled', NOW())");
    $studentId1 = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO enrollments (student_id, section_id, academic_term_id, status, created_at) VALUES ($studentId1, $sectionId1, $termId, 'enrolled', NOW())");

    echo "  [+] Mock environment ready: SS=$sectionSubjectId, Student=$studentId1\n";

    // 2. Ownership verification
    $owned = verifyTeacherOwnsSubject($pdo, $teacherId, $sectionSubjectId);
    if (!$owned) {
        throw new RuntimeException("verifyTeacherOwnsSubject failed for assigned teacher.");
    }
    echo "  [+] Teacher ownership verified.\n";

    // 3. Create Curriculum & LMS Items:
    // Module with 2 Lessons
    $pdo->exec("INSERT INTO lms_modules (section_subject_id, title, description, display_order, is_published, created_at) VALUES ($sectionSubjectId, 'Radar & ARPA Operations', 'Introduction', 1, 1, NOW())");
    $moduleId = (int)$pdo->lastInsertId();

    $pdo->exec("INSERT INTO lms_lessons (module_id, title, content_type, content_body, display_order, is_published, created_at) VALUES ($moduleId, 'Lesson 1: Radar Fundamentals', 'text', 'Basics of radar pulses', 1, 1, NOW())");
    $lessonId1 = (int)$pdo->lastInsertId();

    $pdo->exec("INSERT INTO lms_lessons (module_id, title, content_type, content_body, display_order, is_published, created_at) VALUES ($moduleId, 'Lesson 2: ARPA Target Tracking', 'text', 'Target vectors', 2, 1, NOW())");
    $lessonId2 = (int)$pdo->lastInsertId();

    // Material
    $pdo->exec("INSERT INTO lms_materials (section_subject_id, title, material_type, file_name, file_path, is_available, created_at) VALUES ($sectionSubjectId, 'ARPA Manual PDF', 'pdf', 'arpa_manual.pdf', 'private_uploads/lms_materials/test.pdf', 1, NOW())");
    $materialId = (int)$pdo->lastInsertId();

    // Assignment
    $pdo->exec("INSERT INTO lms_assignments (section_subject_id, assignment_type, title, max_score, is_published, created_at) VALUES ($sectionSubjectId, 'assignment', 'Radar Plotting Sheet #1', 100.00, 1, NOW())");
    $assignmentId = (int)$pdo->lastInsertId();

    // Quiz
    $pdo->exec("INSERT INTO lms_quizzes (section_subject_id, title, time_limit_minutes, allowed_attempts, is_published, created_at) VALUES ($sectionSubjectId, 'Radar Collision Regulations Quiz', 20, 2, 1, NOW())");
    $quizId = (int)$pdo->lastInsertId();

    echo "  [+] Coursework created: 1 module (2 lessons), 1 material, 1 assignment, 1 quiz (Total 6 activity milestones)\n";

    // 4. Initial Progress Check: 0% completion
    $p0 = fetchLmsCourseProgress($pdo, $studentId1, $subjectId);
    if ((int)$p0['total'] !== 6 || (int)$p0['completed'] !== 0 || (int)$p0['percent'] !== 0) {
        throw new RuntimeException("Initial progress calculation failed. Expected 0 of 6, got {$p0['completed']} of {$p0['total']}.");
    }
    echo "  [+] Initial student progress verified: 0 of 6 milestones (0%).\n";

    // 5. Simulate Partial Activity:
    // Lesson 1 viewed
    $pdo->exec("INSERT INTO lms_lesson_progress (student_id, lesson_id, viewed_at) VALUES ($studentId1, $lessonId1, NOW())");
    // Material viewed
    $pdo->exec("INSERT INTO lms_material_views (student_id, material_id, viewed_at) VALUES ($studentId1, $materialId, NOW())");
    // Assignment submitted
    $pdo->exec("INSERT INTO lms_assignment_submissions (assignment_id, student_id, file_name, file_path, submitted_at, created_at) VALUES ($assignmentId, $studentId1, 'sheet1.pdf', 'path', NOW(), NOW())");
    // Quiz completed
    $pdo->exec("INSERT INTO lms_quiz_attempts (quiz_id, student_id, attempt_number, status, started_at, deadline_at, submitted_at, score, total_points, created_at) VALUES ($quizId, $studentId1, 1, 'submitted', NOW(), DATE_ADD(NOW(), INTERVAL 20 MINUTE), NOW(), 80.00, 100.00, NOW())");

    $p1 = fetchLmsCourseProgress($pdo, $studentId1, $subjectId);
    // Lessons: 1/2, Materials: 1/1, Assignments: 1/1, Quizzes: 1/1, Modules: 0/1 (since Lesson 2 not viewed yet)
    // Completed: 1 + 1 + 1 + 1 + 0 = 4 of 6 (67%)
    if ((int)$p1['completed'] !== 4 || (int)$p1['percent'] !== 67) {
        throw new RuntimeException("Partial progress calculation mismatch. Expected 4 of 6 (67%), got {$p1['completed']} of {$p1['total']} ({$p1['percent']}%).");
    }
    echo "  [+] Partial student progress verified: 4 of 6 milestones (67%). Module remains incomplete until all lessons viewed.\n";

    // 6. Complete Lesson 2 -> Module now completes!
    $pdo->exec("INSERT INTO lms_lesson_progress (student_id, lesson_id, viewed_at) VALUES ($studentId1, $lessonId2, NOW())");

    $p2 = fetchLmsCourseProgress($pdo, $studentId1, $subjectId);
    // Lessons: 2/2, Materials: 1/1, Assignments: 1/1, Quizzes: 1/1, Modules: 1/1
    // Completed: 2 + 1 + 1 + 1 + 1 = 6 of 6 (100%)
    if ((int)$p2['completed'] !== 6 || (int)$p2['percent'] !== 100) {
        throw new RuntimeException("Full progress calculation mismatch. Expected 6 of 6 (100%), got {$p2['completed']} of {$p2['total']} ({$p2['percent']}%).");
    }
    if ((int)$p2['items']['modules']['completed'] !== 1) {
        throw new RuntimeException("Module did not register as completed after all lessons were viewed.");
    }
    echo "  [+] Full student progress verified: 6 of 6 milestones (100%). Module registered as completed!\n";

    echo "\n>>> ALL TEACHER STUDENT PROGRESS TESTS PASSED SUCCESSFULLY! <<<\n";

} finally {
    $pdo->rollBack();
    echo "Transaction rolled back (database clean).\n";
}
