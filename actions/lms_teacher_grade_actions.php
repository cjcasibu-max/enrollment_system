<?php
/**
 * Teacher LMS - Grade Action Handler (Phase 7)
 * Actions: save_grades, submit_grades, calculate_grades (AJAX)
 * Security: POST-only, CSRF, teacher ownership re-verified on every write.
 * Data: writes to student_grades and grade_submissions (shared tables read by student LMS and Registrar).
 */

require_once '../includes/lms_access.php';

$teacher = requireLmsTeacherAccess();
$userId  = $teacher['user_id'];

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

$action           = trim((string)($_POST['action'] ?? ''));
$sectionSubjectId = (int)filter_input(INPUT_POST, 'section_subject_id', FILTER_VALIDATE_INT);
$isAjax           = ($action === 'calculate_grades');

$returnBase = $sectionSubjectId > 0
    ? resolveAppUrl('teacher/lms_grades?section_subject_id=' . $sectionSubjectId)
    : resolveAppUrl('teacher/lms_grades');

if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Session expired. Please refresh and try again.']);
        exit;
    }
    $_SESSION['flash_error'] = 'Your session token expired. Please try again.';
    header('Location: ' . $returnBase);
    exit;
}

if ($sectionSubjectId < 1) {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Subject selection is required.']);
        exit;
    }
    $_SESSION['flash_error'] = 'Subject selection is required.';
    header('Location: ' . $returnBase);
    exit;
}

// Ownership verification
$subject = verifyTeacherOwnsSubject($pdo, $userId, $sectionSubjectId);
if (!$subject) {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Access denied: you are not assigned to that subject.']);
        exit;
    }
    $_SESSION['flash_error'] = 'Access denied: you are not assigned to that subject.';
    header('Location: ' . resolveAppUrl('teacher/lms'));
    exit;
}

$sectionId = (int)$subject['section_id'];

// Helper to sanitize score/grade input
function lmsTgParseGrade(?string $val): ?float {
    if ($val === null) return null;
    $trimmed = trim($val);
    if ($trimmed === '') return null;
    $f = filter_var($trimmed, FILTER_VALIDATE_FLOAT);
    if ($f === false || $f < 0 || $f > 100) {
        throw new DomainException('Grades must be valid numbers between 0.00 and 100.00.');
    }
    return round($f, 2);
}

