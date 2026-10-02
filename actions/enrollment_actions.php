<?php
/**
 * Enrollment Actions Processor
 * Handles cadet enrollment submissions (multi-section checks) and registrar approvals/drops.
 * Secured with proper guards, transactions, capacity validations, and duplicate checks.
 */

require_once '../config/database.php';
require_once '../includes/auth_check.php';
require_once '../includes/academic_terms.php';
require_once '../includes/assessments.php';
require_once '../includes/registration_rules.php';
require_once '../includes/notifications.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['flash_error'] = "Invalid request method.";
    header("Location: ../index");
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
    $_SESSION['flash_error'] = "Security validation failed. Please try again.";
    header("Location: ../student/my_enrollments");
    exit;
}

$action = isset($_POST['action']) ? trim($_POST['action']) : '';

switch ($action) {
    case 'enroll':
        // Secured to Student role only
        require_once '../includes/auth_check.php';
        checkRole(['student']);

        $studentId = isset($_SESSION['student_id']) ? (int)$_SESSION['student_id'] : 0;
        $userId = (int)$_SESSION['user_id'];
        $sectionIds = isset($_POST['section_ids']) && is_array($_POST['section_ids']) ? $_POST['section_ids'] : [];

        // Sanitize: keep only positive integer section IDs
        $sectionIds = array_values(array_filter(array_map('intval', $sectionIds), function ($id) {
            return $id > 0;
        }));

        if (empty($studentId)) {
            // Retrieve student profile ID if session is missing it
            try {
                $stmt = $pdo->prepare("SELECT id, academic_term_id, enrollment_status FROM students WHERE user_id = :user_id LIMIT 1");
                $stmt->execute(['user_id' => $userId]);
                $student = $stmt->fetch();
                if ($student) {
                    $studentId = (int)$student['id'];
                    $_SESSION['student_id'] = $studentId;
                    $_SESSION['enrollment_status'] = $student['enrollment_status'];
                }
            } catch (\PDOException $e) {
                error_log("Retrieve student ID failed: " . $e->getMessage());
            }
        }

        if (empty($studentId)) {
            $_SESSION['flash_error'] = "Student profile record not found.";
            header("Location: ../index");
            exit;
        }

        // Verify Student Eligibility (payment and active academic term are required)
        try {
            $stmt = $pdo->prepare("SELECT enrollment_status, academic_term_id, program_code, program_applying_for, year_level FROM students WHERE id = :id LIMIT 1");
            $stmt->execute(['id' => $studentId]);
            $statusRow = $stmt->fetch();
            $eligibility = $statusRow ? $statusRow['enrollment_status'] : '';
            $activeTerm = requireActiveAcademicTerm($pdo);

            if ($eligibility !== 'paid') {
                $_SESSION['flash_error'] = "You are not eligible to enroll at this time. Payment must be completed before class enrollment.";
                header("Location: ../student/my_enrollments");
                exit;
            }
            if (!$statusRow['academic_term_id'] || (int)$statusRow['academic_term_id'] !== (int)$activeTerm['id']) {
                $_SESSION['flash_error'] = "Your account is not enrolled in the active academic term.";
                header("Location: ../student/my_enrollments");
                exit;
            }

            // Resolve student program and year level
            $studentProgram = trim($statusRow['program_code'] ?? '');
            if ($studentProgram === '') {
                $progApp = trim($statusRow['program_applying_for'] ?? '');
                if (stripos($progApp, 'Marine Transportation') !== false || stripos($progApp, 'BSMT') !== false) {
                    $studentProgram = 'BSMT';
                } elseif (stripos($progApp, 'Marine Engineering') !== false || stripos($progApp, 'BSMarE') !== false) {
                    $studentProgram = 'BSMarE';
                } else {
                    $studentProgram = 'BSMT';
                }
            }

            $studentYearLevel = trim($statusRow['year_level'] ?? '');
            if ($studentYearLevel === '') {
                $studentYearLevel = '1st Year';
            }

            $previousBalance = getPreviousUnpaidAssessmentBalance($pdo, $studentId, (int)$statusRow['academic_term_id']);
            if ($previousBalance !== null) {
                $_SESSION['flash_error'] = 'Enrollment Blocked. You have an outstanding balance of ₱' . number_format((float)$previousBalance['outstanding_balance'], 2) . ' from the previous semester. Please settle your outstanding balance before enrolling for the next semester.';
                header("Location: ../student/my_enrollments");
                exit;
            }
        } catch (\Exception $e) {
            $_SESSION['flash_error'] = "Database validation error. Please try again.";
            header("Location: ../student/my_enrollments");
            exit;
        }

        if (empty($sectionIds)) {
            $_SESSION['flash_error'] = "Please select at least one class section to enroll.";
            header("Location: ../student/my_enrollments");
            exit;
        }

        $schoolYear = $activeTerm['school_year'];
        $semester = $activeTerm['semester'];

        try {
            $pdo->beginTransaction();
            $placeholders = implode(',', array_fill(0, count($sectionIds), '?'));
            $sectionStmt = $pdo->prepare(
                "SELECT s.id, s.section_name, s.course_id, s.schedule, s.capacity, s.program, s.year_level, c.course_code, c.units
                 FROM sections s
                 LEFT JOIN courses c ON c.id = s.course_id
                 WHERE s.id IN ({$placeholders}) AND s.academic_term_id = ?
                 FOR UPDATE"
            );
            $sectionStmt->execute(array_merge($sectionIds, [(int)$activeTerm['id']]));
            $sectionsById = [];
            foreach ($sectionStmt->fetchAll() as $section) {
                $sectionsById[(int)$section['id']] = $section;
            }
            if (count($sectionsById) !== count($sectionIds)) {
                throw new \Exception('One or more selected sections do not belong to the active academic term.');
            }

            // Enforce program and year level matching for each selected section
            foreach ($sectionsById as $sec) {
                if (!empty($sec['program']) && $sec['program'] !== $studentProgram) {
                    throw new \Exception("Enrollment rejected: Section '" . ($sec['section_name'] ?: 'selected') . "' is for {$sec['program']}, which does not match your enrolled program ({$studentProgram}).");
                }
                if (!empty($sec['year_level']) && $sec['year_level'] !== $studentYearLevel) {
                    throw new \Exception("Enrollment rejected: Section '" . ($sec['section_name'] ?: 'selected') . "' is for {$sec['year_level']}, which does not match your current year level ({$studentYearLevel}).");
                }
            }

            $courseIds = [];
            $selectedSchedules = [];
            $selectedUnits = 0.00;
            foreach ($sectionIds as $sectionId) {
                $section = $sectionsById[$sectionId];
                $courseId = (int)$section['course_id'];
                if ($courseId > 0 && isset($courseIds[$courseId])) {
                    throw new \Exception("Enrollment rejected: Course '{$section['course_code']}' was selected more than once.");
                }
                if ($courseId > 0) {
                    $courseIds[$courseId] = true;
                }
                $selectedUnits += (float)($section['units'] ?? 0);
                $selectedSchedules[$sectionId] = parseSchedule($section['schedule']);
            }

            foreach ($selectedSchedules as $firstId => $firstSchedule) {
                foreach ($selectedSchedules as $secondId => $secondSchedule) {
                    if ($firstId >= $secondId) {
                        continue;
                    }
                    if (!empty(array_intersect($firstSchedule['days'], $secondSchedule['days']))
                        && $firstSchedule['start'] < $secondSchedule['end']
                        && $secondSchedule['start'] < $firstSchedule['end']) {
                        throw new \Exception('Enrollment rejected: Two selected sections have overlapping schedules.');
                    }
                }
            }

            // DB-010: LEFT JOIN courses so block-cohort sections (course_id=NULL) are not silently dropped.
            // Also pulls units from section_subjects/subjects to match the assessment calculation.
            $existingStmt = $pdo->prepare(
                "SELECT e.section_id, e.status, s.course_id, s.schedule,
                        COALESCE(c.course_code, s.section_name) AS course_code,
                        COALESCE(
                            (SELECT SUM(sub2.units) FROM section_subjects ss2
                             JOIN subjects sub2 ON sub2.id = ss2.subject_id
                             WHERE ss2.section_id = s.id),
                            c.units,
                            0
                        ) AS units
                 FROM enrollments e
                 JOIN sections s ON s.id = e.section_id
                 LEFT JOIN courses c ON c.id = s.course_id
                 WHERE e.student_id = :student_id AND e.academic_term_id = :academic_term_id AND e.status != 'dropped'
                 FOR UPDATE"
            );
            $existingStmt->execute(['student_id' => $studentId, 'academic_term_id' => $activeTerm['id']]);
            $existingEnrollments = $existingStmt->fetchAll();
            $currentUnits = 0.00;
            $existingCourseIds = [];
            foreach ($existingEnrollments as $existingEnrollment) {
                $currentUnits += (float)$existingEnrollment['units'];
                // Only track course_id conflicts for legacy sections that have a non-NULL course_id.
                // Block-cohort sections (course_id=NULL) cannot be deduplicated by course_id.
                $existingCourseId = $existingEnrollment['course_id'];
                if ($existingCourseId !== null && $existingCourseId > 0) {
                    $existingCourseIds[(int)$existingCourseId] = true;
                }
                $existingSchedule = parseSchedule($existingEnrollment['schedule']);
                foreach ($selectedSchedules as $selectedSchedule) {
                    if (!empty(array_intersect($existingSchedule['days'], $selectedSchedule['days']))
                        && $existingSchedule['start'] < $selectedSchedule['end']
                        && $selectedSchedule['start'] < $existingSchedule['end']) {
                        throw new \RuntimeException('Enrollment rejected: A selected section conflicts with an existing registration.');
                    }
                }
            }

            foreach (array_keys($courseIds) as $courseId) {
                if ($courseId > 0 && isset($existingCourseIds[(int)$courseId])) {
                    throw new \RuntimeException('Enrollment rejected: A selected course is already registered in this term.');
                }
            }


            $maxUnits = (float)$activeTerm['max_units'];
            if ($currentUnits + $selectedUnits > $maxUnits) {
                throw new \RuntimeException('Enrollment rejected: The selected workload exceeds the maximum ' . number_format($maxUnits, 2) . '-unit load for this term.');
            }

            $subjectIdList = [];
            $sectionSubjectMap = [];
            foreach ($sectionIds as $sectionId) {
                $sectionSubjectStmt = $pdo->prepare("
                    SELECT ss.subject_id
                    FROM section_subjects ss
                    WHERE ss.section_id = :section_id
                ");
                $sectionSubjectStmt->execute(['section_id' => $sectionId]);
                $subjectIds = array_column($sectionSubjectStmt->fetchAll(), 'subject_id');
                
                // Fallback: If section has no section_subjects entries, match via course_code
                if (empty($subjectIds)) {
                    $section = $sectionsById[$sectionId];
                    $courseSubStmt = $pdo->prepare("
                        SELECT sub.id 
                        FROM subjects sub
                        WHERE sub.subject_code = :course_code
                    ");
                    $courseSubStmt->execute(['course_code' => $section['course_code']]);
                    $subjectIds = array_column($courseSubStmt->fetchAll(), 'id');
                }

                $sectionSubjectMap[$sectionId] = $subjectIds;
                foreach ($subjectIds as $sid) {
                    $subjectIdList[(int)$sid] = true;
                }
            }

            if (!empty($subjectIdList)) {
                $subjectIdArray = array_keys($subjectIdList);
                $subjectPlaceholders = implode(',', array_fill(0, count($subjectIdArray), '?'));

                $prerequisiteStmt = $pdo->prepare("
                    SELECT subject_id, prerequisite_subject_id
                    FROM subject_prerequisites
                    WHERE subject_id IN ($subjectPlaceholders)
                ");
                $prerequisiteStmt->execute($subjectIdArray);
                $prerequisites = [];
                $allRelevantIds = $subjectIdArray;
                foreach ($prerequisiteStmt->fetchAll() as $prerequisite) {
                    $sid = (int)$prerequisite['subject_id'];
                    $pid = (int)$prerequisite['prerequisite_subject_id'];
                    $prerequisites[$sid][] = $pid;
                    $allRelevantIds[] = $pid;
                }
                $allRelevantIds = array_values(array_unique($allRelevantIds));

                if (!empty($allRelevantIds)) {
                    $extraPlaceholders = implode(',', array_fill(0, count($allRelevantIds), '?'));
                    $extraStmt = $pdo->prepare("
                        SELECT subject_id, prerequisite_subject_id
                        FROM subject_prerequisites
                        WHERE subject_id IN ($extraPlaceholders)
                    ");
                    $extraStmt->execute($allRelevantIds);
                    foreach ($extraStmt->fetchAll() as $prerequisite) {
                        $sid = (int)$prerequisite['subject_id'];
                        $pid = (int)$prerequisite['prerequisite_subject_id'];
                        if (!isset($prerequisites[$sid])) {
                            $prerequisites[$sid] = [];
                        }
                        $prerequisites[$sid][] = $pid;
                    }
                }

                // Check passed subjects (with approved/locked grade submissions and passing mark)
                $completedStmt = $pdo->prepare(
                    "SELECT DISTINCT COALESCE(ss.subject_id, sub.id) AS passed_subject_id
                     FROM student_grades sg
                     JOIN grade_submissions gs ON gs.id = sg.grade_submission_id
                     JOIN enrollments e ON e.id = sg.enrollment_id
                     LEFT JOIN section_subjects ss ON ss.id = sg.section_subject_id
                     LEFT JOIN sections sec ON sec.id = e.section_id
                     LEFT JOIN courses c ON c.id = sec.course_id
                     LEFT JOIN subjects sub ON sub.subject_code = c.course_code
                     WHERE sg.student_id = :student_id
                       AND gs.status IN ('approved', 'locked')
                       AND sg.final_grade IS NOT NULL
                       AND (
                           sg.remarks = 'Passed'
                           OR (sg.final_grade <= 3.0 AND sg.final_grade >= 1.0)
                           OR (sg.final_grade >= 75.0 AND sg.final_grade <= 100.0)
                       )
                       AND (sg.remarks IS NULL OR sg.remarks NOT IN ('Failed', 'Incomplete', 'Dropped', 'INC', 'DRP', '5.0', '5.00'))"
                );
                $completedStmt->execute(['student_id' => $studentId]);
                $completedSubjects = array_fill_keys(array_filter(array_map('intval', $completedStmt->fetchAll(PDO::FETCH_COLUMN))), true);

                foreach ($subjectIdArray as $subjectId) {
                    $queue = $prerequisites[$subjectId] ?? [];
                    $checked = [];
                    while (!empty($queue)) {
                        $pid = array_pop($queue);
                        if (isset($checked[$pid])) {
                            continue;
                        }
                        $checked[$pid] = true;
                        if (!isset($completedSubjects[$pid])) {
                            $prereqNameStmt = $pdo->prepare("SELECT subject_code, subject_name FROM subjects WHERE id = :id LIMIT 1");
                            $prereqNameStmt->execute(['id' => $pid]);
                            $prereqName = $prereqNameStmt->fetch();
                            $prereqDisplay = $prereqName ? $prereqName['subject_code'] . ' - ' . $prereqName['subject_name'] : 'Subject #' . $pid;
                            throw new \RuntimeException('Prerequisite requirement not satisfied. Missing prerequisite: ' . $prereqDisplay);
                        }
                        foreach ($prerequisites[$pid] ?? [] as $nextPid) {
                            if (!isset($checked[$nextPid])) {
                                $queue[] = $nextPid;
                            }
                        }
                    }
                }
            }

            require_once __DIR__ . '/../includes/reservation.php';

            $insertStmt = $pdo->prepare(
                "INSERT INTO enrollments (student_id, section_id, academic_term_id, school_year, semester, status)
                 VALUES (:student_id, :section_id, :academic_term_id, :sy, :sem, 'pending')"
            );
            foreach ($sectionIds as $sectionId) {
                $section = $sectionsById[$sectionId];

                // Check section capacity using existing getSectionAvailableSlots helper
                $slotMetrics = getSectionAvailableSlots($pdo, (int)$sectionId, (int)$studentId);
                if ($slotMetrics['capacity'] <= 0 || $slotMetrics['is_full'] || $slotMetrics['available_slots'] <= 0) {
                    throw new \RuntimeException("Enrollment rejected: Section for '{$section['course_code']}' is full.");
                }

                // Create temporary 48h reservation using existing createTemporaryReservation helper
                $reservation = createTemporaryReservation($pdo, (int)$studentId, (int)$sectionId, 48);

                // Insert pending enrollment record (cleared if hold expires via sweepExpiredReservations)
                $insertStmt->execute([
                    'student_id' => $studentId,
                    'section_id' => $sectionId,
                    'academic_term_id' => $activeTerm['id'],
                    'sy' => $schoolYear,
                    'sem' => $semester
                ]);
            }

            require_once '../includes/assessments.php';
            recalculateAssessment($pdo, $studentId, (int)$activeTerm['id']);

            createNotification($pdo, $userId, 'Registration submitted', 'Your selected class sections were submitted and are awaiting Registrar review.', 'info');
            $pdo->commit();
            $_SESSION['flash_success'] = "Enrollment request submitted successfully. Awaiting registrar verification.";
            header("Location: ../student/my_enrollments");
            exit;

        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['flash_error'] = $e->getMessage();
            header("Location: ../student/my_enrollments");
            exit;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Student enrollment unexpected failure: " . $e->getMessage());
            $_SESSION['flash_error'] = "An unexpected error occurred during enrollment. Please try again.";
            header("Location: ../student/my_enrollments");
            exit;
        }
        break;

    case 'approve':
        // Secured to Registrar and Admin roles only
        require_once '../includes/auth_check.php';
        checkRole(['registrar', 'admin']);

        $enrollmentId = isset($_POST['enrollment_id']) ? (int)$_POST['enrollment_id'] : 0;

        if (empty($enrollmentId)) {
            $_SESSION['flash_error'] = "Enrollment ID is required.";
            header("Location: ../registrar/enrollments");
            exit;
        }

        try {
            require_once __DIR__ . '/../includes/reservation.php';
            // Run sweep to guarantee any expired reservations are processed
            sweepExpiredReservations($pdo);

            $activeTerm = requireActiveAcademicTerm($pdo);
            // Verify enrollment is pending
            $checkStmt = $pdo->prepare("SELECT e.id, e.status, e.academic_term_id, e.section_id, s.user_id, s.id AS student_id, s.enrollment_status AS student_enrollment_status, COALESCE(sec.section_name, c.course_code, 'Section') AS section_label FROM enrollments e JOIN students s ON s.id = e.student_id JOIN sections sec ON sec.id = e.section_id LEFT JOIN courses c ON c.id = sec.course_id WHERE e.id = :id LIMIT 1");
            $checkStmt->execute(['id' => $enrollmentId]);
            $enrollment = $checkStmt->fetch();

            if (!$enrollment) {
                $_SESSION['flash_error'] = "Cannot approve: Registration record not found or reservation expired. The student must re-select an available section.";
                header("Location: ../registrar/enrollments");
                exit;
            }

            if ($enrollment['status'] !== 'pending' || (int)$enrollment['academic_term_id'] !== (int)$activeTerm['id']) {
                $_SESSION['flash_error'] = "Only pending registrations can be approved.";
                header("Location: ../registrar/enrollments");
                exit;
            }

            // Check reservation status in section_reservations if one exists
            $resStmt = $pdo->prepare("SELECT id, status, expires_at FROM section_reservations WHERE student_id = :sid AND section_id = :sec_id ORDER BY id DESC LIMIT 1");
            $resStmt->execute(['sid' => (int)$enrollment['student_id'], 'sec_id' => (int)$enrollment['section_id']]);
            $reservation = $resStmt->fetch();

            if ($reservation) {
                if ($reservation['status'] === 'expired' || strtotime($reservation['expires_at']) <= time()) {
                    $_SESSION['flash_error'] = "Reservation expired, student must re-select a section.";
                    header("Location: ../registrar/enrollments");
                    exit;
                }
                if ($reservation['status'] !== 'active') {
                    $_SESSION['flash_error'] = "Cannot approve: Slot reservation is no longer active ({$reservation['status']}). The student must re-select an available section.";
                    header("Location: ../registrar/enrollments");
                    exit;
                }
            }

            // Verify section capacity
            $slotMetrics = getSectionAvailableSlots($pdo, (int)$enrollment['section_id'], (int)$enrollment['student_id']);
            if ($slotMetrics['capacity'] > 0 && $slotMetrics['available_slots'] <= 0 && empty($slotMetrics['has_reservation'])) {
                $_SESSION['flash_error'] = "Cannot approve: This section is currently full and has no available slots.";
                header("Location: ../registrar/enrollments");
                exit;
            }

            // Re-verify student is still eligible (payment must still be complete)
            if (!in_array($enrollment['student_enrollment_status'], ['paid', 'section_chosen', 'enrolled'], true)) {
                $_SESSION['flash_error'] = "Cannot approve: the student's payment status is no longer valid.";
                header("Location: ../registrar/enrollments");
                exit;
            }

            // Update enrollment status to 'enrolled'
            $stmt = $pdo->prepare("UPDATE enrollments SET status = 'enrolled' WHERE id = :id");
            $stmt->execute(['id' => $enrollmentId]);

            // Also mark any active temporary hold as converted to official enrollment
            if (!empty($enrollment['section_id'])) {
                convertReservationToEnrollment($pdo, (int)$enrollment['student_id'], (int)$enrollment['section_id']);
            }

            // Advance student enrollment_status to 'enrolled' if not already
            if ($enrollment['student_enrollment_status'] === 'paid') {
                $stuStmt = $pdo->prepare("UPDATE students SET enrollment_status = 'enrolled' WHERE id = :id AND enrollment_status = 'paid'");
                $stuStmt->execute(['id' => (int)$enrollment['student_id']]);
            }

            createNotification($pdo, (int)$enrollment['user_id'], 'Registration approved', 'Your registration for ' . $enrollment['section_label'] . ' was approved by the Registrar.', 'success');

            $_SESSION['flash_success'] = "Registration approved. Cadet is now enrolled in the section.";
            header("Location: ../registrar/enrollments");
            exit;

        } catch (\Throwable $e) {
            error_log("Registrar enrollment approval failed: " . $e->getMessage());
            $_SESSION['flash_error'] = "Database error: Failed to approve enrollment.";
            header("Location: ../registrar/enrollments");
            exit;
        }
        break;

    case 'reject':
        // Secured to Registrar and Admin roles only
        require_once '../includes/auth_check.php';
        checkRole(['registrar', 'admin']);

        $enrollmentId = isset($_POST['enrollment_id']) ? (int)$_POST['enrollment_id'] : 0;

        if (empty($enrollmentId)) {
            $_SESSION['flash_error'] = "Enrollment ID is required.";
            header("Location: ../registrar/enrollments");
            exit;
        }

        try {
            $activeTerm = requireActiveAcademicTerm($pdo);
            // Verify enrollment is pending
            $checkStmt = $pdo->prepare("SELECT e.status, e.academic_term_id, s.user_id, s.id AS student_id, COALESCE(sec.section_name, c.course_code, 'Section') AS section_label FROM enrollments e JOIN students s ON s.id = e.student_id JOIN sections sec ON sec.id = e.section_id LEFT JOIN courses c ON c.id = sec.course_id WHERE e.id = :id LIMIT 1");
            $checkStmt->execute(['id' => $enrollmentId]);
            $enrollment = $checkStmt->fetch();

            if (!$enrollment || $enrollment['status'] !== 'pending' || (int)$enrollment['academic_term_id'] !== (int)$activeTerm['id']) {
                $_SESSION['flash_error'] = "Only pending registrations can be rejected.";
                header("Location: ../registrar/enrollments");
                exit;
            }

            // Begin database transaction for atomic update & recalculation
            $pdo->beginTransaction();

            // Update status to 'dropped'
            $stmt = $pdo->prepare("UPDATE enrollments SET status = 'dropped' WHERE id = :id");
            $stmt->execute(['id' => $enrollmentId]);

            // Recalculate assessment
            require_once '../includes/assessments.php';
            recalculateAssessment($pdo, (int)$enrollment['student_id'], (int)$activeTerm['id']);

            createNotification($pdo, (int)$enrollment['user_id'], 'Registration update', 'Your registration for ' . $enrollment['course_code'] . ' was not approved. Please contact the Registrar\'s Office if you need assistance.', 'warning');

            $pdo->commit();
            $_SESSION['flash_success'] = "Registration rejected and status set to 'dropped'.";
            header("Location: ../registrar/enrollments");
            exit;

        } catch (\Throwable $e) {
            error_log("Registrar enrollment drop failed: " . $e->getMessage());
            $_SESSION['flash_error'] = "Database error: Failed to reject enrollment.";
            header("Location: ../registrar/enrollments");
            exit;
        }
        break;

    default:
        $_SESSION['flash_error'] = "Invalid enrollment action specified.";
        header("Location: ../index");
        exit;
}
