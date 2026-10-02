<?php
/**
 * Registrar LMS Access & Enrollment Records (TASK 1.1)
 *
 * Provides the Registrar with a strictly READ-ONLY view of officially enrolled
 * students and their LMS access eligibility.
 *
 * Requirements:
 *   1. Role Gate: requireLmsRegistrarAccess() (only 'registrar', read-only GET requests).
 *   2. View list of officially enrolled students.
 *   3. View each student's LMS access status (Active / Has Access vs. Pending).
 *      - Tied to: Downpayment paid + section enrollment confirmed + applicant-to-student role upgrade.
 *   4. View which section/subjects each student is enrolled in.
 *   5. Purely READ-ONLY — no mutation actions, buttons, or status modifications.
 */

require_once __DIR__ . '/../includes/lms_access.php';
$registrar = requireLmsRegistrarAccess();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/academic_terms.php';
require_once __DIR__ . '/../includes/assessments.php';

/** @var PDO $pdo */
$activeTerm = getActiveAcademicTerm($pdo);

// ── Capture and sanitize filter values ─────────────────────────────────────────
$filterTermId   = isset($_GET['academic_term_id']) ? (int)$_GET['academic_term_id'] : ($activeTerm ? (int)$activeTerm['id'] : 0);
$filterProgram  = isset($_GET['program']) ? trim((string)$_GET['program']) : '';
$filterStatus   = isset($_GET['status']) ? trim((string)$_GET['status']) : 'enrolled'; // Default to officially enrolled
$filterAccess   = isset($_GET['access_status']) ? trim((string)$_GET['access_status']) : '';
$searchQuery    = isset($_GET['search']) ? trim((string)$_GET['search']) : '';

// Fetch all academic terms for dropdown
$termsStmt = $pdo->query("SELECT id, school_year, semester, is_active FROM academic_terms ORDER BY starts_on DESC, id DESC");
$allTerms = $termsStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Main Query: Live Enrollment, Payment, and Role Records ────────────────────
$sql = "
    SELECT e.id AS enrollment_id,
           e.student_id,
           e.section_id,
           e.academic_term_id,
           e.status AS reg_status,
           e.school_year,
           e.semester,
           e.created_at AS enrolled_at,
           st.first_name,
           st.middle_name,
           st.last_name,
           st.suffix,
           st.program_code,
           st.program_applying_for,
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
           sec.schedule,
           c.course_code,
           c.course_name,
           CONCAT(t.school_year, ' (', t.semester, ')') AS term_name,
           COALESCE(t.minimum_downpayment, 0) AS minimum_downpayment,
           COALESCE(t.downpayment_percentage, 0) AS downpayment_percentage,
           COALESCE(a.total_amount, 0) AS assessment_total,
           COALESCE(a.id, 0) AS assessment_id
    FROM enrollments e
    JOIN students st ON st.id = e.student_id
    JOIN users u ON u.id = st.user_id
    JOIN sections sec ON sec.id = e.section_id
    LEFT JOIN courses c ON c.id = sec.course_id
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
    WHERE 1=1
";
$params = [];

if ($filterTermId > 0) {
    $sql .= " AND e.academic_term_id = :term_id";
    $params['term_id'] = $filterTermId;
}

if ($filterProgram !== '') {
    $sql .= " AND (st.program_code = :prog1 OR st.program_applying_for = :prog2 OR sec.program = :prog3)";
    $params['prog1'] = $filterProgram;
    $params['prog2'] = $filterProgram;
    $params['prog3'] = $filterProgram;
}

if ($filterStatus !== '' && $filterStatus !== 'all') {
    $sql .= " AND e.status = :status";
    $params['status'] = $filterStatus;
}

if ($searchQuery !== '') {
    $sql .= " AND (
        st.first_name LIKE :search1 OR
        st.last_name LIKE :search2 OR
        u.username LIKE :search3 OR
        u.email LIKE :search4 OR
        sec.section_name LIKE :search5
    )";
    $term = '%' . $searchQuery . '%';
    $params['search1'] = $term;
    $params['search2'] = $term;
    $params['search3'] = $term;
    $params['search4'] = $term;
    $params['search5'] = $term;
}

