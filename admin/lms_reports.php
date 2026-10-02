<?php
/**
 * Admin LMS Reports & System-Wide Oversight Analytics (TASK 6.1)
 *
 * Provides Administrator with system-wide reports and analytics across all
 * LMS operations:
 *   1. System-Wide Enrollment-to-LMS Access Report:
 *      Aggregates student LMS eligibility (enrolled status, downpayment compliance,
 *      role validation) across all subjects, sections, programs, and academic terms.
 *   2. System-Wide Grade & Course Completion Summary Report:
 *      Aggregates official student grades, grade sheet submission statuses,
 *      and lesson/module completion progress across all subjects and sections.
 *   3. Teacher Activity & Grading Load Summary Report:
 *      Institutional faculty oversight summarizing teaching workloads, published
 *      courseware (modules, lessons, materials, quizzes, assignments), and pending
 *      grading backlog.
 *
 * Requirements:
 *   - Strictly restricted to Administrator via requireLmsAdminAccess().
 *   - Follows existing reporting/export conventions (CSV export + Print/PDF view).
 *   - Zero external libraries required.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/academic_terms.php';
require_once __DIR__ . '/../includes/assessments.php';
require_once __DIR__ . '/../includes/lms_access.php';

// Strict Admin-only access enforcement
$adminSession = requireLmsAdminAccess();
$adminUserId  = (int)$adminSession['user_id'];

ensureCsrfToken();

/** @var PDO $pdo */
$activeTerm = getActiveAcademicTerm($pdo);

// ── Report Mode & Filter Parameters ───────────────────────────────────────────
$allowedReports = ['enrollment_access', 'grade_completion', 'teacher_activity'];
$reportType     = isset($_GET['report']) && in_array($_GET['report'], $allowedReports, true) ? $_GET['report'] : 'enrollment_access';
$exportFormat   = isset($_GET['export']) ? strtolower(trim((string)$_GET['export'])) : '';

$filterTermId   = isset($_GET['term_id']) ? (int)$_GET['term_id'] : ($activeTerm ? (int)$activeTerm['id'] : 0);
$filterProgram  = trim((string)($_GET['program'] ?? ''));
$filterStatus   = trim((string)($_GET['status'] ?? ''));
$searchQuery    = trim((string)($_GET['search'] ?? ''));

// Fetch all Academic Terms for filters
$termsStmt = $pdo->query("SELECT id, school_year, semester, is_active FROM academic_terms ORDER BY starts_on DESC, id DESC");
$allTerms  = $termsStmt->fetchAll(PDO::FETCH_ASSOC);

$selectedTermLabel = 'All Academic Terms';
foreach ($allTerms as $t) {
    if ((int)$t['id'] === $filterTermId) {
        $selectedTermLabel = 'AY ' . $t['school_year'] . ' (' . ucfirst($t['semester']) . ')' . (!empty($t['is_active']) ? ' - Active' : '');
        break;
    }
}

// ==============================================================================
// 1. DATA GATHERING: ENROLLMENT-TO-LMS ACCESS STATUS
// ==============================================================================
$enrollmentRows = [];
$enrollmentStats = [
    'total_enrolled'   => 0,
    'lms_active'       => 0,
    'lms_pending'      => 0,
    'pending_payment'  => 0,
    'pending_role'     => 0,
    'access_rate'      => 0.0,
    'by_program'       => ['BSMT' => ['total' => 0, 'active' => 0], 'BSMarE' => ['total' => 0, 'active' => 0]],
    'by_year'          => ['1st Year' => 0, '2nd Year' => 0, '3rd Year' => 0, '4th Year' => 0],
];

if ($reportType === 'enrollment_access' || ($exportFormat === 'csv' && $reportType === 'enrollment_access')) {
    $eSql = "
        SELECT e.id AS enrollment_id,
               e.student_id,
               e.section_id,
               e.academic_term_id,
               e.status AS reg_status,
               e.created_at AS enrolled_at,
               st.first_name,
               st.middle_name,
               st.last_name,
               st.suffix,
               st.program_code,
               st.year_level AS student_year,
               st.enrollment_status AS student_enrollment_status,
               u.id AS user_id,
               u.username AS student_username,
               u.email AS student_email,
               u.role AS user_role,
               sec.section_name,
               sec.program AS section_program,
               sec.year_level AS section_year,
               sec.room,
               CONCAT(t.school_year, ' (', t.semester, ')') AS term_name,
               COALESCE(t.minimum_downpayment, 0) AS minimum_downpayment,
               COALESCE(t.downpayment_percentage, 0) AS downpayment_percentage,
               COALESCE(a.total_amount, 0) AS assessment_total,
               COALESCE(a.id, 0) AS assessment_id
        FROM enrollments e
        JOIN students st ON st.id = e.student_id
        JOIN users u ON u.id = st.user_id
        JOIN sections sec ON sec.id = e.section_id
        LEFT JOIN academic_terms t ON t.id = e.academic_term_id
        LEFT JOIN assessments a ON a.id = (
            SELECT cur_a.id
            FROM assessments cur_a
            WHERE cur_a.student_id = st.id
              AND cur_a.academic_term_id = e.academic_term_id
              AND cur_a.status != 'cancelled'
            ORDER BY cur_a.generated_at DESC, cur_a.id DESC
            LIMIT 1
        )
        WHERE e.status = 'enrolled'
    ";
    $eParams = [];

    if ($filterTermId > 0) {
        $eSql .= " AND e.academic_term_id = :term_id";
        $eParams['term_id'] = $filterTermId;
    }
    if ($filterProgram !== '') {
        $eSql .= " AND (st.program_code = :prog OR sec.program = :sec_prog)";
        $eParams['prog']     = $filterProgram;
        $eParams['sec_prog'] = $filterProgram;
    }
    if ($searchQuery !== '') {
        $eSql .= " AND (
            st.first_name LIKE :search
            OR st.last_name LIKE :search
            OR u.username LIKE :search
            OR u.email LIKE :search
            OR sec.section_name LIKE :search
        )";
        $eParams['search'] = '%' . $searchQuery . '%';
    }

    $eSql .= " ORDER BY sec.section_name ASC, st.last_name ASC, st.first_name ASC";
    $eStmt = $pdo->prepare($eSql);
    $eStmt->execute($eParams);
    $rawEnrollments = $eStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rawEnrollments as $row) {
        $studentId    = (int)$row['student_id'];
        $assessmentId = (int)$row['assessment_id'];
        $validatedPaid = 0.00;

        if ($assessmentId > 0) {
            $paidStmt = $pdo->prepare("
                SELECT COALESCE((
                    SELECT SUM(pa.amount)
                    FROM assessment_items ai
                    JOIN payment_allocations pa ON pa.assessment_item_id = ai.id
                    JOIN payments p ON p.id = pa.payment_id
                    WHERE ai.assessment_id = :assessment_id
                      AND p.or_status = 'validated'
                ), (
                    SELECT SUM(p.amount)
                    FROM payments p
                    WHERE p.student_id = :student_id
                      AND p.academic_term_id = :term_id
                      AND p.or_status = 'validated'
                ), 0)
            ");
            $paidStmt->execute([
                'assessment_id' => $assessmentId,
                'student_id'    => $studentId,
                'term_id'       => (int)($row['academic_term_id'] ?? 0)
            ]);
            $validatedPaid = (float)$paidStmt->fetchColumn();
        }

        $isRoleStudent = ($row['user_role'] === 'student');
        $isEnrollmentConfirmed = ($row['reg_status'] === 'enrolled' && $row['student_enrollment_status'] === 'enrolled');
        $isDownpaymentMet = hasMetRequiredDownpayment(
            $validatedPaid,
            (float)$row['assessment_total'],
            (float)$row['minimum_downpayment'],
            (float)$row['downpayment_percentage']
        );

        $hasLmsAccess = ($isRoleStudent && $isEnrollmentConfirmed && $isDownpaymentMet);

        $row['validated_paid']     = $validatedPaid;
        $row['is_downpayment_met'] = $isDownpaymentMet;
        $row['has_lms_access']     = $hasLmsAccess;

        $reasons = [];
        if (!$isRoleStudent) {
            $reasons[] = 'Pending Student Role Upgrade';
            $enrollmentStats['pending_role']++;
        }
        if (!$isEnrollmentConfirmed) {
            $reasons[] = 'Enrollment Confirmation Incomplete';
        }
        if (!$isDownpaymentMet) {
            $reqAmt = calculateRequiredDownpayment((float)$row['assessment_total'], (float)$row['minimum_downpayment'], (float)$row['downpayment_percentage']);
            $bal = max(0, $reqAmt - $validatedPaid);
            $reasons[] = 'Downpayment Unmet (₱' . number_format($bal, 2) . ' balance)';
            $enrollmentStats['pending_payment']++;
        }
        $row['pending_reasons'] = $reasons;

        if ($filterStatus === 'active' && !$hasLmsAccess) {
            continue;
        }
        if ($filterStatus === 'pending' && $hasLmsAccess) {
            continue;
        }

        $enrollmentRows[] = $row;

        $enrollmentStats['total_enrolled']++;
        if ($hasLmsAccess) {
            $enrollmentStats['lms_active']++;
        } else {
            $enrollmentStats['lms_pending']++;
        }

        $prog = $row['program_code'] ?: ($row['section_program'] ?: 'Other');
        if (isset($enrollmentStats['by_program'][$prog])) {
            $enrollmentStats['by_program'][$prog]['total']++;
            if ($hasLmsAccess) {
                $enrollmentStats['by_program'][$prog]['active']++;
            }
        }

        $yr = $row['student_year'] ?: ($row['section_year'] ?: 'Other');
        if (isset($enrollmentStats['by_year'][$yr])) {
            $enrollmentStats['by_year'][$yr]++;
        }
    }

    if ($enrollmentStats['total_enrolled'] > 0) {
        $enrollmentStats['access_rate'] = round(($enrollmentStats['lms_active'] / $enrollmentStats['total_enrolled']) * 100, 1);
    }
}

