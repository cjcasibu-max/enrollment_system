<?php
/**
 * Registrar LMS Reports & Record-Keeping Summary (TASK 4.1)
 *
 * Provides the Registrar with a strictly READ-ONLY reporting hub summarizing
 * LMS-connected data for institutional record-keeping:
 *   1. Enrollment-to-LMS Access Status Report
 *   2. Academic Grade Summary Report per Term/Subject
 *
 * Requirements:
 *   - Strictly READ-ONLY (viewing & exporting reports only).
 *   - Built-in CSV export following system conventions.
 *   - Print/PDF export view with official institutional styling.
 *   - Pulls live data from actual enrollment, payment, section, and grade records.
 */

require_once __DIR__ . '/../includes/lms_access.php';
$registrar = requireLmsRegistrarAccess();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/academic_terms.php';
require_once __DIR__ . '/../includes/assessments.php';

/** @var PDO $pdo */
$activeTerm = getActiveAcademicTerm($pdo);

// ── Report Mode & Filters ──────────────────────────────────────────────────────
$reportType    = isset($_GET['report']) && $_GET['report'] === 'grade_summary' ? 'grade_summary' : 'enrollment_access';
$exportFormat  = isset($_GET['export']) ? strtolower(trim((string)$_GET['export'])) : '';

$filterTermId  = isset($_GET['academic_term_id']) ? (int)$_GET['academic_term_id'] : ($activeTerm ? (int)$activeTerm['id'] : 0);
$filterProgram = isset($_GET['program']) ? trim((string)$_GET['program']) : '';
$filterYear    = isset($_GET['year_level']) ? trim((string)$_GET['year_level']) : '';
$filterStatus  = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$searchQuery   = isset($_GET['search']) ? trim((string)$_GET['search']) : '';

// ── Fetch Academic Terms Dropdown ──────────────────────────────────────────────
$termsStmt = $pdo->query("SELECT id, school_year, semester, is_active FROM academic_terms ORDER BY starts_on DESC, id DESC");
$allTerms = $termsStmt->fetchAll(PDO::FETCH_ASSOC);

$selectedTermLabel = 'All Academic Terms';
foreach ($allTerms as $t) {
    if ((int)$t['id'] === $filterTermId) {
        $selectedTermLabel = $t['school_year'] . ' (' . $t['semester'] . ')' . (!empty($t['is_active']) ? ' - Active' : '');
        break;
    }
}

// ==============================================================================
// 1. DATA GATHERING: ENROLLMENT-TO-LMS ACCESS STATUS
// ==============================================================================
$enrollmentRows = [];
$enrollmentStats = [
    'total_enrolled' => 0,
    'lms_active'     => 0,
    'lms_pending'    => 0,
    'access_rate'    => 0.0,
    'by_program'     => ['BSMT' => ['total' => 0, 'active' => 0], 'BSMarE' => ['total' => 0, 'active' => 0]],
    'by_year'        => ['1st Year' => 0, '2nd Year' => 0, '3rd Year' => 0, '4th Year' => 0],
];