$sql .= " ORDER BY e.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rawRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Fetch section subjects lookup for all sections present ────────────────────
$sectionIds = array_unique(array_filter(array_column($rawRows, 'section_id')));
$subjectsBySection = [];

if (!empty($sectionIds)) {
    $inSec = implode(',', array_fill(0, count($sectionIds), '?'));
    $subStmt = $pdo->prepare("
        SELECT ss.section_id,
               ss.id AS section_subject_id,
               sub.subject_code,
               sub.subject_name,
               sub.units,
               COALESCE(ss.day_of_week, sec.day_of_week) AS day_of_week,
               COALESCE(ss.start_time, sec.start_time) AS start_time,
               COALESCE(ss.end_time, sec.end_time) AS end_time,
               COALESCE(ss.room, sec.room) AS room,
               COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u_inst.first_name, u_inst.last_name)), ''), u_inst.username, 'TBA') AS instructor_name
        FROM section_subjects ss
        JOIN sections sec ON sec.id = ss.section_id
        JOIN subjects sub ON sub.id = ss.subject_id
        LEFT JOIN users u_inst ON u_inst.id = COALESCE(ss.instructor_id, sec.teacher_id)
        WHERE ss.section_id IN ($inSec)
        ORDER BY sub.subject_code ASC
    ");
    $subStmt->execute(array_values($sectionIds));
    foreach ($subStmt->fetchAll(PDO::FETCH_ASSOC) as $subRow) {
        $secId = (int)$subRow['section_id'];
        $subjectsBySection[$secId][] = $subRow;
    }
}

// ── Process Rows: Validate Downpayment & LMS Access Status ─────────────────────
$records = [];
$stats = [
    'total_enrolled' => 0,
    'lms_active'     => 0,
    'lms_pending'    => 0,
    'total_subjects' => 0,
];

