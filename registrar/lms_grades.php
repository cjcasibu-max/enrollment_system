<?php
/**
 * Registrar LMS Academic Records & Final Grades View (TASK 3.1)
 *
 * Provides the Registrar with a strictly READ-ONLY view of final grades
 * per student, per subject, as recorded by teachers in the LMS and approved
 * into official academic records.
 *
 * Requirements:
 *   1. Role Gate: requireLmsRegistrarAccess() (only 'registrar', read-only GET requests).
 *   2. View final grades per student, per subject, as recorded by teachers in the LMS.
 *   3. View overall course grades and completion status relevant to official academic records.
 *   4. Clear reflection of the documented finalization workflow:
 *      - Teacher encodes in LMS (Draft)
 *      - Teacher submits final grades to Registrar (Submitted)
 *      - Registrar approves grade submission (Approved/Locked) -> Official Academic Records
 *   5. Strictly READ-ONLY — zero mutation capabilities (no grade editing inputs or submit forms).
 */

require_once __DIR__ . '/../includes/lms_access.php';
$registrar = requireLmsRegistrarAccess();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/academic_terms.php';

/** @var PDO $pdo */
$activeTerm = getActiveAcademicTerm($pdo);

// ── Capture and sanitize filter values ─────────────────────────────────────────
$filterTermId   = isset($_GET['academic_term_id']) ? (int)$_GET['academic_term_id'] : ($activeTerm ? (int)$activeTerm['id'] : 0);
$filterProgram  = isset($_GET['program']) ? trim((string)$_GET['program']) : '';
$filterYear     = isset($_GET['year_level']) ? trim((string)$_GET['year_level']) : '';
$filterStatus   = isset($_GET['status']) ? trim((string)$_GET['status']) : ''; // all, approved, submitted, draft
$filterRemarks  = isset($_GET['remarks']) ? trim((string)$_GET['remarks']) : '';
$searchQuery    = isset($_GET['search']) ? trim((string)$_GET['search']) : '';

// ── Dropdown Options ───────────────────────────────────────────────────────────
$termsStmt = $pdo->query("SELECT id, school_year, semester, is_active FROM academic_terms ORDER BY starts_on DESC, id DESC");
$allTerms = $termsStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Main Query: Live Student Grades & Submissions ─────────────────────────────
$sql = "
    SELECT sg.id AS grade_id,
           sg.prelim_grade,
           sg.midterm_grade,
           sg.final_exam_grade,
           sg.final_grade,
           sg.remarks,
           sg.created_at AS grade_created_at,
           sg.updated_at AS grade_updated_at,
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
           st.enrollment_status,
           u_std.id AS user_id,
           u_std.username AS student_username,
           u_std.email AS student_email,
           sec.id AS section_id,
           sec.section_name,
           sec.program AS section_program,
           sec.year_level AS section_year_level,
           COALESCE(sub.subject_code, c.course_code, sec.section_name, 'SUBJ') AS subject_code,
           COALESCE(sub.subject_name, c.course_name, sec.section_name, 'Subject Course') AS subject_name,
           COALESCE(sub.units, c.units, 3.0) AS units,
           COALESCE(sub.subject_type, 'Core Academic') AS subject_type,
           at.id AS academic_term_id,
           at.school_year,
           at.semester,
           at.is_active AS term_is_active,
           u_tch.id AS teacher_id,
           u_tch.first_name AS teacher_first_name,
           u_tch.last_name AS teacher_last_name,
           u_tch.username AS teacher_username,
           u_tch.email AS teacher_email,
           COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u_tch.first_name, u_tch.last_name)), ''), u_tch.username, 'Assigned Faculty') AS teacher_name,
           u_rev.username AS reviewer_username,
           COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u_rev.first_name, u_rev.last_name)), ''), u_rev.username, 'Registrar Staff') AS reviewer_name
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
    LEFT JOIN users u_rev ON u_rev.id = gs.reviewed_by
    JOIN academic_terms at ON at.id = gs.academic_term_id
    WHERE 1=1