// ==============================================================================
// 2. DATA GATHERING: SYSTEM-WIDE GRADE & COURSE COMPLETION SUMMARY
// ==============================================================================
$gradeRows = [];
$sectionOfferings = [];
$gradeStats = [
    'total_grades'       => 0,
    'official_records'   => 0,
    'pending_approval'   => 0,
    'draft_records'      => 0,
    'passed_count'       => 0,
    'failed_count'       => 0,
    'inc_count'          => 0,
    'drp_count'          => 0,
    'graded_count'       => 0,
    'avg_grade'          => 0.0,
    'passing_rate'       => 0.0,
    'total_modules'      => 0,
    'total_lessons'      => 0,
    'total_lesson_views' => 0,
    'completion_rate'    => 0.0,
];

if ($reportType === 'grade_completion' || ($exportFormat === 'csv' && $reportType === 'grade_completion')) {
    // 2A. Section Offerings & Subject Completion Metrics
    $soSql = "
        SELECT ss.id AS section_subject_id,
               ss.section_id,
               ss.subject_id,
               ss.instructor_id,
               sec.section_name,
               sec.program AS section_program,
               sec.year_level AS section_year,
               sec.academic_term_id,
               at.school_year,
               at.semester,
               COALESCE(sub.subject_code, 'SUBJ') AS subject_code,
               COALESCE(sub.subject_name, 'Subject Course') AS subject_name,
               COALESCE(sub.units, 3.0) AS units,
               COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u_tch.first_name, u_tch.last_name)), ''), u_tch.username, 'Unassigned') AS instructor_name,
               (
                   SELECT COUNT(DISTINCT e.id)
                   FROM enrollments e
                   WHERE e.section_id = sec.id AND e.status = 'enrolled'
               ) AS enrolled_students_count,
               (
                   SELECT COUNT(DISTINCT lm.id)
                   FROM lms_modules lm
                   WHERE lm.section_subject_id = ss.id AND lm.is_published = 1
               ) AS published_modules_count,
               (
                   SELECT COUNT(DISTINCT ll.id)
                   FROM lms_lessons ll
                   JOIN lms_modules lm ON lm.id = ll.module_id
                   WHERE lm.section_subject_id = ss.id AND ll.is_published = 1
               ) AS published_lessons_count,
               (
                   SELECT COUNT(DISTINCT lp.id)
                   FROM lms_lesson_progress lp
                   JOIN lms_lessons ll ON ll.id = lp.lesson_id
                   JOIN lms_modules lm ON lm.id = ll.module_id
                   WHERE lm.section_subject_id = ss.id
               ) AS lesson_views_count,
               gs.id AS grade_submission_id,
               gs.status AS submission_status,
               gs.submitted_at,
               gs.reviewed_at
        FROM section_subjects ss
        JOIN sections sec ON sec.id = ss.section_id
        LEFT JOIN subjects sub ON sub.id = ss.subject_id
        LEFT JOIN academic_terms at ON at.id = sec.academic_term_id
        LEFT JOIN users u_tch ON u_tch.id = ss.instructor_id
        LEFT JOIN grade_submissions gs ON gs.section_id = sec.id AND gs.academic_term_id = sec.academic_term_id
        WHERE 1=1
    ";
    $soParams = [];

    if ($filterTermId > 0) {
        $soSql .= " AND sec.academic_term_id = :term_id";
        $soParams['term_id'] = $filterTermId;
    }
    if ($filterProgram !== '') {
        $soSql .= " AND sec.program = :prog";
        $soParams['prog'] = $filterProgram;
    }
    if ($searchQuery !== '') {
        $soSql .= " AND (
            sub.subject_code LIKE :search
            OR sub.subject_name LIKE :search
            OR sec.section_name LIKE :search
            OR u_tch.first_name LIKE :search
            OR u_tch.last_name LIKE :search
        )";
        $soParams['search'] = '%' . $searchQuery . '%';
    }

    $soSql .= " ORDER BY at.school_year DESC, at.semester DESC, sub.subject_code ASC, sec.section_name ASC";
    $soStmt = $pdo->prepare($soSql);
    $soStmt->execute($soParams);
    $rawOfferings = $soStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rawOfferings as $off) {
        $ssId = (int)$off['section_subject_id'];
        
        // Fetch grade roll-up for this section_subject
        $gRollStmt = $pdo->prepare("
            SELECT COUNT(sg.id) AS total_graded,
                   AVG(sg.final_grade) AS avg_final,
                   SUM(CASE WHEN LOWER(sg.remarks) = 'passed' OR (sg.final_grade IS NOT NULL AND sg.final_grade <= 3.0) THEN 1 ELSE 0 END) AS passed_count,
                   SUM(CASE WHEN LOWER(sg.remarks) = 'failed' OR (sg.final_grade IS NOT NULL AND sg.final_grade > 3.0) THEN 1 ELSE 0 END) AS failed_count,
                   SUM(CASE WHEN LOWER(sg.remarks) IN ('incomplete','inc') THEN 1 ELSE 0 END) AS inc_count,
                   SUM(CASE WHEN LOWER(sg.remarks) IN ('dropped','drp') THEN 1 ELSE 0 END) AS drp_count
            FROM student_grades sg
            WHERE sg.section_subject_id = :ss_id
        ");
        $gRollStmt->execute(['ss_id' => $ssId]);
        $gRoll = $gRollStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $enrolledCount = (int)$off['enrolled_students_count'];
        $pubLessons    = (int)$off['published_lessons_count'];
        $lessonViews   = (int)$off['lesson_views_count'];

        // Theoretical maximum lesson views = enrolled students * published lessons
        $maxPossibleViews = $enrolledCount * $pubLessons;
        $completionRate   = ($maxPossibleViews > 0) ? round(($lessonViews / $maxPossibleViews) * 100, 1) : 0.0;

        $off['graded_count']    = (int)($gRoll['total_graded'] ?? 0);
        $off['avg_grade']       = $gRoll['avg_final'] !== null ? round((float)$gRoll['avg_final'], 2) : null;
        $off['passed_count']    = (int)($gRoll['passed_count'] ?? 0);
        $off['failed_count']    = (int)($gRoll['failed_count'] ?? 0);
        $off['inc_count']       = (int)($gRoll['inc_count'] ?? 0);
        $off['drp_count']       = (int)($gRoll['drp_count'] ?? 0);
        $off['completion_rate'] = $completionRate;

        $sectionOfferings[] = $off;

        // Overall stats aggregation
        $gradeStats['total_modules']      += (int)$off['published_modules_count'];
        $gradeStats['total_lessons']      += $pubLessons;
        $gradeStats['total_lesson_views'] += $lessonViews;
    }

    // 2B. Detailed Student Grade Records
    $gSql = "
        SELECT sg.id AS grade_id,
               sg.prelim_grade,
               sg.midterm_grade,
               sg.final_exam_grade,
               sg.final_grade,
               sg.remarks,
               gs.id AS submission_id,
               gs.status AS submission_status,
               gs.submitted_at,
               gs.reviewed_at,
               st.id AS student_id,
               COALESCE(NULLIF(st.first_name, ''), u_std.first_name, '') AS student_first_name,
               st.middle_name AS student_middle_name,
               COALESCE(NULLIF(st.last_name, ''), u_std.last_name, '') AS student_last_name,
               st.suffix AS student_suffix,
               st.program_code,
               st.year_level AS student_year_level,
               u_std.username AS student_username,
               u_std.email AS student_email,
               sec.id AS section_id,
               sec.section_name,
               sec.program AS section_program,
               COALESCE(sub.subject_code, 'SUBJ') AS subject_code,
               COALESCE(sub.subject_name, 'Subject Course') AS subject_name,
               COALESCE(sub.units, 3.0) AS units,
               at.id AS academic_term_id,
               at.school_year,
               at.semester,
               COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u_tch.first_name, u_tch.last_name)), ''), u_tch.username, 'Assigned Faculty') AS teacher_name
        FROM student_grades sg
        JOIN grade_submissions gs ON gs.id = sg.grade_submission_id
        JOIN enrollments e ON e.id = sg.enrollment_id
        JOIN students st ON st.id = sg.student_id
        JOIN users u_std ON u_std.id = st.user_id
        JOIN sections sec ON sec.id = e.section_id
        LEFT JOIN section_subjects ss ON ss.id = sg.section_subject_id
        LEFT JOIN subjects sub ON sub.id = ss.subject_id
        LEFT JOIN users u_tch ON u_tch.id = COALESCE(gs.teacher_id, ss.instructor_id, sec.teacher_id)
        JOIN academic_terms at ON at.id = gs.academic_term_id
        WHERE 1=1
    ";
    $gParams = [];

    if ($filterTermId > 0) {
        $gSql .= " AND gs.academic_term_id = :term_id";
        $gParams['term_id'] = $filterTermId;
    }
    if ($filterProgram !== '') {
        $gSql .= " AND (st.program_code = :prog OR sec.program = :sec_prog)";
        $gParams['prog']     = $filterProgram;
        $gParams['sec_prog'] = $filterProgram;
    }
    if ($filterStatus === 'approved') {
        $gSql .= " AND gs.status IN ('approved', 'locked')";
    } elseif ($filterStatus === 'submitted') {
        $gSql .= " AND gs.status = 'submitted'";
    } elseif ($filterStatus === 'draft') {
        $gSql .= " AND gs.status = 'draft'";
    }
    if ($searchQuery !== '') {
        $gSql .= " AND (
            st.first_name LIKE :search
            OR st.last_name LIKE :search
            OR u_std.username LIKE :search
            OR sub.subject_code LIKE :search
            OR sub.subject_name LIKE :search
            OR sec.section_name LIKE :search
        )";
        $gParams['search'] = '%' . $searchQuery . '%';
    }

    $gSql .= " ORDER BY at.school_year DESC, at.semester DESC, sub.subject_code ASC, sec.section_name ASC, st.last_name ASC";
    $gStmt = $pdo->prepare($gSql);
    $gStmt->execute($gParams);
    $gradeRows = $gStmt->fetchAll(PDO::FETCH_ASSOC);

    $sumFinalGrades = 0.0;
    foreach ($gradeRows as $gr) {
        $gradeStats['total_grades']++;
        $status = $gr['submission_status'];
        if (in_array($status, ['approved', 'locked'], true)) {
            $gradeStats['official_records']++;
        } elseif ($status === 'submitted') {
            $gradeStats['pending_approval']++;
        } elseif ($status === 'draft') {
            $gradeStats['draft_records']++;
        }

        $rem = strtolower(trim($gr['remarks'] ?? ''));
        $fGrade = $gr['final_grade'];

        if ($fGrade !== null && $fGrade !== '') {
            $gradeStats['graded_count']++;
            $sumFinalGrades += (float)$fGrade;
        }

        if ($rem === 'passed' || ($rem === '' && $fGrade !== null && ((float)$fGrade <= 3.0 || (float)$fGrade >= 75.0))) {
            $gradeStats['passed_count']++;
        } elseif ($rem === 'failed' || ($rem === '' && $fGrade !== null && ((float)$fGrade > 3.0 && (float)$fGrade < 75.0))) {
            $gradeStats['failed_count']++;
        } elseif ($rem === 'incomplete' || $rem === 'inc') {
            $gradeStats['inc_count']++;
        } elseif ($rem === 'dropped' || $rem === 'drp') {
            $gradeStats['drp_count']++;
        }
    }

    if ($gradeStats['graded_count'] > 0) {
        $gradeStats['avg_grade']    = round($sumFinalGrades / $gradeStats['graded_count'], 2);
        $gradeStats['passing_rate'] = round(($gradeStats['passed_count'] / $gradeStats['graded_count']) * 100, 1);
    }
}

