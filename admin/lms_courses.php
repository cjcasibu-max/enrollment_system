<?php
/**
 * LMS Course & Subject Management (TASK 2.1)
 * Administrator portal view for managing courses, programs, subjects,
 * section offerings, and instructor assignments in the LMS.
 *
 * Upstream source of truth for:
 *   - Student-side "My Courses" (student/lms.php, student/lms_course.php)
 *   - Teacher-side "My Subjects" (teacher/lms.php, teacher/lms_subject.php)
 *   - Registrar-side "LMS Records" (registrar/lms_subjects.php)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/academic_terms.php';
require_once __DIR__ . '/../includes/lms_access.php';

// Strict Admin-only access enforcement
$adminSession = requireLmsAdminAccess();
$adminUserId  = (int)$adminSession['user_id'];

ensureCsrfToken();

/** @var PDO $pdo */
$activeTerm = getActiveAcademicTerm($pdo);

// ── 1. Fetch Academic Programs ────────────────────────────────────────────────
try {
    $progStmt = $pdo->query("SELECT id, program_code, program_name FROM programs ORDER BY program_code ASC");
    $programs = $progStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("Failed to fetch programs: " . $e->getMessage());
    $programs = [];
}

// ── 2. Fetch Active Teachers for Assignments ──────────────────────────────────
try {
    $teachersStmt = $pdo->query("
        SELECT id, username, first_name, last_name, email 
        FROM users 
        WHERE role = 'teacher' AND is_active = 1 
        ORDER BY last_name ASC, first_name ASC
    ");
    $teachers = $teachersStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("Failed to fetch teachers: " . $e->getMessage());
    $teachers = [];
}

// ── 3. Fetch Active Class Sections ────────────────────────────────────────────
try {
    $secStmt = $pdo->query("
        SELECT s.id, s.section_name, s.program, s.year_level, s.academic_term_id, at.school_year, at.semester
        FROM sections s
        LEFT JOIN academic_terms at ON at.id = s.academic_term_id
        WHERE s.status = 'active'
        ORDER BY s.section_name ASC
    ");
    $sections = $secStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("Failed to fetch sections: " . $e->getMessage());
    $sections = [];
}

// ── 4. Fetch All Subjects ─────────────────────────────────────────────────────
try {
    $subStmt = $pdo->query("
        SELECT s.id, s.subject_code, s.subject_name, s.units, s.subject_type, s.year_level, s.semester_name,
               s.description, s.status, s.program_id,
               p.program_code, p.program_name
        FROM subjects s
        LEFT JOIN programs p ON p.id = s.program_id
        ORDER BY p.program_code ASC, s.year_level ASC, s.semester_name ASC, s.subject_code ASC
    ");
    $subjects = $subStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("Failed to fetch subjects: " . $e->getMessage());
    $subjects = [];
}

// ── 5. Fetch Active Section Offerings per Subject ─────────────────────────────
$offeringsBySubject = [];
try {
    $offStmt = $pdo->query("
        SELECT ss.id AS section_subject_id, ss.section_id, ss.subject_id, ss.instructor_id,
               ss.day_of_week, ss.start_time, ss.end_time, ss.room,
               sec.section_name, sec.program, sec.year_level,
               u.username AS teacher_username, u.first_name AS teacher_first_name, u.last_name AS teacher_last_name,
               (SELECT COUNT(*) FROM enrollments e WHERE e.section_id = sec.id AND e.status = 'enrolled') AS enrolled_students
        FROM section_subjects ss
        JOIN sections sec ON sec.id = ss.section_id
        LEFT JOIN users u ON u.id = ss.instructor_id
        WHERE sec.status = 'active'
        ORDER BY sec.section_name ASC
    ");
    $allOfferings = $offStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($allOfferings as $off) {
        $offeringsBySubject[$off['subject_id']][] = $off;
    }
} catch (Throwable $e) {
    error_log("Failed to fetch section offerings: " . $e->getMessage());
    $allOfferings = [];
}

// ── 6. Compute KPI Statistics ─────────────────────────────────────────────────
$stats = [
    'total_programs'  => count($programs),
    'total_subjects'  => count($subjects),
    'active_subjects' => 0,
    'total_offerings' => count($allOfferings),
    'assigned_instructors' => 0,
];

$assignedTeacherIds = [];
foreach ($subjects as $s) {
    if (($s['status'] ?? 'active') === 'active') {
        $stats['active_subjects']++;
    }
}
foreach ($allOfferings as $off) {
    if (!empty($off['instructor_id'])) {
        $assignedTeacherIds[$off['instructor_id']] = true;
    }
}
$stats['assigned_instructors'] = count($assignedTeacherIds);

$page_title = 'LMS Course & Subject Management — Administrator';
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
            <a href="lms_courses" class="btn btn-sm text-white fw-bold active text-nowrap" style="background:rgba(255,255,255,.2);border-radius:6px;opacity:1;" aria-current="page">
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
            <a href="lms_reports" class="btn btn-sm text-white text-nowrap" style="opacity:.85;" onmouseenter="this.style.opacity=1" onmouseleave="this.style.opacity='.85'">
                <i class="bi bi-file-earmark-bar-graph-fill me-1"></i>LMS Reports
            </a>
            <a href="curriculum" class="btn btn-sm text-white text-nowrap ms-auto" style="opacity:.85;" onmouseenter="this.style.opacity=1" onmouseleave="this.style.opacity='.85'" title="Full Curriculum Matrix">
                <i class="bi bi-box-arrow-up-right me-1"></i>Curriculum Matrix
            </a>
        </div>
    </div>
</nav>

<!-- ── BREADCRUMB ──────────────────────────────────────────────────────────── -->
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb mb-0" style="font-size:.82rem;">
        <li class="breadcrumb-item"><a href="dashboard" class="text-decoration-none text-brand-primary"><i class="bi bi-shield-shaded me-1"></i>Administrator</a></li>
        <li class="breadcrumb-item active" aria-current="page">LMS Course & Subject Management</li>
    </ol>
</nav>

<!-- ── PAGE HEADER ─────────────────────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
    <div>
        <h1 class="h3 fw-bold text-navy-alt mb-1">
            <i class="bi bi-book-half me-2 text-brand-primary"></i>LMS Course & Subject Management
        </h1>
        <p class="text-muted mb-0" style="font-size:.9rem;">
            Configure academic courses, subjects, class section offerings, and assigned teachers. Live upstream source for Student & Teacher LMS.
        </p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <button type="button" class="btn btn-outline-brand-primary d-inline-flex align-items-center gap-1.5 shadow-sm" data-bs-toggle="modal" data-bs-target="#addProgramModal">
            <i class="bi bi-folder-plus"></i> New Program
        </button>
        <button type="button" class="btn btn-outline-secondary d-inline-flex align-items-center gap-1.5 shadow-sm" data-bs-toggle="modal" data-bs-target="#addOfferingModal">
            <i class="bi bi-calendar-plus"></i> New Section Offering
        </button>
        <button type="button" class="btn btn-brand-primary d-inline-flex align-items-center gap-1.5 shadow-sm" data-bs-toggle="modal" data-bs-target="#addSubjectModal">
            <i class="bi bi-plus-circle-fill"></i> New Subject
        </button>
    </div>
</div>

<!-- ── METRIC CARDS ────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card card-premium shadow-sm h-100 border-0">
            <div class="card-body p-3">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted small fw-semibold text-uppercase" style="font-size:.7rem;letter-spacing:.05em;">Academic Programs</span>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill">Degree Paths</span>
                </div>
                <div class="h3 fw-bold text-navy-alt mb-0"><?php echo number_format($stats['total_programs']); ?></div>
                <div class="small text-muted mt-1">Maritime degree tracks</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card card-premium shadow-sm h-100 border-0">
            <div class="card-body p-3">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted small fw-semibold text-uppercase" style="font-size:.7rem;letter-spacing:.05em;">LMS Subjects</span>
                    <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill"><?php echo $stats['active_subjects']; ?> Active</span>
                </div>
                <div class="h3 fw-bold text-navy-alt mb-0"><?php echo number_format($stats['total_subjects']); ?></div>
                <div class="small text-muted mt-1">Curriculum course catalog</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card card-premium shadow-sm h-100 border-0">
            <div class="card-body p-3">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted small fw-semibold text-uppercase" style="font-size:.7rem;letter-spacing:.05em;">Class Section Offerings</span>
                    <span class="badge bg-info-subtle text-info border border-info-subtle rounded-pill">Active Term</span>
                </div>
                <div class="h3 fw-bold text-info mb-0"><?php echo number_format($stats['total_offerings']); ?></div>
                <div class="small text-muted mt-1">Scheduled course sections</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card card-premium shadow-sm h-100 border-0">
            <div class="card-body p-3">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted small fw-semibold text-uppercase" style="font-size:.7rem;letter-spacing:.05em;">Assigned Instructors</span>
                    <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill">Staffed</span>
                </div>
                <div class="h3 fw-bold text-success mb-0"><?php echo number_format($stats['assigned_instructors']); ?></div>
                <div class="small text-muted mt-1">Active teacher allocations</div>
            </div>
        </div>
    </div>
</div>

<!-- ── SEARCH & FILTER TOOLBAR ─────────────────────────────────────────────── -->
<div class="card card-premium shadow-sm mb-4 border-0">
    <div class="card-body card-body-premium py-3">
        <div class="row g-3 align-items-center">
            <div class="col-12 col-md-4">
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" id="subjectSearchInput" class="form-control" placeholder="Search by subject code, title, or section..." oninput="filterSubjectsTable()">
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="bi bi-diagram-3 text-muted"></i></span>
                    <select id="programFilter" class="form-select" onchange="filterSubjectsTable()">
                        <option value="">All Programs</option>
                        <?php foreach ($programs as $prog): ?>
                            <option value="<?php echo htmlspecialchars(strtolower($prog['program_code'])); ?>">
                                <?php echo htmlspecialchars($prog['program_code']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="bi bi-mortarboard text-muted"></i></span>
                    <select id="yearLevelFilter" class="form-select" onchange="filterSubjectsTable()">
                        <option value="">All Years</option>
                        <option value="1st year">1st Year</option>
                        <option value="2nd year">2nd Year</option>
                        <option value="3rd year">3rd Year</option>
                        <option value="4th year">4th Year</option>
                    </select>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="bi bi-calendar3 text-muted"></i></span>
                    <select id="semesterFilter" class="form-select" onchange="filterSubjectsTable()">
                        <option value="">All Semesters</option>
                        <option value="1st semester">1st Semester</option>
                        <option value="2nd semester">2nd Semester</option>
                        <option value="summer">Summer</option>
                    </select>
                </div>
            </div>
            <div class="col-6 col-md-2 text-md-end">
                <button type="button" class="btn btn-outline-secondary w-100" onclick="resetSubjectFilters()">
                    <i class="bi bi-arrow-counterclockwise me-1"></i> Reset
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ── SUBJECTS & SECTION OFFERINGS TABLE ──────────────────────────────────── -->
<div class="card card-premium shadow-sm border-0">
    <div class="card-body card-body-premium p-0">
        <div class="table-responsive">
            <table class="table table-hover table-maritime align-middle m-0" id="lmsSubjectsTable">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4" style="width: 130px;">Subject Code</th>
                        <th style="min-width: 220px;">Subject Title & Details</th>
                        <th style="width: 140px;">Program & Term</th>
                        <th style="width: 80px;" class="text-center">Units</th>
                        <th style="min-width: 260px;">Active Section Offerings & Instructors</th>
                        <th style="width: 100px;">Status</th>
                        <th class="pe-4 text-end" style="width: 150px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($subjects)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">
                                <i class="bi bi-book display-6 d-block mb-2 text-muted opacity-50"></i>
                                No subjects currently found in the catalog.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($subjects as $s): 
                            $subId      = (int)$s['id'];
                            $code       = $s['subject_code'];
                            $name       = $s['subject_name'];
                            $progCode   = $s['program_code'] ?: 'BSMT';
                            $yearLevel  = $s['year_level'] ?: '1st Year';
                            $semName    = $s['semester_name'] ?: '1st Semester';
                            $units      = number_format((float)$s['units'], 1);
                            $type       = $s['subject_type'] ?: 'Professional';
                            $status     = $s['status'] ?: 'active';
                            $isActive   = ($status === 'active');
                            $offerings  = $offeringsBySubject[$subId] ?? [];
                            $offeringSectionNames = implode(' ', array_column($offerings, 'section_name'));
                        ?>
                            <tr class="lms-subject-row"
                                data-code="<?php echo htmlspecialchars(strtolower($code)); ?>"
                                data-name="<?php echo htmlspecialchars(strtolower($name)); ?>"
                                data-program="<?php echo htmlspecialchars(strtolower($progCode)); ?>"
                                data-year="<?php echo htmlspecialchars(strtolower($yearLevel)); ?>"
                                data-sem="<?php echo htmlspecialchars(strtolower($semName)); ?>"
                                data-sections="<?php echo htmlspecialchars(strtolower($offeringSectionNames)); ?>"
                                data-status="<?php echo $status; ?>">
                                
                                <td class="ps-4">
                                    <span class="fw-bold text-navy-alt font-monospace" style="font-size:0.92rem;">
                                        <?php echo htmlspecialchars($code); ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="fw-semibold text-darker"><?php echo htmlspecialchars($name); ?></div>
                                    <div class="text-muted small d-flex align-items-center gap-2">
                                        <span class="badge bg-light text-muted border" style="font-size:0.68rem;"><?php echo htmlspecialchars($type); ?></span>
                                        <?php if (!empty($s['description'])): ?>
                                            <span><?php echo htmlspecialchars(substr($s['description'], 0, 45)) . (strlen($s['description']) > 45 ? '...' : ''); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <td>
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle mb-1" style="font-size:0.7rem;">
                                        <?php echo htmlspecialchars($progCode); ?>
                                    </span>
                                    <div class="text-muted small" style="font-size:0.75rem;">
                                        <?php echo htmlspecialchars($yearLevel . ' &bull; ' . $semName); ?>
                                    </div>
                                </td>

                                <td class="text-center fw-semibold text-navy-alt">
                                    <?php echo $units; ?>
                                </td>

                                <td>
                                    <?php if (empty($offerings)): ?>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="badge bg-light text-muted border fw-normal py-1">
                                                <i class="bi bi-dash-circle me-1"></i>No Section Offering
                                            </span>
                                            <button type="button" class="btn btn-sm btn-outline-brand-primary py-0 px-2" style="font-size:0.75rem;"
                                                    onclick="openAddOfferingModal(<?php echo $subId; ?>, '<?php echo htmlspecialchars(addslashes($code)); ?>')">
                                                <i class="bi bi-plus me-1"></i>Offer in Section
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <div class="d-flex flex-column gap-1.5">
                                            <?php foreach ($offerings as $off): 
                                                $teacherName = trim(($off['teacher_first_name'] ?? '') . ' ' . ($off['teacher_last_name'] ?? '')) ?: $off['teacher_username'];
                                                $hasTeacher = !empty($teacherName);
                                            ?>
                                                <div class="d-flex align-items-center justify-content-between p-1.5 px-2 bg-light rounded border" style="font-size:0.8rem;">
                                                    <div>
                                                        <span class="fw-bold text-navy-alt"><?php echo htmlspecialchars($off['section_name']); ?></span>
                                                        <span class="text-muted ms-1 small">(<?php echo htmlspecialchars($off['day_of_week'] . ' ' . substr($off['start_time'], 0, 5) . '-' . substr($off['end_time'], 0, 5)); ?>)</span>
                                                        <div class="small">
                                                            <?php if ($hasTeacher): ?>
                                                                <span class="text-success"><i class="bi bi-person-check-fill me-1"></i><?php echo htmlspecialchars($teacherName); ?></span>
                                                            <?php else: ?>
                                                                <span class="text-warning-dark"><i class="bi bi-exclamation-triangle-fill me-1"></i>Unassigned</span>
                                                            <?php endif; ?>
                                                            <span class="text-muted ms-1">&bull; <?php echo (int)$off['enrolled_students']; ?> cadets</span>
                                                        </div>
                                                    </div>
                                                    <div class="d-inline-flex gap-1">
                                                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1.5" title="Change Instructor"
                                                                onclick="openChangeTeacherModal(<?php echo (int)$off['section_subject_id']; ?>, <?php echo (int)($off['instructor_id'] ?? 0); ?>, '<?php echo htmlspecialchars(addslashes($code . ' - ' . $off['section_name'])); ?>')">
                                                            <i class="bi bi-person-gear"></i>
                                                        </button>
                                                        <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1.5" title="Remove Section Offering"
                                                                onclick="confirmRemoveOffering(<?php echo (int)$off['section_subject_id']; ?>, '<?php echo htmlspecialchars(addslashes($code)); ?>', '<?php echo htmlspecialchars(addslashes($off['section_name'])); ?>')">
                                                            <i class="bi bi-trash3"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                            <div>
                                                <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none text-brand-primary" style="font-size:0.75rem;"
                                                        onclick="openAddOfferingModal(<?php echo $subId; ?>, '<?php echo htmlspecialchars(addslashes($code)); ?>')">
                                                    <i class="bi bi-plus-circle me-1"></i>Add Another Section
                                                </button>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if ($isActive): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 rounded-pill">
                                            Active
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2 py-1 rounded-pill">
                                            Inactive
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td class="pe-4 text-end">
                                    <div class="d-inline-flex gap-1">
                                        <!-- Edit Subject Details -->
                                        <button type="button" class="btn btn-sm btn-outline-secondary" title="Edit Subject Details"
                                                onclick="openEditSubjectModal(<?php echo htmlspecialchars(json_encode([
                                                    'id'            => $subId,
                                                    'subject_code'  => $code,
                                                    'subject_name'  => $name,
                                                    'units'         => $s['units'],
                                                    'subject_type'  => $type,
                                                    'year_level'    => $yearLevel,
                                                    'semester_name' => $semName,
                                                    'description'   => $s['description'],
                                                    'status'        => $status
                                                ])); ?>)">
                                            <i class="bi bi-pencil"></i>
                                        </button>

                                        <!-- Toggle Status (Active / Inactive) -->
                                        <button type="button" class="btn btn-sm <?php echo $isActive ? 'btn-outline-warning' : 'btn-outline-success'; ?>"
                                                title="<?php echo $isActive ? 'Deactivate Subject' : 'Activate Subject'; ?>"
                                                onclick="confirmToggleSubjectStatus(<?php echo $subId; ?>, '<?php echo htmlspecialchars(addslashes($code)); ?>', '<?php echo $status; ?>')">
                                            <i class="bi <?php echo $isActive ? 'bi-toggle-on' : 'bi-toggle-off'; ?>"></i>
                                        </button>

                                        <!-- Delete Subject with Safeguard -->
                                        <button type="button" class="btn btn-sm btn-outline-danger" title="Delete Subject"
                                                onclick="confirmDeleteSubject(<?php echo $subId; ?>, '<?php echo htmlspecialchars(addslashes($code)); ?>')">
                                            <i class="bi bi-trash3"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- =========================================================================
     MODAL: ADD ACADEMIC PROGRAM / COURSE
     ========================================================================= -->
<div class="modal fade" id="addProgramModal" tabindex="-1" aria-labelledby="addProgramModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 shadow-lg border-0">
            <div class="modal-header text-white rounded-top-4 py-3" style="background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);">
                <h5 class="modal-title fw-bold text-white" id="addProgramModalLabel">
                    <i class="bi bi-folder-plus me-2 text-white"></i>Create Academic Program
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="../actions/lms_admin_actions" method="POST" class="needs-validation" novalidate>
                <input type="hidden" name="action" value="create_program">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="redirect_to" value="admin/lms_courses">

                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label for="prog_code" class="form-label fw-semibold">Program Code <span class="text-danger">*</span></label>
                        <input type="text" name="program_code" id="prog_code" class="form-control text-uppercase" placeholder="e.g. BSMT or BSMarE" required pattern="[A-Za-z0-9_-]{2,20}">
                        <div class="form-text small">Standard short program code (e.g. BSMT, BSMarE, BSTM).</div>
                    </div>
                    <div class="mb-3">
                        <label for="prog_name" class="form-label fw-semibold">Program Title <span class="text-danger">*</span></label>
                        <input type="text" name="program_name" id="prog_name" class="form-control" placeholder="e.g. Bachelor of Science in Marine Transportation" required>
                    </div>
                </div>
                <div class="modal-footer bg-light py-3">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brand-primary">Create Program</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- =========================================================================
     MODAL: ADD NEW LMS SUBJECT
     ========================================================================= -->
<div class="modal fade" id="addSubjectModal" tabindex="-1" aria-labelledby="addSubjectModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content rounded-4 shadow-lg border-0">
            <div class="modal-header text-white rounded-top-4 py-3" style="background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);">
                <h5 class="modal-title fw-bold text-white" id="addSubjectModalLabel">
                    <i class="bi bi-plus-circle-fill me-2 text-white"></i>Create New Subject Offering
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="../actions/lms_admin_actions" method="POST" class="needs-validation" novalidate>
                <input type="hidden" name="action" value="create_subject">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="redirect_to" value="admin/lms_courses">

                <div class="modal-body p-4">
                    <div class="row g-3 mb-3">
                        <div class="col-12 col-md-6">
                            <label for="add_sub_prog" class="form-label fw-semibold">Academic Program <span class="text-danger">*</span></label>
                            <select name="program_id" id="add_sub_prog" class="form-select" required>
                                <option value="">-- Select Program --</option>
                                <?php foreach ($programs as $p): ?>
                                    <option value="<?php echo (int)$p['id']; ?>">
                                        <?php echo htmlspecialchars($p['program_code'] . ' - ' . $p['program_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-6">
                            <label for="add_sub_code" class="form-label fw-semibold">Subject Code <span class="text-danger">*</span></label>
                            <input type="text" name="subject_code" id="add_sub_code" class="form-control text-uppercase" placeholder="e.g. NAV 101 or BSMT-MT101" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="add_sub_name" class="form-label fw-semibold">Subject Course Title <span class="text-danger">*</span></label>
                        <input type="text" name="subject_name" id="add_sub_name" class="form-control" placeholder="e.g. Terrestrial and Coastal Navigation 1" required>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-6 col-md-4">
                            <label for="add_sub_units" class="form-label fw-semibold">Credit Units <span class="text-danger">*</span></label>
                            <input type="number" step="0.5" min="0.5" max="12" name="units" id="add_sub_units" class="form-control" value="3.0" required>
                        </div>
                        <div class="col-6 col-md-4">
                            <label for="add_sub_type" class="form-label fw-semibold">Subject Type</label>
                            <select name="subject_type" id="add_sub_type" class="form-select">
                                <option value="Professional">Professional Course</option>
                                <option value="General Education">General Education</option>
                                <option value="Physical Education">Physical Education</option>
                                <option value="NSTP">NSTP</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label for="add_sub_year" class="form-label fw-semibold">Year Level</label>
                            <select name="year_level" id="add_sub_year" class="form-select">
                                <option value="1st Year">1st Year</option>
                                <option value="2nd Year">2nd Year</option>
                                <option value="3rd Year">3rd Year</option>
                                <option value="4th Year">4th Year</option>
                            </select>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-12 col-md-4">
                            <label for="add_sub_sem" class="form-label fw-semibold">Semester</label>
                            <select name="semester_name" id="add_sub_sem" class="form-select">
                                <option value="1st Semester">1st Semester</option>
                                <option value="2nd Semester">2nd Semester</option>
                                <option value="Summer">Summer</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-8">
                            <label for="add_sub_desc" class="form-label fw-semibold">Course Description</label>
                            <input type="text" name="description" id="add_sub_desc" class="form-control" placeholder="Brief overview of subject objectives">
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light py-3">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brand-primary">Create Subject</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- =========================================================================
     MODAL: EDIT SUBJECT
     ========================================================================= -->
<div class="modal fade" id="editSubjectModal" tabindex="-1" aria-labelledby="editSubjectModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content rounded-4 shadow-lg border-0">
            <div class="modal-header text-white rounded-top-4 py-3" style="background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);">
                <h5 class="modal-title fw-bold text-white" id="editSubjectModalLabel">
                    <i class="bi bi-pencil-square me-2 text-white"></i>Edit Subject Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="../actions/lms_admin_actions" method="POST" class="needs-validation" novalidate>
                <input type="hidden" name="action" value="edit_subject">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="redirect_to" value="admin/lms_courses">
                <input type="hidden" name="subject_id" id="edit_sub_id">

                <div class="modal-body p-4">
                    <div class="row g-3 mb-3">
                        <div class="col-12 col-md-4">
                            <label for="edit_sub_code" class="form-label fw-semibold">Subject Code <span class="text-danger">*</span></label>
                            <input type="text" name="subject_code" id="edit_sub_code" class="form-control text-uppercase" required>
                        </div>
                        <div class="col-12 col-md-8">
                            <label for="edit_sub_name" class="form-label fw-semibold">Subject Title <span class="text-danger">*</span></label>
                            <input type="text" name="subject_name" id="edit_sub_name" class="form-control" required>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-6 col-md-4">
                            <label for="edit_sub_units" class="form-label fw-semibold">Credit Units <span class="text-danger">*</span></label>
                            <input type="number" step="0.5" min="0.5" max="12" name="units" id="edit_sub_units" class="form-control" required>
                        </div>
                        <div class="col-6 col-md-4">
                            <label for="edit_sub_type" class="form-label fw-semibold">Subject Type</label>
                            <select name="subject_type" id="edit_sub_type" class="form-select">
                                <option value="Professional">Professional Course</option>
                                <option value="General Education">General Education</option>
                                <option value="Physical Education">Physical Education</option>
                                <option value="NSTP">NSTP</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label for="edit_sub_status" class="form-label fw-semibold">Catalog Status</label>
                            <select name="status" id="edit_sub_status" class="form-select">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-12 col-md-3">
                            <label for="edit_sub_year" class="form-label fw-semibold">Year Level</label>
                            <select name="year_level" id="edit_sub_year" class="form-select">
                                <option value="1st Year">1st Year</option>
                                <option value="2nd Year">2nd Year</option>
                                <option value="3rd Year">3rd Year</option>
                                <option value="4th Year">4th Year</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-3">
                            <label for="edit_sub_sem" class="form-label fw-semibold">Semester</label>
                            <select name="semester_name" id="edit_sub_sem" class="form-select">
                                <option value="1st Semester">1st Semester</option>
                                <option value="2nd Semester">2nd Semester</option>
                                <option value="Summer">Summer</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-6">
                            <label for="edit_sub_desc" class="form-label fw-semibold">Course Description</label>
                            <input type="text" name="description" id="edit_sub_desc" class="form-control" placeholder="Description">
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light py-3">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brand-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- =========================================================================
     MODAL: CREATE / ADD SECTION OFFERING
     ========================================================================= -->
<div class="modal fade" id="addOfferingModal" tabindex="-1" aria-labelledby="addOfferingModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content rounded-4 shadow-lg border-0">
            <div class="modal-header text-white rounded-top-4 py-3" style="background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);">
                <h5 class="modal-title fw-bold text-white" id="addOfferingModalLabel">
                    <i class="bi bi-calendar-plus me-2 text-white"></i>Create Class Section Offering
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="../actions/lms_admin_actions" method="POST" class="needs-validation" novalidate>
                <input type="hidden" name="action" value="create_section_offering">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="redirect_to" value="admin/lms_courses">

                <div class="modal-body p-4">
                    <div class="row g-3 mb-3">
                        <div class="col-12 col-md-6">
                            <label for="offering_subject_id" class="form-label fw-semibold">Subject to Offer <span class="text-danger">*</span></label>
                            <select name="subject_id" id="offering_subject_id" class="form-select" required>
                                <option value="">-- Choose Subject --</option>
                                <?php foreach ($subjects as $sub): ?>
                                    <option value="<?php echo (int)$sub['id']; ?>">
                                        <?php echo htmlspecialchars($sub['subject_code'] . ' - ' . $sub['subject_name'] . ' (' . ($sub['program_code'] ?: 'BSMT') . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-6">
                            <label for="offering_section_id" class="form-label fw-semibold">Class Section <span class="text-danger">*</span></label>
                            <select name="section_id" id="offering_section_id" class="form-select" required>
                                <option value="">-- Choose Section --</option>
                                <?php foreach ($sections as $sec): ?>
                                    <option value="<?php echo (int)$sec['id']; ?>">
                                        <?php echo htmlspecialchars($sec['section_name'] . ' (' . $sec['program'] . ' &bull; ' . $sec['year_level'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="offering_teacher_id" class="form-label fw-semibold">Assigned Instructor <span class="text-muted small fw-normal">(Optional — can be assigned later)</span></label>
                        <select name="teacher_id" id="offering_teacher_id" class="form-select">
                            <option value="">-- Unassigned / TBA --</option>
                            <?php foreach ($teachers as $t): 
                                $tName = trim(($t['first_name'] ?? '') . ' ' . ($t['last_name'] ?? '')) ?: $t['username'];
                            ?>
                                <option value="<?php echo (int)$t['id']; ?>">
                                    <?php echo htmlspecialchars($tName . ' (@' . $t['username'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text small">Assigning an instructor populates this subject directly in the Teacher's "My Subjects" LMS workspace.</div>
                    </div>

                    <div class="row g-3">
                        <div class="col-12 col-md-4">
                            <label for="offering_day" class="form-label fw-semibold">Day of Week</label>
                            <input type="text" name="day_of_week" id="offering_day" class="form-control" value="MWF" placeholder="e.g. MWF, TTH, SAT">
                        </div>
                        <div class="col-6 col-md-3">
                            <label for="offering_start" class="form-label fw-semibold">Start Time</label>
                            <input type="time" name="start_time" id="offering_start" class="form-control" value="08:00">
                        </div>
                        <div class="col-6 col-md-3">
                            <label for="offering_end" class="form-label fw-semibold">End Time</label>
                            <input type="time" name="end_time" id="offering_end" class="form-control" value="10:00">
                        </div>
                        <div class="col-12 col-md-2">
                            <label for="offering_room" class="form-label fw-semibold">Room</label>
                            <input type="text" name="room" id="offering_room" class="form-control" value="Room 301" placeholder="Room #">
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light py-3">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brand-primary">Create Section Offering</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- =========================================================================
     MODAL: CHANGE / ASSIGN TEACHER INLINE
     ========================================================================= -->
<div class="modal fade" id="changeTeacherModal" tabindex="-1" aria-labelledby="changeTeacherModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 shadow-lg border-0">
            <div class="modal-header text-white rounded-top-4 py-3" style="background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);">
                <h5 class="modal-title fw-bold text-white" id="changeTeacherModalLabel">
                    <i class="bi bi-person-gear me-2 text-white"></i>Assign / Change Instructor
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="../actions/lms_admin_actions" method="POST">
                <input type="hidden" name="action" value="assign_teacher_subject">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="redirect_to" value="admin/lms_courses">
                <input type="hidden" name="section_subject_id" id="change_ss_id">

                <div class="modal-body p-4">
                    <div class="mb-3">
                        <div class="text-muted small text-uppercase fw-semibold">Offering</div>
                        <h6 class="fw-bold text-navy-alt mb-0" id="change_offering_label">—</h6>
                    </div>

                    <div class="mb-3">
                        <label for="change_teacher_select" class="form-label fw-semibold">Select Instructor</label>
                        <select name="teacher_id" id="change_teacher_select" class="form-select" required>
                            <option value="">-- Choose Instructor --</option>
                            <?php foreach ($teachers as $t): 
                                $tName = trim(($t['first_name'] ?? '') . ' ' . ($t['last_name'] ?? '')) ?: $t['username'];
                            ?>
                                <option value="<?php echo (int)$t['id']; ?>">
                                    <?php echo htmlspecialchars($tName . ' (@' . $t['username'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer bg-light py-3">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brand-primary">Save Assignment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- =========================================================================
     HIDDEN CONFIRMATION FORMS
     ========================================================================= -->
<form id="deleteSubjectForm" action="../actions/lms_admin_actions" method="POST" style="display: none;">
    <input type="hidden" name="action" value="delete_subject">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="redirect_to" value="admin/lms_courses">
    <input type="hidden" name="subject_id" id="delete_subject_id">
</form>

<form id="toggleSubjectStatusForm" action="../actions/lms_admin_actions" method="POST" style="display: none;">
    <input type="hidden" name="action" value="toggle_subject_status">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="redirect_to" value="admin/lms_courses">
    <input type="hidden" name="subject_id" id="toggle_subject_id">
</form>

<form id="removeOfferingForm" action="../actions/lms_admin_actions" method="POST" style="display: none;">
    <input type="hidden" name="action" value="delete_section_offering">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="redirect_to" value="admin/lms_courses">
    <input type="hidden" name="section_subject_id" id="remove_section_subject_id">
</form>

<script>
function openAddOfferingModal(subjectId, subjectCode) {
    document.getElementById('offering_subject_id').value = subjectId;
    const modal = new bootstrap.Modal(document.getElementById('addOfferingModal'));
    modal.show();
}

function openEditSubjectModal(sub) {
    document.getElementById('edit_sub_id').value = sub.id;
    document.getElementById('edit_sub_code').value = sub.subject_code;
    document.getElementById('edit_sub_name').value = sub.subject_name;
    document.getElementById('edit_sub_units').value = sub.units;
    document.getElementById('edit_sub_type').value = sub.subject_type;
    document.getElementById('edit_sub_year').value = sub.year_level;
    document.getElementById('edit_sub_sem').value = sub.semester_name;
    document.getElementById('edit_sub_desc').value = sub.description || '';
    document.getElementById('edit_sub_status').value = sub.status || 'active';
    
    const modal = new bootstrap.Modal(document.getElementById('editSubjectModal'));
    modal.show();
}

function openChangeTeacherModal(sectionSubjectId, currentTeacherId, label) {
    document.getElementById('change_ss_id').value = sectionSubjectId;
    document.getElementById('change_offering_label').textContent = label;
    if (currentTeacherId > 0) {
        document.getElementById('change_teacher_select').value = currentTeacherId;
    } else {
        document.getElementById('change_teacher_select').value = '';
    }
    const modal = new bootstrap.Modal(document.getElementById('changeTeacherModal'));
    modal.show();
}

function confirmDeleteSubject(subjectId, subjectCode) {
    const textPrompt = `Are you sure you want to delete subject '${subjectCode}'? Deletion will be blocked if active student enrollments, historical grades, or LMS coursework exist.`;
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Delete Subject?',
            text: textPrompt,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            confirmButtonText: 'Yes, Delete Subject'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('delete_subject_id').value = subjectId;
                document.getElementById('deleteSubjectForm').submit();
            }
        });
    } else {
        if (confirm(textPrompt)) {
            document.getElementById('delete_subject_id').value = subjectId;
            document.getElementById('deleteSubjectForm').submit();
        }
    }
}

function confirmToggleSubjectStatus(subjectId, subjectCode, currentStatus) {
    const newStatus = (currentStatus === 'active') ? 'inactive' : 'active';
    const textPrompt = `Change status of subject '${subjectCode}' to ${newStatus}?`;
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Toggle Subject Status?',
            text: textPrompt,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#0b4f5c',
            confirmButtonText: 'Confirm'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('toggle_subject_id').value = subjectId;
                document.getElementById('toggleSubjectStatusForm').submit();
            }
        });
    } else {
        if (confirm(textPrompt)) {
            document.getElementById('toggle_subject_id').value = subjectId;
            document.getElementById('toggleSubjectStatusForm').submit();
        }
    }
}

function confirmRemoveOffering(sectionSubjectId, subjectCode, sectionName) {
    const textPrompt = `Remove offering of '${subjectCode}' from section '${sectionName}'?`;
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Remove Section Offering?',
            text: textPrompt,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            confirmButtonText: 'Yes, Remove'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('remove_section_subject_id').value = sectionSubjectId;
                document.getElementById('removeOfferingForm').submit();
            }
        });
    } else {
        if (confirm(textPrompt)) {
            document.getElementById('remove_section_subject_id').value = sectionSubjectId;
            document.getElementById('removeOfferingForm').submit();
        }
    }
}

