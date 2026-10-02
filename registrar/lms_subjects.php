<?php
/**
 * Registrar LMS Subject & Section Offerings View (TASK 2.1)
 *
 * Provides the Registrar with a strictly READ-ONLY view of subjects/courses
 * offered within the LMS, their assigned teachers, and enrolled student counts.
 *
 * Requirements:
 *   1. Role Gate: requireLmsRegistrarAccess() (only 'registrar', read-only GET requests).
 *   2. View list of subjects/courses offered, with codes and sections.
 *   3. View assigned teacher for each subject (with explicit vs lead indicator).
 *   4. View enrolled student count per subject/section.
 *   5. Purely READ-ONLY — zero mutation capabilities (no create, edit, reassign, or delete).
 *   6. Single source of truth: pulls live data from section_subjects, sections, subjects,
 *      users, and enrollments — matching the Teacher-side LMS logic.
 */

require_once __DIR__ . '/../includes/lms_access.php';
$registrar = requireLmsRegistrarAccess();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/academic_terms.php';
require_once __DIR__ . '/../includes/assessments.php';

/** @var PDO $pdo */
$activeTerm = getActiveAcademicTerm($pdo);

// ── Capture and sanitize filter values ─────────────────────────────────────────
$filterTermId     = isset($_GET['academic_term_id']) ? (int)$_GET['academic_term_id'] : ($activeTerm ? (int)$activeTerm['id'] : 0);
$filterProgram    = isset($_GET['program']) ? trim((string)$_GET['program']) : '';
$filterYearLevel  = isset($_GET['year_level']) ? trim((string)$_GET['year_level']) : '';
$filterTeacherId  = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0;
$filterAssignment = isset($_GET['assignment']) ? trim((string)$_GET['assignment']) : '';
$searchQuery      = isset($_GET['search']) ? trim((string)$_GET['search']) : '';

// ── Dropdown Options ───────────────────────────────────────────────────────────
$termsStmt = $pdo->query("SELECT id, school_year, semester, is_active FROM academic_terms ORDER BY starts_on DESC, id DESC");
$allTerms = $termsStmt->fetchAll(PDO::FETCH_ASSOC);

$teachersStmt = $pdo->query("
    SELECT id, first_name, last_name, username, email 
    FROM users 
    WHERE role = 'teacher' AND is_active = 1 
    ORDER BY last_name ASC, first_name ASC
");
$allTeachers = $teachersStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Main Query: Live LMS Subject & Section Offerings ───────────────────────────
$sql = "
    SELECT ss.id AS section_subject_id,
           ss.section_id,
           ss.subject_id,
           ss.instructor_id,
           sub.subject_code,
           sub.subject_name,
           sub.units,
           sub.subject_type,
           sub.year_level AS subject_year_level,
           sec.section_name,
           sec.year_level AS section_year_level,
           sec.program,
           sec.status AS section_status,
           sec.capacity,
           COALESCE(ss.day_of_week, sec.day_of_week) AS day_of_week,
           COALESCE(ss.start_time, sec.start_time) AS start_time,
           COALESCE(ss.end_time, sec.end_time) AS end_time,
           COALESCE(ss.room, sec.room) AS room,
           sec.academic_term_id,
           at.school_year,
           at.semester,
           at.is_active AS term_is_active,
           u_inst.id AS teacher_id,
           u_inst.first_name AS teacher_first_name,
           u_inst.last_name AS teacher_last_name,
           u_inst.username AS teacher_username,
           u_inst.email AS teacher_email,
           COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u_inst.first_name, u_inst.last_name)), ''), u_inst.username, 'Unassigned / TBA') AS teacher_name,
           (CASE 
               WHEN ss.instructor_id IS NOT NULL AND ss.instructor_id > 0 THEN 'Subject Instructor'
               WHEN sec.teacher_id IS NOT NULL AND sec.teacher_id > 0 THEN 'Section Lead'
               ELSE 'Unassigned'
            END) AS teacher_assignment_type,
           (SELECT COUNT(*) 
            FROM enrollments e 
            WHERE e.section_id = sec.id 
              AND e.status = 'enrolled') AS enrolled_students_count,
           (SELECT COUNT(*) FROM lms_modules m JOIN lms_lessons les ON les.module_id = m.id WHERE m.section_subject_id = ss.id) AS lesson_count,
           (SELECT COUNT(*) FROM lms_materials mat WHERE mat.section_subject_id = ss.id) AS material_count,
           (SELECT COUNT(*) FROM lms_assignments asn WHERE asn.section_subject_id = ss.id) AS assignment_count,
           (SELECT COUNT(*) FROM lms_quizzes qz WHERE qz.section_subject_id = ss.id) AS quiz_count
    FROM section_subjects ss
    JOIN sections sec ON sec.id = ss.section_id
    JOIN subjects sub ON sub.id = ss.subject_id
    LEFT JOIN users u_inst ON u_inst.id = COALESCE(ss.instructor_id, sec.teacher_id)
    LEFT JOIN academic_terms at ON at.id = sec.academic_term_id
    WHERE 1=1