// ==============================================================================
// 3. DATA GATHERING: TEACHER ACTIVITY & GRADING LOAD SUMMARY
// ==============================================================================
$teacherRows = [];
$teacherStats = [
    'total_teachers'       => 0,
    'active_teachers'      => 0,
    'total_assigned_subj'  => 0,
    'total_modules'        => 0,
    'total_lessons'        => 0,
    'total_materials'      => 0,
    'total_assignments'    => 0,
    'total_quizzes'        => 0,
    'total_submissions'    => 0,
    'total_pending_grade'  => 0,
    'total_graded_subs'    => 0,
];

if ($reportType === 'teacher_activity' || ($exportFormat === 'csv' && $reportType === 'teacher_activity')) {
    $tSql = "
        SELECT u.id AS teacher_id,
               u.username,
               u.first_name,
               u.last_name,
               u.email,
               u.is_active,
               u.created_at,
               (
                   SELECT COUNT(ss.id)
                   FROM section_subjects ss
                   JOIN sections sec ON sec.id = ss.section_id
                   WHERE ss.instructor_id = u.id
                   " . ($filterTermId > 0 ? " AND sec.academic_term_id = :t_term1" : "") . "
                   " . ($filterProgram !== '' ? " AND sec.program = :t_prog1" : "") . "
               ) AS assigned_subjects_count,
               (
                   SELECT COUNT(lm.id)
                   FROM lms_modules lm
                   JOIN section_subjects ss ON ss.id = lm.section_subject_id
                   JOIN sections sec ON sec.id = ss.section_id
                   WHERE ss.instructor_id = u.id
                   " . ($filterTermId > 0 ? " AND sec.academic_term_id = :t_term2" : "") . "
                   " . ($filterProgram !== '' ? " AND sec.program = :t_prog2" : "") . "
               ) AS modules_count,
               (
                   SELECT COUNT(ll.id)
                   FROM lms_lessons ll
                   JOIN lms_modules lm ON lm.id = ll.module_id
                   JOIN section_subjects ss ON ss.id = lm.section_subject_id
                   JOIN sections sec ON sec.id = ss.section_id
                   WHERE ss.instructor_id = u.id
                   " . ($filterTermId > 0 ? " AND sec.academic_term_id = :t_term3" : "") . "
                   " . ($filterProgram !== '' ? " AND sec.program = :t_prog3" : "") . "
               ) AS lessons_count,
               (
                   SELECT COUNT(lmat.id)
                   FROM lms_materials lmat
                   JOIN section_subjects ss ON ss.id = lmat.section_subject_id
                   JOIN sections sec ON sec.id = ss.section_id
                   WHERE ss.instructor_id = u.id
                   " . ($filterTermId > 0 ? " AND sec.academic_term_id = :t_term4" : "") . "
                   " . ($filterProgram !== '' ? " AND sec.program = :t_prog4" : "") . "
               ) AS materials_count,
               (
                   SELECT COUNT(la.id)
                   FROM lms_assignments la
                   JOIN section_subjects ss ON ss.id = la.section_subject_id
                   JOIN sections sec ON sec.id = ss.section_id
                   WHERE ss.instructor_id = u.id
                   " . ($filterTermId > 0 ? " AND sec.academic_term_id = :t_term5" : "") . "
                   " . ($filterProgram !== '' ? " AND sec.program = :t_prog5" : "") . "
               ) AS assignments_count,
               (
                   SELECT COUNT(lq.id)
                   FROM lms_quizzes lq
                   JOIN section_subjects ss ON ss.id = lq.section_subject_id
                   JOIN sections sec ON sec.id = ss.section_id
                   WHERE ss.instructor_id = u.id
                   " . ($filterTermId > 0 ? " AND sec.academic_term_id = :t_term6" : "") . "
                   " . ($filterProgram !== '' ? " AND sec.program = :t_prog6" : "") . "
               ) AS quizzes_count,
               (
                   SELECT COUNT(sub.id)
                   FROM lms_assignment_submissions sub
                   JOIN lms_assignments la ON la.id = sub.assignment_id
                   JOIN section_subjects ss ON ss.id = la.section_subject_id
                   JOIN sections sec ON sec.id = ss.section_id
                   WHERE ss.instructor_id = u.id
                   " . ($filterTermId > 0 ? " AND sec.academic_term_id = :t_term7" : "") . "
                   " . ($filterProgram !== '' ? " AND sec.program = :t_prog7" : "") . "
               ) AS total_submissions_count,
               (
                   SELECT COUNT(sub.id)
                   FROM lms_assignment_submissions sub
                   JOIN lms_assignments la ON la.id = sub.assignment_id
                   JOIN section_subjects ss ON ss.id = la.section_subject_id
                   JOIN sections sec ON sec.id = ss.section_id
                   WHERE ss.instructor_id = u.id
                     AND sub.score IS NULL
                   " . ($filterTermId > 0 ? " AND sec.academic_term_id = :t_term8" : "") . "
                   " . ($filterProgram !== '' ? " AND sec.program = :t_prog8" : "") . "
               ) AS pending_grading_count,
               (
                   SELECT COUNT(sub.id)
                   FROM lms_assignment_submissions sub
                   JOIN lms_assignments la ON la.id = sub.assignment_id
                   JOIN section_subjects ss ON ss.id = la.section_subject_id
                   JOIN sections sec ON sec.id = ss.section_id
                   WHERE ss.instructor_id = u.id
                     AND sub.score IS NOT NULL
                   " . ($filterTermId > 0 ? " AND sec.academic_term_id = :t_term9" : "") . "
                   " . ($filterProgram !== '' ? " AND sec.program = :t_prog9" : "") . "
               ) AS graded_submissions_count,
               (
                   SELECT COUNT(qa.id)
                   FROM lms_quiz_attempts qa
                   JOIN lms_quizzes lq ON lq.id = qa.quiz_id
                   JOIN section_subjects ss ON ss.id = lq.section_subject_id
                   JOIN sections sec ON sec.id = ss.section_id
                   WHERE ss.instructor_id = u.id
                     AND qa.status = 'submitted'
                   " . ($filterTermId > 0 ? " AND sec.academic_term_id = :t_term10" : "") . "
                   " . ($filterProgram !== '' ? " AND sec.program = :t_prog10" : "") . "
               ) AS quiz_attempts_count,
               (
                   SELECT COUNT(gs.id)
                   FROM grade_submissions gs
                   WHERE gs.teacher_id = u.id
                   " . ($filterTermId > 0 ? " AND gs.academic_term_id = :t_term11" : "") . "
               ) AS grade_submissions_count,
               (
                   SELECT COUNT(gs.id)
                   FROM grade_submissions gs
                   WHERE gs.teacher_id = u.id AND gs.status IN ('approved','locked')
                   " . ($filterTermId > 0 ? " AND gs.academic_term_id = :t_term12" : "") . "
               ) AS approved_grades_count,
               (
                   SELECT COUNT(gs.id)
                   FROM grade_submissions gs
                   WHERE gs.teacher_id = u.id AND gs.status = 'submitted'
                   " . ($filterTermId > 0 ? " AND gs.academic_term_id = :t_term13" : "") . "
               ) AS pending_approval_grades_count
        FROM users u
        WHERE u.role = 'teacher'
    ";
    $tParams = [];
    if ($filterTermId > 0) {
        for ($i = 1; $i <= 13; $i++) {
            $tParams["t_term{$i}"] = $filterTermId;
        }
    }
    if ($filterProgram !== '') {
        for ($i = 1; $i <= 10; $i++) {
            $tParams["t_prog{$i}"] = $filterProgram;
        }
    }

    if ($searchQuery !== '') {
        $tSql .= " AND (
            u.first_name LIKE :t_search
            OR u.last_name LIKE :t_search
            OR u.username LIKE :t_search
            OR u.email LIKE :t_search
        )";
        $tParams['t_search'] = '%' . $searchQuery . '%';
    }

    $tSql .= " ORDER BY u.last_name ASC, u.first_name ASC";
    $tStmt = $pdo->prepare($tSql);
    $tStmt->execute($tParams);
    $teacherRows = $tStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($teacherRows as $tr) {
        $teacherStats['total_teachers']++;
        if (!empty($tr['is_active'])) {
            $teacherStats['active_teachers']++;
        }
        $teacherStats['total_assigned_subj'] += (int)$tr['assigned_subjects_count'];
        $teacherStats['total_modules']       += (int)$tr['modules_count'];
        $teacherStats['total_lessons']       += (int)$tr['lessons_count'];
        $teacherStats['total_materials']     += (int)$tr['materials_count'];
        $teacherStats['total_assignments']   += (int)$tr['assignments_count'];
        $teacherStats['total_quizzes']       += (int)$tr['quizzes_count'];
        $teacherStats['total_submissions']   += (int)$tr['total_submissions_count'];
        $teacherStats['total_pending_grade'] += (int)$tr['pending_grading_count'];
        $teacherStats['total_graded_subs']   += (int)$tr['graded_submissions_count'];
    }
}