function filterSubjectsTable() {
    const searchVal  = (document.getElementById('subjectSearchInput').value || '').toLowerCase().trim();
    const progVal    = (document.getElementById('programFilter').value || '').toLowerCase().trim();
    const yearVal    = (document.getElementById('yearLevelFilter').value || '').toLowerCase().trim();
    const semVal     = (document.getElementById('semesterFilter').value || '').toLowerCase().trim();

    const rows = document.querySelectorAll('#lmsSubjectsTable tbody tr.lms-subject-row');
    rows.forEach(row => {
        const code     = row.getAttribute('data-code') || '';
        const name     = row.getAttribute('data-name') || '';
        const prog     = row.getAttribute('data-program') || '';
        const year     = row.getAttribute('data-year') || '';
        const sem      = row.getAttribute('data-sem') || '';
        const sections = row.getAttribute('data-sections') || '';

        const matchesSearch = !searchVal || code.includes(searchVal) || name.includes(searchVal) || sections.includes(searchVal);
        const matchesProg   = !progVal || prog === progVal;
        const matchesYear   = !yearVal || year === yearVal;
        const matchesSem    = !semVal || sem === semVal;

        if (matchesSearch && matchesProg && matchesYear && matchesSem) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

function resetSubjectFilters() {
    document.getElementById('subjectSearchInput').value = '';
    document.getElementById('programFilter').value = '';
    document.getElementById('yearLevelFilter').value = '';
    document.getElementById('semesterFilter').value = '';
    filterSubjectsTable();
}
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