";
$params = [];

if ($filterTermId > 0) {
    $sql .= " AND sec.academic_term_id = :term_id";
    $params['term_id'] = $filterTermId;
}

if ($filterProgram !== '') {
    $sql .= " AND (sec.program = :program OR (sec.program IS NULL AND sub.subject_code LIKE :prog_prefix))";
    $params['program'] = $filterProgram;
    $params['prog_prefix'] = ($filterProgram === 'BSMarE' ? 'BSMarE%' : 'BSMT%');
}

if ($filterYearLevel !== '') {
    $sql .= " AND (sec.year_level = :year_level OR sub.year_level = :year_level_sub)";
    $params['year_level'] = $filterYearLevel;
    $params['year_level_sub'] = $filterYearLevel;
}

if ($filterTeacherId > 0) {
    $sql .= " AND (COALESCE(ss.instructor_id, sec.teacher_id) = :filter_teacher_id)";
    $params['filter_teacher_id'] = $filterTeacherId;
}

if ($filterAssignment === 'assigned') {
    $sql .= " AND (ss.instructor_id IS NOT NULL OR sec.teacher_id IS NOT NULL)";
} elseif ($filterAssignment === 'unassigned') {
    $sql .= " AND (ss.instructor_id IS NULL AND sec.teacher_id IS NULL)";
}

if ($searchQuery !== '') {
    $sql .= " AND (
        sub.subject_code LIKE :search
        OR sub.subject_name LIKE :search
        OR sec.section_name LIKE :search
        OR u_inst.first_name LIKE :search
        OR u_inst.last_name LIKE :search
        OR u_inst.username LIKE :search
    )";
    $params['search'] = '%' . $searchQuery . '%';
}

$sql .= " ORDER BY sec.section_name ASC, sub.subject_code ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rawRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Collect Section IDs for Enrolled Cadets Roster ─────────────────────────────
$sectionIds = array_unique(array_filter(array_column($rawRows, 'section_id')));
$studentsBySection = [];

if (!empty($sectionIds)) {
    $inSec = implode(',', array_fill(0, count($sectionIds), '?'));
    $stdStmt = $pdo->prepare("
        SELECT e.section_id,
               st.id AS student_id,
               COALESCE(NULLIF(st.first_name, ''), u.first_name, '') AS first_name,
               st.middle_name,
               COALESCE(NULLIF(st.last_name, ''), u.last_name, '') AS last_name,
               st.suffix,
               st.program_code,
               st.year_level,
               st.enrollment_status AS student_enrollment_status,
               u.id AS user_id,
               u.username,
               u.email,
               u.role AS user_role,
               e.status AS reg_status,
               e.created_at AS enrolled_at,
               COALESCE(t.minimum_downpayment, 0) AS minimum_downpayment,
               COALESCE(t.downpayment_percentage, 0) AS downpayment_percentage,
               COALESCE(a.total_amount, 0) AS assessment_total,
               COALESCE(a.id, 0) AS assessment_id
        FROM enrollments e
        JOIN students st ON st.id = e.student_id
        JOIN users u ON u.id = st.user_id
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
        WHERE e.section_id IN ($inSec)
          AND e.status = 'enrolled'
        ORDER BY st.last_name ASC, st.first_name ASC
    ");
    $stdStmt->execute(array_values($sectionIds));
    $allStudents = $stdStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($allStudents as $sRow) {
        $studentId    = (int)$sRow['student_id'];
        $assessmentId = (int)$sRow['assessment_id'];
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
                'term_id'       => (int)($sRow['academic_term_id'] ?? 0)
            ]);
            $validatedPaid = (float)$paidStmt->fetchColumn();
        }

        $isRoleStudent = ($sRow['user_role'] === 'student');
        $isEnrollmentConfirmed = ($sRow['reg_status'] === 'enrolled' && $sRow['student_enrollment_status'] === 'enrolled');
        $isDownpaymentMet = hasMetRequiredDownpayment(
            $validatedPaid,
            (float)$sRow['assessment_total'],
            (float)$sRow['minimum_downpayment'],
            (float)$sRow['downpayment_percentage']
        );

        $sRow['has_lms_access'] = ($isRoleStudent && $isEnrollmentConfirmed && $isDownpaymentMet);
        $sRow['validated_paid'] = $validatedPaid;

        $secId = (int)$sRow['section_id'];
        $studentsBySection[$secId][] = $sRow;
    }
}