";
$params = [];

if ($filterTermId > 0) {
    $sql .= " AND gs.academic_term_id = :term_id";
    $params['term_id'] = $filterTermId;
}

if ($filterProgram !== '') {
    $sql .= " AND (st.program_code = :program OR sec.program = :sec_prog)";
    $params['program']  = $filterProgram;
    $params['sec_prog'] = $filterProgram;
}

if ($filterYear !== '') {
    $sql .= " AND (st.year_level = :year_level OR sec.year_level = :sec_yr)";
    $params['year_level'] = $filterYear;
    $params['sec_yr']     = $filterYear;
}

if ($filterStatus === 'approved') {
    $sql .= " AND gs.status IN ('approved', 'locked')";
} elseif ($filterStatus === 'submitted') {
    $sql .= " AND gs.status = 'submitted'";
} elseif ($filterStatus === 'draft') {
    $sql .= " AND gs.status = 'draft'";
}

if ($filterRemarks !== '') {
    $sql .= " AND sg.remarks = :remarks";
    $params['remarks'] = $filterRemarks;
}

if ($searchQuery !== '') {
    $sql .= " AND (
        st.first_name LIKE :search
        OR st.last_name LIKE :search
        OR u_std.username LIKE :search
        OR sub.subject_code LIKE :search
        OR sub.subject_name LIKE :search
        OR sec.section_name LIKE :search
        OR u_tch.first_name LIKE :search
        OR u_tch.last_name LIKE :search
    )";
    $params['search'] = '%' . $searchQuery . '%';
}

$sql .= " ORDER BY at.school_year DESC, at.semester DESC, st.last_name ASC, st.first_name ASC, subject_code ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Calculate KPI Metrics ──────────────────────────────────────────────────────
$stats = [
    'total_grades'     => count($records),
    'official_records' => 0,
    'pending_approval' => 0,
    'draft_records'    => 0,
    'passed_count'     => 0,
    'total_final_sum'  => 0.0,
    'graded_count'     => 0,
];

foreach ($records as $r) {
    $status = $r['submission_status'];
    if (in_array($status, ['approved', 'locked'], true)) {
        $stats['official_records']++;
    } elseif ($status === 'submitted') {
        $stats['pending_approval']++;
    } elseif ($status === 'draft') {
        $stats['draft_records']++;
    }

    if ($r['final_grade'] !== null && $r['final_grade'] !== '') {
        $fGrade = (float)$r['final_grade'];
        $stats['total_final_sum'] += $fGrade;
        $stats['graded_count']++;

        $remarksLower = strtolower($r['remarks'] ?? '');
        if ($remarksLower === 'passed' || ($remarksLower === '' && ($fGrade <= 3.0 || $fGrade >= 75.0))) {
            $stats['passed_count']++;
        }
    }
}

$avgFinalGrade = $stats['graded_count'] > 0 ? round($stats['total_final_sum'] / $stats['graded_count'], 2) : 0.0;
$passingRate   = $stats['graded_count'] > 0 ? round(($stats['passed_count'] / $stats['graded_count']) * 100, 1) : 0.0;

$page_title = 'LMS Academic Records & Final Grades — Registrar Office';
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
            <a href="lms_subjects" class="btn btn-sm text-white text-nowrap" style="opacity:.85;" onmouseenter="this.style.opacity=1" onmouseleave="this.style.opacity='.85'">
                <i class="bi bi-layers-fill me-1"></i>Subjects & Sections
            </a>
            <a href="lms_grades" class="btn btn-sm text-white fw-bold active text-nowrap" style="background:rgba(255,255,255,.2);border-radius:6px;opacity:1;" aria-current="page">
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
        <li class="breadcrumb-item active" aria-current="page">LMS Academic Records & Final Grades</li>
    </ol>