// ==============================================================================
// 4. CSV EXPORT DISPATCHER (STRICTLY SYSTEM CONVENTION)
// ==============================================================================
if ($exportFormat === 'csv') {
    $timestamp = date('Ymd_His');

    if ($reportType === 'enrollment_access') {
        $filename = "ncst_admin_lms_enrollment_access_{$timestamp}.csv";
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $fp = fopen('php://output', 'w');
        fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM

        fputcsv($fp, ['NCST MARITIME ACADEMY — SYSTEM-WIDE LMS ENROLLMENT-TO-ACCESS REPORT']);
        fputcsv($fp, ['Academic Term: ' . $selectedTermLabel, 'Generated At: ' . date('Y-m-d H:i:s'), 'Admin User: ' . ($adminSession['username'] ?? 'admin')]);
        fputcsv($fp, [
            'Total Enrolled: ' . $enrollmentStats['total_enrolled'],
            'Active Access: ' . $enrollmentStats['lms_active'],
            'Pending Access: ' . $enrollmentStats['lms_pending'],
            'Access Rate: ' . $enrollmentStats['access_rate'] . '%'
        ]);
        fputcsv($fp, []);

        fputcsv($fp, [
            '#',
            'Cadet Name',
            'Username',
            'Email',
            'Program',
            'Year Level',
            'Section Block',
            'Term',
            'Validated Paid',
            'Assessment Total',
            'Downpayment Met',
            'User Role',
            'LMS Access Status',
            'Pending Blockers'
        ]);

        $idx = 1;
        foreach ($enrollmentRows as $er) {
            $cadetName = trim(($er['last_name'] ?? '') . ', ' . ($er['first_name'] ?? '') . ' ' . ($er['middle_name'] ?? '') . ' ' . ($er['suffix'] ?? ''));
            fputcsv($fp, [
                $idx++,
                $cadetName,
                $er['student_username'] ?? '',
                $er['student_email'] ?? '',
                $er['program_code'] ?? '',
                $er['student_year'] ?? '',
                $er['section_name'] ?? '',
                $er['term_name'] ?? '',
                number_format((float)$er['validated_paid'], 2),
                number_format((float)$er['assessment_total'], 2),
                !empty($er['is_downpayment_met']) ? 'YES' : 'NO',
                $er['user_role'] ?? '',
                !empty($er['has_lms_access']) ? 'Active / Access Granted' : 'Pending Access',
                implode('; ', $er['pending_reasons'] ?? [])
            ]);
        }
        fclose($fp);
        exit;

    } elseif ($reportType === 'grade_completion') {
        $filename = "ncst_admin_lms_grade_completion_report_{$timestamp}.csv";
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $fp = fopen('php://output', 'w');
        fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF));

        fputcsv($fp, ['NCST MARITIME ACADEMY — SYSTEM-WIDE LMS GRADE & COURSE COMPLETION REPORT']);
        fputcsv($fp, ['Academic Term: ' . $selectedTermLabel, 'Generated At: ' . date('Y-m-d H:i:s'), 'Admin User: ' . ($adminSession['username'] ?? 'admin')]);
        fputcsv($fp, [
            'Total Grades: ' . $gradeStats['total_grades'],
            'Official Records: ' . $gradeStats['official_records'],
            'Pending Approval: ' . $gradeStats['pending_approval'],
            'Institutional Avg: ' . $gradeStats['avg_grade'],
            'Passing Rate: ' . $gradeStats['passing_rate'] . '%'
        ]);
        fputcsv($fp, []);

        // 1. Subject Offering Summary
        fputcsv($fp, ['--- SECTION & SUBJECT OFFERING COMPLETION SUMMARY ---']);
        fputcsv($fp, [
            'Subject Code',
            'Subject Title',
            'Units',
            'Section Block',
            'Program',
            'Instructor',
            'Enrolled Cadets',
            'Graded Count',
            'Average Grade',
            'Passed',
            'Failed',
            'Published Modules',
            'Published Lessons',
            'Lesson Views',
            'Completion Rate (%)',
            'Submission Status'
        ]);

        foreach ($sectionOfferings as $so) {
            fputcsv($fp, [
                $so['subject_code'],
                $so['subject_name'],
                number_format((float)$so['units'], 1),
                $so['section_name'],
                $so['section_program'] ?? '',
                $so['instructor_name'],
                $so['enrolled_students_count'],
                $so['graded_count'],
                $so['avg_grade'] !== null ? number_format((float)$so['avg_grade'], 2) : 'N/A',
                $so['passed_count'],
                $so['failed_count'],
                $so['published_modules_count'],
                $so['published_lessons_count'],
                $so['lesson_views_count'],
                $so['completion_rate'] . '%',
                ucfirst($so['submission_status'] ?? 'No Grade Sheet')
            ]);
        }
        fputcsv($fp, []);

        // 2. Individual Cadet Grades
        fputcsv($fp, ['--- INDIVIDUAL CADET GRADE RECORDS ---']);
        fputcsv($fp, [
            '#',
            'Cadet Name',
            'Username',
            'Program',
            'Year Level',
            'Subject Code',
            'Section Block',
            'Instructor',
            'Prelim Grade',
            'Midterm Grade',
            'Final Exam Grade',
            'Final Grade',
            'Remarks',
            'Record Status'
        ]);

        $idx = 1;
        foreach ($gradeRows as $gr) {
            $cadetName = trim(($gr['student_last_name'] ?? '') . ', ' . ($gr['student_first_name'] ?? '') . ' ' . ($gr['student_middle_name'] ?? '') . ' ' . ($gr['student_suffix'] ?? ''));
            fputcsv($fp, [
                $idx++,
                $cadetName,
                $gr['student_username'] ?? '',
                $gr['program_code'] ?? '',
                $gr['student_year_level'] ?? '',
                $gr['subject_code'] ?? '',
                $gr['section_name'] ?? '',
                $gr['teacher_name'] ?? '',
                $gr['prelim_grade'] !== null ? number_format((float)$gr['prelim_grade'], 2) : '',
                $gr['midterm_grade'] !== null ? number_format((float)$gr['midterm_grade'], 2) : '',
                $gr['final_exam_grade'] !== null ? number_format((float)$gr['final_exam_grade'], 2) : '',
                $gr['final_grade'] !== null ? number_format((float)$gr['final_grade'], 2) : '',
                $gr['remarks'] ?? '',
                $gr['submission_status'] ?? ''
            ]);
        }

        fclose($fp);
        exit;

    } elseif ($reportType === 'teacher_activity') {
        $filename = "ncst_admin_lms_teacher_activity_report_{$timestamp}.csv";
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $fp = fopen('php://output', 'w');
        fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF));

        fputcsv($fp, ['NCST MARITIME ACADEMY — SYSTEM-WIDE LMS TEACHER ACTIVITY & GRADING LOAD REPORT']);
        fputcsv($fp, ['Academic Term: ' . $selectedTermLabel, 'Generated At: ' . date('Y-m-d H:i:s'), 'Admin User: ' . ($adminSession['username'] ?? 'admin')]);
        fputcsv($fp, [
            'Total Faculty: ' . $teacherStats['total_teachers'],
            'Active Faculty: ' . $teacherStats['active_teachers'],
            'Total Subjects Assigned: ' . $teacherStats['total_assigned_subj'],
            'Total Modules: ' . $teacherStats['total_modules'],
            'Total Submissions: ' . $teacherStats['total_submissions'],
            'Pending Grading Backlog: ' . $teacherStats['total_pending_grade']
        ]);
        fputcsv($fp, []);

        fputcsv($fp, [
            '#',
            'Faculty Name',
            'Username',
            'Email',
            'Account Status',
            'Assigned Subjects',
            'Published Modules',
            'Published Lessons',
            'Course Materials',
            'Assignments',
            'Quizzes',
            'Total Submissions Received',
            'Graded Submissions',
            'Pending Grading Load',
            'Quiz Attempts Submitted',
            'Grade Sheets Total',
            'Approved Grade Sheets',
            'Pending Approval Grade Sheets'
        ]);

        $idx = 1;
        foreach ($teacherRows as $tr) {
            $facultyName = trim(($tr['last_name'] ?? '') . ', ' . ($tr['first_name'] ?? ''));
            if ($facultyName === '' || $facultyName === ',') {
                $facultyName = $tr['username'];
            }
            fputcsv($fp, [
                $idx++,
                $facultyName,
                $tr['username'],
                $tr['email'],
                !empty($tr['is_active']) ? 'Active' : 'Inactive',
                $tr['assigned_subjects_count'],
                $tr['modules_count'],
                $tr['lessons_count'],
                $tr['materials_count'],
                $tr['assignments_count'],
                $tr['quizzes_count'],
                $tr['total_submissions_count'],
                $tr['graded_submissions_count'],
                $tr['pending_grading_count'],
                $tr['quiz_attempts_count'],
                $tr['grade_submissions_count'],
                $tr['approved_grades_count'],
                $tr['pending_approval_grades_count']
            ]);
        }

        fclose($fp);
        exit;
    }
}

