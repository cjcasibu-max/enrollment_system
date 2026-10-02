<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/assessments.php';
require_once __DIR__ . '/notifications.php';

const LMS_PERMITTED_ROLES = ['student', 'teacher', 'registrar', 'admin'];

function lmsRoleIsPermitted(string $role): bool
{
    return in_array($role, LMS_PERMITTED_ROLES, true);
}

function lmsStudentMeetsAccessRequirements(array $record): bool
{
    $assessmentTotal = max(0.0, (float)($record['assessment_total'] ?? 0));
    $minimumDownpayment = max(0.0, (float)($record['minimum_downpayment'] ?? 0));
    $downpaymentPercentage = max(0.0, (float)($record['downpayment_percentage'] ?? 0));
    return ($record['enrollment_status'] ?? '') === 'enrolled'
        && !empty($record['has_confirmed_enrollment'])
        && hasMetRequiredDownpayment(
            (float)($record['validated_paid'] ?? 0),
            $assessmentTotal,
            $minimumDownpayment,
            $downpaymentPercentage
        );
}

function lmsCourseProgressPercent(int $completedGradeCheckpoints): int
{
    $completedGradeCheckpoints = max(0, min(3, $completedGradeCheckpoints));
    return (int)round(($completedGradeCheckpoints / 3) * 100);
}