</nav>

<!-- ── PAGE HEADER ─────────────────────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
    <div>
        <h1 class="h3 fw-bold text-navy-alt mb-1">
            <i class="bi bi-mortarboard-fill me-2 text-brand-primary"></i>LMS Academic Records & Final Grades
        </h1>
        <p class="text-muted small mb-0">
            View final grades and academic completion status as they flow from the Teacher LMS into official student academic transcripts. Strict Read-Only view.
        </p>
    </div>
    <div class="d-flex gap-2">
        <a href="grade_approvals" class="btn btn-sm btn-outline-brand-primary">
            <i class="bi bi-check2-square me-1"></i>Grade Approvals Queue
        </a>
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
                <div class="text-muted small fw-semibold text-uppercase" style="font-size:.72rem;">Recorded Grades</div>
                <div class="h3 fw-bold text-navy-alt my-1"><?php echo number_format($stats['total_grades']); ?></div>
                <div class="text-muted small" style="font-size:.75rem;">Subject entries in scope</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body p-3 text-center">
                <div class="text-muted small fw-semibold text-uppercase" style="font-size:.72rem;">Official Academic Records</div>
                <div class="h3 fw-bold text-success my-1">
                    <i class="bi bi-patch-check-fill me-1" style="font-size:1.1rem;"></i><?php echo number_format($stats['official_records']); ?>
                </div>
                <div class="text-muted small" style="font-size:.75rem;">Registrar-approved & locked</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body p-3 text-center">
                <div class="text-muted small fw-semibold text-uppercase" style="font-size:.72rem;">Average Final Grade</div>
                <div class="h3 fw-bold text-brand-primary my-1">
                    <i class="bi bi-award-fill me-1" style="font-size:1.1rem;"></i><?php echo $avgFinalGrade > 0 ? number_format($avgFinalGrade, 2) : '—'; ?>
                </div>
                <div class="text-muted small" style="font-size:.75rem;">Across graded cadets</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body p-3 text-center">
                <div class="text-muted small fw-semibold text-uppercase" style="font-size:.72rem;">Passing Rate</div>
                <div class="h3 fw-bold text-info my-1">
                    <i class="bi bi-graph-up-arrow me-1" style="font-size:1.1rem;"></i><?php echo $stats['graded_count'] > 0 ? $passingRate . '%' : '—'; ?>
                </div>
                <div class="text-muted small" style="font-size:.75rem;"><?php echo $stats['passed_count']; ?> Passed / <?php echo $stats['graded_count']; ?> Graded</div>
            </div>
        </div>
    </div>
</div>

<!-- ── SEARCH & FILTER CONTROLS (STRICTLY READ-ONLY GET REQUESTS) ───────────── -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-body p-3">
        <form method="GET" action="lms_grades" class="row g-2 align-items-center">
            <div class="col-12 col-md-3">
                <label class="form-label small fw-semibold text-muted mb-1">Search Cadet / Subject / Section</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" name="search" class="form-control border-start-0" 
                           placeholder="Cadet name, code, section..." 
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
                <label class="form-label small fw-semibold text-muted mb-1">Record Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Statuses</option>
                    <option value="approved" <?php echo $filterStatus === 'approved' ? 'selected' : ''; ?>>Official (Approved/Locked)</option>
                    <option value="submitted" <?php echo $filterStatus === 'submitted' ? 'selected' : ''; ?>>Submitted (Pending Approval)</option>
                    <option value="draft" <?php echo $filterStatus === 'draft' ? 'selected' : ''; ?>>Draft (In Progress)</option>
                </select>
            </div>
            <div class="col-6 col-md-1">
                <label class="form-label small fw-semibold text-muted mb-1">Remarks</label>
                <select name="remarks" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="Passed" <?php echo $filterRemarks === 'Passed' ? 'selected' : ''; ?>>Passed</option>
                    <option value="Failed" <?php echo $filterRemarks === 'Failed' ? 'selected' : ''; ?>>Failed</option>
                    <option value="Incomplete" <?php echo $filterRemarks === 'Incomplete' ? 'selected' : ''; ?>>INC</option>
                    <option value="Dropped" <?php echo $filterRemarks === 'Dropped' ? 'selected' : ''; ?>>DRP</option>
                </select>
            </div>
            <div class="col-12 col-md-2 d-flex gap-2 align-self-end">
                <button type="submit" class="btn btn-brand-primary btn-sm flex-grow-1">
                    <i class="bi bi-funnel-fill me-1"></i>Filter
                </button>
                <a href="lms_grades" class="btn btn-outline-secondary btn-sm" title="Clear Filters">
                    <i class="bi bi-arrow-counterclockwise"></i>
                </a>
            </div>
        </form>
    </div>
