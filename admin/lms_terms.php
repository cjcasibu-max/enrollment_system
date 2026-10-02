<?php
/**
 * LMS Academic Terms & Semesters Management (TASK 3.1)
 * Administrator portal view for configuring, activating, and archiving
 * academic terms that structure the Learning Management System.
 *
 * Drives:
 *   - Student LMS "My Courses" current-term course visibility
 *   - Teacher LMS subject scoping
 *   - Historical records preservation (grades, submissions, transcripts)
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

// Fetch all terms with real-time LMS statistics
$terms = [];
$error = null;
try {
    $stmt = $pdo->query("
        SELECT t.id, t.school_year, t.semester, t.starts_on, t.ends_on,
               t.enrollment_starts_on, t.enrollment_ends_on, t.registration_deadline,
               t.late_registration_deadline, t.is_enrollment_open, t.max_units,
               t.is_active, COALESCE(t.is_archived, 0) AS is_archived,
               (SELECT COUNT(*) FROM sections sec WHERE sec.academic_term_id = t.id) AS sections_count,
               (SELECT COUNT(ss.id) FROM section_subjects ss JOIN sections s2 ON s2.id = ss.section_id WHERE s2.academic_term_id = t.id) AS offerings_count,
               (SELECT COUNT(DISTINCT e.student_id) FROM enrollments e WHERE e.academic_term_id = t.id AND e.status = 'enrolled') AS enrolled_students_count,
               (SELECT COUNT(DISTINCT COALESCE(ss2.instructor_id, s3.teacher_id)) 
                FROM sections s3 
                LEFT JOIN section_subjects ss2 ON ss2.section_id = s3.id 
                WHERE s3.academic_term_id = t.id AND COALESCE(ss2.instructor_id, s3.teacher_id) IS NOT NULL
               ) AS assigned_teachers_count
        FROM academic_terms t
        ORDER BY t.starts_on DESC, t.id DESC
    ");
    $terms = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("LMS academic terms fetch failed: " . $e->getMessage());
    $error = "Academic terms data is temporarily unavailable.";
}

$activeTerm = null;
$archivedCount = 0;
$totalSectionsInActive = 0;
$totalStudentsInActive = 0;

foreach ($terms as $t) {
    if ((int)$t['is_active'] === 1 && (int)$t['is_archived'] === 0) {
        $activeTerm = $t;
        $totalSectionsInActive = (int)$t['sections_count'];
        $totalStudentsInActive = (int)$t['enrolled_students_count'];
    }
    if ((int)$t['is_archived'] === 1) {
        $archivedCount++;
    }
}
$activeEnrollmentStatus = getEnrollmentPeriodStatus($activeTerm);

$page_title = 'LMS Academic Terms & Semesters — Administrator';
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
            <a href="lms_terms" class="btn btn-sm text-white fw-bold active text-nowrap" style="background:rgba(255,255,255,.2);border-radius:6px;opacity:1;" aria-current="page">
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
            <a href="academic_terms" class="btn btn-sm text-white text-nowrap ms-auto" style="opacity:.85;" onmouseenter="this.style.opacity=1" onmouseleave="this.style.opacity='.85'" title="Full Academic Terms & Enrollment Rules">
                <i class="bi bi-gear-wide-connected me-1"></i>Full Terms Config
            </a>
        </div>
    </div>
</nav>

<!-- ── BREADCRUMB ──────────────────────────────────────────────────────────── -->
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb mb-0" style="font-size:.82rem;">
        <li class="breadcrumb-item"><a href="dashboard" class="text-decoration-none text-brand-primary"><i class="bi bi-shield-shaded me-1"></i>Administrator</a></li>
        <li class="breadcrumb-item active" aria-current="page">LMS Academic Terms & Semesters</li>
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
<?php if ($error): ?>
    <div class="alert alert-danger shadow-sm mb-4">
        <i class="bi bi-x-octagon-fill me-2"></i><?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<!-- ── PAGE HEADER ─────────────────────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
    <div>
        <div class="page-eyebrow text-muted small fw-semibold text-uppercase" style="letter-spacing:.05em;">
            <i class="bi bi-calendar-range me-1"></i>LMS Academic Structure
        </div>
        <h1 class="h3 fw-bold text-navy-alt mb-1">
            <i class="bi bi-calendar3-range me-2 text-brand-primary"></i>Academic Terms & Semesters
        </h1>
        <p class="text-muted small mb-0">
            Configure school years and semesters, designate the active current term for student course access, and close completed terms into read-only archive.
        </p>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <button type="button" class="btn btn-brand-primary shadow-sm d-flex align-items-center gap-1.5" data-bs-toggle="modal" data-bs-target="#createTermModal">
            <i class="bi bi-calendar-plus-fill"></i>
            <span>Add Academic Term</span>
        </button>
    </div>
</div>

<!-- ── ACTIVE CURRENT TERM HERO CARD ──────────────────────────────────────── -->
<div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4" style="border: 1px solid #c2e2e6 !important; background: linear-gradient(135deg, #f0fbfb 0%, #e0f4f5 100%);">
    <div class="card-body p-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-4">
            <div class="d-flex align-items-start gap-3">
                <div class="rounded-circle d-flex align-items-center justify-content-center shadow-sm"
                     style="width: 56px; height: 56px; min-width: 56px; background: var(--brand-primary, #064b55); color: #ffffff; font-size: 1.6rem;">
                    <i class="bi bi-mortarboard-fill"></i>
                </div>
                <div>
                    <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                        <span class="small text-muted fw-bold text-uppercase" style="letter-spacing: 0.5px;">Current LMS Term</span>
                        <?php if ($activeTerm): ?>
                            <span class="badge bg-success text-white px-2 py-0.5 fw-bold">Active Current Term</span>
                        <?php else: ?>
                            <span class="badge bg-danger text-white px-2 py-0.5 fw-bold">No Active Term</span>
                        <?php endif; ?>
                    </div>
                    <h3 class="fw-bold text-navy mb-1">
                        <?php if ($activeTerm): ?>
                            AY <?php echo htmlspecialchars($activeTerm['school_year']); ?> • <?php echo htmlspecialchars(ucfirst($activeTerm['semester'])); ?> Semester
                        <?php else: ?>
                            No Active Academic Term Configured
                        <?php endif; ?>
                    </h3>
                    <div class="text-muted small">
                        <?php if ($activeTerm): ?>
                            <i class="bi bi-calendar-event me-1"></i>Term Dates: 
                            <strong><?php echo htmlspecialchars(($activeTerm['starts_on'] ? date('M j, Y', strtotime($activeTerm['starts_on'])) : 'TBD') . ' – ' . ($activeTerm['ends_on'] ? date('M j, Y', strtotime($activeTerm['ends_on'])) : 'TBD')); ?></strong>
                            &nbsp;•&nbsp; 
                            <i class="bi bi-check2-circle text-success me-1"></i>Determines active courses shown in Cadet <strong>"My Courses"</strong> portal.
                        <?php else: ?>
                            Please activate an academic term below to allow cadet and instructor LMS course access.
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($activeTerm): ?>
                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <div class="bg-white p-3 rounded-3 border border-light-subtle shadow-2xs">
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="text-muted small fw-semibold">Enrollment Window:</span>
                            <?php echo $activeEnrollmentStatus['badge_html']; ?>
                        </div>
                        <div class="small fw-bold text-dark font-monospace">
                            <i class="bi bi-clock-history text-brand-primary me-1"></i><?php echo htmlspecialchars($activeEnrollmentStatus['period_text']); ?>
                        </div>
                        <div class="small text-muted mt-1">
                            Sections Active: <strong><?php echo (int)$totalSectionsInActive; ?></strong> &nbsp;|&nbsp; Enrolled Cadets: <strong><?php echo (int)$totalStudentsInActive; ?></strong>
                        </div>
                    </div>

                    <div class="d-flex flex-column gap-2">
                        <form action="../actions/academic_term_actions" method="POST" class="m-0">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                            <input type="hidden" name="action" value="toggle_enrollment">
                            <input type="hidden" name="term_id" value="<?php echo (int)$activeTerm['id']; ?>">
                            <input type="hidden" name="redirect_to" value="../admin/lms_terms">
                            <?php if ((int)($activeTerm['is_enrollment_open'] ?? 1) === 1): ?>
                                <button type="submit" class="btn btn-outline-danger btn-sm w-100 fw-semibold d-flex align-items-center justify-content-center gap-1.5 shadow-2xs">
                                    <i class="bi bi-door-closed-fill"></i> Close Enrollment
                                </button>
                            <?php else: ?>
                                <button type="submit" class="btn btn-success btn-sm w-100 fw-semibold d-flex align-items-center justify-content-center gap-1.5 shadow-2xs">
                                    <i class="bi bi-door-open-fill"></i> Reopen Enrollment
                                </button>
                            <?php endif; ?>
                        </form>
                        
                        <form action="../actions/academic_term_actions" method="POST" class="m-0" onsubmit="return confirm('Archive and close this current term? It will become read-only and will no longer be marked as current. Students and teachers will retain read-only access to all past courses, submissions, and grades.');">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                            <input type="hidden" name="action" value="archive_term">
                            <input type="hidden" name="term_id" value="<?php echo (int)$activeTerm['id']; ?>">
                            <input type="hidden" name="redirect_to" value="../admin/lms_terms">
                            <button type="submit" class="btn btn-outline-secondary btn-sm w-100 fw-semibold d-flex align-items-center justify-content-center gap-1.5 shadow-2xs">
                                <i class="bi bi-archive-fill"></i> Archive Term
                            </button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ── KPI METRICS ────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="card shadow-sm border-0 rounded-3 h-100 p-3 bg-white">
            <div class="d-flex align-items-center gap-3">
                <div class="rounded-3 p-3 bg-primary-subtle text-primary fs-4">
                    <i class="bi bi-calendar3"></i>
                </div>
                <div>
                    <div class="text-muted small fw-semibold">Configured Terms</div>
                    <div class="h3 fw-bold mb-0 text-navy"><?php echo count($terms); ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="card shadow-sm border-0 rounded-3 h-100 p-3 bg-white">
            <div class="d-flex align-items-center gap-3">
                <div class="rounded-3 p-3 bg-success-subtle text-success fs-4">
                    <i class="bi bi-check-circle-fill"></i>
                </div>
                <div>
                    <div class="text-muted small fw-semibold">Active Current Term</div>
                    <div class="h5 fw-bold mb-0 text-navy">
                        <?php echo $activeTerm ? htmlspecialchars($activeTerm['school_year'] . ' ' . ucfirst($activeTerm['semester'])) : 'None Active'; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="card shadow-sm border-0 rounded-3 h-100 p-3 bg-white">
            <div class="d-flex align-items-center gap-3">
                <div class="rounded-3 p-3 bg-secondary-subtle text-secondary fs-4">
                    <i class="bi bi-archive-fill"></i>
                </div>
                <div>
                    <div class="text-muted small fw-semibold">Archived Terms</div>
                    <div class="h3 fw-bold mb-0 text-navy"><?php echo (int)$archivedCount; ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="card shadow-sm border-0 rounded-3 h-100 p-3 bg-white">
            <div class="d-flex align-items-center gap-3">
                <div class="rounded-3 p-3 bg-info-subtle text-info-emphasis fs-4">
                    <i class="bi bi-people-fill"></i>
                </div>
                <div>
                    <div class="text-muted small fw-semibold">Active Term Cadets</div>
                    <div class="h3 fw-bold mb-0 text-navy"><?php echo (int)$totalStudentsInActive; ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── RETENTION POLICY CALLOUT ───────────────────────────────────────────── -->
<div class="alert alert-info border-info-subtle shadow-sm mb-4 d-flex align-items-center gap-3 rounded-3">
    <div class="fs-3 text-info-emphasis">
        <i class="bi bi-info-circle-fill"></i>
    </div>
    <div class="small">
        <strong class="d-block mb-0.5">LMS Term Architecture & Historical Records Integrity:</strong>
        Setting a term as the <strong>Current Active Term</strong> determines what appears under <em>"My Courses"</em> for enrolled students. 
        When advancing to a new semester or closing a completed term, archiving sets the term to read-only status without breaking historical records: 
        past grades, assignment submissions, quiz records, and official transcripts remain permanently preserved and accessible for reference.
    </div>
</div>

<!-- ── TERMS DIRECTORY TABLE ──────────────────────────────────────────────── -->
<div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-5">
    <div class="card-header bg-white py-3 px-4 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h5 class="m-0 fw-bold text-navy-alt">
            <i class="bi bi-table me-2 text-brand-primary"></i>All Academic Terms & Semesters
        </h5>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-light text-dark border"><?php echo count($terms); ?> Records Configured</span>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size: 0.88rem;">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4" style="width: 140px;">School Year</th>
                        <th style="width: 120px;">Semester</th>
                        <th>Term Dates</th>
                        <th>LMS Structure & Capacity</th>
                        <th>Enrollment Status</th>
                        <th>Term Status</th>
                        <th class="pe-4 text-end" style="width: 220px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!empty($terms)): ?>
                    <?php foreach ($terms as $term): 
                        $isActive = ((int)$term['is_active'] === 1 && (int)$term['is_archived'] === 0);
                        $isArchived = ((int)$term['is_archived'] === 1);
                        $isEnrollOpen = ((int)($term['is_enrollment_open'] ?? 1) === 1);
                        $tStatus = getEnrollmentPeriodStatus($term);
                    ?>
                        <tr class="<?php echo $isActive ? 'table-light' : ($isArchived ? 'bg-light text-muted' : ''); ?>">
                            <td class="ps-4 fw-bold text-navy">
                                <?php echo htmlspecialchars($term['school_year']); ?>
                                <?php if ($isActive): ?>
                                    <span class="badge bg-brand-primary text-white ms-1" style="font-size: 0.65rem;">CURRENT</span>
                                <?php elseif ($isArchived): ?>
                                    <span class="badge bg-secondary text-white ms-1" style="font-size: 0.65rem;">ARCHIVED</span>
                                <?php endif; ?>
                            </td>
                            <td class="fw-semibold">
                                <?php echo htmlspecialchars(ucfirst($term['semester'])); ?> Semester
                            </td>
                            <td>
                                <div class="text-dark small">
                                    <i class="bi bi-calendar-event me-1 text-muted"></i>
                                    <?php echo htmlspecialchars(($term['starts_on'] ? date('M j, Y', strtotime($term['starts_on'])) : 'No start') . ' – ' . ($term['ends_on'] ? date('M j, Y', strtotime($term['ends_on'])) : 'No end')); ?>
                                </div>
                                <div class="text-muted font-monospace mt-0.5" style="font-size: 0.72rem;">
                                    Max Load: <?php echo number_format((float)$term['max_units'], 1); ?> units
                                </div>
                            </td>
                            <td>
                                <div class="d-flex flex-wrap gap-2 small">
                                    <span class="badge bg-light text-dark border" title="Sections created">
                                        <i class="bi bi-grid-3x3 me-1"></i><?php echo (int)$term['sections_count']; ?> Sections
                                    </span>
                                    <span class="badge bg-light text-dark border" title="Course offerings">
                                        <i class="bi bi-journal-text me-1"></i><?php echo (int)$term['offerings_count']; ?> Courses
                                    </span>
                                    <span class="badge bg-light text-dark border" title="Enrolled cadets">
                                        <i class="bi bi-person-check me-1"></i><?php echo (int)$term['enrolled_students_count']; ?> Cadets
                                    </span>
                                    <span class="badge bg-light text-dark border" title="Assigned instructors">
                                        <i class="bi bi-person-badge me-1"></i><?php echo (int)$term['assigned_teachers_count']; ?> Teachers
                                    </span>
                                </div>
                            </td>
                            <td>
                                <?php if ($isArchived): ?>
                                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">
                                        <i class="bi bi-lock-fill me-1"></i>Closed
                                    </span>
                                <?php elseif ($isEnrollOpen): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle">
                                        <i class="bi bi-door-open-fill me-1"></i>Open
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle">
                                        <i class="bi bi-door-closed-fill me-1"></i>Closed
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($isActive): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2.5 py-1 fw-bold">
                                        <i class="bi bi-check-circle-fill me-1"></i>Current LMS Term
                                    </span>
                                <?php elseif ($isArchived): ?>
                                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2.5 py-1 fw-bold">
                                        <i class="bi bi-archive-fill me-1"></i>Archived (Read-Only)
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-light text-muted border px-2.5 py-1 fw-semibold">
                                        Inactive Term
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="pe-4 text-end">
                                <div class="btn-group btn-group-sm">
                                    <!-- Set as Active Current Term -->
                                    <?php if (!$isActive && !$isArchived): ?>
                                        <form action="../actions/academic_term_actions" method="POST" class="d-inline" onsubmit="return confirm('Set AY <?php echo htmlspecialchars($term['school_year']); ?> <?php echo htmlspecialchars(ucfirst($term['semester'])); ?> as the active current term? This will update cadet \'My Courses\' views across the LMS.');">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                                            <input type="hidden" name="action" value="activate">
                                            <input type="hidden" name="term_id" value="<?php echo (int)$term['id']; ?>">
                                            <input type="hidden" name="redirect_to" value="../admin/lms_terms">
                                            <button type="submit" class="btn btn-outline-success btn-sm" title="Set as Current Active Term">
                                                <i class="bi bi-check2-circle me-1"></i>Activate
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <!-- Archive / Unarchive Action -->
                                    <?php if (!$isArchived): ?>
                                        <form action="../actions/academic_term_actions" method="POST" class="d-inline" onsubmit="return confirm('Archive and close AY <?php echo htmlspecialchars($term['school_year']); ?> <?php echo htmlspecialchars(ucfirst($term['semester'])); ?>? Content remains permanently viewable read-only for students and faculty.');">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                                            <input type="hidden" name="action" value="archive_term">
                                            <input type="hidden" name="term_id" value="<?php echo (int)$term['id']; ?>">
                                            <input type="hidden" name="redirect_to" value="../admin/lms_terms">
                                            <button type="submit" class="btn btn-outline-secondary btn-sm" title="Archive / Close Term (Preserve Read-Only)">
                                                <i class="bi bi-archive"></i>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <form action="../actions/academic_term_actions" method="POST" class="d-inline" onsubmit="return confirm('Unarchive AY <?php echo htmlspecialchars($term['school_year']); ?> <?php echo htmlspecialchars(ucfirst($term['semester'])); ?>?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                                            <input type="hidden" name="action" value="unarchive_term">
                                            <input type="hidden" name="term_id" value="<?php echo (int)$term['id']; ?>">
                                            <input type="hidden" name="redirect_to" value="../admin/lms_terms">
                                            <button type="submit" class="btn btn-outline-info btn-sm" title="Unarchive Term">
                                                <i class="bi bi-arrow-counterclockwise"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <!-- Edit Configuration Modal Trigger -->
                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#editTermModal<?php echo (int)$term['id']; ?>" title="Edit Term Configuration">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="text-center py-4 text-muted">
                            <i class="bi bi-calendar-x fs-3 d-block mb-2"></i>
                            No academic terms have been created yet. Click "Add Academic Term" to configure the first term.
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- =========================================================================
     MODAL: CREATE NEW ACADEMIC TERM
     ========================================================================= -->
<div class="modal fade" id="createTermModal" tabindex="-1" aria-labelledby="createTermModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form action="../actions/academic_term_actions" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="redirect_to" value="../admin/lms_terms">

                <div class="modal-header border-bottom py-3" style="background: var(--surface-tint,#f4f8f8);">
                    <h5 class="modal-title fw-bold text-navy" id="createTermModalLabel">
                        <i class="bi bi-calendar-plus me-2 text-brand-primary"></i>Create New Academic Term
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body p-4">
                    <p class="text-muted small mb-3">
                        Define school year and semester parameters to structure the LMS course offerings and student enrollment periods.
                    </p>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-navy">School Year <span class="text-danger">*</span></label>
                            <input type="text" name="school_year" class="form-control font-monospace" placeholder="e.g. 2026-2027" pattern="\d{4}-\d{4}" required>
                            <div class="form-text" style="font-size: 0.72rem;">Format: YYYY-YYYY (e.g. 2026-2027)</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-navy">Semester <span class="text-danger">*</span></label>
                            <select name="semester" class="form-select" required>
                                <option value="1st">1st Semester</option>
                                <option value="2nd">2nd Semester</option>
                                <option value="summer">Summer Term</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-navy">Term Start Date</label>
                            <input type="date" name="starts_on" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-navy">Term End Date</label>
                            <input type="date" name="ends_on" class="form-control">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-navy">Enrollment Starts On</label>
                            <input type="date" name="enrollment_starts_on" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-navy">Enrollment Ends On</label>
                            <input type="date" name="enrollment_ends_on" class="form-control">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-navy">Regular Registration Cutoff</label>
                            <input type="date" name="registration_deadline" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-navy">Late Registration Deadline</label>
                            <input type="date" name="late_registration_deadline" class="form-control">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-navy">Max Cadet Unit Load</label>
                            <input type="number" step="0.5" name="max_units" class="form-control font-monospace" value="24.0" min="1" max="60" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-navy">Enrollment Window State</label>
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" role="switch" id="createEnrollSwitch" name="is_enrollment_open" value="1" checked>
                                <label class="form-check-label small" for="createEnrollSwitch">Enrollment Open immediately</label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer border-top py-2 px-4 bg-light">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brand-primary btn-sm px-3 fw-semibold">
                        <i class="bi bi-plus-circle me-1"></i> Save Academic Term
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- =========================================================================
     MODALS: EDIT ACADEMIC TERMS
     ========================================================================= -->
<?php if (!empty($terms)): foreach ($terms as $term): ?>
    <div class="modal fade" id="editTermModal<?php echo (int)$term['id']; ?>" tabindex="-1" aria-labelledby="editTermLabel<?php echo (int)$term['id']; ?>" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <form action="../actions/academic_term_actions" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="term_id" value="<?php echo (int)$term['id']; ?>">
                    <input type="hidden" name="redirect_to" value="../admin/lms_terms">

                    <div class="modal-header border-bottom py-3" style="background: var(--surface-tint,#f4f8f8);">
                        <h5 class="modal-title fw-bold text-navy" id="editTermLabel<?php echo (int)$term['id']; ?>">
                            <i class="bi bi-pencil-square me-2 text-brand-primary"></i>Edit Term: AY <?php echo htmlspecialchars($term['school_year']); ?> (<?php echo htmlspecialchars(ucfirst($term['semester'])); ?> Sem)
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body p-4">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-navy">School Year <span class="text-danger">*</span></label>
                                <input type="text" name="school_year" class="form-control font-monospace" value="<?php echo htmlspecialchars($term['school_year']); ?>" pattern="\d{4}-\d{4}" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-navy">Semester <span class="text-danger">*</span></label>
                                <select name="semester" class="form-select" required>
                                    <option value="1st" <?php echo $term['semester'] === '1st' ? 'selected' : ''; ?>>1st Semester</option>
                                    <option value="2nd" <?php echo $term['semester'] === '2nd' ? 'selected' : ''; ?>>2nd Semester</option>
                                    <option value="summer" <?php echo $term['semester'] === 'summer' ? 'selected' : ''; ?>>Summer Term</option>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-navy">Term Start Date</label>
                                <input type="date" name="starts_on" class="form-control" value="<?php echo htmlspecialchars($term['starts_on'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-navy">Term End Date</label>
                                <input type="date" name="ends_on" class="form-control" value="<?php echo htmlspecialchars($term['ends_on'] ?? ''); ?>">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-navy">Enrollment Starts On</label>
                                <input type="date" name="enrollment_starts_on" class="form-control" value="<?php echo htmlspecialchars($term['enrollment_starts_on'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-navy">Enrollment Ends On</label>
                                <input type="date" name="enrollment_ends_on" class="form-control" value="<?php echo htmlspecialchars($term['enrollment_ends_on'] ?? ''); ?>">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-navy">Regular Registration Cutoff</label>
                                <input type="date" name="registration_deadline" class="form-control" value="<?php echo htmlspecialchars($term['registration_deadline'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-navy">Late Registration Deadline</label>
                                <input type="date" name="late_registration_deadline" class="form-control" value="<?php echo htmlspecialchars($term['late_registration_deadline'] ?? ''); ?>">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-navy">Max Cadet Unit Load</label>
                                <input type="number" step="0.5" name="max_units" class="form-control font-monospace" value="<?php echo htmlspecialchars((string)$term['max_units']); ?>" min="1" max="60" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-navy">Enrollment Window State</label>
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" role="switch" id="editEnrollSwitch<?php echo (int)$term['id']; ?>" name="is_enrollment_open" value="1" <?php echo (int)($term['is_enrollment_open'] ?? 1) === 1 ? 'checked' : ''; ?>>
                                    <label class="form-check-label small" for="editEnrollSwitch<?php echo (int)$term['id']; ?>">Enrollment Open</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer border-top py-2 px-4 bg-light">
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-brand-primary btn-sm px-3 fw-semibold">
                            <i class="bi bi-save me-1"></i> Update Term
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endforeach; endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
