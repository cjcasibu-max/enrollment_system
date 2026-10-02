<?php
/**
 * Enrollee Section Choice Actions
 * Handles enrollee class section selection and workload queueing.
 */

require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/notifications.php';
require_once '../includes/assessments.php';
require_once __DIR__ . '/../includes/walk_in.php';
require_once __DIR__ . '/../includes/reservation.php';
global $pdo;

$action = $_POST['action'] ?? '';

switch ($action) {

    // ---------------------------------------------------------------
    // choose_section: enrollee selects a section
    //   → creates temporary 48-hour reservation
    //   → inserts into enrollments (status=pending)
    //   → enrollment_status = 'section_chosen'
    // ---------------------------------------------------------------
    case 'choose_section':
        // Only enrollees may choose a section
        checkRole(['enrollee']);

        // CSRF protection — consistent with all other action handlers
        if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
            $_SESSION['flash_error'] = 'Invalid request token. Please try again.';
            header('Location: ../enrollee/sections');
            exit;
        }

        $sectionId = isset($_POST['section_id']) ? (int)$_POST['section_id'] : 0;

        if ($sectionId <= 0) {
            $_SESSION['flash_error'] = 'Invalid section selected.';
            header('Location: ../enrollee/sections');
            exit;
        }

        try {
            // Load the student record for the logged-in enrollee
            $stuStmt = $pdo->prepare("SELECT id, application_status, enrollment_status, program_code, program_applying_for, year_level, civil_status FROM students WHERE user_id = :uid LIMIT 1");
            $stuStmt->execute(['uid' => (int)$_SESSION['user_id']]);
            $stu = $stuStmt->fetch();

            if (!$stu || $stu['application_status'] !== 'eligible_to_enroll') {
                $_SESSION['flash_error'] = 'You are not yet eligible to choose a section.';
                header('Location: ../enrollee/dashboard');
                exit;
            }

            // Resolve student program and year level
            $studentProgram = trim($stu['program_code'] ?? '');
            if ($studentProgram === '') {
                $progApp = trim($stu['program_applying_for'] ?? '');
                if (stripos($progApp, 'Marine Transportation') !== false || stripos($progApp, 'BSMT') !== false) {
                    $studentProgram = 'BSMT';
                } elseif (stripos($progApp, 'Marine Engineering') !== false || stripos($progApp, 'BSMarE') !== false) {
                    $studentProgram = 'BSMarE';
                } else {
                    $studentProgram = 'BSMT';
                }
            }
            $studentYearLevel = trim($stu['year_level'] ?? '');
            if ($studentYearLevel === '') {
                $studentYearLevel = '1st Year';
            }

            if ($stu['enrollment_status'] === 'section_chosen' || $stu['enrollment_status'] === 'paid' || $stu['enrollment_status'] === 'enrolled') {
                $isChanging = (($_POST['is_changing'] ?? '0') === '1');
                // Only allow re-choice if in section_chosen and explicitly changing
                if (!$isChanging || !in_array($stu['enrollment_status'], ['section_chosen'], true)) {
                    $_SESSION['flash_error'] = 'You have already chosen a section and cannot change at this stage.';
                    header('Location: ../enrollee/my_section');
                    exit;
                }
            } else {
                $isChanging = false;
            }

            // Validate section exists, is active, and matches student's program and year level
            $secStmt = $pdo->prepare("
                SELECT se.*, c.course_name, c.course_code, at.id AS term_id, at.school_year, at.semester
                FROM sections se
                LEFT JOIN courses c ON c.id = se.course_id
                LEFT JOIN academic_terms at ON at.id = se.academic_term_id
                WHERE se.id = :id
                LIMIT 1
            ");
            $secStmt->execute(['id' => $sectionId]);
            $section = $secStmt->fetch();

            if (!$section) {
                $_SESSION['flash_error'] = 'Selected section not found.';
                header('Location: ../enrollee/sections');
                exit;
            }

            if (!empty($section['program']) && $section['program'] !== $studentProgram) {
                $_SESSION['flash_error'] = "This section belongs to {$section['program']}, which does not match your enrolled program ({$studentProgram}).";
                header('Location: ../enrollee/sections');
                exit;
            }

            if (!empty($section['year_level']) && $section['year_level'] !== $studentYearLevel) {
                $_SESSION['flash_error'] = "This section is for {$section['year_level']}, which does not match your year level ({$studentYearLevel}).";
                header('Location: ../enrollee/sections');
                exit;
            }

            $pdo->beginTransaction();

            // If changing section: drop prior pending enrollment & reservations for this student
            if ($isChanging) {
                cancelStudentReservation($pdo, (int)$stu['id']);
                $pdo->prepare(
                    "DELETE FROM enrollments WHERE student_id = :sid AND status IN ('pending','section_chosen')"
                )->execute(['sid' => $stu['id']]);
                $pdo->prepare("DELETE FROM student_selected_subjects WHERE student_id = :sid AND is_finalized = 0")
                    ->execute(['sid' => $stu['id']]);
                // Reset enrollment status so we can re-set it below
                $pdo->prepare("UPDATE students SET enrollment_status = 'draft' WHERE id = :id")
                    ->execute(['id' => $stu['id']]);
            }

            // Create temporary slot reservation (48 hours)
            $reservation = createTemporaryReservation($pdo, (int)$stu['id'], $sectionId, 48);

            $subjectStmt = $pdo->prepare("
                SELECT ss.subject_id, sub.subject_code, sub.subject_name, sub.units
                FROM section_subjects ss
                JOIN subjects sub ON sub.id = ss.subject_id
                WHERE ss.section_id = :section_id
            ");
            $subjectStmt->execute(['section_id' => $sectionId]);
            $selectedSubjects = $subjectStmt->fetchAll();

            $insertSubjectStmt = $pdo->prepare("
                INSERT INTO student_selected_subjects (student_id, section_id, subject_id, subject_code, subject_name, units, is_finalized)
                VALUES (:student_id, :section_id, :subject_id, :subject_code, :subject_name, :units, 0)
                ON DUPLICATE KEY UPDATE
                    subject_code = VALUES(subject_code),
                    subject_name = VALUES(subject_name),
                    units = VALUES(units),
                    is_finalized = 0,
                    finalized_at = NULL,
                    finalized_by = NULL
            ");
            foreach ($selectedSubjects as $subject) {
                $insertSubjectStmt->execute([
                    'student_id' => $stu['id'],
                    'section_id' => $sectionId,
                    'subject_id' => (int)$subject['subject_id'],
                    'subject_code' => $subject['subject_code'],
                    'subject_name' => $subject['subject_name'],
                    'units' => (float)($subject['units'] ?? 0),
                ]);
            }

            // Advance the student's enrollment status
            $pdo->prepare("UPDATE students SET enrollment_status = 'section_chosen' WHERE id = :id")
                ->execute(['id' => $stu['id']]);

            $expiresAtStr = !empty($reservation['expires_at']) ? date('M d, Y h:i A', strtotime($reservation['expires_at'])) : '48 hours from now';
            $_SESSION['reservation_expires_at'] = $reservation['expires_at'] ?? null;

            // Notify registrar(s)
            $regStmt = $pdo->query("SELECT id FROM users WHERE role IN ('registrar','admin') AND is_active = 1");
            $registrars = $regStmt->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($registrars as $regUserId) {
                createNotification(
                    $pdo,
                    (int)$regUserId,
                    'Applicant Reserved a Section Slot',
                    'An applicant has temporarily reserved a slot in section ' . ($section['section_name'] ?? '') . ' (valid for 48 hours).',
                    'info'
                );
            }

            // Notify the enrollee with explicit 48-hour expiration notice and physical walk-in submission instructions
            $enrolleeUserId = (int)$_SESSION['user_id'];
            if ($enrolleeUserId > 0) {
                $marriageNote = (($stu['civil_status'] ?? '') === 'Married')
                    ? ', PSA Marriage Certificate'
                    : '';
                $walkInNoticeMsg = 'Your slot in section "' . ($section['section_name'] ?? '') . '" has been temporarily reserved for 48 hours (valid until ' . $expiresAtStr . '). '
                    . 'IMPORTANT: You must visit the campus in person (walk-in) and submit the required physical document photocopies to the Registrar\'s Office before this deadline: '
                    . 'Form 137/SF-10, SHS Diploma/Certificate, Good Moral Certificate, PSA Birth Certificate, '
                    . 'Medical Screening Clearance' . $marriageNote . ', and ID Photo. '
                    . 'If physical documents are not submitted within 48 hours, your slot reservation will automatically expire and release back to other cadets.';
                createNotification(
                    $pdo,
                    $enrolleeUserId,
                    'Temporary Slot Reserved (48 Hours Valid)',
                    $walkInNoticeMsg,
                    'warning'
                );
            }

            $pdo->commit();

            $_SESSION['flash_success'] = 'Section slot reserved! Your 48-hour temporary hold is active.';
            // Show the walk-in document submission popup on the next page load.
            $_SESSION['walk_in_notice'] = '1';
            header('Location: ../enrollee/my_section');
            exit;

        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Choose section error: ' . $e->getMessage());
            $_SESSION['flash_error'] = 'Database error while selecting section: ' . $e->getMessage();
            header('Location: ../enrollee/sections');
            exit;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['flash_error'] = $e->getMessage();
            header('Location: ../enrollee/sections');
            exit;
        }

    case 'approve_paid_walk_in':
        checkRole(['registrar', 'admin']);

        if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
            $_SESSION['flash_error'] = 'Invalid request token. Please try again.';
            header('Location: ../registrar/students');
            exit;
        }

        $studentId = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
        $notes = isset($_POST['walk_in_notes']) ? trim((string)$_POST['walk_in_notes']) : '';

        if ($studentId <= 0) {
            $_SESSION['flash_error'] = 'Student is required.';
            header('Location: ../registrar/students');
            exit;
        }

        try {
            approvePaidWalkIn($pdo, (int)$_SESSION['user_id'], $studentId, $notes);
            $_SESSION['flash_success'] = 'The walk-in student was approved and moved to enrolled status.';
            header('Location: ../registrar/students');
            exit;
        } catch (Throwable $e) {
            error_log('Approve paid walk-in failed: ' . $e->getMessage());
            $_SESSION['flash_error'] = 'Unable to approve this paid walk-in student.';
            header('Location: ../registrar/students');
            exit;
        }

    case 'finalize_walk_in':
        checkRole(['registrar', 'admin']);

        if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
            $_SESSION['flash_error'] = 'Invalid request token. Please try again.';
            header('Location: ../registrar/students');
            exit;
        }

        $studentId = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
        $sectionId = isset($_POST['section_id']) ? (int)$_POST['section_id'] : 0;
        $notes = isset($_POST['walk_in_notes']) ? trim((string)$_POST['walk_in_notes']) : '';

        if ($studentId <= 0 || $sectionId <= 0) {
            $_SESSION['flash_error'] = 'Student and section are required.';
            header('Location: ../registrar/students');
            exit;
        }

        try {
            $stuStmt = $pdo->prepare("SELECT id, user_id, academic_term_id, enrollment_status FROM students WHERE id = :id LIMIT 1 FOR UPDATE");
            $stuStmt->execute(['id' => $studentId]);
            $student = $stuStmt->fetch();

            if (!$student) {
                $_SESSION['flash_error'] = 'Student record not found.';
                header('Location: ../registrar/students');
                exit;
            }

            if (!in_array($student['enrollment_status'], ['section_chosen'], true)) {
                $_SESSION['flash_error'] = 'Only students with a chosen section can be finalized as walk-in ready.';
                header('Location: ../registrar/students');
                exit;
            }

            $subjectIds = isset($_POST['subject_ids']) && is_array($_POST['subject_ids']) ? array_filter(array_map('intval', $_POST['subject_ids'])) : [];
            $subjectIds = array_values(array_unique($subjectIds));

            $pdo->beginTransaction();

            $clearStmt = $pdo->prepare("DELETE FROM student_selected_subjects WHERE student_id = :student_id AND section_id = :section_id");
            $clearStmt->execute(['student_id' => $studentId, 'section_id' => $sectionId]);

            if (empty($subjectIds)) {
                throw new RuntimeException('Please select at least one subject to finalize enrollment.');
            }

            $subjectList = implode(',', array_fill(0, count($subjectIds), '?'));
            $subjectStmt = $pdo->prepare(
                "SELECT s.id, s.subject_code, s.subject_name, s.units
                 FROM subjects s
                 WHERE s.id IN ($subjectList)"
            );
            $subjectStmt->execute($subjectIds);
            $subjects = $subjectStmt->fetchAll();

            if (count($subjects) !== count($subjectIds)) {
                throw new RuntimeException('One or more selected subjects could not be validated.');
            }

            $insertStmt = $pdo->prepare("
                INSERT INTO student_selected_subjects (student_id, section_id, subject_id, subject_code, subject_name, units, is_finalized, finalized_at, finalized_by)
                VALUES (:student_id, :section_id, :subject_id, :subject_code, :subject_name, :units, 1, NOW(), :finalized_by)
            ");
            foreach ($subjects as $subject) {
                $insertStmt->execute([
                    'student_id' => $studentId,
                    'section_id' => $sectionId,
                    'subject_id' => (int)$subject['id'],
                    'subject_code' => $subject['subject_code'],
                    'subject_name' => $subject['subject_name'],
                    'units' => (float)($subject['units'] ?? 0),
                    'finalized_by' => (int)$_SESSION['user_id'],
                ]);
            }

            // Convert temporary reservation to official enrollment
            convertReservationToEnrollment($pdo, $studentId, $sectionId);

            $updateStmt = $pdo->prepare("UPDATE students SET enrollment_status = 'walk_in_ready' WHERE id = :id");
            $updateStmt->execute(['id' => $studentId]);

            $studentTermId = (int)($student['academic_term_id'] ?? 0);
            $assessmentId = 0;
            if ($studentTermId > 0) {
                $assessmentId = generateAssessmentFromFinalizedSubjects($pdo, $studentId, $studentTermId);
            }

            // Finalize tuition assessment if provided or confirm calculated
            $finalizedAmountInput = isset($_POST['finalized_amount']) ? trim((string)$_POST['finalized_amount']) : '';
            $finalizationNotes = isset($_POST['finalization_notes']) ? trim((string)$_POST['finalization_notes']) : $notes;
            $finalizedAmount = null;

            if ($assessmentId > 0) {
                if ($finalizedAmountInput !== '' && is_numeric($finalizedAmountInput)) {
                    $finalizedAmount = (float)$finalizedAmountInput;
                    finalizeAssessmentTuition($pdo, $assessmentId, $finalizedAmount, (int)$_SESSION['user_id'], $finalizationNotes);
                } else {
                    $totals = getAssessmentTotals($pdo, $assessmentId);
                    $finalizedAmount = (float)($totals['calculated_amount'] ?? $totals['total_amount']);
                    finalizeAssessmentTuition($pdo, $assessmentId, $finalizedAmount, (int)$_SESSION['user_id'], $finalizationNotes);
                }
            }

            if ($notes !== '') {
                $pdo->prepare("UPDATE students SET walk_in_notes = :notes WHERE id = :id")->execute([
                    'notes' => $notes,
                    'id' => $studentId,
                ]);
            }

            $amountNotice = ($finalizedAmount !== null && $finalizedAmount > 0) 
                ? ' Total finalized tuition is ₱' . number_format($finalizedAmount, 2) . '.' 
                : '';
            createNotification(
                $pdo,
                (int)$student['user_id'],
                'Tuition Finalized & Walk-in Complete',
                'Your section subjects and tuition have been reviewed and finalized by the registrar.' . $amountNotice . ' Please proceed to the Cashier to complete payment.',
                'success'
            );

            $pdo->commit();
            $_SESSION['flash_success'] = 'The selected subject list and tuition were finalized and the student is now marked as walk-in ready.';
            header('Location: ../registrar/students');
            exit;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Finalize walk-in failed: ' . $e->getMessage());
            $_SESSION['flash_error'] = 'Failed to finalize subjects: ' . $e->getMessage();
            header('Location: ../registrar/student_selection_review?student_id=' . $studentId);
            exit;
        }

    default:
        $_SESSION['flash_error'] = 'Invalid action.';
        header('Location: ../enrollee/dashboard');
        exit;
}