function fetchLmsStudentAccessRecord(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT s.id AS student_id,
                s.enrollment_status,
                COALESCE(a.total_amount, 0) AS assessment_total,
                COALESCE(t.minimum_downpayment, 0) AS minimum_downpayment,
                COALESCE(t.downpayment_percentage, 0) AS downpayment_percentage,
                COALESCE((
                    SELECT SUM(pa.amount)
                    FROM assessment_items ai
                    JOIN payment_allocations pa ON pa.assessment_item_id = ai.id
                    JOIN payments p ON p.id = pa.payment_id
                    WHERE ai.assessment_id = a.id
                      AND p.or_status = 'validated'
                                ), 0) + COALESCE((
                                        SELECT SUM(unallocated_payment.amount)
                                        FROM payments unallocated_payment
                                        WHERE unallocated_payment.student_id = s.id
                                            AND unallocated_payment.academic_term_id = s.academic_term_id
                                            AND unallocated_payment.or_status = 'validated'
                                            AND NOT EXISTS (
                                                    SELECT 1 FROM payment_allocations existing_allocation
                                                    WHERE existing_allocation.payment_id = unallocated_payment.id
                                            )
                                ), 0) AS validated_paid,
                EXISTS (
                    SELECT 1
                    FROM enrollments e
                    WHERE e.student_id = s.id
                      AND e.academic_term_id = s.academic_term_id
                      AND e.status = 'enrolled'
                ) AS has_confirmed_enrollment
         FROM students s
         LEFT JOIN assessments a
                     ON a.id = (
                             SELECT current_assessment.id
                             FROM assessments current_assessment
                             WHERE current_assessment.student_id = s.id
                                 AND current_assessment.academic_term_id = s.academic_term_id
                                 AND current_assessment.status != 'cancelled'
                             ORDER BY current_assessment.generated_at DESC, current_assessment.id DESC
                             LIMIT 1
                     )
         LEFT JOIN academic_terms t ON t.id = s.academic_term_id
         WHERE s.user_id = :user_id
         LIMIT 1"
    );
    $stmt->execute(['user_id' => $userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function fetchLmsEnrolledSubjects(PDO $pdo, int $studentId): array
{
    $stmt = $pdo->prepare(
        "SELECT DISTINCT sub.id AS subject_id, sub.subject_code, sub.subject_name, sub.units,
                sec.section_name, e.school_year, e.semester,
                COALESCE(ss.day_of_week, sec.day_of_week) AS day_of_week,
                COALESCE(ss.start_time, sec.start_time) AS start_time,
                COALESCE(ss.end_time, sec.end_time) AS end_time,
                COALESCE(ss.room, sec.room) AS room,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.username) AS instructor_name,
                COALESCE((
                    SELECT (sg.prelim_grade IS NOT NULL)
                         + (sg.midterm_grade IS NOT NULL)
                         + (sg.final_exam_grade IS NOT NULL)
                    FROM student_grades sg
                    JOIN grade_submissions gs ON gs.id = sg.grade_submission_id
                    WHERE sg.enrollment_id = e.id
                      AND sg.section_subject_id = ss.id
                      AND gs.academic_term_id = e.academic_term_id
                                            AND gs.section_id = e.section_id
                      AND gs.status IN ('approved','locked')
                    LIMIT 1
                ), 0) AS completed_grade_checkpoints
         FROM enrollments e
         JOIN sections sec ON sec.id = e.section_id
         JOIN section_subjects ss ON ss.section_id = sec.id
         JOIN subjects sub ON sub.id = ss.subject_id
         JOIN students s ON s.id = e.student_id
         LEFT JOIN users u ON u.id = COALESCE(ss.instructor_id, sec.teacher_id)
         WHERE s.id = :student_id
           AND s.enrollment_status = 'enrolled'
           AND e.academic_term_id = s.academic_term_id
           AND e.status = 'enrolled'
         ORDER BY sub.subject_code, sub.subject_name"
    );
    $stmt->execute(['student_id' => $studentId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetchLmsSubjectForStudent(PDO $pdo, int $studentId, int $subjectId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT sub.id AS subject_id, sub.subject_code, sub.subject_name, sub.units,
                sec.section_name, e.school_year, e.semester,
                COALESCE(ss.day_of_week, sec.day_of_week) AS day_of_week,
                COALESCE(ss.start_time, sec.start_time) AS start_time,
                COALESCE(ss.end_time, sec.end_time) AS end_time,
                COALESCE(ss.room, sec.room) AS room,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.username) AS instructor_name,
                u.email AS instructor_email
         FROM enrollments e
         JOIN sections sec ON sec.id = e.section_id
         JOIN section_subjects ss ON ss.section_id = sec.id
         JOIN subjects sub ON sub.id = ss.subject_id
         JOIN students s ON s.id = e.student_id
         LEFT JOIN users u ON u.id = COALESCE(ss.instructor_id, sec.teacher_id)
         WHERE s.id = :student_id
           AND s.enrollment_status = 'enrolled'
           AND e.academic_term_id = s.academic_term_id
           AND e.status = 'enrolled'
           AND sub.id = :subject_id
         LIMIT 1"
    );
    $stmt->execute([
        'student_id' => $studentId,
        'subject_id' => $subjectId,
    ]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function fetchLmsCourseGrades(PDO $pdo, int $studentId, int $subjectId): array
{
    $stmt = $pdo->prepare(
        "SELECT sg.prelim_grade, sg.midterm_grade, sg.final_exam_grade, sg.final_grade, sg.remarks,
            gs.status AS grade_status
         FROM student_grades sg
         JOIN grade_submissions gs ON gs.id = sg.grade_submission_id
         JOIN enrollments e ON e.id = sg.enrollment_id
         JOIN students s ON s.id = e.student_id AND s.id = sg.student_id
         JOIN section_subjects ss ON ss.id = sg.section_subject_id
         WHERE s.id = :student_id
           AND s.enrollment_status = 'enrolled'
           AND e.status = 'enrolled'
           AND e.academic_term_id = s.academic_term_id
           AND gs.academic_term_id = e.academic_term_id
           AND gs.section_id = e.section_id
           AND ss.section_id = e.section_id
           AND ss.subject_id = :subject_id
         ORDER BY sg.id DESC"
    );
    $stmt->execute([
        'student_id' => $studentId,
        'subject_id' => $subjectId,
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetchLmsWorkGradesForStudentSubject(PDO $pdo, int $studentId, int $subjectId, string $workType): array
{
    if (!in_array($workType, ['assignment', 'activity'], true)) {
        return [];
    }

    $stmt = $pdo->prepare(
        "SELECT a.id AS item_id, a.title, a.assignment_type, a.max_score,
                subm.score, subm.feedback, subm.graded_at, subm.submitted_at
         FROM lms_assignment_submissions subm
         JOIN lms_assignments a ON a.id = subm.assignment_id
         JOIN section_subjects ss ON ss.id = a.section_subject_id
         JOIN sections sec ON sec.id = ss.section_id
         JOIN enrollments e ON e.section_id = sec.id AND e.student_id = subm.student_id
         JOIN students s ON s.id = e.student_id
         WHERE s.id = :student_id
           AND s.enrollment_status = 'enrolled'
           AND e.status = 'enrolled'
           AND e.academic_term_id = s.academic_term_id
           AND e.academic_term_id = sec.academic_term_id
           AND ss.subject_id = :subject_id
           AND a.assignment_type = :work_type
           AND (subm.score IS NOT NULL OR subm.feedback IS NOT NULL OR subm.graded_at IS NOT NULL)
         ORDER BY a.due_at, a.display_order, a.id"
    );
    $stmt->execute([
        'student_id' => $studentId,
        'subject_id' => $subjectId,
        'work_type' => $workType,
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetchLmsQuizGradesForStudentSubject(PDO $pdo, int $studentId, int $subjectId): array
{
    $stmt = $pdo->prepare(
        "SELECT q.id AS quiz_id, q.title, a.id AS attempt_id, a.attempt_number,
                a.status, a.score, a.total_points, a.feedback, a.submitted_at
         FROM lms_quiz_attempts a
         JOIN lms_quizzes q ON q.id = a.quiz_id
         JOIN section_subjects ss ON ss.id = q.section_subject_id
         JOIN sections sec ON sec.id = ss.section_id
         JOIN enrollments e ON e.section_id = sec.id AND e.student_id = a.student_id
         JOIN students s ON s.id = e.student_id
         WHERE s.id = :student_id
           AND s.enrollment_status = 'enrolled'
           AND e.status = 'enrolled'
           AND e.academic_term_id = s.academic_term_id
           AND e.academic_term_id = sec.academic_term_id
           AND ss.subject_id = :subject_id
           AND a.status IN ('submitted','interrupted','timed_out')
           AND a.score IS NOT NULL
         ORDER BY q.display_order, q.id, a.attempt_number"
    );
    $stmt->execute([
        'student_id' => $studentId,
        'subject_id' => $subjectId,
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetchLmsModulesForStudentSubject(PDO $pdo, int $studentId, int $subjectId): array
{
    $stmt = $pdo->prepare(
        "SELECT m.id AS module_id, m.title AS module_title, m.description AS module_description,
                m.display_order AS module_order,
                l.id AS lesson_id, l.title AS lesson_title, l.description AS lesson_description,
                l.content_type, l.display_order AS lesson_order
         FROM lms_modules m
         JOIN section_subjects ss ON ss.id = m.section_subject_id
         JOIN sections sec ON sec.id = ss.section_id
         JOIN enrollments e ON e.section_id = sec.id
         JOIN students s ON s.id = e.student_id
         LEFT JOIN lms_lessons l ON l.module_id = m.id AND l.is_published = 1
         WHERE s.id = :student_id
           AND s.enrollment_status = 'enrolled'
           AND e.status = 'enrolled'
           AND e.academic_term_id = s.academic_term_id
           AND e.academic_term_id = sec.academic_term_id
           AND ss.subject_id = :subject_id
           AND m.is_published = 1
         ORDER BY m.display_order, m.id, l.display_order, l.id"
    );
    $stmt->execute([
        'student_id' => $studentId,
        'subject_id' => $subjectId,
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetchLmsLessonForStudentSubject(PDO $pdo, int $studentId, int $subjectId, int $lessonId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT l.id, l.title, l.description, l.content_type, l.content_body, l.content_path,
                m.id AS module_id, m.title AS module_title
         FROM lms_lessons l
         JOIN lms_modules m ON m.id = l.module_id AND m.is_published = 1
         JOIN section_subjects ss ON ss.id = m.section_subject_id
         JOIN sections sec ON sec.id = ss.section_id
         JOIN enrollments e ON e.section_id = sec.id
         JOIN students s ON s.id = e.student_id
         WHERE l.id = :lesson_id
           AND l.is_published = 1
           AND s.id = :student_id
           AND s.enrollment_status = 'enrolled'
           AND e.status = 'enrolled'
           AND e.academic_term_id = s.academic_term_id
           AND e.academic_term_id = sec.academic_term_id
           AND ss.subject_id = :subject_id
         LIMIT 1"
    );
    $stmt->execute([
        'lesson_id' => $lessonId,
        'student_id' => $studentId,
        'subject_id' => $subjectId,
    ]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function fetchLmsMaterialsForStudentSubject(PDO $pdo, int $studentId, int $subjectId): array
{
    $stmt = $pdo->prepare(
        "SELECT DISTINCT m.id, m.title, m.description, m.material_type, m.file_name,
                m.display_order
         FROM lms_materials m
         JOIN section_subjects ss ON ss.id = m.section_subject_id
         JOIN sections sec ON sec.id = ss.section_id
         JOIN enrollments e ON e.section_id = sec.id
         JOIN students s ON s.id = e.student_id
         WHERE s.id = :student_id
           AND s.enrollment_status = 'enrolled'
           AND e.status = 'enrolled'
           AND e.academic_term_id = s.academic_term_id
           AND e.academic_term_id = sec.academic_term_id
           AND ss.subject_id = :subject_id
           AND m.is_available = 1
         ORDER BY m.display_order, m.id"
    );
    $stmt->execute([
        'student_id' => $studentId,
        'subject_id' => $subjectId,
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function recordLmsLessonView(PDO $pdo, int $studentId, int $subjectId, int $lessonId): bool
{
    if (!fetchLmsLessonForStudentSubject($pdo, $studentId, $subjectId, $lessonId)) {
        return false;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO lms_lesson_progress (lesson_id, student_id, viewed_at)
         VALUES (:lesson_id, :student_id, NOW())
         ON DUPLICATE KEY UPDATE viewed_at = NOW()'
    );
    $stmt->execute(['lesson_id' => $lessonId, 'student_id' => $studentId]);
    return true;
}

function recordLmsMaterialView(PDO $pdo, int $studentId, int $subjectId, int $materialId): bool
{
    if (!fetchLmsMaterialForStudentSubject($pdo, $studentId, $subjectId, $materialId)) {
        return false;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO lms_material_views (material_id, student_id, viewed_at)
         VALUES (:material_id, :student_id, NOW())
         ON DUPLICATE KEY UPDATE viewed_at = NOW()'
    );
    $stmt->execute(['material_id' => $materialId, 'student_id' => $studentId]);
    return true;
}

function fetchLmsCourseProgress(PDO $pdo, int $studentId, int $subjectId): array
{
    $metrics = [
        'lessons' => ['label' => 'Completed lessons', 'completed' => 0, 'total' => 0],
        'materials' => ['label' => 'Viewed learning materials', 'completed' => 0, 'total' => 0],
        'assignments' => ['label' => 'Submitted assignments', 'completed' => 0, 'total' => 0],
        'quizzes' => ['label' => 'Completed quizzes', 'completed' => 0, 'total' => 0],
        'modules' => ['label' => 'Completed modules', 'completed' => 0, 'total' => 0],
    ];

    $common = " JOIN section_subjects ss ON ss.id = %s.section_subject_id
                JOIN sections sec ON sec.id = ss.section_id
                JOIN enrollments e ON e.section_id = sec.id AND e.student_id = :enrollment_student_id
                JOIN students s ON s.id = e.student_id
                WHERE s.id = :student_id
                  AND s.enrollment_status = 'enrolled'
                  AND e.status = 'enrolled'
                  AND e.academic_term_id = s.academic_term_id
                  AND e.academic_term_id = sec.academic_term_id
                  AND ss.subject_id = :subject_id";

    $queries = [
        'lessons' => sprintf(
            "SELECT COUNT(*) AS total, COALESCE(SUM(lp.id IS NOT NULL), 0) AS completed
             FROM lms_lessons l
             LEFT JOIN lms_lesson_progress lp ON lp.lesson_id = l.id AND lp.student_id = :progress_student_id
             JOIN lms_modules m ON m.id = l.module_id AND m.is_published = 1 AND l.is_published = 1%s",
            sprintf($common, 'm')
        ),
        'materials' => sprintf(
            "SELECT COUNT(*) AS total, COALESCE(SUM(mv.id IS NOT NULL), 0) AS completed
             FROM lms_materials m
             LEFT JOIN lms_material_views mv ON mv.material_id = m.id AND mv.student_id = :progress_student_id
             %s AND m.is_available = 1",
            sprintf($common, 'm')
        ),
        'assignments' => sprintf(
            "SELECT COUNT(*) AS total, COALESCE(SUM(subm.id IS NOT NULL), 0) AS completed
             FROM lms_assignments a
             LEFT JOIN lms_assignment_submissions subm ON subm.assignment_id = a.id AND subm.student_id = :progress_student_id
             %s AND a.is_published = 1",
            sprintf($common, 'a')
        ),
        'quizzes' => sprintf(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(EXISTS(
                        SELECT 1 FROM lms_quiz_attempts qa
                        WHERE qa.quiz_id = q.id AND qa.student_id = :progress_student_id
                          AND qa.status IN ('submitted', 'interrupted', 'timed_out')
                    )), 0) AS completed
             FROM lms_quizzes q%s
               AND q.is_published = 1",
            sprintf($common, 'q')
        ),
        'modules' => "SELECT COUNT(*) AS total,
                             COALESCE(SUM(module_progress.viewed_lessons = module_progress.total_lessons), 0) AS completed
                      FROM (
                          SELECT m.id, COUNT(l.id) AS total_lessons,
                                 COALESCE(SUM(lp.id IS NOT NULL), 0) AS viewed_lessons
                          FROM lms_modules m
                          JOIN section_subjects ss ON ss.id = m.section_subject_id
                          JOIN sections sec ON sec.id = ss.section_id
                          JOIN enrollments e ON e.section_id = sec.id AND e.student_id = :enrollment_student_id
                          JOIN students s ON s.id = e.student_id
                          JOIN lms_lessons l ON l.module_id = m.id AND l.is_published = 1
                          LEFT JOIN lms_lesson_progress lp ON lp.lesson_id = l.id AND lp.student_id = :progress_student_id
                          WHERE s.id = :student_id
                            AND s.enrollment_status = 'enrolled'
                            AND e.status = 'enrolled'
                            AND e.academic_term_id = s.academic_term_id
                            AND e.academic_term_id = sec.academic_term_id
                            AND ss.subject_id = :subject_id
                            AND m.is_published = 1
                          GROUP BY m.id
                      ) AS module_progress",
    ];

    foreach ($queries as $key => $query) {
        $stmt = $pdo->prepare($query);
        $stmt->execute([
            'enrollment_student_id' => $studentId,
            'progress_student_id' => $studentId,
            'student_id' => $studentId,
            'subject_id' => $subjectId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $metrics[$key]['total'] = (int)($row['total'] ?? 0);
        $metrics[$key]['completed'] = min($metrics[$key]['total'], (int)($row['completed'] ?? 0));
    }

    $total = array_sum(array_column($metrics, 'total'));
    $completed = array_sum(array_column($metrics, 'completed'));
    return [
        'items' => $metrics,
        'completed' => $completed,
        'total' => $total,
        'percent' => $total > 0 ? (int)round(($completed / $total) * 100) : 0,
    ];
}

function fetchLmsAnnouncementsForStudentSubject(PDO $pdo, int $studentId, int $subjectId): array
{
    $stmt = $pdo->prepare(
        "SELECT DISTINCT a.id, a.title, a.message, a.published_at, a.is_important,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', author.first_name, author.last_name)), ''), author.username) AS author_name
         FROM lms_announcements a
         JOIN section_subjects ss ON ss.id = a.section_subject_id
         JOIN sections sec ON sec.id = ss.section_id
         JOIN enrollments e ON e.section_id = sec.id AND e.student_id = :enrollment_student_id
         JOIN students s ON s.id = e.student_id
         JOIN users author ON author.id = a.author_id
         WHERE s.id = :student_id
           AND s.enrollment_status = 'enrolled'
           AND e.status = 'enrolled'
           AND e.academic_term_id = s.academic_term_id
           AND e.academic_term_id = sec.academic_term_id
           AND ss.subject_id = :subject_id
           AND a.is_published = 1
           AND a.published_at IS NOT NULL
           AND a.published_at <= NOW()
         ORDER BY a.is_important DESC, a.published_at DESC, a.id DESC"
    );
    $stmt->execute([
        'enrollment_student_id' => $studentId,
        'student_id' => $studentId,
        'subject_id' => $subjectId,
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Creates the existing in-app/email notification for each current student
 * when a future instructor announcement action publishes an announcement.
 */
function notifyLmsAnnouncementRecipients(PDO $pdo, int $announcementId): void
{
    $announcementStmt = $pdo->prepare(
        "SELECT a.title, a.is_important, sub.subject_code, sub.subject_name
         FROM lms_announcements a
         JOIN section_subjects ss ON ss.id = a.section_subject_id
         JOIN subjects sub ON sub.id = ss.subject_id
         WHERE a.id = :announcement_id
           AND a.is_published = 1
           AND a.published_at IS NOT NULL
           AND a.published_at <= NOW()
         LIMIT 1"
    );
    $announcementStmt->execute(['announcement_id' => $announcementId]);
    $announcement = $announcementStmt->fetch(PDO::FETCH_ASSOC);
    if (!$announcement) {
        return;
    }

    $recipientsStmt = $pdo->prepare(
        "SELECT DISTINCT u.id AS user_id
         FROM lms_announcements a
         JOIN section_subjects ss ON ss.id = a.section_subject_id
         JOIN sections sec ON sec.id = ss.section_id
         JOIN enrollments e ON e.section_id = sec.id
         JOIN students s ON s.id = e.student_id
         JOIN users u ON u.id = s.user_id
         WHERE a.id = :announcement_id
           AND s.enrollment_status = 'enrolled'
           AND e.status = 'enrolled'
           AND e.academic_term_id = s.academic_term_id
           AND e.academic_term_id = sec.academic_term_id"
    );
    $recipientsStmt->execute(['announcement_id' => $announcementId]);

    $subjectLabel = trim(($announcement['subject_code'] ?? '') . ' ' . ($announcement['subject_name'] ?? ''));
    $title = !empty($announcement['is_important']) ? 'Important subject announcement' : 'New subject announcement';
    $message = ($subjectLabel ?: 'Your enrolled subject') . ': ' . $announcement['title'];
    $type = !empty($announcement['is_important']) ? 'warning' : 'info';

    foreach ($recipientsStmt->fetchAll(PDO::FETCH_ASSOC) as $recipient) {
        createNotification($pdo, (int)$recipient['user_id'], $title, $message, $type);
    }
}

function fetchLmsMaterialForStudentSubject(PDO $pdo, int $studentId, int $subjectId, int $materialId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT m.id, m.title, m.material_type, m.file_name, m.file_path
         FROM lms_materials m
         JOIN section_subjects ss ON ss.id = m.section_subject_id
         JOIN sections sec ON sec.id = ss.section_id
         JOIN enrollments e ON e.section_id = sec.id
         JOIN students s ON s.id = e.student_id
         WHERE m.id = :material_id
           AND s.id = :student_id
           AND s.enrollment_status = 'enrolled'
           AND e.status = 'enrolled'
           AND e.academic_term_id = s.academic_term_id
           AND e.academic_term_id = sec.academic_term_id
           AND ss.subject_id = :subject_id
           AND m.is_available = 1
         LIMIT 1"
    );
    $stmt->execute([
        'material_id' => $materialId,
        'student_id' => $studentId,
        'subject_id' => $subjectId,
    ]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function fetchLmsAssignmentsForStudentSubject(PDO $pdo, int $studentId, int $subjectId): array
{
    $stmt = $pdo->prepare(
        "SELECT a.id, a.title, a.assignment_type, a.instructions, a.due_at, a.max_score,
                a.allow_late_submissions, a.display_order,
                subm.id AS submission_id, subm.file_name AS submission_file_name,
                subm.submitted_at, subm.score, subm.feedback, subm.graded_at,
                CASE WHEN NOW() > a.due_at THEN 1 ELSE 0 END AS is_past_due,
                CASE WHEN subm.id IS NULL THEN
                    CASE WHEN NOW() > a.due_at THEN 'past_due' ELSE 'not_submitted' END
                WHEN subm.submitted_at > a.due_at THEN 'late' ELSE 'submitted' END AS submission_status,
                CASE WHEN subm.graded_at IS NOT NULL OR subm.score IS NOT NULL OR subm.feedback IS NOT NULL
                    THEN 1 ELSE 0 END AS is_graded
         FROM lms_assignments a
         JOIN section_subjects ss ON ss.id = a.section_subject_id
         JOIN sections sec ON sec.id = ss.section_id
         JOIN enrollments e ON e.section_id = sec.id AND e.student_id = :enrollment_student_id
         JOIN students s ON s.id = e.student_id
         LEFT JOIN lms_assignment_submissions subm
            ON subm.assignment_id = a.id AND subm.student_id = :submission_student_id
         WHERE s.id = :student_id
           AND s.enrollment_status = 'enrolled'
           AND e.status = 'enrolled'
           AND e.academic_term_id = s.academic_term_id
           AND e.academic_term_id = sec.academic_term_id
           AND ss.subject_id = :subject_id
           AND a.is_published = 1
         ORDER BY a.due_at, a.display_order, a.id"
    );
    $stmt->execute([
        'enrollment_student_id' => $studentId,
        'submission_student_id' => $studentId,
        'student_id' => $studentId,
        'subject_id' => $subjectId,
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetchLmsAssignmentFilesForStudentSubject(PDO $pdo, int $studentId, int $subjectId): array
{
    $stmt = $pdo->prepare(
        "SELECT f.id, f.assignment_id, f.file_name
         FROM lms_assignment_files f
         JOIN lms_assignments a ON a.id = f.assignment_id AND a.is_published = 1
         JOIN section_subjects ss ON ss.id = a.section_subject_id
         JOIN sections sec ON sec.id = ss.section_id
         JOIN enrollments e ON e.section_id = sec.id AND e.student_id = :enrollment_student_id
         JOIN students s ON s.id = e.student_id
         WHERE s.id = :student_id
           AND s.enrollment_status = 'enrolled'
           AND e.status = 'enrolled'
           AND e.academic_term_id = s.academic_term_id
           AND e.academic_term_id = sec.academic_term_id
           AND ss.subject_id = :subject_id
         ORDER BY a.due_at, f.id"
    );
    $stmt->execute([
        'enrollment_student_id' => $studentId,
        'student_id' => $studentId,
        'subject_id' => $subjectId,
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetchLmsAssignmentForStudentSubject(
    PDO $pdo,
    int $studentId,
    int $subjectId,
    int $assignmentId,
    bool $lockForUpdate = false
): ?array {
    $stmt = $pdo->prepare(
        "SELECT a.id, a.title, a.due_at, a.allow_late_submissions,
                CASE WHEN NOW() > a.due_at THEN 1 ELSE 0 END AS is_past_due
         FROM lms_assignments a
         JOIN section_subjects ss ON ss.id = a.section_subject_id
         JOIN sections sec ON sec.id = ss.section_id
         JOIN enrollments e ON e.section_id = sec.id AND e.student_id = :enrollment_student_id
         JOIN students s ON s.id = e.student_id
         WHERE a.id = :assignment_id
           AND a.is_published = 1
           AND s.id = :student_id
           AND s.enrollment_status = 'enrolled'
           AND e.status = 'enrolled'
           AND e.academic_term_id = s.academic_term_id
           AND e.academic_term_id = sec.academic_term_id
           AND ss.subject_id = :subject_id
         LIMIT 1" . ($lockForUpdate ? ' FOR UPDATE' : '')
    );
    $stmt->execute([
        'enrollment_student_id' => $studentId,
        'assignment_id' => $assignmentId,
        'student_id' => $studentId,
        'subject_id' => $subjectId,
    ]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function fetchLmsAssignmentFileForStudentSubject(PDO $pdo, int $studentId, int $subjectId, int $fileId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT f.id, f.file_name, f.file_path
         FROM lms_assignment_files f
         JOIN lms_assignments a ON a.id = f.assignment_id AND a.is_published = 1
         JOIN section_subjects ss ON ss.id = a.section_subject_id
         JOIN sections sec ON sec.id = ss.section_id
         JOIN enrollments e ON e.section_id = sec.id AND e.student_id = :enrollment_student_id
         JOIN students s ON s.id = e.student_id
         WHERE f.id = :file_id
           AND s.id = :student_id
           AND s.enrollment_status = 'enrolled'
           AND e.status = 'enrolled'
           AND e.academic_term_id = s.academic_term_id
           AND e.academic_term_id = sec.academic_term_id
           AND ss.subject_id = :subject_id
         LIMIT 1"
    );
    $stmt->execute([
        'enrollment_student_id' => $studentId,
        'file_id' => $fileId,
        'student_id' => $studentId,
        'subject_id' => $subjectId,
    ]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function fetchLmsQuizzesForStudentSubject(PDO $pdo, int $studentId, int $subjectId): array
{
    $stmt = $pdo->prepare(
        "SELECT q.id, q.title, q.instructions, q.time_limit_minutes, q.allowed_attempts,
                q.passing_score,
                q.opens_at, q.closes_at, q.display_order,
                CASE
                    WHEN q.opens_at IS NOT NULL AND NOW() < q.opens_at THEN 'upcoming'
                    WHEN q.closes_at IS NOT NULL AND NOW() > q.closes_at THEN 'closed'
                    ELSE 'available'
                END AS availability_status
         FROM lms_quizzes q
         JOIN section_subjects ss ON ss.id = q.section_subject_id
         JOIN sections sec ON sec.id = ss.section_id
         JOIN enrollments e ON e.section_id = sec.id AND e.student_id = :enrollment_student_id
         JOIN students s ON s.id = e.student_id
         WHERE s.id = :student_id
           AND s.enrollment_status = 'enrolled'
           AND e.status = 'enrolled'
           AND e.academic_term_id = s.academic_term_id
           AND e.academic_term_id = sec.academic_term_id
           AND ss.subject_id = :subject_id
           AND q.is_published = 1
         ORDER BY q.display_order, q.id"
    );
    $stmt->execute([
        'enrollment_student_id' => $studentId,
        'student_id' => $studentId,
        'subject_id' => $subjectId,
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetchLmsQuizAttemptsForStudentSubject(PDO $pdo, int $studentId, int $subjectId): array
{
    $stmt = $pdo->prepare(
        "SELECT a.id, a.quiz_id, a.attempt_number, a.status, a.started_at,
                a.deadline_at, a.submitted_at, a.score, a.total_points
         FROM lms_quiz_attempts a
         JOIN lms_quizzes q ON q.id = a.quiz_id AND q.is_published = 1
         JOIN section_subjects ss ON ss.id = q.section_subject_id
         JOIN sections sec ON sec.id = ss.section_id
         JOIN enrollments e ON e.section_id = sec.id AND e.student_id = :enrollment_student_id
         JOIN students s ON s.id = e.student_id
         WHERE s.id = :student_id
           AND s.enrollment_status = 'enrolled'
           AND e.status = 'enrolled'
           AND e.academic_term_id = s.academic_term_id
           AND e.academic_term_id = sec.academic_term_id
           AND ss.subject_id = :subject_id
         ORDER BY a.quiz_id, a.attempt_number DESC"
    );
    $stmt->execute([
        'enrollment_student_id' => $studentId,
        'student_id' => $studentId,
        'subject_id' => $subjectId,
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetchLmsQuizForStudentSubject(
    PDO $pdo,
    int $studentId,
    int $subjectId,
    int $quizId,
    bool $lockForUpdate = false
): ?array {
    $stmt = $pdo->prepare(
        "SELECT q.id, q.title, q.instructions, q.time_limit_minutes, q.allowed_attempts,
                q.passing_score,
                q.opens_at, q.closes_at,
                CASE WHEN (q.opens_at IS NULL OR NOW() >= q.opens_at)
                          AND (q.closes_at IS NULL OR NOW() <= q.closes_at)
                    THEN 1 ELSE 0 END AS is_available
         FROM lms_quizzes q
         JOIN section_subjects ss ON ss.id = q.section_subject_id
         JOIN sections sec ON sec.id = ss.section_id
         JOIN enrollments e ON e.section_id = sec.id AND e.student_id = :enrollment_student_id
         JOIN students s ON s.id = e.student_id
         WHERE q.id = :quiz_id
           AND q.is_published = 1
           AND s.id = :student_id
           AND s.enrollment_status = 'enrolled'
           AND e.status = 'enrolled'
           AND e.academic_term_id = s.academic_term_id
           AND e.academic_term_id = sec.academic_term_id
           AND ss.subject_id = :subject_id
         LIMIT 1" . ($lockForUpdate ? ' FOR UPDATE' : '')
    );
    $stmt->execute([
        'enrollment_student_id' => $studentId,
        'quiz_id' => $quizId,
        'student_id' => $studentId,
        'subject_id' => $subjectId,
    ]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function fetchLmsQuizAttemptForStudentSubject(PDO $pdo, int $studentId, int $subjectId, int $attemptId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT a.id, a.quiz_id, a.attempt_number, a.status, a.started_at,
                a.deadline_at, a.submitted_at, a.score, a.total_points,
                TIMESTAMPDIFF(SECOND, NOW(), a.deadline_at) AS seconds_remaining,
                q.title AS quiz_title, q.instructions, q.time_limit_minutes, q.passing_score
         FROM lms_quiz_attempts a
         JOIN lms_quizzes q ON q.id = a.quiz_id
         JOIN section_subjects ss ON ss.id = q.section_subject_id
         JOIN sections sec ON sec.id = ss.section_id
         JOIN enrollments e ON e.section_id = sec.id AND e.student_id = :enrollment_student_id
         JOIN students s ON s.id = e.student_id
         WHERE a.id = :attempt_id
           AND a.student_id = :attempt_student_id
           AND s.id = :student_id
           AND s.enrollment_status = 'enrolled'
           AND e.status = 'enrolled'
           AND e.academic_term_id = s.academic_term_id
           AND e.academic_term_id = sec.academic_term_id
           AND ss.subject_id = :subject_id
         LIMIT 1"
    );
    $stmt->execute([
        'enrollment_student_id' => $studentId,
        'attempt_id' => $attemptId,
        'attempt_student_id' => $studentId,
        'student_id' => $studentId,
        'subject_id' => $subjectId,
    ]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function fetchLmsQuizQuestionsForStudent(PDO $pdo, int $quizId): array
{
    $stmt = $pdo->prepare(
        "SELECT q.id AS question_id, q.question_type, q.prompt, q.points,
                c.id AS choice_id, c.choice_text
         FROM lms_quiz_questions q
         LEFT JOIN lms_quiz_choices c ON c.question_id = q.id
         WHERE q.quiz_id = :quiz_id
         ORDER BY q.display_order, q.id, c.display_order, c.id"
    );
    $stmt->execute(['quiz_id' => $quizId]);
    $questions = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $questionId = (int)$row['question_id'];
        if (!isset($questions[$questionId])) {
            $questions[$questionId] = [
                'id' => $questionId,
                'type' => $row['question_type'],
                'prompt' => $row['prompt'],
                'points' => $row['points'],
                'choices' => [],
            ];
        }
        if ($row['choice_id'] !== null) {
            $questions[$questionId]['choices'][] = [
                'id' => (int)$row['choice_id'],
                'text' => $row['choice_text'],
            ];
        }
    }
    return array_values($questions);
}

function fetchLmsQuizResponsesForAttempt(PDO $pdo, int $attemptId): array
{
    $stmt = $pdo->prepare(
        'SELECT question_id, choice_id, answer_text
         FROM lms_quiz_responses
         WHERE attempt_id = :attempt_id'
    );
    $stmt->execute(['attempt_id' => $attemptId]);
    $responses = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $response) {
        $responses[(int)$response['question_id']] = $response;
    }
    return $responses;
}

function scoreLmsQuizResponses(array $answerKeys, array $responses): array
{
    $score = 0.0;
    $totalPoints = 0.0;
    foreach ($answerKeys as $question) {
        $questionId = (int)$question['question_id'];
        $points = max(0.0, (float)$question['points']);
        $totalPoints += $points;
        $response = $responses[$questionId] ?? null;
        if (!$response) {
            continue;
        }

        $isCorrect = false;
        if ($question['question_type'] === 'identification') {
            $normalize = static function ($answer): string {
                $answer = trim((string)$answer);
                $answer = preg_replace('/\s+/u', ' ', $answer) ?? $answer;
                return function_exists('mb_strtolower') ? mb_strtolower($answer, 'UTF-8') : strtolower($answer);
            };
            $isCorrect = $normalize($response['answer_text'] ?? '') !== ''
                && $normalize($response['answer_text'] ?? '') === $normalize($question['correct_answer'] ?? '');
        } else {
            $isCorrect = $question['correct_choice_id'] !== null
                && (int)($response['choice_id'] ?? 0) === (int)$question['correct_choice_id'];
        }
        if ($isCorrect) {
            $score += $points;
        }
    }

    return [
        'score' => round($score, 2),
        'total_points' => round($totalPoints, 2),
    ];
}

function calculateLmsQuizAttemptScore(PDO $pdo, int $quizId, int $attemptId): array
{
    $questionStmt = $pdo->prepare(
        "SELECT q.id AS question_id, q.question_type, q.points, q.correct_answer,
                (SELECT c.id FROM lms_quiz_choices c
                 WHERE c.question_id = q.id AND c.is_correct = 1
                 ORDER BY c.id LIMIT 1) AS correct_choice_id
         FROM lms_quiz_questions q
         WHERE q.quiz_id = :quiz_id
         ORDER BY q.display_order, q.id"
    );
    $questionStmt->execute(['quiz_id' => $quizId]);
    $answerKeys = $questionStmt->fetchAll(PDO::FETCH_ASSOC);
    return scoreLmsQuizResponses($answerKeys, fetchLmsQuizResponsesForAttempt($pdo, $attemptId));
}

function requireLmsAccess(array $pageRoles = ['student']): array
{
    $pageRoles = array_values(array_intersect($pageRoles, LMS_PERMITTED_ROLES));
    checkRole($pageRoles);

    $role = (string)($_SESSION['role'] ?? '');
    if (!lmsRoleIsPermitted($role)) {
        $_SESSION['flash_error'] = 'Access denied: You do not have permission to access the LMS.';
        header('Location: ' . resolveAppUrl('index'));
        exit;
    }

    global $pdo;
    if (!isset($pdo) || !$pdo instanceof PDO) {
        require_once __DIR__ . '/../config/database.php';
    }

    if ($role !== 'student') {
        return ['role' => $role];
    }

    $student = fetchLmsStudentAccessRecord($pdo, (int)$_SESSION['user_id']);

    if (!$student || !lmsStudentMeetsAccessRequirements($student)) {
        $_SESSION['flash_error'] = 'LMS access is available after your section enrollment is confirmed by the Registrar.';
        header('Location: ' . resolveAppUrl('student/dashboard'));
        exit;
    }

    $student['role'] = $role;
    return $student;
}

// ============================================================
// Teacher LMS Guard
// Mirrors the student guard pattern above but applies to the
// teacher role.  Teacher access is NOT gated by payment or
// enrollment status — a users.role = 'teacher' account may
// access the Instructor Management area at any time.
// Subject-level access is scoped to sections where the teacher
// is either the section lead (sections.teacher_id) or a named
// subject instructor (section_subjects.instructor_id).
// ============================================================

/**
 * LMS gate for teacher pages.
 *
 * Call at the top of every teacher LMS page in place of a bare
 * checkRole().  Enforces:
 *   1. User is authenticated and has role 'teacher'.
 *   2. Role is in the LMS_PERMITTED_ROLES allowlist.
 *
 * Returns an array with at least ['role' => 'teacher', 'user_id' => int].
 * No payment or enrollment check is applied to the teacher role.
 */
function requireLmsTeacherAccess(): array
{
    // Step 1 — standard role gate (handles unauthenticated, idle timeout,
    // and DB-synced role check exactly as every other protected page does).
    checkRole(['teacher']);

    $role = (string)($_SESSION['role'] ?? '');

    // Step 2 — double-check the role is in the LMS allowlist.
    // checkRole already confirmed 'teacher', and 'teacher' is in
    // LMS_PERMITTED_ROLES, so this is a belt-and-suspenders assertion.
    if (!lmsRoleIsPermitted($role)) {
        $_SESSION['flash_error'] = 'Access denied: You do not have permission to access the LMS.';
        header('Location: ' . resolveAppUrl('teacher/dashboard'));
        exit;
    }

    // Ensure the PDO connection is available for downstream queries.
    global $pdo;
    if (!isset($pdo) || !$pdo instanceof PDO) {
        require_once __DIR__ . '/../config/database.php';
    }

    return [
        'role'    => $role,
        'user_id' => (int)$_SESSION['user_id'],
    ];
}

/**
 * Fetch all section-subjects assigned to the given teacher.
 *
 * A teacher is assigned to a section-subject when:
 *   a) section_subjects.instructor_id = $teacherUserId, OR
 *   b) sections.teacher_id = $teacherUserId  (section lead covers
 *      every subject in that section for which no named instructor
 *      is explicitly set).
 *
 * Returns an empty array (never an error) when the teacher has no
 * assignments — the UI must render an empty-state rather than crashing.
 *
 * @param PDO $pdo
 * @param int $teacherUserId  users.id of the logged-in teacher
 * @return array[]
 */
function fetchLmsTeacherSubjects(PDO $pdo, int $teacherUserId): array
{
    $stmt = $pdo->prepare(
        "SELECT DISTINCT
                ss.id                                                        AS section_subject_id,
                sub.id                                                       AS subject_id,
                sub.subject_code,
                sub.subject_name,
                sub.units,
                sec.id                                                       AS section_id,
                sec.section_name,
                sec.year_level,
                sec.program,
                COALESCE(ss.day_of_week, sec.day_of_week)                   AS day_of_week,
                COALESCE(ss.start_time,  sec.start_time)                    AS start_time,
                COALESCE(ss.end_time,    sec.end_time)                      AS end_time,
                COALESCE(ss.room,        sec.room)                          AS room,
                at.school_year,
                at.semester,
                -- is this teacher the explicit named instructor for this subject?
                (ss.instructor_id = :check_instructor_id)                   AS is_named_instructor,
                -- is this teacher the section lead?
                (sec.teacher_id = :check_lead_id)                           AS is_section_lead,
                -- enrolled student count for this section
                (SELECT COUNT(*)
                 FROM enrollments e
                 WHERE e.section_id = sec.id
                   AND e.status = 'enrolled')                               AS enrolled_count
         FROM section_subjects ss
         JOIN sections  sec ON sec.id = ss.section_id
         JOIN subjects  sub ON sub.id = ss.subject_id
         LEFT JOIN academic_terms at ON at.id = sec.academic_term_id
         WHERE sec.status = 'active'
           AND (
               ss.instructor_id = :named_instructor_id
               OR (
                   sec.teacher_id = :section_lead_id
                   AND (ss.instructor_id IS NULL OR ss.instructor_id = :section_lead_id2)
               )
           )
         ORDER BY at.school_year DESC, at.semester, sub.subject_code, sub.subject_name"
    );
    $stmt->execute([
        'check_instructor_id' => $teacherUserId,
        'check_lead_id'       => $teacherUserId,
        'named_instructor_id' => $teacherUserId,
        'section_lead_id'     => $teacherUserId,
        'section_lead_id2'    => $teacherUserId,
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Verify that the logged-in teacher owns a specific section-subject.
 *
 * Called on every teacher LMS sub-route that receives a section_subject_id
 * from the URL (e.g. ?section_subject_id=42).  Returns the full row on
 * success, or NULL if the teacher is not the owner.  The calling page is
 * responsible for redirecting/erroring when NULL is returned.
 *
 * This is the backend enforcement half of "a teacher must only see subjects
 * they are actually assigned to" — the UI already filters by
 * fetchLmsTeacherSubjects(), but a direct URL attack must also be blocked.
 *
 * @param PDO $pdo
 * @param int $teacherUserId        users.id of the logged-in teacher
 * @param int $sectionSubjectId     section_subjects.id from the URL
 * @return array|null               Full subject row or null if unauthorized
 */
function verifyTeacherOwnsSubject(PDO $pdo, int $teacherUserId, int $sectionSubjectId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT ss.id                                                        AS section_subject_id,
                sub.id                                                       AS subject_id,
                sub.subject_code,
                sub.subject_name,
                sub.units,
                sec.id                                                       AS section_id,
                sec.section_name,
                sec.year_level,
                sec.program,
                COALESCE(ss.day_of_week, sec.day_of_week)                   AS day_of_week,
                COALESCE(ss.start_time,  sec.start_time)                    AS start_time,
                COALESCE(ss.end_time,    sec.end_time)                      AS end_time,
                COALESCE(ss.room,        sec.room)                          AS room,
                at.school_year,
                at.semester,
                (ss.instructor_id = :check_instructor_id)                   AS is_named_instructor,
                (sec.teacher_id   = :check_lead_id)                         AS is_section_lead
         FROM section_subjects ss
         JOIN sections  sec ON sec.id = ss.section_id
         JOIN subjects  sub ON sub.id = ss.subject_id
         LEFT JOIN academic_terms at ON at.id = sec.academic_term_id
         WHERE ss.id = :section_subject_id
           AND sec.status = 'active'
           AND (
               ss.instructor_id = :named_instructor_id
               OR (
                   sec.teacher_id = :section_lead_id
                   AND (ss.instructor_id IS NULL OR ss.instructor_id = :section_lead_id2)
               )
           )
         LIMIT 1"
    );
    $stmt->execute([
        'section_subject_id'  => $sectionSubjectId,
        'check_instructor_id' => $teacherUserId,
        'check_lead_id'       => $teacherUserId,
        'named_instructor_id' => $teacherUserId,
        'section_lead_id'     => $teacherUserId,
        'section_lead_id2'    => $teacherUserId,
    ]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Convenience wrapper used by every teacher LMS sub-page that requires a
 * valid section_subject_id in the URL.
 *
 * Usage (at the top of each teacher LMS route):
 *
 *   $teacher = requireLmsTeacherAccess();
 *   $subject = requireTeacherSubjectAccess($pdo, $teacher['user_id'], (int)($_GET['section_subject_id'] ?? 0));
 *   // $subject is guaranteed to be owned by this teacher; page continues.
 *
 * On failure (missing param, subject doesn't exist, or teacher doesn't own
 * it) the function sets a flash error and redirects to the Instructor
 * Management hub — it never returns null or throws.
 *
 * @param PDO $pdo
 * @param int $teacherUserId
 * @param int $sectionSubjectId
 * @return array  Verified subject row
 */
function requireTeacherSubjectAccess(PDO $pdo, int $teacherUserId, int $sectionSubjectId): array
{
    if ($sectionSubjectId <= 0) {
        $_SESSION['flash_error'] = 'No subject specified.';
        header('Location: ' . resolveAppUrl('teacher/lms'));
        exit;
    }

    $subject = verifyTeacherOwnsSubject($pdo, $teacherUserId, $sectionSubjectId);

    if ($subject === null) {
        // Either the subject doesn't exist, or this teacher is not its owner.
        // We intentionally give the same generic message in both cases to
        // avoid leaking information about other teachers' subjects.
        $_SESSION['flash_error'] = 'Access denied: you are not assigned to that subject.';
        header('Location: ' . resolveAppUrl('teacher/lms'));
        exit;
    }

    return $subject;
}

// ============================================================
// Registrar LMS Guard (TASK 0.1)
//
// Governs read-only Registrar access to LMS-connected records.
// Enforces:
//   1. Authenticated user has role 'registrar'.
//   2. Role is in LMS_PERMITTED_ROLES allowlist ('student', 'teacher', 'registrar', 'admin').
//   3. Strictly READ-ONLY: any mutation request (POST, PUT, DELETE, PATCH)
//      is rejected with HTTP 403 Forbidden.
//   4. No student-side payment/enrollment gating applies.
//   5. Registrar cannot access teacher-side management or admin settings.
// ============================================================

/**
 * LMS gate for Registrar pages and LMS-connected data views.
 *
 * Call at the top of every Registrar LMS page/route.
 *
 * @return array ['role' => 'registrar', 'user_id' => int]
 */
function requireLmsRegistrarAccess(): array
{
    // Step 1: Role check for registrar (rejects any unauthenticated or non-registrar user)
    checkRole(['registrar']);

    $role = (string)($_SESSION['role'] ?? '');

    // Step 2: Ensure role is in LMS allowlist
    if (!lmsRoleIsPermitted($role)) {
        $_SESSION['flash_error'] = 'Access denied: You do not have permission to access the LMS.';
        header('Location: ' . resolveAppUrl('registrar/dashboard'));
        exit;
    }

    // Step 3: Strictly enforce READ-ONLY access. Any mutation (POST, PUT, DELETE, PATCH) is blocked with HTTP 403.
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        http_response_code(403);
        if (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')) {
            header('Content-Type: application/json');
            echo json_encode([
                'ok'    => false,
                'error' => 'Forbidden: Registrar LMS access is strictly read-only. Mutation requests are not permitted.'
            ]);
            exit;
        }
        $_SESSION['flash_error'] = 'Forbidden: Registrar LMS access is strictly read-only. Mutation requests are not permitted.';
        header('Location: ' . resolveAppUrl('registrar/dashboard'), true, 403);
        exit;
    }

    global $pdo;
    if (!isset($pdo) || !$pdo instanceof PDO) {
        require_once __DIR__ . '/../config/database.php';
    }

    return [
        'role'    => $role,
        'user_id' => (int)($_SESSION['user_id'] ?? 0),
    ];
}

/**
 * Require LMS Administrator access.
 *
 * Enforces:
 *   1. Authenticated session with role = 'admin' (checkRole(['admin'])).
 *   2. Role is in LMS_PERMITTED_ROLES allowlist ('admin').
 *   3. Full administrative oversight: Not gated by student payment/enrollment rules,
 *      and not scoped to "assigned subjects only" like teacher. Admin can view and
 *      manage system-wide LMS data across users, courses, terms, assignments, and reports.
 *   4. Ensures PDO connection is active.
 *
 * @return array{role: string, user_id: int}
 */
function requireLmsAdminAccess(): array
{
    // Step 1: Role check for admin (rejects any unauthenticated or non-admin user)
    checkRole(['admin']);

    $role = (string)($_SESSION['role'] ?? '');

    // Step 2: Ensure role is in LMS allowlist
    if (!lmsRoleIsPermitted($role)) {
        $_SESSION['flash_error'] = 'Access denied: You do not have permission to access the LMS.';
        header('Location: ' . resolveAppUrl('admin/dashboard'));
        exit;
    }

    global $pdo;
    if (!isset($pdo) || !$pdo instanceof PDO) {
        require_once __DIR__ . '/../config/database.php';
    }

    return [
        'role'    => $role,
        'user_id' => (int)($_SESSION['user_id'] ?? 0),
    ];
}

/**
 * Record an administrative LMS accountability entry in the audit_logs table.
 *
 * Follows the existing audit logging pattern established in actions/user_actions.php
 * and actions/academic_term_actions.php.
 *
 * @param PDO $pdo
 * @param int $actorId The admin user ID performing the action
 * @param string $action Standard action descriptor (e.g. 'LMS_TEACHER_ASSIGN', 'LMS_OVERRIDE_ACCESS', 'LMS_TERM_CONFIG')
 * @param string $itemType Target entity type ('user', 'section_subject', 'academic_term', 'enrollment')
 * @param int|null $itemId Target entity primary key
 * @param string $description Human-readable audit narrative
 * @return bool True if logged successfully, false on error
 */
function logLmsAdminAction(PDO $pdo, int $actorId, string $action, string $itemType, ?int $itemId, string $description): bool
{
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO audit_logs (actor_id, action, item_type, item_id, description, created_at)
             VALUES (:actor_id, :action, :item_type, :item_id, :description, NOW())"
        );
        return $stmt->execute([
            'actor_id'    => $actorId,
            'action'      => $action,
            'item_type'   => $itemType,
            'item_id'     => $itemId,
            'description' => substr($description, 0, 255)
        ]);
    } catch (Throwable $e) {
        error_log("Failed to write LMS admin audit log: " . $e->getMessage());
        return false;
    }
}