if ($reportType === 'enrollment_access' || $exportFormat === 'csv') {
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
    if ($filterYear !== '') {
        $eSql .= " AND (st.year_level = :yr OR sec.year_level = :sec_yr)";
        $eParams['yr']     = $filterYear;
        $eParams['sec_yr'] = $filterYear;
    }
    if ($searchQuery !== '') {
        $eSql .= " AND (
            st.first_name LIKE :search
            OR st.last_name LIKE :search
            OR u.username LIKE :search
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

        $row['validated_paid']    = $validatedPaid;
        $row['is_downpayment_met'] = $isDownpaymentMet;
        $row['has_lms_access']    = $hasLmsAccess;

        $reasons = [];
        if (!$isRoleStudent) {
            $reasons[] = 'Account pending role upgrade';
        }
        if (!$isEnrollmentConfirmed) {
            $reasons[] = 'Enrollment confirmation pending';
        }
        if (!$isDownpaymentMet) {
            $reqAmt = calculateRequiredDownpayment((float)$row['assessment_total'], (float)$row['minimum_downpayment'], (float)$row['downpayment_percentage']);
            $bal = max(0, $reqAmt - $validatedPaid);
            $reasons[] = 'Downpayment unmet (₱' . number_format($bal, 2) . ' balance)';
        }
        $row['pending_reasons'] = $reasons;

        // Apply access status filter if chosen
        if ($filterStatus === 'active' && !$hasLmsAccess) {
            continue;
        }
        if ($filterStatus === 'pending' && $hasLmsAccess) {
            continue;
        }

        $enrollmentRows[] = $row;

        // Aggregation
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
// 2. DATA GATHERING: ACADEMIC GRADE SUMMARY REPORT PER TERM / SUBJECT
// ==============================================================================
$gradeRows = [];
$subjectSummaries = [];
$gradeStats = [
    'total_grades'     => 0,
    'official_records' => 0,
    'pending_approval' => 0,
    'draft_records'    => 0,
    'passed_count'     => 0,
    'failed_count'     => 0,
    'inc_count'        => 0,
    'drp_count'        => 0,
    'graded_count'     => 0,
    'avg_grade'        => 0.0,
    'passing_rate'     => 0.0,
];

if ($reportType === 'grade_summary' || $exportFormat === 'csv') {
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
               COALESCE(sub.subject_code, c.course_code, sec.section_name, 'SUBJ') AS subject_code,
               COALESCE(sub.subject_name, c.course_name, sec.section_name, 'Subject Course') AS subject_name,
               COALESCE(sub.units, c.units, 3.0) AS units,
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
        LEFT JOIN courses c ON c.id = sec.course_id
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
    if ($filterYear !== '') {
        $gSql .= " AND st.year_level = :yr";
        $gParams['yr'] = $filterYear;
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

        // Aggregate by Subject & Section
        $key = $gr['subject_code'] . '|' . $gr['section_name'];
        if (!isset($subjectSummaries[$key])) {
            $subjectSummaries[$key] = [
                'subject_code' => $gr['subject_code'],
                'subject_name' => $gr['subject_name'],
                'units'        => (float)$gr['units'],
                'section_name' => $gr['section_name'],
                'teacher_name' => $gr['teacher_name'],
                'status'       => $gr['submission_status'],
                'enrolled'     => 0,
                'graded'       => 0,
                'passed'       => 0,
                'failed'       => 0,
                'inc'          => 0,
                'drp'          => 0,
                'sum_grades'   => 0.0,
            ];
        }
        $subjectSummaries[$key]['enrolled']++;
        if ($fGrade !== null && $fGrade !== '') {
            $subjectSummaries[$key]['graded']++;
            $subjectSummaries[$key]['sum_grades'] += (float)$fGrade;
        }
        if ($rem === 'passed' || ($rem === '' && $fGrade !== null && ((float)$fGrade <= 3.0 || (float)$fGrade >= 75.0))) {
            $subjectSummaries[$key]['passed']++;
        } elseif ($rem === 'failed') {
            $subjectSummaries[$key]['failed']++;
        } elseif ($rem === 'incomplete' || $rem === 'inc') {
            $subjectSummaries[$key]['inc']++;
        } elseif ($rem === 'dropped' || $rem === 'drp') {
            $subjectSummaries[$key]['drp']++;
        }
    }

    if ($gradeStats['graded_count'] > 0) {
        $gradeStats['avg_grade']    = round($sumFinalGrades / $gradeStats['graded_count'], 2);
        $gradeStats['passing_rate'] = round(($gradeStats['passed_count'] / $gradeStats['graded_count']) * 100, 1);
    }
}

// ==============================================================================
// 3. CSV EXPORT DISPATCHER (STRICTLY READ-ONLY)
// ==============================================================================
if ($exportFormat === 'csv') {
    $timestamp = date('Ymd_His');
    if ($reportType === 'enrollment_access') {
        $filename = "ncst_lms_enrollment_access_report_{$timestamp}.csv";
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $fp = fopen('php://output', 'w');
        // UTF-8 BOM for Microsoft Excel
        fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF));

        fputcsv($fp, ['NCST MARITIME ACADEMY — ENROLLMENT TO LMS ACCESS REPORT']);
        fputcsv($fp, ['Academic Term: ' . $selectedTermLabel, 'Generated: ' . date('Y-m-d H:i:s')]);
        fputcsv($fp, ['Total Enrolled: ' . $enrollmentStats['total_enrolled'], 'Has LMS Access: ' . $enrollmentStats['lms_active'], 'Pending Access: ' . $enrollmentStats['lms_pending'], 'Access Rate: ' . $enrollmentStats['access_rate'] . '%']);
        fputcsv($fp, []); // Blank separator

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
            'Role Status',
            'LMS Access Status',
            'Pending Reasons'
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
                !empty($er['has_lms_access']) ? 'Active / Has Access' : 'Pending Access',
                implode('; ', $er['pending_reasons'] ?? [])
            ]);
        }
        fclose($fp);
        exit;
    } else {
        $filename = "ncst_lms_grade_summary_report_{$timestamp}.csv";
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $fp = fopen('php://output', 'w');
        fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF));

        fputcsv($fp, ['NCST MARITIME ACADEMY — LMS ACADEMIC GRADE SUMMARY REPORT']);
        fputcsv($fp, ['Academic Term: ' . $selectedTermLabel, 'Generated: ' . date('Y-m-d H:i:s')]);
        fputcsv($fp, ['Total Grades: ' . $gradeStats['total_grades'], 'Official Records: ' . $gradeStats['official_records'], 'Average Grade: ' . $gradeStats['avg_grade'], 'Passing Rate: ' . $gradeStats['passing_rate'] . '%']);
        fputcsv($fp, []);

        fputcsv($fp, [
            '#',
            'Cadet Name',
            'Username',
            'Program',
            'Year Level',
            'Subject Code',
            'Subject Title',
            'Units',
            'Section',
            'Instructor',
            'Term',
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
                $gr['subject_name'] ?? '',
                number_format((float)$gr['units'], 1),
                $gr['section_name'] ?? '',
                $gr['teacher_name'] ?? '',
                ($gr['school_year'] ?? '') . ' ' . ($gr['semester'] ?? ''),
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
    }
}