// ── Calculate Summary Statistics for KPIs ──────────────────────────────────────
$stats = [
    'total_offerings'    => count($rawRows),
    'distinct_sections'  => count($sectionIds),
    'assigned_teachers'  => 0,
    'total_enrolled'     => 0,
];

$assignedTeacherIds = [];
$countedSectionIds = [];
foreach ($rawRows as $row) {
    if (!empty($row['teacher_id'])) {
        $assignedTeacherIds[$row['teacher_id']] = true;
    }
    $secId = (int)$row['section_id'];
    if (!isset($countedSectionIds[$secId])) {
        $stats['total_enrolled'] += (int)$row['enrolled_students_count'];
        $countedSectionIds[$secId] = true;
    }
}
$stats['assigned_teachers'] = count($assignedTeacherIds);

$page_title = 'LMS Subject & Section Offerings — Registrar Office';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- ── REGISTRAR LMS NAVIGATION BAR ─────────────────────────────────────────── -->
<nav class="card shadow-sm mb-4 border-0" style="background:var(--sidebar-bg,#0b4f5c);" aria-label="Registrar LMS navigation">
    <div class="card-body py-2 px-3">
        <div class="d-flex flex-wrap gap-1 align-items-center">
            <span class="text-white opacity-75 small fw-semibold me-2 text-nowrap" style="font-size:.7rem;letter-spacing:.06em;text-transform:uppercase;">
                <i class="bi bi-mortarboard me-1"></i>LMS Records
            </span>
            <a href="lms_enrollments" class="btn btn-sm text-white text-nowrap" style="opacity:.85;" onmouseenter="this.style.opacity=1" onmouseleave="this.style.opacity='.85'">
                <i class="bi bi-person-check-fill me-1"></i>LMS Enrollments
            </a>
            <a href="lms_subjects" class="btn btn-sm text-white fw-bold active text-nowrap" style="background:rgba(255,255,255,.2);border-radius:6px;opacity:1;" aria-current="page">
                <i class="bi bi-layers-fill me-1"></i>Subjects & Sections
            </a>
            <a href="lms_grades" class="btn btn-sm text-white text-nowrap" style="opacity:.85;" onmouseenter="this.style.opacity=1" onmouseleave="this.style.opacity='.85'">
                <i class="bi bi-mortarboard-fill me-1"></i>Academic Grades
            </a>
            <a href="lms_reports" class="btn btn-sm text-white text-nowrap" style="opacity:.85;" onmouseenter="this.style.opacity=1" onmouseleave="this.style.opacity='.85'">
                <i class="bi bi-file-earmark-bar-graph-fill me-1"></i>LMS Reports
            </a>
            <a href="grade_approvals" class="btn btn-sm text-white text-nowrap" style="opacity:.85;" onmouseenter="this.style.opacity=1" onmouseleave="this.style.opacity='.85'">
                <i class="bi bi-check2-square me-1"></i>Grade Approvals
            </a>
        </div>
    </div>
</nav>

<!-- ── BREADCRUMB ──────────────────────────────────────────────────────────── -->
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb mb-0" style="font-size:.82rem;">
        <li class="breadcrumb-item"><a href="dashboard" class="text-decoration-none text-brand-primary"><i class="bi bi-journal-bookmark-fill me-1"></i>Registrar Office</a></li>
        <li class="breadcrumb-item active" aria-current="page">LMS Subject & Section Offerings</li>
    </ol>