foreach ($rawRows as $row) {
    $studentId   = (int)$row['student_id'];
    $termId      = (int)$row['academic_term_id'];
    $assessmentId= (int)$row['assessment_id'];
    $secId       = (int)$row['section_id'];

    // 1. Calculate validated paid amount live (matching lms_access.php)
    $paidStmt = $pdo->prepare("
        SELECT COALESCE((
            SELECT SUM(pa.amount)
            FROM assessment_items ai
            JOIN payment_allocations pa ON pa.assessment_item_id = ai.id
            JOIN payments p ON p.id = pa.payment_id
            WHERE ai.assessment_id = :a_id AND p.or_status = 'validated'
        ), 0) + COALESCE((
            SELECT SUM(up.amount)
            FROM payments up
            WHERE up.student_id = :st_id AND up.academic_term_id = :term_id AND up.or_status = 'validated'
              AND NOT EXISTS (
                  SELECT 1 FROM payment_allocations epa WHERE epa.payment_id = up.id
              )
        ), 0) AS total_validated_paid
    ");
    $paidStmt->execute([
        'a_id'    => $assessmentId,
        'st_id'   => $studentId,
        'term_id' => $termId,
    ]);
    $validatedPaid = (float)$paidStmt->fetchColumn();

    $assessmentTotal = (float)$row['assessment_total'];
    $minDownpayment  = (float)$row['minimum_downpayment'];
    $downpaymentPct  = (float)$row['downpayment_percentage'];
    $reqDownpayment  = calculateRequiredDownpayment($assessmentTotal, $minDownpayment, $downpaymentPct);

    // 2. Evaluate access criteria
    $isRoleStudent          = ($row['user_role'] === 'student');
    $isEnrollmentConfirmed  = ($row['reg_status'] === 'enrolled' && $row['student_enrollment_status'] === 'enrolled');
    $isDownpaymentMet       = hasMetRequiredDownpayment($validatedPaid, $assessmentTotal, $minDownpayment, $downpaymentPct);

    $missingReasons = [];
    if (!$isRoleStudent) {
        $missingReasons[] = 'Account Role is ' . htmlspecialchars($row['user_role']) . ' (needs student)';
    }
    if (!$isEnrollmentConfirmed) {
        $missingReasons[] = 'Enrollment Status is ' . htmlspecialchars($row['reg_status']);
    }
    if (!$isDownpaymentMet) {
        $missingReasons[] = 'Downpayment Unmet (Paid: ₱' . number_format($validatedPaid, 2) . ' / Req: ₱' . number_format($reqDownpayment, 2) . ')';
    }

    $hasLmsAccess = ($isRoleStudent && $isEnrollmentConfirmed && $isDownpaymentMet);

    $row['validated_paid']       = $validatedPaid;
    $row['required_downpayment'] = $reqDownpayment;
    $row['is_downpayment_met']   = $isDownpaymentMet;
    $row['has_lms_access']       = $hasLmsAccess;
    $row['missing_reasons']      = $missingReasons;
    $row['section_subjects']     = $subjectsBySection[$secId] ?? [];

    // Filter by LMS Access Status if set
    if ($filterAccess === 'active' && !$hasLmsAccess) {
        continue;
    }
    if ($filterAccess === 'pending' && $hasLmsAccess) {
        continue;
    }

    // Accumulate metrics
    $stats['total_enrolled']++;
    if ($hasLmsAccess) {
        $stats['lms_active']++;
    } else {
        $stats['lms_pending']++;
    }
    $stats['total_subjects'] += count($row['section_subjects']);

    $records[] = $row;
}

$page_title = 'LMS Enrollment & Access Records — Registrar Office';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- ── REGISTRAR LMS NAVIGATION BAR ─────────────────────────────────────────── -->
<nav class="card shadow-sm mb-4 border-0" style="background:var(--sidebar-bg,#0b4f5c);" aria-label="Registrar LMS navigation">
    <div class="card-body py-2 px-3">
        <div class="d-flex flex-wrap gap-1 align-items-center">
            <span class="text-white opacity-75 small fw-semibold me-2 text-nowrap" style="font-size:.7rem;letter-spacing:.06em;text-transform:uppercase;">
                <i class="bi bi-mortarboard me-1"></i>LMS Records
            </span>
            <a href="lms_enrollments" class="btn btn-sm text-white fw-bold active text-nowrap" style="background:rgba(255,255,255,.2);border-radius:6px;opacity:1;" aria-current="page">
                <i class="bi bi-person-check-fill me-1"></i>LMS Enrollments
            </a>
            <a href="lms_subjects" class="btn btn-sm text-white text-nowrap" style="opacity:.85;" onmouseenter="this.style.opacity=1" onmouseleave="this.style.opacity='.85'">
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
        <li class="breadcrumb-item active" aria-current="page">LMS Enrollment & Access Records</li>
    </ol>
</nav>

<!-- ── PAGE HEADER ─────────────────────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
    <div>
        <h1 class="h3 fw-bold text-navy-alt mb-1">
            <i class="bi bi-person-check-fill me-2 text-brand-primary"></i>LMS Enrollment & Access Records
        </h1>
        <p class="text-muted small mb-0">
            Monitor officially enrolled cadets, their section subjects, and live LMS eligibility. Strict Read-Only view.
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
                <div class="text-muted small fw-semibold text-uppercase" style="font-size:.72rem;">Enrolled Cadets</div>
                <div class="h3 fw-bold text-navy-alt my-1"><?php echo number_format($stats['total_enrolled']); ?></div>
                <div class="text-muted small" style="font-size:.75rem;">Matching current filters</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body p-3 text-center">
                <div class="text-muted small fw-semibold text-uppercase" style="font-size:.72rem;">Has LMS Access</div>
                <div class="h3 fw-bold text-success my-1">
                    <i class="bi bi-check-circle-fill me-1" style="font-size:1.1rem;"></i><?php echo number_format($stats['lms_active']); ?>
                </div>
                <div class="text-muted small" style="font-size:.75rem;">Downpayment + Confirmed</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body p-3 text-center">
                <div class="text-muted small fw-semibold text-uppercase" style="font-size:.72rem;">Pending Access</div>
                <div class="h3 fw-bold text-warning my-1">
                    <i class="bi bi-clock-history me-1" style="font-size:1.1rem;"></i><?php echo number_format($stats['lms_pending']); ?>
                </div>
                <div class="text-muted small" style="font-size:.75rem;">Payment or role pending</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body p-3 text-center">
                <div class="text-muted small fw-semibold text-uppercase" style="font-size:.72rem;">Section Subjects</div>
                <div class="h3 fw-bold text-brand-primary my-1">
                    <i class="bi bi-book-half me-1" style="font-size:1.1rem;"></i><?php echo number_format($stats['total_subjects']); ?>
                </div>
                <div class="text-muted small" style="font-size:.75rem;">Total subject enrollments</div>
            </div>
        </div>
    </div>
</div>

<!-- ── SEARCH & FILTER PANEL ────────────────────────────────────────────────── -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-body p-3">
        <form method="GET" action="lms_enrollments" class="row g-2 align-items-center">
            <div class="col-12 col-md-3">
                <label class="form-label small fw-semibold text-muted mb-1">Search</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                    <input type="text" name="search" class="form-control" placeholder="Cadet name, ID, section..." value="<?php echo htmlspecialchars($searchQuery); ?>">
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
                <label class="form-label small fw-semibold text-muted mb-1">Enrollment Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="all" <?php echo $filterStatus === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                    <option value="enrolled" <?php echo $filterStatus === 'enrolled' ? 'selected' : ''; ?>>Officially Enrolled</option>
                    <option value="pending" <?php echo $filterStatus === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="dropped" <?php echo $filterStatus === 'dropped' ? 'selected' : ''; ?>>Dropped</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold text-muted mb-1">LMS Access</label>
                <select name="access_status" class="form-select form-select-sm">
                    <option value="" <?php echo $filterAccess === '' ? 'selected' : ''; ?>>All Access Levels</option>
                    <option value="active" <?php echo $filterAccess === 'active' ? 'selected' : ''; ?>>Has Access (Active)</option>
                    <option value="pending" <?php echo $filterAccess === 'pending' ? 'selected' : ''; ?>>Pending Access</option>
                </select>
            </div>
            <div class="col-12 col-md-1 d-flex gap-1 align-self-end">
                <button type="submit" class="btn btn-sm btn-brand-primary w-100" title="Apply Filter">
                    <i class="bi bi-funnel-fill"></i>
                </button>
                <a href="lms_enrollments" class="btn btn-sm btn-outline-secondary" title="Reset Filters">
                    <i class="bi bi-arrow-counterclockwise"></i>
                </a>
            </div>
        </form>
    </div>
</div>

<!-- ── ENROLLMENT RECORDS TABLE ─────────────────────────────────────────────── -->
<?php if (empty($records)): ?>
    <div class="card shadow-sm border-0 text-center py-5">
        <div class="card-body">
            <i class="bi bi-mortarboard" style="font-size:3rem;opacity:.3;color:var(--brand-primary);"></i>
            <h2 class="h6 fw-semibold mt-3 mb-1">No Enrollment Records Found</h2>
            <p class="text-muted small mb-3">No student enrollment records match the selected term, program, or search criteria.</p>
            <a href="lms_enrollments" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-counterclockwise me-1"></i>Clear Filters</a>
        </div>
    </div>
<?php else: ?>
    <div class="card shadow-sm border-0 mb-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:.85rem;">
                <thead class="table-light text-uppercase small text-muted" style="letter-spacing:.05em;font-size:.72rem;">
                    <tr>
                        <th class="ps-4 py-3">Cadet / Student</th>
                        <th class="py-3">Section &amp; Program</th>
                        <th class="py-3">Enrolled Subjects</th>
                        <th class="py-3">Downpayment Status</th>
                        <th class="py-3">Role</th>
                        <th class="py-3">LMS Access Status</th>
                        <th class="text-end pe-4 py-3">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($records as $r):
                        $cadetName = trim($r['first_name'] . ' ' . $r['last_name'] . ' ' . ($r['suffix'] ?? ''));
                        $prog = $r['program_code'] ?: ($r['program_applying_for'] ?: ($r['section_program'] ?: 'N/A'));
                        $subCount = count($r['section_subjects']);
                        $unitsSum = array_sum(array_column($r['section_subjects'], 'units'));
                    ?>
                        <tr>
                            <!-- Cadet -->
                            <td class="ps-4 py-3">
                                <div class="fw-bold text-navy-alt"><?php echo htmlspecialchars($cadetName); ?></div>
                                <div class="text-muted small" style="font-size:.75rem;">
                                    <i class="bi bi-person-badge me-1"></i><?php echo htmlspecialchars($r['student_username']); ?>
                                    · <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($prog); ?></span>
                                    <?php if (!empty($r['student_year'])): ?>
                                        <span class="badge bg-light text-muted border"><?php echo htmlspecialchars($r['student_year']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-muted small" style="font-size:.72rem;">
                                    <i class="bi bi-envelope me-1"></i><?php echo htmlspecialchars($r['student_email']); ?>
                                </div>
                            </td>

                            <!-- Section -->
                            <td class="py-3">
                                <div class="fw-semibold text-dark"><?php echo htmlspecialchars($r['section_name']); ?></div>
                                <div class="text-muted small" style="font-size:.75rem;">
                                    <?php if (!empty($r['room'])): ?><i class="bi bi-geo-alt me-1"></i><?php echo htmlspecialchars($r['room']); ?> · <?php endif; ?>
                                    <?php echo htmlspecialchars($r['schedule'] ?: 'Schedule TBA'); ?>
                                </div>
                                <div class="text-muted small" style="font-size:.72rem;">
                                    Term: <?php echo htmlspecialchars($r['term_name'] ?: 'N/A'); ?>
                                </div>
                            </td>

                            <!-- Enrolled Subjects -->
                            <td class="py-3">
                                <span class="badge bg-secondary-subtle text-secondary-emphasis border px-2 py-1">
                                    <i class="bi bi-book me-1"></i><?php echo $subCount; ?> Subjects
                                </span>
                                <div class="text-muted small mt-1" style="font-size:.75rem;">
                                    <?php echo (int)$unitsSum; ?> Total Units
                                </div>
                            </td>

                            <!-- Downpayment -->
                            <td class="py-3">
                                <?php if ($r['is_downpayment_met']): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                                        <i class="bi bi-check2-circle me-1"></i>Downpayment Paid
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1">
                                        <i class="bi bi-exclamation-circle me-1"></i>Payment Pending
                                    </span>
                                <?php endif; ?>
                                <div class="text-muted small mt-1" style="font-size:.75rem;">
                                    Paid: <strong>₱<?php echo number_format($r['validated_paid'], 2); ?></strong>
                                    / Req: ₱<?php echo number_format($r['required_downpayment'], 2); ?>
                                </div>
                            </td>

                            <!-- Role -->
                            <td class="py-3">
                                <?php if ($r['user_role'] === 'student'): ?>
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1">
                                        <i class="bi bi-person-check me-1"></i>Student
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-warning-subtle text-dark border border-warning-subtle px-2 py-1">
                                        <i class="bi bi-person-exclamation me-1"></i><?php echo htmlspecialchars($r['user_role']); ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <!-- LMS Access Status -->
                            <td class="py-3">
                                <?php if ($r['has_lms_access']): ?>
                                    <span class="badge bg-success text-white px-2 py-1" style="font-size:.78rem;">
                                        <i class="bi bi-shield-check me-1"></i>Has Access
                                    </span>
                                    <div class="text-success small mt-1" style="font-size:.72rem;">
                                        <i class="bi bi-check-all me-1"></i>All criteria met
                                    </div>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark px-2 py-1" style="font-size:.78rem;">
                                        <i class="bi bi-clock-history me-1"></i>Access Pending
                                    </span>
                                    <?php foreach ($r['missing_reasons'] as $reason): ?>
                                        <div class="text-danger small mt-1" style="font-size:.7rem;">
                                            <i class="bi bi-x-circle me-1"></i><?php echo htmlspecialchars($reason); ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>

                            <!-- Action (View Details Modal) -->
                            <td class="text-end pe-4 py-3">
                                <button type="button"
                                        class="btn btn-sm btn-outline-secondary"
                                        data-bs-toggle="modal"
                                        data-bs-target="#modal-subjects-<?php echo (int)$r['enrollment_id']; ?>"
                                        title="View Enrolled Subjects">
                                    <i class="bi bi-journal-text me-1"></i>Subjects
                                </button>
                            </td>
                        </tr>

                        <!-- Modal for Enrolled Subjects Detail (Strict Read-Only) -->
                        <div class="modal fade" id="modal-subjects-<?php echo (int)$r['enrollment_id']; ?>" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-dialog-centered modal-lg">
                                <div class="modal-content border-0 shadow">
                                    <div class="modal-header py-3" style="background:var(--sidebar-bg,#0b4f5c);color:#fff;">
                                        <h5 class="modal-title fs-6 fw-bold">
                                            <i class="bi bi-mortarboard-fill me-2"></i>Enrolled Section Subjects: <?php echo htmlspecialchars($cadetName); ?>
                                        </h5>
                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body p-4">
                                        <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom flex-wrap gap-2">
                                            <div>
                                                <div class="text-muted small text-uppercase fw-semibold" style="font-size:.7rem;">Enrolled Section</div>
                                                <div class="h6 fw-bold mb-0 text-navy-alt">
                                                    <?php echo htmlspecialchars($r['section_name']); ?>
                                                    (<?php echo htmlspecialchars($prog); ?> · <?php echo htmlspecialchars($r['student_year']); ?>)
                                                </div>
                                            </div>
                                            <div>
                                                <?php if ($r['has_lms_access']): ?>
                                                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                                                        <i class="bi bi-check-circle-fill me-1"></i>LMS Active
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2 py-1">
                                                        <i class="bi bi-clock-history me-1"></i>LMS Pending
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <?php if (empty($r['section_subjects'])): ?>
                                            <div class="text-center py-4 text-muted">
                                                <i class="bi bi-journal-x fs-2 d-block mb-2 opacity-50"></i>
                                                <p class="mb-0 small">No subjects are currently assigned to this section.</p>
                                            </div>
                                        <?php else: ?>
                                            <div class="table-responsive">
                                                <table class="table table-sm table-bordered align-middle mb-0" style="font-size:.82rem;">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th>Subject Code</th>
                                                            <th>Subject Name</th>
                                                            <th class="text-center">Units</th>
                                                            <th>Instructor</th>
                                                            <th>Schedule / Room</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($r['section_subjects'] as $sub): ?>
                                                            <tr>
                                                                <td class="fw-bold text-brand-primary"><?php echo htmlspecialchars($sub['subject_code']); ?></td>
                                                                <td><?php echo htmlspecialchars($sub['subject_name']); ?></td>
                                                                <td class="text-center"><?php echo htmlspecialchars($sub['units']); ?></td>
                                                                <td><i class="bi bi-person me-1 text-muted"></i><?php echo htmlspecialchars($sub['instructor_name']); ?></td>
                                                                <td>
                                                                    <?php if (!empty($sub['day_of_week']) || !empty($sub['start_time'])): ?>
                                                                        <?php echo htmlspecialchars($sub['day_of_week'] . ' ' . $sub['start_time'] . '-' . $sub['end_time']); ?>
                                                                    <?php else: ?>
                                                                        <span class="text-muted">TBA</span>
                                                                    <?php endif; ?>
                                                                    <?php if (!empty($sub['room'])): ?>
                                                                        · <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($sub['room']); ?></span>
                                                                    <?php endif; ?>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="modal-footer py-2 bg-light">
                                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php
require_once __DIR__ . '/../includes/footer.php';