$page_title = 'LMS System-Wide Reports & Analytics — Administrator';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
@media print {
    .sidebar, .top-navbar, .footer, .no-print, nav, .btn, form, .badge-filter, .btn-group {
        display: none !important;
    }
    .main-wrapper, .content-body {
        margin: 0 !important;
        padding: 0 !important;
    }
    .card {
        border: 1px solid #ccc !important;
        box-shadow: none !important;
    }
    .print-header {
        display: block !important;
    }
}
.print-header {
    display: none;
}
.kpi-card {
    border: none;
    border-radius: 12px;
    transition: transform .15s ease, box-shadow .15s ease;
}
.kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0,0,0,.08);
}
</style>

<!-- ── PRINT HEADER (VISIBLE ONLY ON PRINT / PDF SAVE) ───────────────────────── -->
<div class="print-header mb-4 text-center border-bottom pb-3">
    <h4 class="fw-bold mb-0 text-uppercase" style="letter-spacing:.05em;">NCST Maritime Academy</h4>
    <div class="small text-muted fw-semibold">Office of the Administrator &bull; System-Wide LMS Operations & Analytics</div>
    <h5 class="fw-bold mt-2 text-navy-alt">
        <?php 
        if ($reportType === 'enrollment_access') {
            echo 'Official System-Wide Enrollment-to-LMS Access Status Report';
        } elseif ($reportType === 'grade_completion') {
            echo 'Official System-Wide LMS Grade & Course Completion Summary Report';
        } else {
            echo 'Official System-Wide Faculty Activity & Grading Load Report';
        }
        ?>
    </h5>
    <div class="small text-muted">Academic Term: <?php echo htmlspecialchars($selectedTermLabel); ?> &bull; Printed: <?php echo date('F d, Y h:i A'); ?></div>
</div>

<!-- ── ADMIN LMS NAVIGATION BAR ─────────────────────────────────────────── -->
<nav class="card shadow-sm mb-4 border-0 no-print" style="background:var(--sidebar-bg,#0b4f5c);" aria-label="Admin LMS navigation">
    <div class="card-body py-2 px-3">
        <div class="d-flex flex-wrap gap-1 align-items-center">
            <span class="text-white opacity-75 small fw-semibold me-2 text-nowrap" style="font-size:.7rem;letter-spacing:.06em;text-transform:uppercase;">
                <i class="bi bi-shield-lock-fill me-1"></i>LMS Administration
            </span>
            <a href="lms_users" class="btn btn-sm text-white text-nowrap" style="opacity:.85;" onmouseenter="this.style.opacity=1" onmouseleave="this.style.opacity='.85'">
                <i class="bi bi-people-fill me-1"></i>Users & Roles
            </a>
            <a href="lms_courses" class="btn btn-sm text-white text-nowrap" style="opacity:.85;" onmouseenter="this.style.opacity=1" onmouseleave="this.style.opacity='.85'">
                <i class="bi bi-journal-bookmark-fill me-1"></i>Courses & Offerings
            </a>
            <a href="lms_terms" class="btn btn-sm text-white text-nowrap" style="opacity:.85;" onmouseenter="this.style.opacity=1" onmouseleave="this.style.opacity='.85'">
                <i class="bi bi-calendar3-range-fill me-1"></i>Academic Terms
            </a>
            <a href="lms_settings" class="btn btn-sm text-white text-nowrap" style="opacity:.85;" onmouseenter="this.style.opacity=1" onmouseleave="this.style.opacity='.85'">
                <i class="bi bi-sliders me-1"></i>System Settings
            </a>
            <a href="lms_oversight" class="btn btn-sm text-white text-nowrap" style="opacity:.85;" onmouseenter="this.style.opacity=1" onmouseleave="this.style.opacity='.85'">
                <i class="bi bi-eye-fill me-1"></i>Oversight Console
            </a>
            <a href="../registrar/lms_enrollments" class="btn btn-sm text-white text-nowrap" style="opacity:.85;" onmouseenter="this.style.opacity=1" onmouseleave="this.style.opacity='.85'">
                <i class="bi bi-person-check-fill me-1"></i>LMS Enrollments
            </a>
            <a href="../registrar/lms_subjects" class="btn btn-sm text-white text-nowrap" style="opacity:.85;" onmouseenter="this.style.opacity=1" onmouseleave="this.style.opacity='.85'">
                <i class="bi bi-layers-fill me-1"></i>Subjects & Sections
            </a>
            <a href="../registrar/lms_grades" class="btn btn-sm text-white text-nowrap" style="opacity:.85;" onmouseenter="this.style.opacity=1" onmouseleave="this.style.opacity='.85'">
                <i class="bi bi-mortarboard-fill me-1"></i>Academic Grades
            </a>
            <a href="lms_reports" class="btn btn-sm text-white fw-bold active text-nowrap" style="background:rgba(255,255,255,.2);border-radius:6px;opacity:1;" aria-current="page">
                <i class="bi bi-file-earmark-bar-graph-fill me-1"></i>LMS Reports
            </a>
        </div>
    </div>
</nav>

<!-- ── BREADCRUMB ──────────────────────────────────────────────────────────── -->
<nav aria-label="breadcrumb" class="mb-3 no-print">
    <ol class="breadcrumb mb-0" style="font-size:.82rem;">
        <li class="breadcrumb-item"><a href="dashboard" class="text-decoration-none text-brand-primary"><i class="bi bi-shield-shaded me-1"></i>Administrator</a></li>
        <li class="breadcrumb-item"><a href="lms_oversight" class="text-decoration-none text-brand-primary">LMS Administration</a></li>
        <li class="breadcrumb-item active" aria-current="page">System-Wide LMS Reports & Analytics</li>
    </ol>
</nav>

<!-- ── PAGE HEADER & REPORT SWITCHER ───────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4 no-print">
    <div>
        <div class="page-eyebrow text-muted small fw-semibold text-uppercase" style="letter-spacing:.05em;">
            <i class="bi bi-shield-lock me-1"></i>Full Administrative Authority
        </div>
        <h1 class="h3 fw-bold text-navy-alt mb-1">
            <i class="bi bi-file-earmark-bar-graph-fill me-2 text-brand-primary"></i>System-Wide LMS Reports & Analytics
        </h1>
        <p class="text-muted small mb-0">
            Comprehensive institutional analytics summarizing student LMS access compliance, academic grade and module completions, and faculty teaching activity.
        </p>
    </div>

    <!-- Export & Print Actions -->
    <div class="d-flex gap-2 align-items-center flex-wrap">
        <a href="?report=<?php echo htmlspecialchars($reportType); ?>&term_id=<?php echo (int)$filterTermId; ?>&program=<?php echo urlencode($filterProgram); ?>&status=<?php echo urlencode($filterStatus); ?>&search=<?php echo urlencode($searchQuery); ?>&export=csv" 
           class="btn btn-sm btn-outline-success shadow-sm fw-semibold">
            <i class="bi bi-file-earmark-spreadsheet-fill me-1"></i>Export to CSV
        </a>
        <button type="button" onclick="window.print()" class="btn btn-sm btn-outline-secondary shadow-sm fw-semibold">
            <i class="bi bi-printer-fill me-1"></i>Print / Save PDF
        </button>
    </div>
</div>