</nav>

<!-- ── PAGE HEADER ─────────────────────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
    <div>
        <h1 class="h3 fw-bold text-navy-alt mb-1">
            <i class="bi bi-layers-fill me-2 text-brand-primary"></i>LMS Subject & Section Offerings
        </h1>
        <p class="text-muted small mb-0">
            View subjects/courses offered within the LMS, assigned instructors, and enrolled cadet counts. Strict Read-Only view.
        </p>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-outline-secondary btn-sm" onclick="window.print()">
            <i class="bi bi-printer me-1"></i>Print View
        </button>
    </div>
</div>

<!-- ── KPI METRICS STRIP ────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body p-3 text-center">
                <div class="text-muted small fw-semibold text-uppercase" style="font-size:.72rem;">Total Offerings</div>
                <div class="h3 fw-bold text-navy-alt my-1"><?php echo number_format($stats['total_offerings']); ?></div>
                <div class="text-muted small" style="font-size:.75rem;">Subject-section pairings</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body p-3 text-center">
                <div class="text-muted small fw-semibold text-uppercase" style="font-size:.72rem;">Active Sections</div>
                <div class="h3 fw-bold text-brand-primary my-1">
                    <i class="bi bi-diagram-3-fill me-1" style="font-size:1.1rem;"></i><?php echo number_format($stats['distinct_sections']); ?>
                </div>
                <div class="text-muted small" style="font-size:.75rem;">Distinct class cohorts</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body p-3 text-center">
                <div class="text-muted small fw-semibold text-uppercase" style="font-size:.72rem;">Assigned Teachers</div>
                <div class="h3 fw-bold text-success my-1">
                    <i class="bi bi-person-badge-fill me-1" style="font-size:1.1rem;"></i><?php echo number_format($stats['assigned_teachers']); ?>
                </div>
                <div class="text-muted small" style="font-size:.75rem;">Active instructors</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body p-3 text-center">
                <div class="text-muted small fw-semibold text-uppercase" style="font-size:.72rem;">Enrolled Cadets</div>
                <div class="h3 fw-bold text-info my-1">
                    <i class="bi bi-people-fill me-1" style="font-size:1.1rem;"></i><?php echo number_format($stats['total_enrolled']); ?>
                </div>
                <div class="text-muted small" style="font-size:.75rem;">Across listed cohorts</div>
            </div>
        </div>
    </div>
</div>