</div>

<!-- ── WORKFLOW CALLOUT BANNER ─────────────────────────────────────────────── -->
<div class="alert alert-info border-0 shadow-sm py-2 px-3 mb-4 d-flex align-items-center gap-2" style="background:#e8f4f8;color:#0b4f5c;font-size:.84rem;">
    <i class="bi bi-info-circle-fill fs-5 flex-shrink-0"></i>
    <div>
        <strong>Academic Record Flow:</strong> In NCST Maritime Academy, grades recorded by instructors in the LMS progress from <em>Draft</em> &rarr; <em>Submitted</em> &rarr; <em>Approved</em>. Only <strong>Official (Approved/Locked)</strong> grades appear on cadet official transcripts and are considered completed for graduation evaluations. Grade corrections must be made by instructors on the Teacher side.
    </div>
</div>

<!-- ── MAIN GRADES TABLE ────────────────────────────────────────────────────── -->
<div class="card shadow-sm border-0">
    <div class="card-header bg-white py-3 px-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="fw-bold text-navy-alt">
            <i class="bi bi-table me-2 text-brand-primary"></i>LMS Grade Records & Academic Transcript Flow
            <span class="badge bg-secondary-subtle text-secondary ms-2"><?php echo count($records); ?> Entries</span>
        </div>
        <div class="text-muted small">
            Live Database Sync
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($records)): ?>
            <div class="text-center py-5">
                <i class="bi bi-journal-x text-muted display-4 d-block mb-3 opacity-50"></i>
                <h5 class="fw-bold text-navy-alt">No Grade Records Found</h5>
                <p class="text-muted small mb-0">No academic grade records match the selected term, program, or status criteria.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size:.875rem;">
                    <thead class="table-light text-muted fw-semibold" style="font-size:.75rem;letter-spacing:.04em;text-transform:uppercase;">
                        <tr>
                            <th class="ps-4" style="width:25%;">Cadet / Student</th>
                            <th style="width:22%;">Subject & Section</th>
                            <th style="width:16%;">Instructor</th>
                            <th style="width:7%;" class="text-center">Prelim</th>
                            <th style="width:7%;" class="text-center">Midterm</th>
                            <th style="width:7%;" class="text-center">Final</th>
                            <th style="width:8%;" class="text-center">Remarks</th>
                            <th style="width:8%;" class="text-center">Record Status</th>
                            <th class="pe-4 text-end" style="width:5%;">Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($records as $row): 
                            $gradeId   = (int)$row['grade_id'];
                            $cadetName = trim(($row['student_last_name'] ?? '') . ', ' . ($row['student_first_name'] ?? '') . ' ' . ($row['student_middle_name'] ?? '') . ' ' . ($row['student_suffix'] ?? ''));
                            $status    = $row['submission_status'];
                            $isOfficial= in_array($status, ['approved', 'locked'], true);
                            $remarks   = trim($row['remarks'] ?? '');
                            $fGrade    = $row['final_grade'];
                        ?>
                            <tr>
                                <!-- 1. Cadet Details -->
                                <td class="ps-4">
                                    <div class="fw-bold text-navy-alt">
                                        <?php echo htmlspecialchars($cadetName); ?>
                                    </div>
                                    <div class="text-muted small" style="font-size:.78rem;">
                                        <span class="badge bg-secondary-subtle text-secondary me-1"><?php echo htmlspecialchars($row['program_code'] ?: 'N/A'); ?></span>
                                        <span><?php echo htmlspecialchars($row['student_year_level'] ?: 'N/A'); ?></span>
                                        <span class="text-muted">&bull; <?php echo htmlspecialchars($row['student_username'] ?? ''); ?></span>
                                    </div>
                                </td>

                                <!-- 2. Subject & Section -->
                                <td>
                                    <div class="fw-bold text-navy-alt">
                                        <span class="badge bg-navy-subtle text-navy me-1 px-1.5 py-0.5" style="background:#e0edf1;color:#0b4f5c;font-size:.78rem;">
                                            <?php echo htmlspecialchars($row['subject_code']); ?>
                                        </span>
                                        <?php echo htmlspecialchars($row['subject_name']); ?>
                                    </div>
                                    <div class="text-muted small" style="font-size:.75rem;">
                                        <i class="bi bi-layers me-1"></i><?php echo htmlspecialchars($row['section_name']); ?>
                                        <span class="ms-1">&bull; <?php echo number_format((float)$row['units'], 1); ?> Units</span>
                                    </div>
                                </td>

                                <!-- 3. Instructor -->
                                <td>
                                    <div class="fw-semibold text-navy-alt" style="font-size:.83rem;">
                                        <i class="bi bi-person-badge me-1 text-muted"></i><?php echo htmlspecialchars($row['teacher_name']); ?>
                                    </div>
                                    <?php if (!empty($row['teacher_email'])): ?>
                                        <div class="text-muted small" style="font-size:.72rem;">
                                            <?php echo htmlspecialchars($row['teacher_email']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <!-- 4. Prelim Grade -->
                                <td class="text-center font-monospace" style="font-size:.84rem;">
                                    <?php echo $row['prelim_grade'] !== null ? number_format((float)$row['prelim_grade'], 2) : '<span class="text-muted">—</span>'; ?>
                                </td>

                                <!-- 5. Midterm Grade -->
                                <td class="text-center font-monospace" style="font-size:.84rem;">
                                    <?php echo $row['midterm_grade'] !== null ? number_format((float)$row['midterm_grade'], 2) : '<span class="text-muted">—</span>'; ?>
                                </td>

                                <!-- 6. Final Grade -->
                                <td class="text-center font-monospace fw-bold" style="font-size:.88rem;color:#0b4f5c;">
                                    <?php echo $fGrade !== null ? number_format((float)$fGrade, 2) : '<span class="text-muted fw-normal">—</span>'; ?>
                                </td>

                                <!-- 7. Remarks -->
                                <td class="text-center">
                                    <?php 
                                    if (strcasecmp($remarks, 'Passed') === 0): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1" style="font-size:.72rem;">
                                            <i class="bi bi-check-circle me-0.5"></i>Passed
                                        </span>
                                    <?php elseif (strcasecmp($remarks, 'Failed') === 0): ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1" style="font-size:.72rem;">
                                            <i class="bi bi-x-circle me-0.5"></i>Failed
                                        </span>
                                    <?php elseif (strcasecmp($remarks, 'Incomplete') === 0 || strcasecmp($remarks, 'INC') === 0): ?>
                                        <span class="badge bg-warning-subtle text-warning border border-warning-subtle px-2 py-1" style="font-size:.72rem;">
                                            <i class="bi bi-clock-history me-0.5"></i>INC
                                        </span>
                                    <?php elseif (strcasecmp($remarks, 'Dropped') === 0 || strcasecmp($remarks, 'DRP') === 0): ?>
                                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2 py-1" style="font-size:.72rem;">
                                            <i class="bi bi-dash-circle me-0.5"></i>DRP
                                        </span>
                                    <?php elseif ($remarks !== ''): ?>
                                        <span class="badge bg-light text-secondary border px-2 py-1" style="font-size:.72rem;">
                                            <?php echo htmlspecialchars($remarks); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted small">—</span>
                                    <?php endif; ?>
                                </td>

                                <!-- 8. Record Status -->
                                <td class="text-center">
                                    <?php if ($isOfficial): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 fw-bold" style="font-size:.72rem;" title="Approved & locked into official academic records">
                                            <i class="bi bi-lock-fill me-0.5"></i>Official
                                        </span>
                                    <?php elseif ($status === 'submitted'): ?>
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1 fw-bold" style="font-size:.72rem;" title="Awaiting Registrar approval in Grade Approvals">
                                            <i class="bi bi-hourglass-split me-0.5"></i>Submitted
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-warning-subtle text-warning border border-warning-subtle px-2 py-1" style="font-size:.72rem;" title="Teacher draft in progress">
                                            <i class="bi bi-pencil me-0.5"></i>Draft
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- 9. Details Modal Button -->
                                <td class="pe-4 text-end">
                                    <button type="button" class="btn btn-outline-brand-primary btn-sm px-2 py-1" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#modal-grade-<?php echo $gradeId; ?>"
                                            style="font-size:.78rem;"
                                            title="View Record Details">
                                        <i class="bi bi-eye"></i>
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

<!-- ── READ-ONLY MODALS: GRADE RECORD BREAKDOWN & VERIFICATION ───────────────── -->
<?php if (!empty($records)): ?>
    <?php foreach ($records as $row): 
        $gradeId   = (int)$row['grade_id'];
        $cadetName = trim(($row['student_last_name'] ?? '') . ', ' . ($row['student_first_name'] ?? '') . ' ' . ($row['student_middle_name'] ?? '') . ' ' . ($row['student_suffix'] ?? ''));
        $status    = $row['submission_status'];
        $isOfficial= in_array($status, ['approved', 'locked'], true);
    ?>
        <div class="modal fade" id="modal-grade-<?php echo $gradeId; ?>" tabindex="-1" aria-labelledby="modalLabel-<?php echo $gradeId; ?>" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow">
                    <div class="modal-header text-white" style="background:var(--sidebar-bg,#0b4f5c);">
                        <div>
                            <h6 class="modal-title fw-bold mb-0" id="modalLabel-<?php echo $gradeId; ?>">
                                <i class="bi bi-mortarboard-fill me-2"></i>Academic Record Breakdown
                            </h6>
                            <small class="opacity-75" style="font-size:.78rem;">
                                <?php echo htmlspecialchars($cadetName); ?> &bull; <?php echo htmlspecialchars($row['subject_code']); ?>
                            </small>
                        </div>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body p-3">
                        <div class="mb-3 p-3 bg-light rounded-2 border">
                            <div class="row g-2 text-center">
                                <div class="col-4">
                                    <div class="text-muted small fw-semibold text-uppercase" style="font-size:.7rem;">Prelim</div>
                                    <div class="h5 fw-bold text-navy-alt mb-0">
                                        <?php echo $row['prelim_grade'] !== null ? number_format((float)$row['prelim_grade'], 2) : '—'; ?>
                                    </div>
                                </div>
                                <div class="col-4 border-start border-end">
                                    <div class="text-muted small fw-semibold text-uppercase" style="font-size:.7rem;">Midterm</div>
                                    <div class="h5 fw-bold text-navy-alt mb-0">
                                        <?php echo $row['midterm_grade'] !== null ? number_format((float)$row['midterm_grade'], 2) : '—'; ?>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="text-muted small fw-semibold text-uppercase" style="font-size:.7rem;">Final Exam</div>
                                    <div class="h5 fw-bold text-navy-alt mb-0">
                                        <?php echo $row['final_exam_grade'] !== null ? number_format((float)$row['final_exam_grade'], 2) : '—'; ?>
                                    </div>
                                </div>
                            </div>
                            <hr class="my-2">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="fw-bold text-navy-alt">Overall Course Final Grade:</span>
                                <span class="h4 fw-bold mb-0 text-brand-primary">
                                    <?php echo $row['final_grade'] !== null ? number_format((float)$row['final_grade'], 2) : '—'; ?>
                                </span>
                            </div>
                        </div>

                        <ul class="list-group list-group-flush small">
                            <li class="list-group-item d-flex justify-content-between px-0 py-2">
                                <span class="text-muted">Subject & Section:</span>
                                <span class="fw-semibold text-navy-alt text-end">
                                    <?php echo htmlspecialchars($row['subject_code'] . ' - ' . $row['subject_name']); ?>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($row['section_name']); ?></small>
                                </span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between px-0 py-2">
                                <span class="text-muted">Credit Units:</span>
                                <span class="fw-semibold"><?php echo number_format((float)$row['units'], 1); ?> Units</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between px-0 py-2">
                                <span class="text-muted">Assigned Instructor:</span>
                                <span class="fw-semibold"><?php echo htmlspecialchars($row['teacher_name']); ?></span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between px-0 py-2">
                                <span class="text-muted">Completion Status:</span>
                                <span class="fw-bold"><?php echo htmlspecialchars($row['remarks'] ?: 'Pending Completion'); ?></span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between px-0 py-2">
                                <span class="text-muted">Workflow Status:</span>
                                <span>
                                    <?php if ($isOfficial): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-0.5">
                                            <i class="bi bi-lock-fill me-1"></i>Official Academic Record
                                        </span>
                                    <?php elseif ($status === 'submitted'): ?>
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-0.5">
                                            <i class="bi bi-hourglass-split me-1"></i>Submitted &bull; Pending Registrar Approval
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-warning-subtle text-warning border border-warning-subtle px-2 py-0.5">
                                            <i class="bi bi-pencil me-1"></i>Teacher Draft
                                        </span>
                                    <?php endif; ?>
                                </span>
                            </li>
                            <?php if (!empty($row['submitted_at'])): ?>
                                <li class="list-group-item d-flex justify-content-between px-0 py-2">
                                    <span class="text-muted">Submitted by Teacher:</span>
                                    <span class="text-muted"><?php echo date('M d, Y h:i A', strtotime($row['submitted_at'])); ?></span>
                                </li>
                            <?php endif; ?>
                            <?php if ($isOfficial && !empty($row['reviewed_at'])): ?>
                                <li class="list-group-item d-flex justify-content-between px-0 py-2">
                                    <span class="text-muted">Approved & Locked:</span>
                                    <span class="text-success fw-semibold">
                                        <?php echo date('M d, Y h:i A', strtotime($row['reviewed_at'])); ?>
                                        <?php if (!empty($row['reviewer_name'])): ?>
                                            by <?php echo htmlspecialchars($row['reviewer_name']); ?>
                                        <?php endif; ?>
                                    </span>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                    <div class="modal-footer bg-light py-2 px-3 border-top d-flex justify-content-between">
                        <div class="text-muted small" style="font-size:.75rem;">
                            <i class="bi bi-shield-lock me-1"></i>Strictly Read-Only Registrar View
                        </div>
                        <div class="d-flex gap-2">
                            <?php if ($status === 'submitted'): ?>
                                <a href="grade_approvals" class="btn btn-sm btn-brand-primary">
                                    <i class="bi bi-check2-square me-1"></i>Go to Approvals
                                </a>
                            <?php endif; ?>
                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php
require_once __DIR__ . '/../includes/footer.php';