try {
    switch ($action) {

        /* ===== SAVE GRADES (DRAFT) ========================================= */
        case 'save_grades': {
            $pdo->beginTransaction();

            // Check or create grade_submissions record for this section & term
            $subStmt = $pdo->prepare('SELECT id, status FROM grade_submissions WHERE section_id = :sec_id AND academic_term_id = :term_id FOR UPDATE');
            $subStmt->execute(['sec_id' => $sectionId, 'term_id' => $subject['academic_term_id']]);
            $submission = $subStmt->fetch(PDO::FETCH_ASSOC);

            if ($submission && in_array($submission['status'], ['approved', 'locked'], true)) {
                throw new DomainException('Grades for this section have already been approved and locked by the Registrar.');
            }

            if (!$submission) {
                $insSub = $pdo->prepare("INSERT INTO grade_submissions (section_id, academic_term_id, teacher_id, status, created_at) VALUES (:sec_id, :term_id, :tid, 'draft', NOW())");
                $insSub->execute([
                    'sec_id'  => $sectionId,
                    'term_id' => $subject['academic_term_id'],
                    'tid'     => $userId,
                ]);
                $submissionId = (int)$pdo->lastInsertId();
            } else {
                $submissionId = (int)$submission['id'];
                // If it was 'submitted', saving drafts resets to 'draft' or keeps existing status
            }

            // Fetch enrolled students in section
            $enrStmt = $pdo->prepare("SELECT id, student_id FROM enrollments WHERE section_id = :sec_id AND status = 'enrolled'");
            $enrStmt->execute(['sec_id' => $sectionId]);
            $enrollments = $enrStmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($enrollments)) {
                throw new DomainException('No enrolled students found for this section.');
            }

            $prelims   = $_POST['prelim'] ?? [];
            $midterms  = $_POST['midterm'] ?? [];
            $finalExams= $_POST['final_exam'] ?? [];
            $finalGrades= $_POST['final_grade'] ?? [];
            $remarksArr= $_POST['remarks'] ?? [];

            $saveStmt = $pdo->prepare(
                'INSERT INTO student_grades (grade_submission_id, section_subject_id, enrollment_id, student_id, prelim_grade, midterm_grade, final_exam_grade, final_grade, remarks, created_at)
                 VALUES (:submission_id, :section_subject_id, :enrollment_id, :student_id, :prelim, :midterm, :final_exam, :final_grade, :remarks, NOW())
                 ON DUPLICATE KEY UPDATE
                    prelim_grade = VALUES(prelim_grade),
                    midterm_grade = VALUES(midterm_grade),
                    final_exam_grade = VALUES(final_exam_grade),
                    final_grade = VALUES(final_grade),
                    remarks = VALUES(remarks)'
            );

            foreach ($enrollments as $enr) {
                $enrId = (int)$enr['id'];
                $stId  = (int)$enr['student_id'];

                $pGrade = lmsTgParseGrade($prelims[$enrId] ?? null);
                $mGrade = lmsTgParseGrade($midterms[$enrId] ?? null);
                $feGrade= lmsTgParseGrade($finalExams[$enrId] ?? null);
                $fgGrade= lmsTgParseGrade($finalGrades[$enrId] ?? null);
                $rem    = trim((string)($remarksArr[$enrId] ?? ''));

                $saveStmt->execute([
                    'submission_id'      => $submissionId,
                    'section_subject_id' => $sectionSubjectId,
                    'enrollment_id'      => $enrId,
                    'student_id'         => $stId,
                    'prelim'             => $pGrade,
                    'midterm'            => $mGrade,
                    'final_exam'         => $feGrade,
                    'final_grade'        => $fgGrade,
                    'remarks'            => $rem !== '' ? $rem : null,
                ]);
            }

            $pdo->commit();
            $_SESSION['flash_success'] = 'Subject grades and instructor remarks saved successfully.';
            header('Location: ' . resolveAppUrl('teacher/lms_grades?section_subject_id=' . $sectionSubjectId));
            exit;
        }

        /* ===== SUBMIT GRADES TO REGISTRAR ================================== */
        case 'submit_grades': {
            $pdo->beginTransaction();

            $subStmt = $pdo->prepare('SELECT id, status FROM grade_submissions WHERE section_id = :sec_id AND academic_term_id = :term_id FOR UPDATE');
            $subStmt->execute(['sec_id' => $sectionId, 'term_id' => $subject['academic_term_id']]);
            $submission = $subStmt->fetch(PDO::FETCH_ASSOC);

            if ($submission && in_array($submission['status'], ['approved', 'locked'], true)) {
                throw new DomainException('Grades for this section have already been approved and locked by the Registrar.');
            }

            if (!$submission) {
                $insSub = $pdo->prepare("INSERT INTO grade_submissions (section_id, academic_term_id, teacher_id, status, created_at) VALUES (:sec_id, :term_id, :tid, 'draft', NOW())");
                $insSub->execute([
                    'sec_id'  => $sectionId,
                    'term_id' => $subject['academic_term_id'],
                    'tid'     => $userId,
                ]);
                $submissionId = (int)$pdo->lastInsertId();
            } else {
                $submissionId = (int)$submission['id'];
            }

            // Save posted values first
            $enrStmt = $pdo->prepare("SELECT id, student_id FROM enrollments WHERE section_id = :sec_id AND status = 'enrolled'");
            $enrStmt->execute(['sec_id' => $sectionId]);
            $enrollments = $enrStmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($enrollments)) {
                throw new DomainException('No enrolled students found for this section.');
            }

            $prelims   = $_POST['prelim'] ?? [];
            $midterms  = $_POST['midterm'] ?? [];
            $finalExams= $_POST['final_exam'] ?? [];
            $finalGrades= $_POST['final_grade'] ?? [];
            $remarksArr= $_POST['remarks'] ?? [];

            $saveStmt = $pdo->prepare(
                'INSERT INTO student_grades (grade_submission_id, section_subject_id, enrollment_id, student_id, prelim_grade, midterm_grade, final_exam_grade, final_grade, remarks, created_at)
                 VALUES (:submission_id, :section_subject_id, :enrollment_id, :student_id, :prelim, :midterm, :final_exam, :final_grade, :remarks, NOW())
                 ON DUPLICATE KEY UPDATE
                    prelim_grade = VALUES(prelim_grade),
                    midterm_grade = VALUES(midterm_grade),
                    final_exam_grade = VALUES(final_exam_grade),
                    final_grade = VALUES(final_grade),
                    remarks = VALUES(remarks)'
            );

            foreach ($enrollments as $enr) {
                $enrId = (int)$enr['id'];
                $stId  = (int)$enr['student_id'];

                $pGrade = lmsTgParseGrade($prelims[$enrId] ?? null);
                $mGrade = lmsTgParseGrade($midterms[$enrId] ?? null);
                $feGrade= lmsTgParseGrade($finalExams[$enrId] ?? null);
                $fgGrade= lmsTgParseGrade($finalGrades[$enrId] ?? null);
                $rem    = trim((string)($remarksArr[$enrId] ?? ''));

                if ($fgGrade === null) {
                    throw new DomainException('Every enrolled student must have a Final Grade before submitting to the Registrar.');
                }

                $saveStmt->execute([
                    'submission_id'      => $submissionId,
                    'section_subject_id' => $sectionSubjectId,
                    'enrollment_id'      => $enrId,
                    'student_id'         => $stId,
                    'prelim'             => $pGrade,
                    'midterm'            => $mGrade,
                    'final_exam'         => $feGrade,
                    'final_grade'        => $fgGrade,
                    'remarks'            => $rem !== '' ? $rem : null,
                ]);
            }

            // Update submission status to 'submitted'
            $updSub = $pdo->prepare("UPDATE grade_submissions SET status = 'submitted', submitted_at = NOW() WHERE id = :id");
            $updSub->execute(['id' => $submissionId]);

            // Notify registrars
            $regStmt = $pdo->query("SELECT id FROM users WHERE role IN ('registrar', 'admin') AND status = 'active'");
            $regUsers = $regStmt->fetchAll(PDO::FETCH_COLUMN);
            foreach ($regUsers as $regId) {
                createNotification(
                    $pdo,
                    (int)$regId,
                    'Grades Submitted for Review',
                    "Instructor submitted grades for {$subject['subject_code']} ({$subject['section_name']}). Ready for Registrar review.",
                    'info'
                );
            }

            $pdo->commit();
            $_SESSION['flash_success'] = 'Grades successfully submitted to the Registrar for final review and approval.';
            header('Location: ' . resolveAppUrl('teacher/lms_grades?section_subject_id=' . $sectionSubjectId));
            exit;
        }

        /* ===== AUTO-CALCULATE GRADES (AJAX) ================================ */
        case 'calculate_grades': {
            header('Content-Type: application/json; charset=utf-8');

            $formula = trim((string)($_POST['formula'] ?? 'periodic_weighted')); // 'equal_periodic', 'periodic_weighted', 'with_coursework'

            // Fetch enrolled students with their coursework stats
            $enrStmt = $pdo->prepare("SELECT id, student_id FROM enrollments WHERE section_id = :sec_id AND status = 'enrolled'");
            $enrStmt->execute(['sec_id' => $sectionId]);
            $enrollments = $enrStmt->fetchAll(PDO::FETCH_ASSOC);

            $prelims   = $_POST['prelim'] ?? [];
            $midterms  = $_POST['midterm'] ?? [];
            $finalExams= $_POST['final_exam'] ?? [];
            $courseworkAvgs = $_POST['coursework_avg'] ?? [];

            $calculated = [];

            foreach ($enrollments as $enr) {
                $enrId = (int)$enr['id'];
                $p  = isset($prelims[$enrId]) && $prelims[$enrId] !== '' ? (float)$prelims[$enrId] : null;
                $m  = isset($midterms[$enrId]) && $midterms[$enrId] !== '' ? (float)$midterms[$enrId] : null;
                $fe = isset($finalExams[$enrId]) && $finalExams[$enrId] !== '' ? (float)$finalExams[$enrId] : null;
                $cw = isset($courseworkAvgs[$enrId]) && $courseworkAvgs[$enrId] !== '' ? (float)$courseworkAvgs[$enrId] : null;

                $computed = null;
                if ($formula === 'equal_periodic') {
                    // (P + M + FE) / 3
                    $parts = array_filter([$p, $m, $fe], fn($v) => $v !== null);
                    if (!empty($parts)) {
                        $computed = round(array_sum($parts) / count($parts), 2);
                    }
                } elseif ($formula === 'with_coursework') {
                    // 40% Coursework + 20% Prelim + 20% Midterm + 20% Final Exam
                    $totalWeight = 0.0;
                    $scoreSum    = 0.0;
                    if ($cw !== null) { $scoreSum += $cw * 0.40; $totalWeight += 0.40; }
                    if ($p  !== null) { $scoreSum += $p * 0.20;  $totalWeight += 0.20; }
                    if ($m  !== null) { $scoreSum += $m * 0.20;  $totalWeight += 0.20; }
                    if ($fe !== null) { $scoreSum += $fe * 0.20; $totalWeight += 0.20; }
                    if ($totalWeight > 0) {
                        $computed = round($scoreSum / $totalWeight, 2);
                    }
                } else {
                    // Standard Periodic Weighted: 30% Prelim + 30% Midterm + 40% Final Exam
                    $totalWeight = 0.0;
                    $scoreSum    = 0.0;
                    if ($p  !== null) { $scoreSum += $p * 0.30;  $totalWeight += 0.30; }
                    if ($m  !== null) { $scoreSum += $m * 0.30;  $totalWeight += 0.30; }
                    if ($fe !== null) { $scoreSum += $fe * 0.40; $totalWeight += 0.40; }
                    if ($totalWeight > 0) {
                        $computed = round($scoreSum / $totalWeight, 2);
                    }
                }

                $calculated[$enrId] = $computed !== null ? number_format($computed, 2, '.', '') : '';
            }

            echo json_encode([
                'ok'         => true,
                'formula'    => $formula,
                'calculated' => $calculated,
            ]);
            exit;
        }

        default:
            throw new DomainException('Invalid action request.');
    }
} catch (DomainException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }
    $_SESSION['flash_error'] = $e->getMessage();
    header('Location: ' . $returnBase);
    exit;
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('LMS Teacher Grade Actions error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'A system error occurred. Please try again.']);
        exit;
    }
    $_SESSION['flash_error'] = 'An unexpected error occurred while saving grades. Please try again.';
    header('Location: ' . $returnBase);
    exit;
}