<!-- ── SEARCH & FILTER CONTROLS (STRICTLY READ-ONLY GET REQUESTS) ───────────── -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-body p-3">
        <form method="GET" action="lms_subjects" class="row g-2 align-items-center">
            <div class="col-12 col-md-3">
                <label class="form-label small fw-semibold text-muted mb-1">Search Subjects / Teachers</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" name="search" class="form-control border-start-0" 
                           placeholder="Code, title, section, teacher..." 
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
            <div class="col-6 col-md-1">
                <label class="form-label small fw-semibold text-muted mb-1">Year</label>
                <select name="year_level" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="1st Year" <?php echo $filterYearLevel === '1st Year' ? 'selected' : ''; ?>>1st</option>
                    <option value="2nd Year" <?php echo $filterYearLevel === '2nd Year' ? 'selected' : ''; ?>>2nd</option>
                    <option value="3rd Year" <?php echo $filterYearLevel === '3rd Year' ? 'selected' : ''; ?>>3rd</option>
                    <option value="4th Year" <?php echo $filterYearLevel === '4th Year' ? 'selected' : ''; ?>>4th</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold text-muted mb-1">Teacher</label>
                <select name="teacher_id" class="form-select form-select-sm">
                    <option value="0">All Teachers</option>
                    <?php foreach ($allTeachers as $tch): ?>
                        <?php 
                        $tName = trim(($tch['first_name'] ?? '') . ' ' . ($tch['last_name'] ?? '')) ?: $tch['username'];
                        ?>
                        <option value="<?php echo (int)$tch['id']; ?>" <?php echo $filterTeacherId === (int)$tch['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($tName); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2 d-flex gap-2 align-self-end">
                <button type="submit" class="btn btn-brand-primary btn-sm flex-grow-1">
                    <i class="bi bi-funnel-fill me-1"></i>Filter
                </button>
                <a href="lms_subjects" class="btn btn-outline-secondary btn-sm" title="Clear Filters">
                    <i class="bi bi-arrow-counterclockwise"></i>
                </a>
            </div>
        </form>
    </div>
</div>

<!-- ── READ-ONLY NOTICE BANNER ─────────────────────────────────────────────── -->
<div class="alert alert-info border-0 shadow-sm py-2 px-3 mb-4 d-flex align-items-center gap-2" style="background:#e8f4f8;color:#0b4f5c;font-size:.84rem;">
    <i class="bi bi-info-circle-fill fs-5 flex-shrink-0"></i>
    <div>
        <strong>Registrar View-Only Mode:</strong> This view displays active course offerings, schedules, teacher assignments, and enrollment metrics as connected to the LMS. Subject, section, and instructor changes are managed in Section/Curriculum management.
    </div>
</div>

<!-- ── MAIN DATA TABLE ──────────────────────────────────────────────────────── -->
<div class="card shadow-sm border-0">
    <div class="card-header bg-white py-3 px-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="fw-bold text-navy-alt">
            <i class="bi bi-table me-2 text-brand-primary"></i>LMS Subject Offerings & Section Assignments
            <span class="badge bg-secondary-subtle text-secondary ms-2"><?php echo count($rawRows); ?> Offerings</span>
        </div>
        <div class="text-muted small">
            Live LMS Data Sync
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($rawRows)): ?>
            <div class="text-center py-5">
                <i class="bi bi-journal-x text-muted display-4 d-block mb-3 opacity-50"></i>
                <h5 class="fw-bold text-navy-alt">No LMS Subject Offerings Found</h5>
                <p class="text-muted small mb-0">No subject-section offerings match the selected term, program, or teacher criteria.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size:.875rem;">
                    <thead class="table-light text-muted fw-semibold" style="font-size:.75rem;letter-spacing:.04em;text-transform:uppercase;">
                        <tr>
                            <th class="ps-4" style="width:28%;">Subject / Course</th>
                            <th style="width:20%;">Section & Term</th>
                            <th style="width:16%;">Schedule & Room</th>
                            <th style="width:20%;">Assigned Teacher</th>
                            <th style="width:10%;" class="text-center">Enrolled Cadets</th>
                            <th class="pe-4 text-end" style="width:6%;">Class List</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rawRows as $row): 
                            $ssId = (int)$row['section_subject_id'];
                            $secId = (int)$row['section_id'];
                            $enrolledCount = (int)$row['enrolled_students_count'];
                            $students = $studentsBySection[$secId] ?? [];
                            $activeLmsCount = 0;
                            foreach ($students as $st) {
                                if (!empty($st['has_lms_access'])) {
                                    $activeLmsCount++;
                                }
                            }
                        ?>
                            <tr>
                                <!-- 1. Subject Details -->
                                <td class="ps-4">
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="rounded-2 p-2 text-center text-brand-primary" style="background:#eaf4f6;min-width:44px;">
                                            <i class="bi bi-book-half fs-5"></i>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-navy-alt">
                                                <span class="badge bg-navy-subtle text-navy me-1 px-2 py-1 fw-bold" style="background:#e0edf1;color:#0b4f5c;">
                                                    <?php echo htmlspecialchars($row['subject_code']); ?>
                                                </span>
                                                <?php echo htmlspecialchars($row['subject_name']); ?>
                                            </div>
                                            <div class="text-muted small mt-0.5" style="font-size:.78rem;">
                                                <span class="me-2"><i class="bi bi-award me-1"></i><?php echo number_format((float)$row['units'], 1); ?> Units</span>
                                                <?php if (!empty($row['subject_type'])): ?>
                                                    <span class="badge bg-light text-secondary border me-1" style="font-size:.7rem;"><?php echo htmlspecialchars($row['subject_type']); ?></span>
                                                <?php endif; ?>
                                                <!-- LMS activity indicators -->
                                                <span class="text-muted" title="LMS Content Items">
                                                    <i class="bi bi-folder2-open ms-1 me-1"></i><?php echo (int)$row['lesson_count']; ?> Lessons &bull; <?php echo (int)$row['assignment_count']; ?> Asg &bull; <?php echo (int)$row['quiz_count']; ?> Quiz
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                <!-- 2. Section & Term -->
                                <td>
                                    <div class="fw-bold text-navy-alt">
                                        <i class="bi bi-layers text-brand-primary me-1"></i><?php echo htmlspecialchars($row['section_name']); ?>
                                    </div>
                                    <div class="small text-muted" style="font-size:.78rem;">
                                        <span class="badge bg-secondary-subtle text-secondary me-1"><?php echo htmlspecialchars($row['program'] ?: 'N/A'); ?></span>
                                        <span><?php echo htmlspecialchars($row['section_year_level'] ?: 'N/A'); ?></span>
                                    </div>
                                    <div class="text-muted small" style="font-size:.75rem;">
                                        Term: <?php echo htmlspecialchars(($row['school_year'] ?? '') . ' (' . ($row['semester'] ?? '') . ')'); ?>
                                    </div>
                                </td>

                                <!-- 3. Schedule & Room -->
                                <td>
                                    <div class="fw-semibold text-navy-alt">
                                        <i class="bi bi-calendar3 me-1 text-muted"></i><?php echo htmlspecialchars($row['day_of_week'] ?: 'TBA'); ?>
                                    </div>
                                    <div class="text-muted small" style="font-size:.78rem;">
                                        <i class="bi bi-clock me-1"></i>
                                        <?php 
                                        if (!empty($row['start_time']) && !empty($row['end_time'])) {
                                            echo date('h:i A', strtotime($row['start_time'])) . ' - ' . date('h:i A', strtotime($row['end_time']));
                                        } else {
                                            echo 'TBA';
                                        }
                                        ?>
                                    </div>
                                    <div class="text-muted small" style="font-size:.78rem;">
                                        <i class="bi bi-geo-alt me-1"></i>Room: <?php echo htmlspecialchars($row['room'] ?: 'TBA'); ?>
                                    </div>
                                </td>

                                <!-- 4. Assigned Teacher -->
                                <td>
                                    <?php if (!empty($row['teacher_id'])): ?>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold" 
                                                 style="width:32px;height:32px;background:#0b4f5c;font-size:.75rem;flex-shrink:0;">
                                                <?php 
                                                $initials = strtoupper(substr($row['teacher_first_name'] ?? 'I', 0, 1) . substr($row['teacher_last_name'] ?? 'T', 0, 1));
                                                echo htmlspecialchars($initials);
                                                ?>
                                            </div>
                                            <div>
                                                <div class="fw-bold text-navy-alt" style="font-size:.85rem;">
                                                    <?php echo htmlspecialchars($row['teacher_name']); ?>
                                                </div>
                                                <div class="d-flex align-items-center gap-1 flex-wrap">
                                                    <span class="badge <?php echo $row['teacher_assignment_type'] === 'Subject Instructor' ? 'bg-success-subtle text-success' : 'bg-primary-subtle text-primary'; ?> border" 
                                                          style="font-size:.68rem;padding:2px 6px;">
                                                        <i class="bi bi-person-check me-1"></i><?php echo htmlspecialchars($row['teacher_assignment_type']); ?>
                                                    </span>
                                                    <?php if (!empty($row['teacher_email'])): ?>
                                                        <span class="text-muted small" style="font-size:.72rem;">&bull; <?php echo htmlspecialchars($row['teacher_email']); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-muted">
                                            <span class="badge bg-warning-subtle text-warning border px-2 py-1" style="font-size:.75rem;">
                                                <i class="bi bi-exclamation-circle me-1"></i>Unassigned / TBA
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <!-- 5. Enrolled Student Count -->
                                <td class="text-center">
                                    <div class="d-inline-flex flex-column align-items-center">
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2.5 py-1 fw-bold" style="font-size:.82rem;">
                                            <i class="bi bi-people-fill me-1"></i><?php echo number_format($enrolledCount); ?> Cadets
                                        </span>
                                        <span class="text-muted mt-1" style="font-size:.72rem;" title="Cadets with active LMS access">
                                            <i class="bi bi-check2 text-success me-0.5"></i><?php echo $activeLmsCount; ?> Active LMS
                                        </span>
                                    </div>
                                </td>

                                <!-- 6. View Roster Button -->
                                <td class="pe-4 text-end">
                                    <button type="button" class="btn btn-outline-brand-primary btn-sm px-2 py-1" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#modal-students-<?php echo $ssId; ?>"
                                            style="font-size:.78rem;"
                                            title="View Cadet Class List">
                                        <i class="bi bi-people me-1"></i>Class List
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── READ-ONLY MODALS: ENROLLED CADETS PER SECTION/SUBJECT ─────────────────── -->
<?php if (!empty($rawRows)): ?>
    <?php foreach ($rawRows as $row): 
        $ssId = (int)$row['section_subject_id'];
        $secId = (int)$row['section_id'];
        $students = $studentsBySection[$secId] ?? [];
    ?>
        <div class="modal fade" id="modal-students-<?php echo $ssId; ?>" tabindex="-1" aria-labelledby="modalLabel-<?php echo $ssId; ?>" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content border-0 shadow">
                    <div class="modal-header text-white" style="background:var(--sidebar-bg,#0b4f5c);">
                        <div>
                            <h6 class="modal-title fw-bold mb-0" id="modalLabel-<?php echo $ssId; ?>">
                                <i class="bi bi-people-fill me-2"></i>Enrolled Cadets — <?php echo htmlspecialchars($row['subject_code']); ?> (<?php echo htmlspecialchars($row['section_name']); ?>)
                            </h6>
                            <small class="opacity-75" style="font-size:.78rem;">
                                <?php echo htmlspecialchars($row['subject_name']); ?> &bull; Instructor: <?php echo htmlspecialchars($row['teacher_name']); ?>
                            </small>
                        </div>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body p-0">
                        <?php if (empty($students)): ?>
                            <div class="text-center py-4 text-muted">
                                <i class="bi bi-people text-muted fs-2 d-block mb-2 opacity-50"></i>
                                <span class="small">No cadets officially enrolled in this section yet.</span>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover align-middle mb-0" style="font-size:.83rem;">
                                    <thead class="table-light text-muted" style="font-size:.72rem;letter-spacing:.03em;text-transform:uppercase;">
                                        <tr>
                                            <th class="ps-4" style="width:5%;">#</th>
                                            <th style="width:35%;">Cadet Name</th>
                                            <th style="width:25%;">Username / Email</th>
                                            <th style="width:15%;">Program / Year</th>
                                            <th class="pe-4 text-end" style="width:20%;">LMS Access</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $num = 1;
                                        foreach ($students as $st): 
                                            $cadetName = trim(($st['last_name'] ?? '') . ', ' . ($st['first_name'] ?? '') . ' ' . ($st['middle_name'] ?? '') . ' ' . ($st['suffix'] ?? ''));
                                        ?>
                                            <tr>
                                                <td class="ps-4 text-muted"><?php echo $num++; ?></td>
                                                <td>
                                                    <span class="fw-bold text-navy-alt"><?php echo htmlspecialchars($cadetName); ?></span>
                                                </td>
                                                <td class="text-muted small">
                                                    <div><?php echo htmlspecialchars($st['username'] ?? '—'); ?></div>
                                                    <div style="font-size:.72rem;"><?php echo htmlspecialchars($st['email'] ?? ''); ?></div>
                                                </td>
                                                <td>
                                                    <span class="badge bg-secondary-subtle text-secondary me-1"><?php echo htmlspecialchars($st['program_code'] ?? '—'); ?></span>
                                                    <span class="small text-muted"><?php echo htmlspecialchars($st['year_level'] ?? '—'); ?></span>
                                                </td>
                                                <td class="pe-4 text-end">
                                                    <?php if (!empty($st['has_lms_access'])): ?>
                                                        <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1" style="font-size:.72rem;">
                                                            <i class="bi bi-check-circle-fill me-1"></i>Active / Has Access
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning-subtle text-warning border border-warning-subtle px-2 py-1" style="font-size:.72rem;">
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
                    <div class="modal-footer bg-light py-2 px-3 border-top d-flex justify-content-between">
                        <div class="text-muted small" style="font-size:.75rem;">
                            <i class="bi bi-shield-lock me-1"></i>Strictly Read-Only Registrar View
                        </div>
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php
require_once __DIR__ . '/../includes/footer.php';