<!-- ── REPORT VIEW SELECTOR TABS ───────────────────────────────────────────── -->
<div class="card shadow-sm border-0 rounded-4 mb-4 no-print">
    <div class="card-body p-2">
        <ul class="nav nav-pills nav-fill gap-2" role="tablist">
            <li class="nav-item">
                <a class="nav-link py-2 fw-semibold <?php echo $reportType === 'enrollment_access' ? 'active' : 'text-dark bg-light'; ?>" 
                   href="?report=enrollment_access&term_id=<?php echo (int)$filterTermId; ?>&program=<?php echo urlencode($filterProgram); ?>">
                    <i class="bi bi-person-check-fill me-2"></i>1. Enrollment-to-LMS Access Report
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link py-2 fw-semibold <?php echo $reportType === 'grade_completion' ? 'active' : 'text-dark bg-light'; ?>" 
                   href="?report=grade_completion&term_id=<?php echo (int)$filterTermId; ?>&program=<?php echo urlencode($filterProgram); ?>">
                    <i class="bi bi-mortarboard-fill me-2"></i>2. Grade & Completion Summary
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link py-2 fw-semibold <?php echo $reportType === 'teacher_activity' ? 'active' : 'text-dark bg-light'; ?>" 
                   href="?report=teacher_activity&term_id=<?php echo (int)$filterTermId; ?>&program=<?php echo urlencode($filterProgram); ?>">
                    <i class="bi bi-person-workspace me-2"></i>3. Teacher Activity & Grading Load
                </a>
            </li>
        </ul>
    </div>
</div>

<!-- ── FILTER BAR ─────────────────────────────────────────────────────────── -->
<div class="card shadow-sm border-0 rounded-4 p-3 bg-white mb-4 no-print">
    <form method="GET" action="" class="row g-2 align-items-center">
        <input type="hidden" name="report" value="<?php echo htmlspecialchars($reportType); ?>">

        <div class="col-12 col-md-3">
            <label class="form-label small text-muted mb-1 fw-semibold">Academic Term</label>
            <select name="term_id" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="0">All Configured Terms</option>
                <?php foreach ($allTerms as $t): ?>
                    <option value="<?php echo (int)$t['id']; ?>" <?php echo $filterTermId === (int)$t['id'] ? 'selected' : ''; ?>>
                        AY <?php echo htmlspecialchars($t['school_year']); ?> (<?php echo htmlspecialchars(ucfirst($t['semester'])); ?>) <?php echo (int)$t['is_active'] === 1 ? '— Active' : ''; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-12 col-md-3">
            <label class="form-label small text-muted mb-1 fw-semibold">Academic Program</label>
            <select name="program" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All Programs</option>
                <option value="BSMT" <?php echo $filterProgram === 'BSMT' ? 'selected' : ''; ?>>BSMT (Marine Transportation)</option>
                <option value="BSMarE" <?php echo $filterProgram === 'BSMarE' ? 'selected' : ''; ?>>BSMarE (Marine Engineering)</option>
            </select>
        </div>

        <?php if ($reportType === 'enrollment_access'): ?>
            <div class="col-12 col-md-2">
                <label class="form-label small text-muted mb-1 fw-semibold">Access Status</label>
                <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All Statuses</option>
                    <option value="active" <?php echo $filterStatus === 'active' ? 'selected' : ''; ?>>Active / Has Access</option>
                    <option value="pending" <?php echo $filterStatus === 'pending' ? 'selected' : ''; ?>>Pending Access</option>
                </select>
            </div>
        <?php elseif ($reportType === 'grade_completion'): ?>
            <div class="col-12 col-md-2">
                <label class="form-label small text-muted mb-1 fw-semibold">Submission Status</label>
                <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All Records</option>
                    <option value="approved" <?php echo $filterStatus === 'approved' ? 'selected' : ''; ?>>Approved / Locked</option>
                    <option value="submitted" <?php echo $filterStatus === 'submitted' ? 'selected' : ''; ?>>Pending Approval</option>
                    <option value="draft" <?php echo $filterStatus === 'draft' ? 'selected' : ''; ?>>Draft Records</option>
                </select>
            </div>
        <?php else: ?>
            <div class="col-12 col-md-2">
                <label class="form-label small text-muted mb-1 fw-semibold">Teacher Status</label>
                <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All Faculty</option>
                    <option value="active" <?php echo $filterStatus === 'active' ? 'selected' : ''; ?>>Active Only</option>
                </select>
            </div>
        <?php endif; ?>

        <div class="col-12 col-md-3">
            <label class="form-label small text-muted mb-1 fw-semibold">Keyword Search</label>
            <div class="input-group input-group-sm">
                <input type="text" name="search" class="form-control" placeholder="Search name, code, subject..." value="<?php echo htmlspecialchars($searchQuery); ?>">
                <button class="btn btn-brand-primary" type="submit"><i class="bi bi-search"></i></button>
            </div>
        </div>

        <div class="col-12 col-md-1 d-flex align-items-end pt-3">
            <a href="?report=<?php echo htmlspecialchars($reportType); ?>" class="btn btn-sm btn-outline-secondary w-100" title="Reset Filters">
                <i class="bi bi-arrow-counterclockwise"></i>
            </a>
        </div>
    </form>
</div>

<!-- ========================================================================== -->
<!-- REPORT 1: SYSTEM-WIDE ENROLLMENT-TO-LMS ACCESS REPORT                      -->
<!-- ========================================================================== -->
<?php if ($reportType === 'enrollment_access'): ?>

    <!-- KPI Summary Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card kpi-card shadow-sm p-3 bg-white border-start border-4 border-primary">
                <div class="text-muted small fw-semibold text-uppercase">Total Enrolled</div>
                <div class="h3 fw-bold text-navy-alt mb-0 mt-1"><?php echo number_format($enrollmentStats['total_enrolled']); ?></div>
                <div class="small text-muted mt-1">Confirmed student registrations</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card kpi-card shadow-sm p-3 bg-white border-start border-4 border-success">
                <div class="text-muted small fw-semibold text-uppercase">LMS Access Active</div>
                <div class="h3 fw-bold text-success mb-0 mt-1"><?php echo number_format($enrollmentStats['lms_active']); ?></div>
                <div class="small text-success mt-1 fw-semibold"><?php echo $enrollmentStats['access_rate']; ?>% compliant</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card kpi-card shadow-sm p-3 bg-white border-start border-4 border-warning">
                <div class="text-muted small fw-semibold text-uppercase">Pending Access</div>
                <div class="h3 fw-bold text-warning mb-0 mt-1"><?php echo number_format($enrollmentStats['lms_pending']); ?></div>
                <div class="small text-muted mt-1">Blocked by downpayment or role</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card kpi-card shadow-sm p-3 bg-white border-start border-4 border-info">
                <div class="text-muted small fw-semibold text-uppercase">Program Distribution</div>
                <div class="d-flex justify-content-between small mt-1">
                    <span>BSMT: <strong><?php echo $enrollmentStats['by_program']['BSMT']['active'] ?? 0; ?>/<?php echo $enrollmentStats['by_program']['BSMT']['total'] ?? 0; ?></strong></span>
                    <span>BSMarE: <strong><?php echo $enrollmentStats['by_program']['BSMarE']['active'] ?? 0; ?>/<?php echo $enrollmentStats['by_program']['BSMarE']['total'] ?? 0; ?></strong></span>
                </div>
                <div class="progress mt-2" style="height: 6px;">
                    <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $enrollmentStats['access_rate']; ?>%"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Data Table Card -->
    <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4">
        <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h6 class="fw-bold mb-0 text-navy-alt">
                    <i class="bi bi-people-fill me-2 text-brand-primary"></i>Student LMS Access Compliance List
                </h6>
                <div class="small text-muted">Showing <?php echo count($enrollmentRows); ?> student enrollment records matching filters</div>
            </div>
            <span class="badge bg-primary-subtle text-primary fw-semibold px-3 py-2 rounded-pill">
                <?php echo htmlspecialchars($selectedTermLabel); ?>
            </span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:.85rem;">
                <thead class="table-light text-muted small text-uppercase" style="letter-spacing:.04em;">
                    <tr>
                        <th class="ps-3" style="width:40px;">#</th>
                        <th>Cadet Name</th>
                        <th>Program & Year</th>
                        <th>Section Block</th>
                        <th>Term</th>
                        <th class="text-end">Paid / Assessment</th>
                        <th class="text-center">Downpayment</th>
                        <th class="text-center">LMS Access</th>
                        <th class="pe-3">Blockers / Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($enrollmentRows)): ?>
                        <tr>
                            <td colspan="9" class="text-center py-5 text-muted">
                                <i class="bi bi-inbox fs-2 d-block mb-2 text-secondary"></i>
                                No student enrollment records found matching the specified criteria.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php $idx = 1; foreach ($enrollmentRows as $er): ?>
                            <?php $cadetName = trim(($er['last_name'] ?? '') . ', ' . ($er['first_name'] ?? '') . ' ' . ($er['middle_name'] ?? '') . ' ' . ($er['suffix'] ?? '')); ?>
                            <tr>
                                <td class="ps-3 text-muted"><?php echo $idx++; ?></td>
                                <td>
                                    <div class="fw-bold text-navy-alt"><?php echo htmlspecialchars($cadetName); ?></div>
                                    <div class="small text-muted font-monospace"><?php echo htmlspecialchars($er['student_username'] ?? ''); ?> &bull; <?php echo htmlspecialchars($er['student_email'] ?? ''); ?></div>
                                </td>
                                <td>
                                    <span class="badge bg-secondary-subtle text-secondary fw-semibold"><?php echo htmlspecialchars($er['program_code'] ?: ($er['section_program'] ?: 'N/A')); ?></span>
                                    <div class="small text-muted"><?php echo htmlspecialchars($er['student_year'] ?: ($er['section_year'] ?: '')); ?></div>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($er['section_name'] ?? ''); ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars($er['room'] ?? 'TBA'); ?></div>
                                </td>
                                <td class="small text-muted"><?php echo htmlspecialchars($er['term_name'] ?? ''); ?></td>
                                <td class="text-end">
                                    <div class="fw-semibold text-dark">₱<?php echo number_format((float)$er['validated_paid'], 2); ?></div>
                                    <div class="small text-muted">Total: ₱<?php echo number_format((float)$er['assessment_total'], 2); ?></div>
                                </td>
                                <td class="text-center">
                                    <?php if (!empty($er['is_downpayment_met'])): ?>
                                        <span class="badge bg-success-subtle text-success fw-semibold"><i class="bi bi-check-circle me-1"></i>Met</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger-subtle text-danger fw-semibold"><i class="bi bi-x-circle me-1"></i>Unmet</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if (!empty($er['has_lms_access'])): ?>
                                        <span class="badge bg-success text-white fw-bold px-2 py-1"><i class="bi bi-unlock-fill me-1"></i>Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark fw-bold px-2 py-1"><i class="bi bi-lock-fill me-1"></i>Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td class="pe-3 small">
                                    <?php if (empty($er['pending_reasons'])): ?>
                                        <span class="text-success"><i class="bi bi-check2-all me-1"></i>All gates cleared</span>
                                    <?php else: ?>
                                        <ul class="list-unstyled mb-0 text-danger" style="font-size:.78rem;">
                                            <?php foreach ($er['pending_reasons'] as $r): ?>
                                                <li><i class="bi bi-exclamation-circle me-1"></i><?php echo htmlspecialchars($r); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<!-- ========================================================================== -->