$page_title = 'LMS Reports & Records Summary — Registrar Office';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
@media print {
    .sidebar, .top-navbar, .footer, .no-print, nav, .btn, form, .badge-filter {
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
</style>

<!-- ── PRINT HEADER (VISIBLE ONLY ON PRINT / PDF SAVE) ───────────────────────── -->
<div class="print-header mb-4 text-center border-bottom pb-3">
    <h4 class="fw-bold mb-0 text-uppercase" style="letter-spacing:.05em;">NCST Maritime Academy</h4>
    <div class="small text-muted fw-semibold">Office of the Registrar &bull; Academic Records Management</div>
    <h5 class="fw-bold mt-2 text-navy-alt">
        <?php echo $reportType === 'enrollment_access' ? 'Official Enrollment-to-LMS Access Status Report' : 'Official LMS Academic Grade Summary Report'; ?>
    </h5>
    <div class="small text-muted">Academic Term: <?php echo htmlspecialchars($selectedTermLabel); ?> &bull; Printed: <?php echo date('F d, Y h:i A'); ?></div>
</div>

<!-- ── REGISTRAR LMS NAVIGATION BAR ─────────────────────────────────────────── -->
<nav class="card shadow-sm mb-4 border-0 no-print" style="background:var(--sidebar-bg,#0b4f5c);" aria-label="Registrar LMS navigation">
    <div class="card-body py-2 px-3">
        <div class="d-flex flex-wrap gap-1 align-items-center">
            <span class="text-white opacity-75 small fw-semibold me-2 text-nowrap" style="font-size:.7rem;letter-spacing:.06em;text-transform:uppercase;">
                <i class="bi bi-mortarboard me-1"></i>LMS Records
            </span>
            <a href="lms_enrollments" class="btn btn-sm text-white text-nowrap" style="opacity:.85;" onmouseenter="this.style.opacity=1" onmouseleave="this.style.opacity='.85'">
                <i class="bi bi-person-check-fill me-1"></i>LMS Enrollments
            </a>
            <a href="lms_subjects" class="btn btn-sm text-white text-nowrap" style="opacity:.85;" onmouseenter="this.style.opacity=1" onmouseleave="this.style.opacity='.85'">
                <i class="bi bi-layers-fill me-1"></i>Subjects & Sections
            </a>
            <a href="lms_grades" class="btn btn-sm text-white text-nowrap" style="opacity:.85;" onmouseenter="this.style.opacity=1" onmouseleave="this.style.opacity='.85'">
                <i class="bi bi-mortarboard-fill me-1"></i>Academic Grades
            </a>
            <a href="lms_reports" class="btn btn-sm text-white fw-bold active text-nowrap" style="background:rgba(255,255,255,.2);border-radius:6px;opacity:1;" aria-current="page">
                <i class="bi bi-file-earmark-bar-graph-fill me-1"></i>LMS Reports
            </a>
            <a href="grade_approvals" class="btn btn-sm text-white text-nowrap" style="opacity:.85;" onmouseenter="this.style.opacity=1" onmouseleave="this.style.opacity='.85'">
                <i class="bi bi-check2-square me-1"></i>Grade Approvals
            </a>
        </div>
    </div>
</nav>

<!-- ── BREADCRUMB ──────────────────────────────────────────────────────────── -->
<nav aria-label="breadcrumb" class="mb-3 no-print">
    <ol class="breadcrumb mb-0" style="font-size:.82rem;">
        <li class="breadcrumb-item"><a href="dashboard" class="text-decoration-none text-brand-primary"><i class="bi bi-journal-bookmark-fill me-1"></i>Registrar Office</a></li>
        <li class="breadcrumb-item active" aria-current="page">LMS Reports & Record-Keeping</li>
    </ol>
</nav>

<!-- ── PAGE HEADER ─────────────────────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
    <div>
        <h1 class="h3 fw-bold text-navy-alt mb-1">
            <i class="bi bi-file-earmark-bar-graph-fill me-2 text-brand-primary"></i>LMS Reports & Records Summary
        </h1>
        <p class="text-muted small mb-0">
            Generate and export institutional record-keeping summaries of LMS enrollment access readiness and academic grades.
        </p>
    </div>
    <div class="d-flex gap-2 no-print">
        <?php 
        $exportCsvQuery = $_GET;
        $exportCsvQuery['export'] = 'csv';
        $exportCsvUrl = 'lms_reports?' . http_build_query($exportCsvQuery);
        ?>
        <a href="<?php echo htmlspecialchars($exportCsvUrl); ?>" class="btn btn-sm btn-success d-inline-flex align-items-center gap-1 shadow-sm">
            <i class="bi bi-file-earmark-spreadsheet-fill"></i>Export to CSV
        </a>
        <button class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-1" onclick="window.print()">
            <i class="bi bi-printer"></i>Print / Save PDF
        </button>
    </div>
</div>

<!-- ── REPORT TYPE SELECTOR PILLS ───────────────────────────────────────────── -->
<div class="card shadow-sm border-0 mb-4 no-print">
    <div class="card-body p-2 d-flex flex-wrap gap-2 align-items-center bg-light rounded-3">
        <span class="text-muted small fw-bold text-uppercase ms-2 me-1" style="font-size:.72rem;">Select Report:</span>
        <a href="lms_reports?report=enrollment_access&academic_term_id=<?php echo $filterTermId; ?>" 
           class="btn btn-sm <?php echo $reportType === 'enrollment_access' ? 'btn-brand-primary text-white fw-bold shadow-sm' : 'btn-outline-secondary'; ?>">
            <i class="bi bi-person-check-fill me-1"></i>Enrollment-to-LMS Access Status
        </a>
        <a href="lms_reports?report=grade_summary&academic_term_id=<?php echo $filterTermId; ?>" 
           class="btn btn-sm <?php echo $reportType === 'grade_summary' ? 'btn-brand-primary text-white fw-bold shadow-sm' : 'btn-outline-secondary'; ?>">
            <i class="bi bi-award-fill me-1"></i>Academic Grade Summary per Term/Subject
        </a>
    </div>
</div>

<!-- ── SEARCH & FILTER FORM (STRICTLY READ-ONLY GET REQUESTS) ───────────────── -->
<div class="card shadow-sm border-0 mb-4 no-print">
    <div class="card-body p-3">
        <form method="GET" action="lms_reports" class="row g-2 align-items-center">
            <input type="hidden" name="report" value="<?php echo htmlspecialchars($reportType); ?>">
            <div class="col-12 col-md-3">
                <label class="form-label small fw-semibold text-muted mb-1">Search Keywords</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" name="search" class="form-control border-start-0" 
                           placeholder="<?php echo $reportType === 'enrollment_access' ? 'Cadet, section block...' : 'Cadet, subject code, section...'; ?>" 
                           value="<?php echo htmlspecialchars($searchQuery); ?>">
                </div>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold text-muted mb-1">Academic Term</label>
                <select name="academic_term_id" class="form-select form-select-sm">
                    <option value="0">All Terms</option>
                    <?php foreach ($allTerms as $t): ?>
                        <option value="<?php echo (int)$t['id']; ?>" <?php echo $filterTermId === (int)$t['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($t['school_year'] . ' (' . $t['semester'] . ')'); ?><?php echo !empty($t['is_active']) ? ' (Active)' : ''; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold text-muted mb-1">Program</label>
                <select name="program" class="form-select form-select-sm">
                    <option value="">All Programs</option>
                    <option value="BSMT" <?php echo $filterProgram === 'BSMT' ? 'selected' : ''; ?>>BSMT</option>
                    <option value="BSMarE" <?php echo $filterProgram === 'BSMarE' ? 'selected' : ''; ?>>BSMarE</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold text-muted mb-1">Year Level</label>
                <select name="year_level" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="1st Year" <?php echo $filterYear === '1st Year' ? 'selected' : ''; ?>>1st Year</option>
                    <option value="2nd Year" <?php echo $filterYear === '2nd Year' ? 'selected' : ''; ?>>2nd Year</option>
                    <option value="3rd Year" <?php echo $filterYear === '3rd Year' ? 'selected' : ''; ?>>3rd Year</option>
                    <option value="4th Year" <?php echo $filterYear === '4th Year' ? 'selected' : ''; ?>>4th Year</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold text-muted mb-1">
                    <?php echo $reportType === 'enrollment_access' ? 'LMS Access' : 'Record Status'; ?>
                </label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Statuses</option>
                    <?php if ($reportType === 'enrollment_access'): ?>
                        <option value="active" <?php echo $filterStatus === 'active' ? 'selected' : ''; ?>>Active / Has Access</option>
                        <option value="pending" <?php echo $filterStatus === 'pending' ? 'selected' : ''; ?>>Pending Access</option>
                    <?php else: ?>
                        <option value="approved" <?php echo $filterStatus === 'approved' ? 'selected' : ''; ?>>Official (Approved)</option>
                        <option value="submitted" <?php echo $filterStatus === 'submitted' ? 'selected' : ''; ?>>Submitted (Pending)</option>
                        <option value="draft" <?php echo $filterStatus === 'draft' ? 'selected' : ''; ?>>Draft (In Progress)</option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="col-12 col-md-1 d-flex gap-1 align-self-end">
                <button type="submit" class="btn btn-brand-primary btn-sm w-100">
                    <i class="bi bi-funnel-fill me-1"></i>Filter
                </button>
            </div>
        </form>
    </div>
</div>

<?php if ($reportType === 'enrollment_access'): ?>
    <!-- ======================================================================== -->
    <!-- REPORT 1: ENROLLMENT-TO-LMS ACCESS STATUS REPORT                         -->
    <!-- ======================================================================== -->

    <!-- KPI Metrics -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body p-3 text-center">
                    <div class="text-muted small fw-semibold text-uppercase" style="font-size:.72rem;">Enrolled Cadets</div>
                    <div class="h3 fw-bold text-navy-alt my-1"><?php echo number_format($enrollmentStats['total_enrolled']); ?></div>
                    <div class="text-muted small" style="font-size:.75rem;">Total confirmed enrollments</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body p-3 text-center">
                    <div class="text-muted small fw-semibold text-uppercase" style="font-size:.72rem;">Has LMS Access</div>
                    <div class="h3 fw-bold text-success my-1">
                        <i class="bi bi-check-circle-fill me-1" style="font-size:1.1rem;"></i><?php echo number_format($enrollmentStats['lms_active']); ?>
                    </div>
                    <div class="text-muted small" style="font-size:.75rem;">Eligible for LMS courses</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body p-3 text-center">
                    <div class="text-muted small fw-semibold text-uppercase" style="font-size:.72rem;">Pending Access</div>
                    <div class="h3 fw-bold text-warning my-1">
                        <i class="bi bi-clock-history me-1" style="font-size:1.1rem;"></i><?php echo number_format($enrollmentStats['lms_pending']); ?>
                    </div>
                    <div class="text-muted small" style="font-size:.75rem;">Downpayment or role pending</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body p-3 text-center">
                    <div class="text-muted small fw-semibold text-uppercase" style="font-size:.72rem;">LMS Access Rate</div>
                    <div class="h3 fw-bold text-brand-primary my-1">
                        <i class="bi bi-pie-chart-fill me-1" style="font-size:1.1rem;"></i><?php echo $enrollmentStats['access_rate']; ?>%
                    </div>
                    <div class="text-muted small" style="font-size:.75rem;">Active / Total enrolled</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Data Table -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white py-3 px-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="fw-bold text-navy-alt">
                <i class="bi bi-table me-2 text-brand-primary"></i>Enrollment-to-LMS Access Cadet Ledger
                <span class="badge bg-secondary-subtle text-secondary ms-2"><?php echo count($enrollmentRows); ?> Cadets</span>
            </div>
            <div class="text-muted small">
                Scope: <?php echo htmlspecialchars($selectedTermLabel); ?>
            </div>
        </div>
        <div class="card-body p-0">
            <?php if (empty($enrollmentRows)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-people text-muted display-4 d-block mb-3 opacity-50"></i>
                    <h5 class="fw-bold text-navy-alt">No Enrollment Records Found</h5>
                    <p class="text-muted small mb-0">No cadets match the selected term, program, or status criteria.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size:.85rem;">
                        <thead class="table-light text-muted fw-semibold" style="font-size:.73rem;letter-spacing:.03em;text-transform:uppercase;">
                            <tr>
                                <th class="ps-4" style="width:25%;">Cadet Details</th>
                                <th style="width:18%;">Program & Cohort</th>
                                <th style="width:20%;">Payment Status</th>
                                <th style="width:22%;">LMS Access Readiness</th>
                                <th class="pe-4 text-end" style="width:15%;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($enrollmentRows as $er): 
                                $cadetName = trim(($er['last_name'] ?? '') . ', ' . ($er['first_name'] ?? '') . ' ' . ($er['middle_name'] ?? '') . ' ' . ($er['suffix'] ?? ''));
                            ?>
                                <tr>
                                    <td class="ps-4">
                                        <div class="fw-bold text-navy-alt"><?php echo htmlspecialchars($cadetName); ?></div>
                                        <div class="text-muted small" style="font-size:.75rem;">
                                            <span><?php echo htmlspecialchars($er['student_username'] ?? '—'); ?></span>
                                            <span class="text-muted">&bull; <?php echo htmlspecialchars($er['student_email'] ?? ''); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <div><span class="badge bg-secondary-subtle text-secondary me-1"><?php echo htmlspecialchars($er['program_code'] ?: 'N/A'); ?></span><?php echo htmlspecialchars($er['student_year'] ?: 'N/A'); ?></div>
                                        <div class="text-muted small" style="font-size:.75rem;"><i class="bi bi-layers me-1"></i><?php echo htmlspecialchars($er['section_name']); ?></div>
                                    </td>
                                    <td>
                                        <div class="small">
                                            <span class="text-muted">Paid:</span> 
                                            <span class="fw-semibold">₱<?php echo number_format((float)$er['validated_paid'], 2); ?></span>
                                        </div>
                                        <div class="text-muted small" style="font-size:.72rem;">
                                            Assessed: ₱<?php echo number_format((float)$er['assessment_total'], 2); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if (!empty($er['has_lms_access'])): ?>
                                            <div class="text-success small fw-semibold">
                                                <i class="bi bi-check2-all me-1"></i>All Prerequisites Met
                                            </div>
                                            <div class="text-muted" style="font-size:.72rem;">Downpayment + Section + Student Role</div>
                                        <?php else: ?>
                                            <div class="text-warning small fw-semibold">
                                                <i class="bi bi-exclamation-triangle me-1"></i>Pending Requirements:
                                            </div>
                                            <ul class="text-muted mb-0 ps-3" style="font-size:.72rem;">
                                                <?php foreach ($er['pending_reasons'] as $r): ?>
                                                    <li><?php echo htmlspecialchars($r); ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                    </td>
                                    <td class="pe-4 text-end">
                                        <?php if (!empty($er['has_lms_access'])): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 fw-bold" style="font-size:.72rem;">
                                                <i class="bi bi-check-circle-fill me-1"></i>Has Access
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-warning-subtle text-warning border border-warning-subtle px-2 py-1 fw-bold" style="font-size:.72rem;">
                                                <i class="bi bi-clock-history me-1"></i>Pending Access
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php else: ?>
    <!-- ======================================================================== -->
    <!-- REPORT 2: ACADEMIC GRADE SUMMARY REPORT PER TERM / SUBJECT               -->
    <!-- ======================================================================== -->

    <!-- KPI Metrics -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body p-3 text-center">
                    <div class="text-muted small fw-semibold text-uppercase" style="font-size:.72rem;">Recorded Grades</div>
                    <div class="h3 fw-bold text-navy-alt my-1"><?php echo number_format($gradeStats['total_grades']); ?></div>
                    <div class="text-muted small" style="font-size:.75rem;">Subject entries in scope</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body p-3 text-center">
                    <div class="text-muted small fw-semibold text-uppercase" style="font-size:.72rem;">Official Records</div>
                    <div class="h3 fw-bold text-success my-1">
                        <i class="bi bi-patch-check-fill me-1" style="font-size:1.1rem;"></i><?php echo number_format($gradeStats['official_records']); ?>
                    </div>
                    <div class="text-muted small" style="font-size:.75rem;">Approved into transcripts</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body p-3 text-center">
                    <div class="text-muted small fw-semibold text-uppercase" style="font-size:.72rem;">Average Final Grade</div>
                    <div class="h3 fw-bold text-brand-primary my-1">
                        <i class="bi bi-award-fill me-1" style="font-size:1.1rem;"></i><?php echo $gradeStats['avg_grade'] > 0 ? number_format($gradeStats['avg_grade'], 2) : '—'; ?>
                    </div>
                    <div class="text-muted small" style="font-size:.75rem;">Across graded cadets</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body p-3 text-center">
                    <div class="text-muted small fw-semibold text-uppercase" style="font-size:.72rem;">Overall Passing Rate</div>
                    <div class="h3 fw-bold text-info my-1">
                        <i class="bi bi-graph-up-arrow me-1" style="font-size:1.1rem;"></i><?php echo $gradeStats['graded_count'] > 0 ? $gradeStats['passing_rate'] . '%' : '—'; ?>
                    </div>
                    <div class="text-muted small" style="font-size:.75rem;"><?php echo $gradeStats['passed_count']; ?> Passed / <?php echo $gradeStats['graded_count']; ?> Graded</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Section A: Per Subject-Section Aggregate Summary -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white py-3 px-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="fw-bold text-navy-alt">
                <i class="bi bi-collection me-2 text-brand-primary"></i>Grade Summary per Subject & Section Offering
                <span class="badge bg-secondary-subtle text-secondary ms-2"><?php echo count($subjectSummaries); ?> Offerings</span>
            </div>
            <div class="text-muted small">Summary Breakdown</div>
        </div>
        <div class="card-body p-0">
            <?php if (empty($subjectSummaries)): ?>
                <div class="text-center py-4 text-muted small">No offerings found for this selection.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0" style="font-size:.83rem;">
                        <thead class="table-light text-muted fw-semibold" style="font-size:.72rem;letter-spacing:.03em;text-transform:uppercase;">
                            <tr>
                                <th class="ps-4">Subject / Course</th>
                                <th>Section</th>
                                <th>Instructor</th>
                                <th class="text-center">Enrolled</th>
                                <th class="text-center">Graded</th>
                                <th class="text-center">Average</th>
                                <th class="text-center">Passed</th>
                                <th class="text-center">Failed</th>
                                <th class="text-center">Pass %</th>
                                <th class="pe-4 text-end">Workflow Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subjectSummaries as $sub): 
                                $avg = $sub['graded'] > 0 ? round($sub['sum_grades'] / $sub['graded'], 2) : 0.0;
                                $rate = $sub['graded'] > 0 ? round(($sub['passed'] / $sub['graded']) * 100, 1) : 0.0;
                                $isOfficial = in_array($sub['status'], ['approved', 'locked'], true);
                            ?>
                                <tr>
                                    <td class="ps-4 fw-bold text-navy-alt">
                                        <span class="badge bg-navy-subtle text-navy me-1 px-1.5 py-0.5" style="background:#e0edf1;color:#0b4f5c;">
                                            <?php echo htmlspecialchars($sub['subject_code']); ?>
                                        </span>
                                        <?php echo htmlspecialchars($sub['subject_name']); ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($sub['section_name']); ?></td>
                                    <td class="text-muted small"><?php echo htmlspecialchars($sub['teacher_name']); ?></td>
                                    <td class="text-center"><?php echo $sub['enrolled']; ?></td>
                                    <td class="text-center"><?php echo $sub['graded']; ?></td>
                                    <td class="text-center font-monospace fw-bold text-navy-alt"><?php echo $avg > 0 ? number_format($avg, 2) : '—'; ?></td>
                                    <td class="text-center text-success fw-bold"><?php echo $sub['passed']; ?></td>
                                    <td class="text-center text-danger"><?php echo $sub['failed']; ?></td>
                                    <td class="text-center fw-bold text-brand-primary"><?php echo $sub['graded'] > 0 ? $rate . '%' : '—'; ?></td>
                                    <td class="pe-4 text-end">
                                        <?php if ($isOfficial): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-0.5 fw-bold" style="font-size:.7rem;">
                                                <i class="bi bi-lock-fill me-0.5"></i>Official
                                            </span>
                                        <?php elseif ($sub['status'] === 'submitted'): ?>
                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-0.5 fw-bold" style="font-size:.7rem;">
                                                <i class="bi bi-hourglass-split me-0.5"></i>Submitted
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-warning-subtle text-warning border border-warning-subtle px-2 py-0.5" style="font-size:.7rem;">
                                                <i class="bi bi-pencil me-0.5"></i>Draft
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Section B: Cadet Grade Records Detail -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white py-3 px-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="fw-bold text-navy-alt">
                <i class="bi bi-card-checklist me-2 text-brand-primary"></i>Individual Cadet Academic Records
                <span class="badge bg-secondary-subtle text-secondary ms-2"><?php echo count($gradeRows); ?> Entries</span>
            </div>
            <div class="text-muted small">Cadet Final Grades</div>
        </div>
        <div class="card-body p-0">
            <?php if (empty($gradeRows)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-journal-x text-muted display-4 d-block mb-3 opacity-50"></i>
                    <h5 class="fw-bold text-navy-alt">No Grade Records Found</h5>
                    <p class="text-muted small mb-0">No cadet grade records match the selected filters.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size:.84rem;">
                        <thead class="table-light text-muted fw-semibold" style="font-size:.72rem;letter-spacing:.03em;text-transform:uppercase;">
                            <tr>
                                <th class="ps-4" style="width:24%;">Cadet Name</th>
                                <th style="width:22%;">Subject & Section</th>
                                <th style="width:16%;">Instructor</th>
                                <th style="width:7%;" class="text-center">Prelim</th>
                                <th style="width:7%;" class="text-center">Midterm</th>
                                <th style="width:7%;" class="text-center">Final</th>
                                <th style="width:8%;" class="text-center">Remarks</th>
                                <th class="pe-4 text-end" style="width:9%;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($gradeRows as $gr): 
                                $cadetName = trim(($gr['student_last_name'] ?? '') . ', ' . ($gr['student_first_name'] ?? '') . ' ' . ($gr['student_middle_name'] ?? '') . ' ' . ($gr['student_suffix'] ?? ''));
                                $isOfficial= in_array($gr['submission_status'], ['approved', 'locked'], true);
                                $fGrade    = $gr['final_grade'];
                                $rem       = trim($gr['remarks'] ?? '');
                            ?>
                                <tr>
                                    <td class="ps-4">
                                        <div class="fw-bold text-navy-alt"><?php echo htmlspecialchars($cadetName); ?></div>
                                        <div class="text-muted small" style="font-size:.75rem;">
                                            <span><?php echo htmlspecialchars($gr['student_username'] ?? '—'); ?></span>
                                            <span class="badge bg-secondary-subtle text-secondary ms-1"><?php echo htmlspecialchars($gr['program_code'] ?? ''); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-navy-alt">
                                            <span class="badge bg-navy-subtle text-navy me-1 px-1.5 py-0.5" style="background:#e0edf1;color:#0b4f5c;">
                                                <?php echo htmlspecialchars($gr['subject_code']); ?>
                                            </span>
                                            <?php echo htmlspecialchars($gr['subject_name']); ?>
                                        </div>
                                        <div class="text-muted small" style="font-size:.74rem;">
                                            <i class="bi bi-layers me-1"></i><?php echo htmlspecialchars($gr['section_name']); ?>
                                            &bull; <?php echo number_format((float)$gr['units'], 1); ?> Units
                                        </div>
                                    </td>
                                    <td class="text-muted small">
                                        <?php echo htmlspecialchars($gr['teacher_name']); ?>
                                    </td>
                                    <td class="text-center font-monospace" style="font-size:.82rem;">
                                        <?php echo $gr['prelim_grade'] !== null ? number_format((float)$gr['prelim_grade'], 2) : '—'; ?>
                                    </td>
                                    <td class="text-center font-monospace" style="font-size:.82rem;">
                                        <?php echo $gr['midterm_grade'] !== null ? number_format((float)$gr['midterm_grade'], 2) : '—'; ?>
                                    </td>
                                    <td class="text-center font-monospace fw-bold" style="font-size:.85rem;color:#0b4f5c;">
                                        <?php echo $fGrade !== null ? number_format((float)$fGrade, 2) : '—'; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if (strcasecmp($rem, 'Passed') === 0): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-0.5" style="font-size:.7rem;">Passed</span>
                                        <?php elseif (strcasecmp($rem, 'Failed') === 0): ?>
                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-0.5" style="font-size:.7rem;">Failed</span>
                                        <?php elseif (strcasecmp($rem, 'Incomplete') === 0 || strcasecmp($rem, 'INC') === 0): ?>
                                            <span class="badge bg-warning-subtle text-warning border border-warning-subtle px-2 py-0.5" style="font-size:.7rem;">INC</span>
                                        <?php elseif (strcasecmp($rem, 'Dropped') === 0 || strcasecmp($rem, 'DRP') === 0): ?>
                                            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2 py-0.5" style="font-size:.7rem;">DRP</span>
                                        <?php else: ?>
                                            <span class="text-muted small"><?php echo htmlspecialchars($rem ?: '—'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="pe-4 text-end">
                                        <?php if ($isOfficial): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-0.5 fw-bold" style="font-size:.7rem;">
                                                <i class="bi bi-lock-fill me-0.5"></i>Official
                                            </span>
                                        <?php elseif ($gr['submission_status'] === 'submitted'): ?>
                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-0.5 fw-bold" style="font-size:.7rem;">
                                                <i class="bi bi-hourglass-split me-0.5"></i>Submitted
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-warning-subtle text-warning border border-warning-subtle px-2 py-0.5" style="font-size:.7rem;">
                                                <i class="bi bi-pencil me-0.5"></i>Draft
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php
require_once __DIR__ . '/../includes/footer.php';
