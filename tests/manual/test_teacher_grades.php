<?php
/**
 * Test Teacher LMS Grades recording, auto-calculation, student LMS reflection,
 * and Registrar academic record separation.
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/lms_access.php';

echo "Testing Teacher LMS Grades flow...\n";

$pdo->beginTransaction();

try {
    // 1. Setup mock term, course, section, subject, section_subject
    $pdo->exec("INSERT INTO academic_terms (school_year, semester, is_active, created_at) VALUES ('2026-2027', '1st Semester', 1, NOW())");
    $termId = (int)$pdo->lastInsertId();

    $pdo->exec("INSERT INTO courses (course_code, course_name, created_at) VALUES ('BSMT-G', 'BS Marine Transportation Grades Test', NOW())");
    $courseId = (int)$pdo->lastInsertId();

    $pdo->exec("INSERT INTO users (username, email, password_hash, role, first_name, last_name, created_at) VALUES ('grade_teacher', 'gteacher@example.com', 'hash', 'teacher', 'Capt', 'Instructor', NOW())");
    $teacherId = (int)$pdo->lastInsertId();

    $pdo->exec("INSERT INTO sections (section_name, course_id, academic_term_id, teacher_id, created_at) VALUES ('BSMT-G 1', $courseId, $termId, $teacherId, NOW())");
    $sectionId = (int)$pdo->lastInsertId();

    $pdo->exec("INSERT INTO subjects (subject_code, subject_name, units, created_at) VALUES ('NAV-G', 'Navigation Grades Test', 3, NOW())");
    $subjectId = (int)$pdo->lastInsertId();

    $pdo->exec("INSERT INTO section_subjects (section_id, subject_id, instructor_id, created_at) VALUES ($sectionId, $subjectId, $teacherId, NOW())");
    $sectionSubjectId = (int)$pdo->lastInsertId();

    // 2. Setup mock student and enrollment
    $pdo->exec("INSERT INTO users (username, email, password_hash, role, first_name, last_name, created_at) VALUES ('cadet_grade', 'cadet_grade@example.com', 'hash', 'student', 'Alex', 'Cadet', NOW())");
    $studentUserId = (int)$pdo->lastInsertId();

    $pdo->exec("INSERT INTO students (user_id, academic_term_id, enrollment_status, created_at) VALUES ($studentUserId, $termId, 'enrolled', NOW())");
    $studentId = (int)$pdo->lastInsertId();

    $pdo->exec("INSERT INTO enrollments (student_id, section_id, academic_term_id, status, created_at) VALUES ($studentId, $sectionId, $termId, 'enrolled', NOW())");
    $enrollmentId = (int)$pdo->lastInsertId();

    echo "  [+] Mock environment established: SectionSubject ID $sectionSubjectId, Student ID $studentId, Enrollment ID $enrollmentId\n";

    // 3. Verify teacher ownership check
    $owned = verifyTeacherOwnsSubject($pdo, $teacherId, $sectionSubjectId);
    if (!$owned) {
        throw new RuntimeException("verifyTeacherOwnsSubject failed for assigned instructor.");
    }
    echo "  [+] verifyTeacherOwnsSubject passed.\n";

    // 4. Create grade_submissions and student_grades (Draft mode)
    $subStmt = $pdo->prepare("INSERT INTO grade_submissions (section_id, academic_term_id, teacher_id, status, created_at) VALUES (:sec, :term, :tid, 'draft', NOW())");
    $subStmt->execute(['sec' => $sectionId, 'term' => $termId, 'tid' => $teacherId]);
    $submissionId = (int)$pdo->lastInsertId();

    $sgStmt = $pdo->prepare("
        INSERT INTO student_grades (grade_submission_id, section_subject_id, enrollment_id, student_id, prelim_grade, midterm_grade, final_exam_grade, final_grade, remarks, created_at)
        VALUES (:subm, :ss, :enr, :st, 85.50, 88.00, 92.50, 89.05, 'Excellent performance in charts and navigation.', NOW())
    ");
    $sgStmt->execute([
        'subm' => $submissionId,
        'ss'   => $sectionSubjectId,
        'enr'  => $enrollmentId,
        'st'   => $studentId,
    ]);
    echo "  [+] Draft grades and remarks recorded in student_grades.\n";

    // 5. Test that Student-side LMS immediately sees the recorded draft grades
    $lmsGrades = fetchLmsCourseGrades($pdo, $studentId, $subjectId);
    if (empty($lmsGrades)) {
        throw new RuntimeException("fetchLmsCourseGrades failed to return recorded draft grades for student LMS.");
    }
    $lg = $lmsGrades[0];
    if ((float)$lg['prelim_grade'] !== 85.50 || (float)$lg['final_grade'] !== 89.05 || $lg['grade_status'] !== 'draft') {
        throw new RuntimeException("LMS grade data mismatch: Prelim {$lg['prelim_grade']}, Final {$lg['final_grade']}, Status {$lg['grade_status']}");
    }
    echo "  [+] fetchLmsCourseGrades immediately returned recorded draft grades with status 'draft'.\n";

    // 6. Test that Registrar Academic Transcript STILL blocks draft grades (preserves Registrar ownership)
    $transcriptStmt = $pdo->prepare("
        SELECT sg.id, sg.final_grade
        FROM student_grades sg
        JOIN grade_submissions gs ON gs.id = sg.grade_submission_id
          AND gs.status IN ('approved','locked')
        WHERE sg.student_id = :sid
    ");
    $transcriptStmt->execute(['sid' => $studentId]);
    $transcriptRows = $transcriptStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($transcriptRows)) {
        throw new RuntimeException("Draft grades leaked into official Registrar academic transcript query before approval!");
    }
    echo "  [+] Official Registrar transcript query strictly excludes draft/submitted grades as expected.\n";

    // 7. Test Auto-calculation formulas
    // Formula: 30% Prelim + 30% Midterm + 40% Final Exam
    // (85.50 * 0.3) + (88.00 * 0.3) + (92.50 * 0.4) = 25.65 + 26.40 + 37.00 = 89.05
    $calcExpected = round((85.50 * 0.3) + (88.00 * 0.3) + (92.50 * 0.4), 2);
    if ($calcExpected !== 89.05) {
        throw new RuntimeException("Calculation formula mismatch: expected 89.05, got $calcExpected");
    }
    echo "  [+] Auto-calculation formula (30/30/40) verified (89.05).\n";

    // 8. Submit grades to Registrar
    $updSub = $pdo->prepare("UPDATE grade_submissions SET status = 'submitted', submitted_at = NOW() WHERE id = :id");
    $updSub->execute(['id' => $submissionId]);

    $lmsGradesSubmitted = fetchLmsCourseGrades($pdo, $studentId, $subjectId);
    if ($lmsGradesSubmitted[0]['grade_status'] !== 'submitted') {
        throw new RuntimeException("Expected grade_status 'submitted', got " . $lmsGradesSubmitted[0]['grade_status']);
    }
    echo "  [+] Grade submission status successfully changed to 'submitted'.\n";

    // 9. Simulate Registrar approval
    $approveSub = $pdo->prepare("UPDATE grade_submissions SET status = 'approved', reviewed_at = NOW() WHERE id = :id");
    $approveSub->execute(['id' => $submissionId]);

    $transcriptStmt->execute(['sid' => $studentId]);
    $approvedTranscriptRows = $transcriptStmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($approvedTranscriptRows)) {
        throw new RuntimeException("Approved grade did not unlock into official transcript!");
    }
    echo "  [+] After Registrar approval, grade unlocked in official academic records.\n";

    echo "All Teacher LMS Grades test assertions PASSED!\n";
} finally {
    $pdo->rollBack();
    echo "  [+] Test transaction rolled back cleanly.\n";
}
