<?php
/**
 * Test Teacher LMS Announcements Management (Phase 8)
 *
 * Verifies:
 *   1. verifyTeacherOwnsSubject() authorization and rejection of unauthorized teachers.
 *   2. Creation of announcements for specific section_subject.
 *   3. Enrolled student visibility via fetchLmsAnnouncementsForStudentSubject().
 *   4. Strict section-subject isolation (non-enrolled students cannot see announcement).
 *   5. Publication scheduling (future published_at is not visible until due).
 *   6. Draft status (is_published = 0 is hidden).
 *   7. Notification dispatch to enrolled students via notifyLmsAnnouncementRecipients().
 *   8. Announcement editing and deletion.
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/lms_access.php';

echo "Testing Teacher LMS Announcements flow...\n";

$pdo->beginTransaction();

try {
    // 1. Setup mock term, courses, section, subject, section_subject
    $pdo->exec("INSERT INTO academic_terms (school_year, semester, is_active, created_at) VALUES ('2026-2027', '1st Semester', 1, NOW())");
    $termId = (int)$pdo->lastInsertId();

    $pdo->exec("INSERT INTO courses (course_code, course_name, created_at) VALUES ('BSMT-ANN', 'BS Marine Transportation Announcements Test', NOW())");
    $courseId = (int)$pdo->lastInsertId();

    // Teacher 1 (assigned)
    $pdo->exec("INSERT INTO users (username, email, password_hash, role, first_name, last_name, created_at) VALUES ('ann_teacher1', 'ann1@example.com', 'hash', 'faculty', 'Capt', 'Avery', NOW())");
    $teacherId1 = (int)$pdo->lastInsertId();

    // Teacher 2 (unassigned)
    $pdo->exec("INSERT INTO users (username, email, password_hash, role, first_name, last_name, created_at) VALUES ('ann_teacher2', 'ann2@example.com', 'hash', 'faculty', 'Capt', 'Bates', NOW())");
    $teacherId2 = (int)$pdo->lastInsertId();

    // Section 1
    $pdo->exec("INSERT INTO sections (section_name, course_id, academic_term_id, teacher_id, created_at) VALUES ('BSMT-ANN 1', $courseId, $termId, $teacherId1, NOW())");
    $sectionId1 = (int)$pdo->lastInsertId();

    // Section 2 (different section)
    $pdo->exec("INSERT INTO sections (section_name, course_id, academic_term_id, teacher_id, created_at) VALUES ('BSMT-ANN 2', $courseId, $termId, $teacherId1, NOW())");
    $sectionId2 = (int)$pdo->lastInsertId();

    // Subject
    $pdo->exec("INSERT INTO subjects (subject_code, subject_name, units, created_at) VALUES ('NAV-ANN', 'Navigation Announcements Test', 3, NOW())");
    $subjectId = (int)$pdo->lastInsertId();

    // Section Subject 1 (Section 1)
    $pdo->exec("INSERT INTO section_subjects (section_id, subject_id, instructor_id, created_at) VALUES ($sectionId1, $subjectId, $teacherId1, NOW())");
    $sectionSubjectId1 = (int)$pdo->lastInsertId();

    // Section Subject 2 (Section 2)
    $pdo->exec("INSERT INTO section_subjects (section_id, subject_id, instructor_id, created_at) VALUES ($sectionId2, $subjectId, $teacherId1, NOW())");
    $sectionSubjectId2 = (int)$pdo->lastInsertId();

    // Enrolled Student 1 (in Section 1)
    $pdo->exec("INSERT INTO users (username, email, password_hash, role, first_name, last_name, created_at) VALUES ('cadet_ann1', 'cadet_ann1@example.com', 'hash', 'student', 'Charlie', 'Cadet', NOW())");
    $studentUser1 = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO students (user_id, academic_term_id, enrollment_status, created_at) VALUES ($studentUser1, $termId, 'enrolled', NOW())");
    $studentId1 = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO enrollments (student_id, section_id, academic_term_id, status, created_at) VALUES ($studentId1, $sectionId1, $termId, 'enrolled', NOW())");
    $enrollmentId1 = (int)$pdo->lastInsertId();

    // Enrolled Student 2 (in Section 2 - different section)
    $pdo->exec("INSERT INTO users (username, email, password_hash, role, first_name, last_name, created_at) VALUES ('cadet_ann2', 'cadet_ann2@example.com', 'hash', 'student', 'David', 'Cadet', NOW())");
    $studentUser2 = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO students (user_id, academic_term_id, enrollment_status, created_at) VALUES ($studentUser2, $termId, 'enrolled', NOW())");
    $studentId2 = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO enrollments (student_id, section_id, academic_term_id, status, created_at) VALUES ($studentId2, $sectionId2, $termId, 'enrolled', NOW())");
    $enrollmentId2 = (int)$pdo->lastInsertId();

    echo "  [+] Mock environment ready: SS1=$sectionSubjectId1 (Sec 1), SS2=$sectionSubjectId2 (Sec 2)\n";

    // 2. Ownership verification
    $owned1 = verifyTeacherOwnsSubject($pdo, $teacherId1, $sectionSubjectId1);
    if (!$owned1) {
        throw new RuntimeException("verifyTeacherOwnsSubject failed for assigned teacher.");
    }
    $unowned = verifyTeacherOwnsSubject($pdo, $teacherId2, $sectionSubjectId1);
    if ($unowned) {
        throw new RuntimeException("verifyTeacherOwnsSubject incorrectly granted access to unassigned teacher.");
    }
    echo "  [+] Teacher ownership checks passed.\n";

    // 3. Test Creation of Live Announcement in SectionSubject 1
    $ins = $pdo->prepare(
        "INSERT INTO lms_announcements (section_subject_id, author_id, title, message, published_at, is_published, is_important, created_at, updated_at)
         VALUES (:ss, :author, :title, :message, NOW(), 1, 1, NOW(), NOW())"
    );
    $ins->execute([
        'ss'      => $sectionSubjectId1,
        'author'  => $teacherId1,
        'title'   => 'Midterm Room Change to Chartroom A',
        'message' => 'Please bring your parallel rulers and dividers.',
    ]);
    $annId1 = (int)$pdo->lastInsertId();
    echo "  [+] Created live announcement ID $annId1 for SectionSubject $sectionSubjectId1\n";

    // 4. Test Student 1 (Enrolled in Sec 1) sees the announcement
    $student1Announcements = fetchLmsAnnouncementsForStudentSubject($pdo, $studentId1, $subjectId);
    $found1 = false;
    foreach ($student1Announcements as $a) {
        if ((int)$a['id'] === $annId1) {
            $found1 = true;
            if (empty($a['is_important'])) {
                throw new RuntimeException("Announcement is_important flag did not match.");
            }
            break;
        }
    }
    if (!$found1) {
        throw new RuntimeException("Student 1 in Section 1 failed to see the published announcement.");
    }
    echo "  [+] Student 1 correctly sees the published announcement.\n";

    // 5. Test Student 2 (Enrolled in Sec 2 for same subject) CANNOT see announcement from Sec 1 (Strict Isolation)
    $student2Announcements = fetchLmsAnnouncementsForStudentSubject($pdo, $studentId2, $subjectId);
    foreach ($student2Announcements as $a) {
        if ((int)$a['id'] === $annId1) {
            throw new RuntimeException("Data leak! Student 2 in Section 2 can see announcement posted for Section 1.");
        }
    }
    echo "  [+] Subject isolation verified: Student in different section cannot see announcement.\n";

    // 6. Test Notification Dispatch
    $notifBeforeStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :uid");
    $notifBeforeStmt->execute(['uid' => $studentUser1]);
    $notifCountBefore = (int)$notifBeforeStmt->fetchColumn();

    notifyLmsAnnouncementRecipients($pdo, $annId1);

    $notifAfterStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :uid");
    $notifAfterStmt->execute(['uid' => $studentUser1]);
    $notifCountAfter = (int)$notifAfterStmt->fetchColumn();

    if ($notifCountAfter <= $notifCountBefore) {
        throw new RuntimeException("notifyLmsAnnouncementRecipients did not create a notification for Student 1.");
    }

    // Verify Student 2 did NOT receive the notification
    $notifS2Stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :uid");
    $notifS2Stmt->execute(['uid' => $studentUser2]);
    $notifCountS2 = (int)$notifS2Stmt->fetchColumn();
    if ($notifCountS2 > 0) {
        throw new RuntimeException("Student 2 unexpectedly received a notification for Section 1 announcement.");
    }
    echo "  [+] Notification dispatch verified: Only enrolled students in the target section are notified.\n";

    // 7. Test Scheduled (Future) Announcement
    $insFuture = $pdo->prepare(
        "INSERT INTO lms_announcements (section_subject_id, author_id, title, message, published_at, is_published, is_important, created_at, updated_at)
         VALUES (:ss, :author, :title, :message, DATE_ADD(NOW(), INTERVAL 5 DAY), 1, 0, NOW(), NOW())"
    );
    $insFuture->execute([
        'ss'      => $sectionSubjectId1,
        'author'  => $teacherId1,
        'title'   => 'Scheduled Future Notice',
        'message' => 'This should not appear to students yet.',
    ]);
    $annIdFuture = (int)$pdo->lastInsertId();

    $student1AnnouncementsFuture = fetchLmsAnnouncementsForStudentSubject($pdo, $studentId1, $subjectId);
    foreach ($student1AnnouncementsFuture as $a) {
        if ((int)$a['id'] === $annIdFuture) {
            throw new RuntimeException("Scheduled future announcement appeared prematurely to student!");
        }
    }
    echo "  [+] Scheduled announcement correctly hidden until publication date.\n";

    // 8. Test Draft (Hidden) Announcement
    $insDraft = $pdo->prepare(
        "INSERT INTO lms_announcements (section_subject_id, author_id, title, message, published_at, is_published, is_important, created_at, updated_at)
         VALUES (:ss, :author, :title, :message, NOW(), 0, 0, NOW(), NOW())"
    );
    $insDraft->execute([
        'ss'      => $sectionSubjectId1,
        'author'  => $teacherId1,
        'title'   => 'Draft Announcement',
        'message' => 'Unpublished draft.',
    ]);
    $annIdDraft = (int)$pdo->lastInsertId();

    $student1AnnouncementsDraft = fetchLmsAnnouncementsForStudentSubject($pdo, $studentId1, $subjectId);
    foreach ($student1AnnouncementsDraft as $a) {
        if ((int)$a['id'] === $annIdDraft) {
            throw new RuntimeException("Draft announcement appeared to student!");
        }
    }
    echo "  [+] Draft announcement correctly hidden from students.\n";

    // 9. Test Editing Announcement
    $upd = $pdo->prepare(
        "UPDATE lms_announcements SET title = :title, message = :msg, is_important = 0, updated_at = NOW() WHERE id = :id"
    );
    $upd->execute([
        'title' => 'Updated Midterm Room Change to Chartroom B',
        'msg'   => 'Updated details for chartroom B.',
        'id'    => $annId1,
    ]);

    $student1AnnouncementsUpdated = fetchLmsAnnouncementsForStudentSubject($pdo, $studentId1, $subjectId);
    $updatedFound = false;
    foreach ($student1AnnouncementsUpdated as $a) {
        if ((int)$a['id'] === $annId1 && strpos($a['title'], 'Chartroom B') !== false) {
            $updatedFound = true;
            break;
        }
    }
    if (!$updatedFound) {
        throw new RuntimeException("Announcement edit did not reflect immediately for student.");
    }
    echo "  [+] Announcement edit verified and reflected immediately for student.\n";

    // 10. Test Deletion
    $del = $pdo->prepare("DELETE FROM lms_announcements WHERE id = :id AND section_subject_id = :ss");
    $del->execute(['id' => $annId1, 'ss' => $sectionSubjectId1]);

    $student1AnnouncementsAfterDel = fetchLmsAnnouncementsForStudentSubject($pdo, $studentId1, $subjectId);
    foreach ($student1AnnouncementsAfterDel as $a) {
        if ((int)$a['id'] === $annId1) {
            throw new RuntimeException("Deleted announcement is still visible to student!");
        }
    }
    echo "  [+] Announcement deletion verified.\n";

    echo "\n>>> ALL TEACHER ANNOUNCEMENTS TESTS PASSED SUCCESSFULLY! <<<\n";

} finally {
    // Always roll back test data
    $pdo->rollBack();
    echo "Transaction rolled back (database clean).\n";
}