<!-- REPORT 2: SYSTEM-WIDE GRADE & COURSE COMPLETION SUMMARY                    -->
<!-- ========================================================================== -->
<?php elseif ($reportType === 'grade_completion'): ?>

    <!-- KPI Summary Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card kpi-card shadow-sm p-3 bg-white border-start border-4 border-primary">
                <div class="text-muted small fw-semibold text-uppercase">Total Grade Records</div>
                <div class="h3 fw-bold text-navy-alt mb-0 mt-1"><?php echo number_format($gradeStats['total_grades']); ?></div>
                <div class="small text-muted mt-1">Official: <strong><?php echo number_format($gradeStats['official_records']); ?></strong> &bull; Pending: <strong><?php echo number_format($gradeStats['pending_approval']); ?></strong></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card kpi-card shadow-sm p-3 bg-white border-start border-4 border-success">
                <div class="text-muted small fw-semibold text-uppercase">Passing Rate</div>
                <div class="h3 fw-bold text-success mb-0 mt-1"><?php echo $gradeStats['passing_rate']; ?>%</div>
                <div class="small text-muted mt-1">Passed: <strong><?php echo number_format($gradeStats['passed_count']); ?></strong> &bull; Failed: <strong><?php echo number_format($gradeStats['failed_count']); ?></strong></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card kpi-card shadow-sm p-3 bg-white border-start border-4 border-info">
                <div class="text-muted small fw-semibold text-uppercase">Institutional Avg Grade</div>
                <div class="h3 fw-bold text-info mb-0 mt-1"><?php echo $gradeStats['avg_grade'] > 0 ? number_format($gradeStats['avg_grade'], 2) : 'N/A'; ?></div>
                <div class="small text-muted mt-1">Based on <?php echo number_format($gradeStats['graded_count']); ?> scored entries</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card kpi-card shadow-sm p-3 bg-white border-start border-4 border-warning">
                <div class="text-muted small fw-semibold text-uppercase">LMS Course Content</div>
                <div class="h3 fw-bold text-navy-alt mb-0 mt-1"><?php echo number_format($gradeStats['total_modules']); ?> Modules</div>
                <div class="small text-muted mt-1"><?php echo number_format($gradeStats['total_lessons']); ?> lessons &bull; <?php echo number_format($gradeStats['total_lesson_views']); ?> views</div>
            </div>
        </div>
    </div>

    <!-- Section & Subject Completion Roll-up Table -->
    <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4">
        <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h6 class="fw-bold mb-0 text-navy-alt">
                    <i class="bi bi-collection-fill me-2 text-brand-primary"></i>Subject Offerings & Course Completion Summary
                </h6>
                <div class="small text-muted">Aggregated performance and module completion by subject block</div>
            </div>
            <span class="badge bg-secondary-subtle text-secondary fw-semibold px-3 py-2 rounded-pill">
                <?php echo count($sectionOfferings); ?> Offerings Analyzed
            </span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:.85rem;">
                <thead class="table-light text-muted small text-uppercase" style="letter-spacing:.04em;">
                    <tr>
                        <th class="ps-3">Subject</th>
                        <th>Section Block</th>
                        <th>Instructor</th>
                        <th class="text-center">Enrolled</th>
                        <th class="text-center">Graded</th>
                        <th class="text-center">Avg Grade</th>
                        <th class="text-center">Passed / Failed</th>
                        <th class="text-center">Modules / Lessons</th>
                        <th class="text-center">Lesson Views</th>
                        <th class="text-center">Completion Rate</th>
                        <th class="pe-3 text-center">Grade Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($sectionOfferings)): ?>
                        <tr>
                            <td colspan="11" class="text-center py-5 text-muted">
                                <i class="bi bi-inbox fs-2 d-block mb-2 text-secondary"></i>
                                No subject offerings found matching the selected term and filters.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($sectionOfferings as $so): ?>
                            <tr>
                                <td class="ps-3">
                                    <div class="fw-bold text-navy-alt"><?php echo htmlspecialchars($so['subject_code']); ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars($so['subject_name']); ?> (<?php echo number_format((float)$so['units'], 1); ?> u)</div>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($so['section_name']); ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars($so['section_program'] ?? ''); ?></div>
                                </td>
                                <td>
                                    <div class="small fw-semibold text-dark"><?php echo htmlspecialchars($so['instructor_name']); ?></div>
                                </td>
                                <td class="text-center fw-bold"><?php echo (int)$so['enrolled_students_count']; ?></td>
                                <td class="text-center"><?php echo (int)$so['graded_count']; ?></td>
                                <td class="text-center fw-bold text-brand-primary">
                                    <?php echo $so['avg_grade'] !== null ? number_format((float)$so['avg_grade'], 2) : '—'; ?>
                                </td>
                                <td class="text-center small">
                                    <span class="text-success fw-semibold"><?php echo (int)$so['passed_count']; ?>P</span> /
                                    <span class="text-danger fw-semibold"><?php echo (int)$so['failed_count']; ?>F</span>
                                </td>
                                <td class="text-center small">
                                    <span class="badge bg-light text-dark border"><?php echo (int)$so['published_modules_count']; ?> mod</span>
                                    <span class="badge bg-light text-dark border"><?php echo (int)$so['published_lessons_count']; ?> les</span>
                                </td>
                                <td class="text-center small text-muted">
                                    <?php echo number_format((int)$so['lesson_views_count']); ?>
                                </td>
                                <td class="text-center">
                                    <div class="small fw-bold text-dark mb-1"><?php echo $so['completion_rate']; ?>%</div>
                                    <div class="progress" style="height: 5px; width: 70px; margin: 0 auto;">
                                        <div class="progress-bar bg-info" role="progressbar" style="width: <?php echo min(100, $so['completion_rate']); ?>%"></div>
                                    </div>
                                </td>
                                <td class="pe-3 text-center">
                                    <?php
                                    $st = $so['submission_status'] ?? 'draft';
                                    if (in_array($st, ['approved', 'locked'], true)) {
                                        echo '<span class="badge bg-success-subtle text-success fw-semibold"><i class="bi bi-shield-check me-1"></i>Approved</span>';
                                    } elseif ($st === 'submitted') {
                                        echo '<span class="badge bg-primary-subtle text-primary fw-semibold"><i class="bi bi-send-check me-1"></i>Submitted</span>';
                                    } else {
                                        echo '<span class="badge bg-secondary-subtle text-secondary fw-semibold">Draft / None</span>';
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Detailed Individual Student Grades Table -->
    <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4">
        <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h6 class="fw-bold mb-0 text-navy-alt">
                    <i class="bi bi-table me-2 text-brand-primary"></i>Individual Student Grade Records
                </h6>
                <div class="small text-muted">Showing <?php echo count($gradeRows); ?> enrolled cadet grade entries</div>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:.85rem;">
                <thead class="table-light text-muted small text-uppercase" style="letter-spacing:.04em;">
                    <tr>
                        <th class="ps-3" style="width:40px;">#</th>
                        <th>Cadet Name</th>
                        <th>Program</th>
                        <th>Subject & Section</th>
                        <th>Instructor</th>
                        <th class="text-center">Prelim</th>
                        <th class="text-center">Midterm</th>
                        <th class="text-center">Final Exam</th>
                        <th class="text-center">Final Grade</th>
                        <th class="text-center">Remarks</th>
                        <th class="pe-3 text-center">Record Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($gradeRows)): ?>
                        <tr>
                            <td colspan="11" class="text-center py-5 text-muted">
                                <i class="bi bi-inbox fs-2 d-block mb-2 text-secondary"></i>
                                No student grade entries found matching current filters.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php $gIdx = 1; foreach ($gradeRows as $gr): ?>
                            <?php $cName = trim(($gr['student_last_name'] ?? '') . ', ' . ($gr['student_first_name'] ?? '') . ' ' . ($gr['student_middle_name'] ?? '') . ' ' . ($gr['student_suffix'] ?? '')); ?>
                            <tr>
                                <td class="ps-3 text-muted"><?php echo $gIdx++; ?></td>
                                <td>
                                    <div class="fw-bold text-navy-alt"><?php echo htmlspecialchars($cName); ?></div>
                                    <div class="small text-muted font-monospace"><?php echo htmlspecialchars($gr['student_username'] ?? ''); ?></div>
                                </td>
                                <td class="small text-muted"><?php echo htmlspecialchars($gr['program_code'] ?? ''); ?></td>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($gr['subject_code']); ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars($gr['section_name']); ?></div>
                                </td>
                                <td class="small"><?php echo htmlspecialchars($gr['teacher_name']); ?></td>
                                <td class="text-center"><?php echo $gr['prelim_grade'] !== null ? number_format((float)$gr['prelim_grade'], 2) : '—'; ?></td>
                                <td class="text-center"><?php echo $gr['midterm_grade'] !== null ? number_format((float)$gr['midterm_grade'], 2) : '—'; ?></td>
                                <td class="text-center"><?php echo $gr['final_exam_grade'] !== null ? number_format((float)$gr['final_exam_grade'], 2) : '—'; ?></td>
                                <td class="text-center fw-bold text-navy-alt"><?php echo $gr['final_grade'] !== null ? number_format((float)$gr['final_grade'], 2) : '—'; ?></td>
                                <td class="text-center">
                                    <?php 
                                    $rem = strtolower(trim($gr['remarks'] ?? ''));
                                    if ($rem === 'passed') {
                                        echo '<span class="badge bg-success-subtle text-success fw-semibold">Passed</span>';
                                    } elseif ($rem === 'failed') {
                                        echo '<span class="badge bg-danger-subtle text-danger fw-semibold">Failed</span>';
                                    } elseif (in_array($rem, ['incomplete', 'inc'], true)) {
                                        echo '<span class="badge bg-warning-subtle text-warning fw-semibold">INC</span>';
                                    } elseif (in_array($rem, ['dropped', 'drp'], true)) {
                                        echo '<span class="badge bg-secondary-subtle text-secondary fw-semibold">DRP</span>';
                                    } else {
                                        echo '<span class="text-muted small">' . htmlspecialchars($gr['remarks'] ?: '—') . '</span>';
                                    }
                                    ?>
                                </td>
                                <td class="pe-3 text-center">
                                    <?php
                                    $gst = $gr['submission_status'] ?? 'draft';
                                    if (in_array($gst, ['approved', 'locked'], true)) {
                                        echo '<span class="badge bg-success-subtle text-success fw-semibold"><i class="bi bi-check-circle me-1"></i>Approved</span>';
                                    } elseif ($gst === 'submitted') {
                                        echo '<span class="badge bg-primary-subtle text-primary fw-semibold"><i class="bi bi-clock me-1"></i>Pending</span>';
                                    } else {
                                        echo '<span class="badge bg-light text-muted border">Draft</span>';
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<!-- ========================================================================== -->
<!-- REPORT 3: TEACHER ACTIVITY & GRADING LOAD SUMMARY                          -->
<!-- ========================================================================== -->
<?php else: ?>

    <!-- KPI Summary Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card kpi-card shadow-sm p-3 bg-white border-start border-4 border-primary">
                <div class="text-muted small fw-semibold text-uppercase">Faculty Headcount</div>
                <div class="h3 fw-bold text-navy-alt mb-0 mt-1"><?php echo number_format($teacherStats['total_teachers']); ?></div>
                <div class="small text-muted mt-1">Active instructors: <strong><?php echo number_format($teacherStats['active_teachers']); ?></strong></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card kpi-card shadow-sm p-3 bg-white border-start border-4 border-success">
                <div class="text-muted small fw-semibold text-uppercase">Subject Assignments</div>
                <div class="h3 fw-bold text-success mb-0 mt-1"><?php echo number_format($teacherStats['total_assigned_subj']); ?></div>
                <div class="small text-muted mt-1">Active teaching section blocks</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card kpi-card shadow-sm p-3 bg-white border-start border-4 border-info">
                <div class="text-muted small fw-semibold text-uppercase">Course Content Items</div>
                <div class="h3 fw-bold text-info mb-0 mt-1">
                    <?php echo number_format($teacherStats['total_modules'] + $teacherStats['total_materials'] + $teacherStats['total_assignments'] + $teacherStats['total_quizzes']); ?>
                </div>
                <div class="small text-muted mt-1"><?php echo $teacherStats['total_modules']; ?> mod &bull; <?php echo $teacherStats['total_materials']; ?> mat &bull; <?php echo $teacherStats['total_assignments']; ?> assign &bull; <?php echo $teacherStats['total_quizzes']; ?> quiz</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card kpi-card shadow-sm p-3 bg-white border-start border-4 border-danger">
                <div class="text-muted small fw-semibold text-uppercase">Pending Grading Load</div>
                <div class="h3 fw-bold <?php echo $teacherStats['total_pending_grade'] > 0 ? 'text-danger' : 'text-success'; ?> mb-0 mt-1">
                    <?php echo number_format($teacherStats['total_pending_grade']); ?>
                </div>
                <div class="small text-muted mt-1">Total received: <?php echo number_format($teacherStats['total_submissions']); ?> &bull; Graded: <?php echo number_format($teacherStats['total_graded_subs']); ?></div>
            </div>
        </div>
    </div>

    <!-- Teacher Activity Table -->
    <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4">
        <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h6 class="fw-bold mb-0 text-navy-alt">
                    <i class="bi bi-person-workspace me-2 text-brand-primary"></i>Faculty Activity & Grading Workload Oversight
                </h6>
                <div class="small text-muted">Showing teaching metrics and submission grading queue across all instructors</div>
            </div>
            <span class="badge bg-secondary-subtle text-secondary fw-semibold px-3 py-2 rounded-pill">
                <?php echo count($teacherRows); ?> Teachers
            </span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:.85rem;">
                <thead class="table-light text-muted small text-uppercase" style="letter-spacing:.04em;">
                    <tr>
                        <th class="ps-3" style="width:40px;">#</th>
                        <th>Instructor Name</th>
                        <th>Email & Account</th>
                        <th class="text-center">Assigned Offerings</th>
                        <th class="text-center">Modules & Lessons</th>
                        <th class="text-center">Materials</th>
                        <th class="text-center">Assignments & Quizzes</th>
                        <th class="text-center">Submissions Received</th>
                        <th class="text-center">Pending Grading Load</th>
                        <th class="pe-3 text-center">Grade Approvals</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($teacherRows)): ?>
                        <tr>
                            <td colspan="10" class="text-center py-5 text-muted">
                                <i class="bi bi-person-x fs-2 d-block mb-2 text-secondary"></i>
                                No teacher accounts found matching current search and filter criteria.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php $tIdx = 1; foreach ($teacherRows as $tr): ?>
                            <?php 
                            $fName = trim(($tr['last_name'] ?? '') . ', ' . ($tr['first_name'] ?? ''));
                            if ($fName === '' || $fName === ',') {
                                $fName = $tr['username'];
                            }
                            ?>
                            <tr>
                                <td class="ps-3 text-muted"><?php echo $tIdx++; ?></td>
                                <td>
                                    <div class="fw-bold text-navy-alt"><?php echo htmlspecialchars($fName); ?></div>
                                    <div class="small text-muted font-monospace">@<?php echo htmlspecialchars($tr['username']); ?></div>
                                </td>
                                <td>
                                    <div class="small text-dark"><?php echo htmlspecialchars($tr['email']); ?></div>
                                    <?php if (!empty($tr['is_active'])): ?>
                                        <span class="badge bg-success-subtle text-success fw-semibold" style="font-size:.7rem;">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger-subtle text-danger fw-semibold" style="font-size:.7rem;">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-primary-subtle text-primary fw-bold fs-6">
                                        <?php echo (int)$tr['assigned_subjects_count']; ?>
                                    </span>
                                </td>
                                <td class="text-center small">
                                    <span class="badge bg-light text-dark border"><?php echo (int)$tr['modules_count']; ?> mod</span>
                                    <span class="badge bg-light text-dark border"><?php echo (int)$tr['lessons_count']; ?> les</span>
                                </td>
                                <td class="text-center small fw-semibold text-secondary">
                                    <?php echo (int)$tr['materials_count']; ?> files
                                </td>
                                <td class="text-center small">
                                    <span class="badge bg-light text-dark border"><?php echo (int)$tr['assignments_count']; ?> assign</span>
                                    <span class="badge bg-light text-dark border"><?php echo (int)$tr['quizzes_count']; ?> quiz</span>
                                </td>
                                <td class="text-center">
                                    <div class="fw-bold text-dark"><?php echo number_format((int)$tr['total_submissions_count']); ?></div>
                                    <div class="small text-muted"><?php echo (int)$tr['graded_submissions_count']; ?> graded</div>
                                </td>
                                <td class="text-center">
                                    <?php $pLoad = (int)$tr['pending_grading_count']; ?>
                                    <?php if ($pLoad > 0): ?>
                                        <span class="badge bg-danger text-white fw-bold px-3 py-1">
                                            <i class="bi bi-clock-history me-1"></i><?php echo $pLoad; ?> Pending
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-success-subtle text-success fw-semibold">
                                            <i class="bi bi-check2 me-1"></i>Up to date
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="pe-3 text-center small">
                                    <div class="fw-semibold text-dark">
                                        <?php echo (int)$tr['grade_submissions_count']; ?> Sheets
                                    </div>
                                    <div class="text-muted" style="font-size:.75rem;">
                                        <span class="text-success"><?php echo (int)$tr['approved_grades_count']; ?> Appr</span> &bull; 
                                        <span class="text-primary"><?php echo (int)$tr['pending_approval_grades_count']; ?> Pend</span>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
