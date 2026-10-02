<?php
/**
 * LMS Administrative Oversight Console (TASK 5.1)
 *
 * System-wide administrator oversight over:
 *   1. Subject Content Oversight: Inspect lessons, materials, assignments, quizzes,
 *      and announcements across all subjects and instructors system-wide.
 *   2. Corrective Teacher Assignment: Reassign or remove instructors for administrative reasons.
 *   3. Student Access Diagnostics & Overrides: Diagnostic view of student enrollment-to-LMS
 *      access, with ability to fix stuck sync issues or execute administrative overrides.
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

$activeTerm = getActiveAcademicTerm($pdo);

// Capture view selection: 'content' (default) or 'students'
$activeView = trim((string)($_GET['view'] ?? 'content'));
if (!in_array($activeView, ['content', 'students'], true)) {
    $activeView = 'content';
}

// ── Shared Term & Program Filters ─────────────────────────────────────────────
$filterTermId  = isset($_GET['term_id']) ? (int)$_GET['term_id'] : ($activeTerm ? (int)$activeTerm['id'] : 0);
$filterProgram = trim((string)($_GET['program'] ?? ''));
$searchQuery   = trim((string)($_GET['search'] ?? ''));

// Fetch all terms for filter
$allTerms = $pdo->query("SELECT id, school_year, semester, is_active FROM academic_terms ORDER BY starts_on DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch all active teachers for reassignment modal
$allTeachers = $pdo->query("
    SELECT id, username, first_name, last_name, email 
    FROM users 
    WHERE role = 'teacher' AND is_active = 1 
    ORDER BY last_name ASC, first_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// ── 1. Content Oversight Queries ──────────────────────────────────────────────
$subjectOfferings = [];
$contentStats = [
    'total_offerings'   => 0,
    'total_modules'     => 0,
    'total_materials'   => 0,
    'total_assignments' => 0,
    'total_quizzes'     => 0,
];

if ($activeView === 'content') {
    $sql = "
        SELECT ss.id AS section_subject_id, ss.section_id, ss.subject_id, ss.instructor_id,
               sub.subject_code, sub.subject_name, sub.units,
               sec.section_name, sec.program, sec.year_level,
               at.id AS term_id, at.school_year, at.semester,
               u.id AS teacher_user_id, u.username AS teacher_username,
               CONCAT_WS(' ', u.first_name, u.last_name) AS teacher_full_name,
               (SELECT COUNT(*) FROM lms_modules m WHERE m.section_subject_id = ss.id) AS module_count,
               (SELECT COUNT(*) FROM lms_materials mat WHERE mat.section_subject_id = ss.id) AS material_count,
               (SELECT COUNT(*) FROM lms_assignments a WHERE a.section_subject_id = ss.id) AS assignment_count,
               (SELECT COUNT(*) FROM lms_quizzes q WHERE q.section_subject_id = ss.id) AS quiz_count,
               (SELECT COUNT(*) FROM lms_announcements an WHERE an.section_subject_id = ss.id) AS announcement_count,
               (SELECT COUNT(DISTINCT e.student_id) FROM enrollments e WHERE e.section_id = sec.id AND e.status = 'enrolled') AS enrolled_students_count
        FROM section_subjects ss
        JOIN subjects sub ON sub.id = ss.subject_id
        JOIN sections sec ON sec.id = ss.section_id
        LEFT JOIN academic_terms at ON at.id = sec.academic_term_id
        LEFT JOIN users u ON u.id = ss.instructor_id
        WHERE 1=1
    ";
    $params = [];

    if ($filterTermId > 0) {
        $sql .= " AND sec.academic_term_id = :term_id";
        $params['term_id'] = $filterTermId;
    }
    if ($filterProgram !== '') {
        $sql .= " AND sec.program = :prog";
        $params['prog'] = $filterProgram;
    }
    if ($searchQuery !== '') {
        $sql .= " AND (sub.subject_code LIKE :q1 OR sub.subject_name LIKE :q2 OR sec.section_name LIKE :q3 OR u.username LIKE :q4 OR u.first_name LIKE :q5 OR u.last_name LIKE :q6)";
        $termLike = '%' . $searchQuery . '%';
        $params['q1'] = $termLike;
        $params['q2'] = $termLike;
        $params['q3'] = $termLike;
        $params['q4'] = $termLike;
        $params['q5'] = $termLike;
        $params['q6'] = $termLike;
    }

    $sql .= " ORDER BY sub.subject_code ASC, sec.section_name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $subjectOfferings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Compute aggregates
    $contentStats['total_offerings'] = count($subjectOfferings);
    foreach ($subjectOfferings as $row) {
        $contentStats['total_modules']     += (int)$row['module_count'];
        $contentStats['total_materials']   += (int)$row['material_count'];
        $contentStats['total_assignments'] += (int)$row['assignment_count'];
        $contentStats['total_quizzes']     += (int)$row['quiz_count'];
    }
}

// ── 2. Student LMS Access Diagnostic Queries ──────────────────────────────────
$studentList = [];
$accessStats = [
    'total_students' => 0,
    'access_active'  => 0,
    'access_pending' => 0,
    'sync_issues'    => 0,
];

if ($activeView === 'students') {
    $sqlStudents = "
        SELECT s.id AS student_id, s.user_id, s.first_name, s.middle_name, s.last_name,
               s.program_code, s.program_applying_for, s.year_level,
               s.enrollment_status AS student_enrollment_status,
               s.academic_term_id AS student_term_id,
               u.username, u.email, u.role AS user_role, u.is_active AS user_active,
               e.id AS enrollment_id, e.status AS enrollment_record_status, e.section_id,
               e.academic_term_id AS enrollment_term_id,
               sec.section_name,
               COALESCE(a.total_amount, 0) AS assessment_total,
               COALESCE(t.minimum_downpayment, 0) AS minimum_downpayment,
               COALESCE(t.downpayment_percentage, 0) AS downpayment_percentage,
               COALESCE((
                   SELECT SUM(pa.amount)
                   FROM assessment_items ai
                   JOIN payment_allocations pa ON pa.assessment_item_id = ai.id
                   JOIN payments p ON p.id = pa.payment_id
                   WHERE ai.assessment_id = a.id AND p.or_status = 'validated'
               ), 0) + COALESCE((
                   SELECT SUM(unalloc.amount)
                   FROM payments unalloc
                   WHERE unalloc.student_id = s.id
                     AND unalloc.academic_term_id = s.academic_term_id
                     AND unalloc.or_status = 'validated'
                     AND NOT EXISTS (SELECT 1 FROM payment_allocations ex WHERE ex.payment_id = unalloc.id)
               ), 0) AS validated_paid
        FROM students s
        JOIN users u ON u.id = s.user_id
        LEFT JOIN academic_terms t ON t.id = s.academic_term_id
        LEFT JOIN enrollments e ON e.id = (
            SELECT cur_e.id
            FROM enrollments cur_e
            WHERE cur_e.student_id = s.id
              AND (cur_e.academic_term_id = :filter_term OR :filter_term = 0)
            ORDER BY cur_e.id DESC
            LIMIT 1
        )
        LEFT JOIN sections sec ON sec.id = e.section_id
        LEFT JOIN assessments a ON a.id = (
            SELECT cur_a.id
            FROM assessments cur_a
            WHERE cur_a.student_id = s.id
              AND (cur_a.academic_term_id = :filter_term2 OR :filter_term2 = 0)
              AND cur_a.status != 'cancelled'
            ORDER BY cur_a.generated_at DESC, cur_a.id DESC
            LIMIT 1
        )
        WHERE 1=1
    ";
    $paramsSt = [
        'filter_term'  => $filterTermId,
        'filter_term2' => $filterTermId,
    ];

    if ($filterProgram !== '') {
        $sqlStudents .= " AND (s.program_code = :prog1 OR s.program_applying_for = :prog2)";
        $paramsSt['prog1'] = $filterProgram;
        $paramsSt['prog2'] = $filterProgram;
    }
    if ($searchQuery !== '') {
        $sqlStudents .= " AND (s.first_name LIKE :sq1 OR s.last_name LIKE :sq2 OR u.username LIKE :sq3 OR u.email LIKE :sq4)";
        $termLike = '%' . $searchQuery . '%';
        $paramsSt['sq1'] = $termLike;
        $paramsSt['sq2'] = $termLike;
        $paramsSt['sq3'] = $termLike;
        $paramsSt['sq4'] = $termLike;
    }

    $sqlStudents .= " ORDER BY s.last_name ASC, s.first_name ASC LIMIT 250";
    $stStmt = $pdo->prepare($sqlStudents);
    $stStmt->execute($paramsSt);
    $studentList = $stStmt->fetchAll(PDO::FETCH_ASSOC);

    // Compute Diagnostics
    $accessStats['total_students'] = count($studentList);
    foreach ($studentList as &$stu) {
        $hasPaid = hasMetRequiredDownpayment(
            (float)$stu['validated_paid'],
            (float)$stu['assessment_total'],
            (float)$stu['minimum_downpayment'],
            (float)$stu['downpayment_percentage']
        );
        $hasConfirmedEnrollment = ($stu['enrollment_record_status'] === 'enrolled');
        $isEnrolledStatus = ($stu['student_enrollment_status'] === 'enrolled');
        $isStudentRole = ($stu['user_role'] === 'student');

        // Diagnose sync discrepancy
        $isSyncIssue = false;
        if ($hasConfirmedEnrollment && $hasPaid && (!$isEnrolledStatus || !$isStudentRole)) {
            $isSyncIssue = true;
            $accessStats['sync_issues']++;
        }

        $meetsAccess = ($isEnrolledStatus && $hasConfirmedEnrollment && $hasPaid);
        if ($meetsAccess) {
            $accessStats['access_active']++;
        } else {
            $accessStats['access_pending']++;
        }

        $stu['diag_has_paid']     = $hasPaid;
        $stu['diag_confirmed_sec'] = $hasConfirmedEnrollment;
        $stu['diag_meets_access']  = $meetsAccess;
        $stu['diag_sync_issue']    = $isSyncIssue;
    }
    unset($stu);
}

$page_title = 'LMS Administrative Oversight — Administrator';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- ── ADMIN LMS NAVIGATION BAR ─────────────────────────────────────────── -->
<nav class="card shadow-sm mb-4 border-0" style="background:var(--sidebar-bg,#0b4f5c);" aria-label="Admin LMS navigation">
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
            <a href="lms_oversight" class="btn btn-sm text-white fw-bold active text-nowrap" style="background:rgba(255,255,255,.2);border-radius:6px;opacity:1;" aria-current="page">
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
            <a href="lms_reports" class="btn btn-sm text-white text-nowrap" style="opacity:.85;" onmouseenter="this.style.opacity=1" onmouseleave="this.style.opacity='.85'">
                <i class="bi bi-file-earmark-bar-graph-fill me-1"></i>LMS Reports
            </a>
        </div>
    </div>
</nav>

<!-- ── BREADCRUMB ──────────────────────────────────────────────────────────── -->
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb mb-0" style="font-size:.82rem;">
        <li class="breadcrumb-item"><a href="dashboard" class="text-decoration-none text-brand-primary"><i class="bi bi-shield-shaded me-1"></i>Administrator</a></li>
        <li class="breadcrumb-item active" aria-current="page">LMS Oversight & Governance Console</li>
    </ol>
</nav>

<!-- ── FLASH NOTIFICATIONS ────────────────────────────────────────────────── -->
<?php if (isset($_SESSION['flash_success'])): ?>
    <div class="alert alert-success alert-dismissible fade show shadow-sm mb-4" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($_SESSION['flash_success']); unset($_SESSION['flash_success']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>
<?php if (isset($_SESSION['flash_error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show shadow-sm mb-4" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- ── PAGE HEADER ─────────────────────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
    <div>
        <div class="page-eyebrow text-muted small fw-semibold text-uppercase" style="letter-spacing:.05em;">
            <i class="bi bi-shield-lock me-1"></i>Full Administrative Authority
        </div>
        <h1 class="h3 fw-bold text-navy-alt mb-1">
            <i class="bi bi-eye me-2 text-brand-primary"></i>LMS Oversight & Governance Console
        </h1>
        <p class="text-muted small mb-0">
            System-wide inspection of all subject coursework, corrective teacher reassignment, and diagnostic student access overrides.
        </p>
    </div>

    <!-- View Switcher Pills -->
    <div class="btn-group shadow-sm p-1 bg-white rounded-3 border">
        <a href="?view=content&term_id=<?php echo (int)$filterTermId; ?>" class="btn btn-sm <?php echo $activeView === 'content' ? 'btn-brand-primary' : 'btn-light text-dark'; ?> fw-semibold">
            <i class="bi bi-collection-play-fill me-1"></i> Course & Content Oversight
        </a>
        <a href="?view=students&term_id=<?php echo (int)$filterTermId; ?>" class="btn btn-sm <?php echo $activeView === 'students' ? 'btn-brand-primary' : 'btn-light text-dark'; ?> fw-semibold">
            <i class="bi bi-person-check-fill me-1"></i> Student Access Diagnostics & Overrides
        </a>
    </div>
</div>

<!-- ── FILTER BAR ─────────────────────────────────────────────────────────── -->
<div class="card shadow-sm border-0 rounded-4 p-3 bg-white mb-4">
    <form method="GET" action="" class="row g-2 align-items-center">
        <input type="hidden" name="view" value="<?php echo htmlspecialchars($activeView); ?>">

        <div class="col-12 col-md-4 col-lg-3">
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

        <div class="col-12 col-md-3 col-lg-3">
            <label class="form-label small text-muted mb-1 fw-semibold">Program</label>
            <select name="program" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All Programs</option>
                <option value="BSMT" <?php echo $filterProgram === 'BSMT' ? 'selected' : ''; ?>>BSMT (Marine Transportation)</option>
                <option value="BSMarE" <?php echo $filterProgram === 'BSMarE' ? 'selected' : ''; ?>>BSMarE (Marine Engineering)</option>
            </select>
        </div>

        <div class="col-12 col-md-5 col-lg-4">
            <label class="form-label small text-muted mb-1 fw-semibold">Search Query</label>
            <div class="input-group input-group-sm">
                <input type="text" name="search" class="form-control" placeholder="<?php echo $activeView === 'content' ? 'Subject code, section, or teacher...' : 'Student name, email, or username...'; ?>" value="<?php echo htmlspecialchars($searchQuery); ?>">
                <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
            </div>
        </div>

        <div class="col-12 col-lg-2 text-lg-end mt-lg-4">
            <a href="?view=<?php echo htmlspecialchars($activeView); ?>" class="btn btn-sm btn-outline-secondary w-100">
                <i class="bi bi-arrow-counterclockwise me-1"></i> Reset
            </a>
        </div>
    </form>
</div>

<!-- =========================================================================
     VIEW 1: COURSE & CONTENT OVERSIGHT
     ========================================================================= -->
<?php if ($activeView === 'content'): ?>
    <!-- KPI Summary Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="card shadow-sm border-0 rounded-3 p-3 bg-white h-100">
                <div class="text-muted small fw-semibold">Active Offerings</div>
                <div class="h3 fw-bold mb-0 text-navy"><?php echo (int)$contentStats['total_offerings']; ?></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card shadow-sm border-0 rounded-3 p-3 bg-white h-100">
                <div class="text-muted small fw-semibold">Lessons & Modules</div>
                <div class="h3 fw-bold mb-0 text-primary"><?php echo (int)$contentStats['total_modules']; ?></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card shadow-sm border-0 rounded-3 p-3 bg-white h-100">
                <div class="text-muted small fw-semibold">Learning Materials</div>
                <div class="h3 fw-bold mb-0 text-info-emphasis"><?php echo (int)$contentStats['total_materials']; ?></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card shadow-sm border-0 rounded-3 p-3 bg-white h-100">
                <div class="text-muted small fw-semibold">Assessments (Quizzes / HW)</div>
                <div class="h3 fw-bold mb-0 text-success"><?php echo (int)($contentStats['total_assignments'] + $contentStats['total_quizzes']); ?></div>
            </div>
        </div>
    </div>

    <!-- Offerings & Content Inspection Table -->
    <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-5">
        <div class="card-header bg-white py-3 px-4 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="m-0 fw-bold text-navy-alt">
                <i class="bi bi-collection-play me-2 text-brand-primary"></i>System-wide Subject Coursework & Faculty Assignment
            </h5>
            <span class="badge bg-light text-dark border"><?php echo count($subjectOfferings); ?> Class Sections</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size: 0.88rem;">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4">Course / Subject</th>
                            <th>Section & Term</th>
                            <th>Assigned Instructor</th>
                            <th>LMS Content Streams</th>
                            <th>Enrolled Cadets</th>
                            <th class="pe-4 text-end" style="width: 220px;">Oversight Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($subjectOfferings)): ?>
                        <?php foreach ($subjectOfferings as $offering): 
                            $hasInstructor = !empty($offering['instructor_id']);
                            $teacherDisplay = $hasInstructor 
                                ? (trim($offering['teacher_full_name']) ?: $offering['teacher_username'])
                                : 'Unassigned';
                        ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="fw-bold text-navy"><?php echo htmlspecialchars($offering['subject_code']); ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars($offering['subject_name']); ?></div>
                                    <span class="badge bg-light text-muted border mt-1" style="font-size: 0.7rem;"><?php echo (float)$offering['units']; ?> units</span>
                                </td>
                                <td>
                                    <div class="fw-semibold text-dark"><?php echo htmlspecialchars($offering['section_name']); ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars($offering['program']); ?> • <?php echo htmlspecialchars($offering['year_level']); ?></div>
                                    <div class="small text-muted font-monospace mt-0.5" style="font-size: 0.72rem;">AY <?php echo htmlspecialchars($offering['school_year'] . ' ' . $offering['semester']); ?></div>
                                </td>
                                <td>
                                    <?php if ($hasInstructor): ?>
                                        <div class="fw-bold text-dark"><i class="bi bi-person-badge text-brand-primary me-1"></i><?php echo htmlspecialchars($teacherDisplay); ?></div>
                                        <div class="small text-muted">@<?php echo htmlspecialchars($offering['teacher_username']); ?></div>
                                    <?php else: ?>
                                        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">
                                            <i class="bi bi-exclamation-triangle-fill me-1"></i>Unassigned
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex flex-wrap gap-1.5 small">
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle" title="Modules">
                                            <i class="bi bi-journal-bookmark me-1"></i><?php echo (int)$offering['module_count']; ?> Modules
                                        </span>
                                        <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle" title="Materials">
                                            <i class="bi bi-file-earmark-arrow-up me-1"></i><?php echo (int)$offering['material_count']; ?> Files
                                        </span>
                                        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle" title="Assignments">
                                            <i class="bi bi-pencil-square me-1"></i><?php echo (int)$offering['assignment_count']; ?> HW
                                        </span>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle" title="Quizzes">
                                            <i class="bi bi-patch-question me-1"></i><?php echo (int)$offering['quiz_count']; ?> Quiz
                                        </span>
                                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle" title="Announcements">
                                            <i class="bi bi-megaphone me-1"></i><?php echo (int)$offering['announcement_count']; ?> News
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border px-2.5 py-1">
                                        <i class="bi bi-people-fill text-muted me-1"></i><?php echo (int)$offering['enrolled_students_count']; ?> Cadets
                                    </span>
                                </td>
                                <td class="pe-4 text-end">
                                    <div class="btn-group btn-group-sm">
                                        <!-- Inspect Content Button -->
                                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="inspectSubjectContent(<?php echo (int)$offering['section_subject_id']; ?>)" title="Inspect All Subject Content">
                                            <i class="bi bi-search me-1"></i> Inspect
                                        </button>
                                        <!-- Reassign / Remove Teacher Trigger -->
                                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#reassignTeacherModal<?php echo (int)$offering['section_subject_id']; ?>" title="Reassign or Remove Faculty">
                                            <i class="bi bi-person-gear"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center py-5 text-muted">
                                <i class="bi bi-folder-x fs-2 d-block mb-2"></i>
                                No subject offerings match the selected criteria.
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ── REASSIGN TEACHER MODALS ────────────────────────────────────────── -->
    <?php foreach ($subjectOfferings as $offering): ?>
        <div class="modal fade" id="reassignTeacherModal<?php echo (int)$offering['section_subject_id']; ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow">
                    <form action="../actions/lms_admin_actions" method="POST" class="oversight-reassign-form"
                          data-subject="<?php echo htmlspecialchars($offering['subject_code'] . ' — ' . $offering['subject_name'], ENT_QUOTES); ?>"
                          data-section="<?php echo htmlspecialchars($offering['section_name'], ENT_QUOTES); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                        <input type="hidden" name="action" value="reassign_teacher_oversight">
                        <input type="hidden" name="section_subject_id" value="<?php echo (int)$offering['section_subject_id']; ?>">
                        <input type="hidden" name="redirect_to" value="../admin/lms_oversight?view=content">

                        <div class="modal-header border-bottom py-3" style="background: var(--surface-tint,#f4f8f8);">
                            <h5 class="modal-title fw-bold text-navy">
                                <i class="bi bi-person-gear me-2 text-brand-primary"></i>Faculty Reassignment / Removal
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body p-4">
                            <div class="p-3 bg-light rounded-3 mb-3 border small">
                                <div><strong>Subject:</strong> <?php echo htmlspecialchars($offering['subject_code'] . ' — ' . $offering['subject_name']); ?></div>
                                <div><strong>Section:</strong> <?php echo htmlspecialchars($offering['section_name']); ?> (<?php echo htmlspecialchars($offering['program']); ?>)</div>
                                <div><strong>Current Instructor:</strong> <?php echo htmlspecialchars($offering['instructor_id'] ? (trim($offering['teacher_full_name']) ?: $offering['teacher_username']) : 'Unassigned'); ?></div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-bold text-navy">Select New Instructor <span class="text-danger">*</span></label>
                                <select name="teacher_id" class="form-select" required>
                                    <option value="0" class="text-danger">-- Remove Instructor (Set as Unassigned) --</option>
                                    <?php foreach ($allTeachers as $t): ?>
                                        <option value="<?php echo (int)$t['id']; ?>" <?php echo ((int)$offering['instructor_id'] === (int)$t['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars(trim(($t['first_name'] ?? '') . ' ' . ($t['last_name'] ?? '')) ?: $t['username']); ?> (@<?php echo htmlspecialchars($t['username']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-bold text-navy">Administrative Reason for Change <span class="text-danger">*</span></label>
                                <textarea name="reason" rows="2" class="form-control" placeholder="e.g. Instructor leave of absence, emergency class load adjustment, departmental reassignment..." required></textarea>
                                <div class="form-text small">This rationale is logged into the system audit trail for institutional accountability.</div>
                            </div>
                        </div>
                        <div class="modal-footer border-top py-2 px-4 bg-light">
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-brand-primary btn-sm px-3 fw-semibold oversight-reassign-submit-btn">
                                <i class="bi bi-check2-circle me-1"></i> Apply Assignment Change
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

<!-- =========================================================================
     VIEW 2: STUDENT LMS ACCESS DIAGNOSTICS & OVERRIDES
     ========================================================================= -->
<?php else: ?>
    <!-- Diagnostic KPI Summary Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="card shadow-sm border-0 rounded-3 p-3 bg-white h-100">
                <div class="text-muted small fw-semibold">Total Evaluated Cadets</div>
                <div class="h3 fw-bold mb-0 text-navy"><?php echo (int)$accessStats['total_students']; ?></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card shadow-sm border-0 rounded-3 p-3 bg-white h-100">
                <div class="text-muted small fw-semibold">Active LMS Access</div>
                <div class="h3 fw-bold mb-0 text-success"><?php echo (int)$accessStats['access_active']; ?></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card shadow-sm border-0 rounded-3 p-3 bg-white h-100">
                <div class="text-muted small fw-semibold">Pending / Restricted</div>
                <div class="h3 fw-bold mb-0 text-warning-emphasis"><?php echo (int)$accessStats['access_pending']; ?></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card shadow-sm border-0 rounded-3 p-3 bg-white h-100">
                <div class="text-muted small fw-semibold">Sync Discrepancies</div>
                <div class="h3 fw-bold mb-0 text-danger"><?php echo (int)$accessStats['sync_issues']; ?></div>
            </div>
        </div>
    </div>

    <!-- Student Diagnostic Table -->
    <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-5">
        <div class="card-header bg-white py-3 px-4 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="m-0 fw-bold text-navy-alt">
                <i class="bi bi-person-lines-fill me-2 text-brand-primary"></i>Enrollment-to-LMS Access Verification & Override
            </h5>
            <span class="badge bg-light text-dark border"><?php echo count($studentList); ?> Cadets Listed</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size: 0.88rem;">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4">Cadet Profile</th>
                            <th>Downpayment Gate</th>
                            <th>Section Confirmation</th>
                            <th>Account Status</th>
                            <th>Computed LMS Access</th>
                            <th class="pe-4 text-end" style="width: 180px;">Admin Override</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($studentList)): ?>
                        <?php foreach ($studentList as $s): 
                            $fullName = trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? ''));
                        ?>
                            <tr class="<?php echo $s['diag_sync_issue'] ? 'table-warning' : ''; ?>">
                                <td class="ps-4">
                                    <div class="fw-bold text-navy"><?php echo htmlspecialchars($fullName ?: $s['username']); ?></div>
                                    <div class="small text-muted">@<?php echo htmlspecialchars($s['username']); ?> &bull; <?php echo htmlspecialchars($s['email']); ?></div>
                                    <span class="badge bg-light text-dark border mt-0.5"><?php echo htmlspecialchars($s['program_code'] ?: $s['program_applying_for']); ?> (<?php echo htmlspecialchars($s['year_level']); ?>)</span>
                                </td>
                                <td>
                                    <?php if ($s['diag_has_paid']): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle">
                                            <i class="bi bi-check-circle-fill me-1"></i>Downpayment Met
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle">
                                            <i class="bi bi-x-circle-fill me-1"></i>Unmet Downpayment
                                        </span>
                                    <?php endif; ?>
                                    <div class="small font-monospace text-muted mt-0.5" style="font-size: 0.72rem;">
                                        Paid: ₱<?php echo number_format((float)$s['validated_paid'], 2); ?> / ₱<?php echo number_format((float)$s['assessment_total'], 2); ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($s['diag_confirmed_sec']): ?>
                                        <div class="fw-bold text-dark"><i class="bi bi-check2 text-success me-1"></i><?php echo htmlspecialchars($s['section_name'] ?: 'Enrolled'); ?></div>
                                        <div class="small text-muted" style="font-size: 0.72rem;">Status: Enrolled</div>
                                    <?php else: ?>
                                        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">
                                            <i class="bi bi-clock-history me-1"></i>No Active Section
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="small">Role: <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($s['user_role']); ?></span></div>
                                    <div class="small text-muted mt-0.5">DB Status: <strong><?php echo htmlspecialchars($s['student_enrollment_status']); ?></strong></div>
                                </td>
                                <td>
                                    <?php if ($s['diag_meets_access']): ?>
                                        <span class="badge bg-success px-2.5 py-1 text-white fw-bold">
                                            <i class="bi bi-shield-check me-1"></i>Active LMS Access
                                        </span>
                                    <?php elseif ($s['diag_sync_issue']): ?>
                                        <span class="badge bg-danger px-2.5 py-1 text-white fw-bold" title="Payment and Section confirmed, but status record out of sync!">
                                            <i class="bi bi-exclamation-triangle-fill me-1"></i>Sync Discrepancy
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2.5 py-1 fw-semibold">
                                            <i class="bi bi-lock-fill me-1"></i>Access Gated
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="pe-4 text-end">
                                    <button type="button" class="btn btn-outline-danger btn-sm fw-semibold" data-bs-toggle="modal" data-bs-target="#overrideModal<?php echo (int)$s['student_id']; ?>">
                                        <i class="bi bi-wrench-adjustable me-1"></i> Correct / Sync
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center py-5 text-muted">
                                <i class="bi bi-person-x fs-2 d-block mb-2"></i>
                                No student records found.
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ── STUDENT OVERRIDE MODALS ────────────────────────────────────────── -->
    <?php foreach ($studentList as $s): 
        $fullName = trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? ''));
    ?>
        <div class="modal fade" id="overrideModal<?php echo (int)$s['student_id']; ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow">
                    <form action="../actions/lms_admin_actions" method="POST" class="oversight-override-form"
                          data-cadet="<?php echo htmlspecialchars($fullName ?: $s['username'], ENT_QUOTES); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                        <input type="hidden" name="action" value="override_student_lms_access">
                        <input type="hidden" name="student_id" value="<?php echo (int)$s['student_id']; ?>">
                        <input type="hidden" name="redirect_to" value="../admin/lms_oversight?view=students">

                        <div class="modal-header border-bottom py-3" style="background: var(--surface-tint,#f4f8f8);">
                            <h5 class="modal-title fw-bold text-navy">
                                <i class="bi bi-wrench-adjustable me-2 text-danger"></i>LMS Access Override & Diagnostic Tool
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body p-4">
                            <div class="p-3 bg-light rounded-3 mb-3 border small">
                                <div><strong>Cadet:</strong> <?php echo htmlspecialchars($fullName ?: $s['username']); ?> (@<?php echo htmlspecialchars($s['username']); ?>)</div>
                                <div><strong>Current DB Status:</strong> <code><?php echo htmlspecialchars($s['student_enrollment_status']); ?></code> &bull; Role: <code><?php echo htmlspecialchars($s['user_role']); ?></code></div>
                                <div><strong>Section Enrollment:</strong> <?php echo htmlspecialchars($s['section_name'] ?: 'None'); ?> (Status: <?php echo htmlspecialchars($s['enrollment_record_status'] ?: 'None'); ?>)</div>
                                <div><strong>Downpayment Met:</strong> <?php echo $s['diag_has_paid'] ? '<span class="text-success fw-bold">YES</span>' : '<span class="text-danger fw-bold">NO</span>'; ?></div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-bold text-navy">Select Corrective Action <span class="text-danger">*</span></label>
                                <select name="target_action" class="form-select" required>
                                    <option value="sync_auto" selected>Auto-Synchronize (Sync enrollment_status to 'enrolled' from confirmed section)</option>
                                    <option value="force_enrolled">Manual Force Grant (Force active 'enrolled' status & upgrade role)</option>
                                    <option value="revert_pending">Revert Access (Revoke active LMS access and set status to 'pending')</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-bold text-navy">Administrative Justification <span class="text-danger">*</span></label>
                                <textarea name="reason" rows="2" class="form-control" placeholder="e.g. Correcting stuck cashier-to-registrar enrollment sync, manual emergency grant for verified payment..." required></textarea>
                                <div class="form-text small">Required for audit tracking.</div>
                            </div>
                        </div>
                        <div class="modal-footer border-top py-2 px-4 bg-light">
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-danger btn-sm px-3 fw-semibold oversight-override-submit-btn">
                                <i class="bi bi-shield-check me-1"></i> Execute Access Override
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- =========================================================================
     MODAL: INSPECT CONTENT DETAILS (AJAX DRIVEN)
     ========================================================================= -->
<div class="modal fade" id="inspectContentModal" tabindex="-1" aria-labelledby="inspectModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom py-3" style="background: var(--surface-tint,#f4f8f8);">
                <div>
                    <h5 class="modal-title fw-bold text-navy" id="inspectModalLabel">
                        <i class="bi bi-search me-2 text-brand-primary"></i>Subject Content Inspection
                    </h5>
                    <div class="small text-muted" id="inspectModalSub">Loading subject coursework...</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4" id="inspectModalBody">
                <div class="text-center py-5">
                    <div class="spinner-border text-brand-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-top py-2 px-4 bg-light">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close Inspector</button>
            </div>
        </div>
    </div>
</div>

<script>
// ── Oversight Console: SweetAlert2 Confirmation Safeguards ────────────────
//
// Per TASK 5.1 requirements: all modifying oversight actions must prompt a
// second deliberate confirmation before executing, given their impact on
// live student/instructor data. The confirmation names the specific record
// and action so the admin cannot accidentally submit the wrong override.

document.addEventListener('DOMContentLoaded', function () {

    // ── Student LMS Access Override ─────────────────────────────────────────
    document.querySelectorAll('.oversight-override-submit-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const form        = btn.closest('form.oversight-override-form');
            const cadetName   = form.dataset.cadet || 'this cadet';
            const actionSel   = form.querySelector('[name="target_action"]');
            const reason      = (form.querySelector('[name="reason"]')?.value || '').trim();

            if (!actionSel || !reason) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Incomplete form',
                    text: 'Please select a corrective action and enter an administrative justification before executing.'
                });
                return;
            }

            const actionLabels = {
                sync_auto:      'Auto-Synchronize enrollment status from confirmed section',
                force_enrolled: 'Manual Force Grant — override to active enrolled status',
                revert_pending: 'Revert Access — revoke active LMS access back to pending'
            };
            const actionLabel = actionLabels[actionSel.value] || actionSel.value;

            Swal.fire({
                icon: 'warning',
                title: 'Confirm Administrative Override',
                html: `<p class="mb-2">You are about to apply a <strong>live data change</strong> to cadet:</p>
                       <p class="fw-bold text-danger mb-2">${cadetName}</p>
                       <p class="mb-2"><strong>Action:</strong> ${actionLabel}</p>
                       <p class="text-muted small mb-0">This action is irreversible and will be permanently recorded in the system audit log.</p>`,
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: '<i class="bi bi-shield-exclamation me-1"></i> Execute Override',
                cancelButtonText: 'Cancel — Go Back',
                reverseButtons: true,
                focusCancel: true
            }).then(function (result) {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
        });
    });

    // ── Teacher Reassignment / Removal ──────────────────────────────────────
    document.querySelectorAll('.oversight-reassign-submit-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const form      = btn.closest('form.oversight-reassign-form');
            const subject   = form.dataset.subject  || 'this subject';
            const section   = form.dataset.section  || 'this section';
            const teacherSel = form.querySelector('[name="teacher_id"]');
            const reason    = (form.querySelector('[name="reason"]')?.value || '').trim();

            if (!reason) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Reason required',
                    text: 'An administrative reason is required before applying a faculty assignment change.'
                });
                return;
            }

            const selectedOption = teacherSel?.options[teacherSel.selectedIndex];
            const isRemoval      = !teacherSel || parseInt(teacherSel.value) === 0;
            const teacherLabel   = isRemoval
                ? '<span class="text-danger">Remove instructor (set Unassigned)</span>'
                : `Assign: <strong>${selectedOption?.text || 'Selected teacher'}</strong>`;

            Swal.fire({
                icon: isRemoval ? 'warning' : 'question',
                title: isRemoval ? 'Confirm Instructor Removal' : 'Confirm Faculty Reassignment',
                html: `<p class="mb-2"><strong>Subject:</strong> ${subject}</p>
                       <p class="mb-2"><strong>Section:</strong> ${section}</p>
                       <p class="mb-2"><strong>Action:</strong> ${teacherLabel}</p>
                       <p class="text-muted small mb-0">This change will take effect immediately and is recorded in the audit log.</p>`,
                showCancelButton: true,
                confirmButtonColor: isRemoval ? '#dc3545' : '#0b5ed7',
                cancelButtonColor: '#6c757d',
                confirmButtonText: '<i class="bi bi-check2-circle me-1"></i> Confirm Change',
                cancelButtonText: 'Cancel',
                reverseButtons: true,
                focusCancel: true
            }).then(function (result) {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
        });
    });

});
</script>

<script>
function inspectSubjectContent(sectionSubjectId) {
    const modalEl = document.getElementById('inspectContentModal');
    const modal = new bootstrap.Modal(modalEl);
    const bodyEl = document.getElementById('inspectModalBody');
    const subEl = document.getElementById('inspectModalSub');

    subEl.textContent = 'Loading coursework...';
    bodyEl.innerHTML = `
        <div class="text-center py-5">
            <div class="spinner-border text-brand-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="text-muted small mt-2">Retrieving all published lessons, materials, quizzes, and assignments...</p>
        </div>
    `;
    modal.show();

    fetch('../actions/lms_admin_actions', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
            csrf_token: '<?php echo htmlspecialchars(ensureCsrfToken()); ?>',
            action: 'get_subject_content_summary',
            section_subject_id: sectionSubjectId,
            response_type: 'json'
        })
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) {
            bodyEl.innerHTML = `<div class="alert alert-danger">${data.message || 'Failed to load content.'}</div>`;
            return;
        }

        const sub = data.subject || {};
        subEl.textContent = `${sub.subject_code} — ${sub.subject_name} (${sub.section_name}) • Instructor: ${sub.instructor_name || 'Unassigned'}`;

        const modules = data.modules || [];
        const materials = data.materials || [];
        const assignments = data.assignments || [];
        const quizzes = data.quizzes || [];
        const announcements = data.announcements || [];

        bodyEl.innerHTML = `
            <!-- Nav tabs -->
            <ul class="nav nav-pills mb-3 border-bottom pb-2" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active btn-sm" id="modules-tab" data-bs-toggle="tab" data-bs-target="#modules-pane" type="button" role="tab">
                        <i class="bi bi-journal-bookmark me-1"></i>Modules (${modules.length})
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link btn-sm" id="materials-tab" data-bs-toggle="tab" data-bs-target="#materials-pane" type="button" role="tab">
                        <i class="bi bi-file-earmark-arrow-up me-1"></i>Materials (${materials.length})
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link btn-sm" id="assign-tab" data-bs-toggle="tab" data-bs-target="#assign-pane" type="button" role="tab">
                        <i class="bi bi-pencil-square me-1"></i>Assignments (${assignments.length})
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link btn-sm" id="quiz-tab" data-bs-toggle="tab" data-bs-target="#quiz-pane" type="button" role="tab">
                        <i class="bi bi-patch-question me-1"></i>Quizzes (${quizzes.length})
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link btn-sm" id="ann-tab" data-bs-toggle="tab" data-bs-target="#ann-pane" type="button" role="tab">
                        <i class="bi bi-megaphone me-1"></i>Announcements (${announcements.length})
                    </button>
                </li>
            </ul>

            <!-- Tab content -->
            <div class="tab-content pt-2">
                <!-- MODULES -->
                <div class="tab-pane fade show active" id="modules-pane" role="tabpanel">
                    ${modules.length === 0 ? '<p class="text-muted small">No learning modules created yet.</p>' : `
                        <div class="list-group list-group-flush">
                            ${modules.map(m => `
                                <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-0">
                                    <div>
                                        <div class="fw-bold text-dark">${m.title}</div>
                                        <div class="small text-muted">${m.description || 'No description'}</div>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge ${parseInt(m.is_published) === 1 ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary'} border">
                                            ${parseInt(m.is_published) === 1 ? 'Published' : 'Draft'}
                                        </span>
                                        <span class="badge bg-light text-dark border ms-1">${m.lesson_count} Lessons</span>
                                    </div>
                                </div>
                            `).join('')}
                        </div>
                    `}
                </div>

                <!-- MATERIALS -->
                <div class="tab-pane fade" id="materials-pane" role="tabpanel">
                    ${materials.length === 0 ? '<p class="text-muted small">No learning materials uploaded.</p>' : `
                        <div class="list-group list-group-flush">
                            ${materials.map(mat => `
                                <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-0">
                                    <div>
                                        <div class="fw-bold text-dark">${mat.title}</div>
                                        <div class="small text-muted font-monospace">${mat.file_name} &bull; ${(parseInt(mat.file_size || 0) / (1024*1024)).toFixed(2)} MB &bull; ${mat.material_type}</div>
                                    </div>
                                    <span class="badge ${parseInt(mat.is_available) === 1 ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary'} border">
                                        ${parseInt(mat.is_available) === 1 ? 'Available' : 'Hidden'}
                                    </span>
                                </div>
                            `).join('')}
                        </div>
                    `}
                </div>

                <!-- ASSIGNMENTS -->
                <div class="tab-pane fade" id="assign-pane" role="tabpanel">
                    ${assignments.length === 0 ? '<p class="text-muted small">No assignments created.</p>' : `
                        <div class="list-group list-group-flush">
                            ${assignments.map(a => `
                                <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-0">
                                    <div>
                                        <div class="fw-bold text-dark">${a.title}</div>
                                        <div class="small text-muted">Due: ${a.due_at || 'No due date'} &bull; Max Score: ${a.max_score} pts</div>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge bg-info-subtle text-info-emphasis border">${a.submission_count} Submissions</span>
                                    </div>
                                </div>
                            `).join('')}
                        </div>
                    `}
                </div>

                <!-- QUIZZES -->
                <div class="tab-pane fade" id="quiz-pane" role="tabpanel">
                    ${quizzes.length === 0 ? '<p class="text-muted small">No quizzes configured.</p>' : `
                        <div class="list-group list-group-flush">
                            ${quizzes.map(q => `
                                <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-0">
                                    <div>
                                        <div class="fw-bold text-dark">${q.title}</div>
                                        <div class="small text-muted">Time limit: ${q.time_limit_minutes} min &bull; Attempts: ${q.allowed_attempts} &bull; Passing: ${q.passing_score}%</div>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge bg-light text-dark border">${q.question_count} Questions</span>
                                        <span class="badge bg-success-subtle text-success border ms-1">${q.attempt_students_count} Cadets Taken</span>
                                    </div>
                                </div>
                            `).join('')}
                        </div>
                    `}
                </div>

                <!-- ANNOUNCEMENTS -->
                <div class="tab-pane fade" id="ann-pane" role="tabpanel">
                    ${announcements.length === 0 ? '<p class="text-muted small">No announcements posted.</p>' : `
                        <div class="list-group list-group-flush">
                            ${announcements.map(an => `
                                <div class="list-group-item py-2 px-0">
                                    <div class="d-flex justify-content-between">
                                        <span class="fw-bold text-dark">${an.title}</span>
                                        <span class="small text-muted">${an.created_at}</span>
                                    </div>
                                    <div class="small text-muted mt-1">${an.content}</div>
                                </div>
                            `).join('')}
                        </div>
                    `}
                </div>
            </div>
        `;
    })
    .catch(err => {
        bodyEl.innerHTML = `<div class="alert alert-danger">Error: ${err.message}</div>`;
    });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
