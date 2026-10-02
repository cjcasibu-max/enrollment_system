<?php
require_once '../includes/auth_check.php';
checkRole(['registrar', 'admin']);
require_once '../config/database.php';
require_once '../includes/academic_terms.php';
require_once '../includes/reservation.php';

$sectionId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($sectionId <= 0) {
    $_SESSION['flash_error'] = "Invalid section selected.";
    header("Location: sections");
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT s.*, c.course_code, c.course_name, c.units, 
               u.username AS teacher_name, u.email AS teacher_email, 
               t.school_year, t.semester 
        FROM sections s 
        LEFT JOIN courses c ON s.course_id = c.id 
        LEFT JOIN academic_terms t ON t.id = s.academic_term_id 
        LEFT JOIN users u ON s.teacher_id = u.id 
        WHERE s.id = :id 
        LIMIT 1
    ");
    $stmt->execute(['id' => $sectionId]);
    $section = $stmt->fetch();
    if (!$section) {
        $_SESSION['flash_error'] = "Section not found.";
        header("Location: sections");
        exit;
    }
} catch (\PDOException $e) {
    error_log("Fetch section detail failed: " . $e->getMessage());
    $_SESSION['flash_error'] = "Database error: Failed to load section.";
    header("Location: sections");
    exit;
}

// Trigger sweep on page load so stale holds don't inflate perceived demand
sweepExpiredReservations($pdo);

// Accurate capacity load via existing getSectionAvailableSlots helper
$slotMetrics = getSectionAvailableSlots($pdo, $sectionId);

try {
    $stmtSubjects = $pdo->prepare("
        SELECT ss.*, sub.subject_code, sub.subject_name, sub.units, sub.year_level, sub.semester_name, 
               u.username AS instructor_name 
        FROM section_subjects ss 
        JOIN subjects sub ON ss.subject_id = sub.id 
        LEFT JOIN users u ON ss.instructor_id = u.id 
        WHERE ss.section_id = :section_id 
        ORDER BY FIELD(ss.day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), ss.start_time ASC
    ");
    $stmtSubjects->execute(['section_id' => $sectionId]);
    $sectionSubjects = $stmtSubjects->fetchAll();
} catch (\PDOException $e) {
    error_log("Fetch section subjects failed: " . $e->getMessage());
    $sectionSubjects = [];
}

try {
    $stmtStudents = $pdo->prepare("
        SELECT e.id AS enrollment_id, e.status AS enrollment_status, e.created_at AS enrolled_at, 
               st.id AS student_id, st.first_name, st.middle_name, st.last_name, st.suffix, 
               st.program_code, st.year_level, st.program_applying_for, 
               u.username, u.email,
               sr.expires_at AS reservation_expires_at,
               sr.status AS reservation_status,
               TIMESTAMPDIFF(SECOND, NOW(), sr.expires_at) AS reservation_seconds_left
        FROM enrollments e 
        JOIN students st ON e.student_id = st.id 
        JOIN users u ON st.user_id = u.id 
        LEFT JOIN section_reservations sr ON sr.student_id = st.id AND sr.section_id = e.section_id AND sr.status = 'active' AND sr.expires_at > NOW()
        WHERE e.section_id = :section_id AND e.status != 'dropped' 
        ORDER BY st.last_name ASC, st.first_name ASC
    ");
    $stmtStudents->execute(['section_id' => $sectionId]);
    $enrolledStudents = $stmtStudents->fetchAll();
} catch (\PDOException $e) {
    error_log("Fetch enrolled students failed: " . $e->getMessage());
    $enrolledStudents = [];
}

// Also fetch active holds from section_reservations that might not have an enrollment row
try {
    $existingStudentIds = array_filter(array_column($enrolledStudents, 'student_id'));
    $exclSql = !empty($existingStudentIds) ? " AND sr.student_id NOT IN (" . implode(',', array_map('intval', $existingStudentIds)) . ")" : "";
    $stmtHolds = $pdo->prepare("
        SELECT NULL AS enrollment_id, 'reserved' AS enrollment_status, sr.reserved_at AS enrolled_at,
               st.id AS student_id, st.first_name, st.middle_name, st.last_name, st.suffix,
               st.program_code, st.year_level, st.program_applying_for,
               u.username, u.email,
               sr.expires_at AS reservation_expires_at,
               sr.status AS reservation_status,
               TIMESTAMPDIFF(SECOND, NOW(), sr.expires_at) AS reservation_seconds_left
        FROM section_reservations sr
        JOIN students st ON sr.student_id = st.id
        JOIN users u ON st.user_id = u.id
        WHERE sr.section_id = :section_id AND sr.status = 'active' AND sr.expires_at > NOW() {$exclSql}
        ORDER BY st.last_name ASC, st.first_name ASC
    ");
    $stmtHolds->execute(['section_id' => $sectionId]);
    $extraHoldStudents = $stmtHolds->fetchAll();
    if (!empty($extraHoldStudents)) {
        $enrolledStudents = array_merge($enrolledStudents, $extraHoldStudents);
    }
} catch (\PDOException $e) {
    error_log("Fetch extra hold students failed: " . $e->getMessage());
}

try {
    $stmtAllSubjects = $pdo->query("SELECT id, subject_code, subject_name, units FROM subjects ORDER BY subject_code ASC");
    $allSubjects = $stmtAllSubjects->fetchAll();
} catch (\PDOException $e) {
    $allSubjects = [];
}

try {
    $stmtTeachers = $pdo->query("SELECT id, username, email FROM users WHERE role = 'teacher' AND is_active = 1 ORDER BY username ASC");
    $teachers = $stmtTeachers->fetchAll();
} catch (\PDOException $e) {
    $teachers = [];
}

$page_title = htmlspecialchars($section['section_name'] ?? ('Section #' . $section['id']));
require_once '../includes/header.php';
?>

<style>
/* Section Details Page Styling — Strict NCST Maritime Academy Standard */
.section-kpi .card-stat {
    transition: transform 0.18s ease, box-shadow 0.18s ease;
}
.section-kpi .card-stat:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 24px rgba(11,155,152,0.12) !important;
}

/* Nav Pills Bar */
.maritime-nav-bar {
    background: #ffffff;
    border: 1px solid #e0eded;
    border-radius: 14px;
    padding: 6px;
    box-shadow: 0 2px 12px rgba(11,155,152,0.04);
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: thin;
}
.maritime-nav-bar .nav-pills {
    flex-wrap: nowrap;
    white-space: nowrap;
}
.maritime-nav-bar .nav-pills .nav-item {
    flex-shrink: 0;
}
.maritime-nav-bar .nav-pills .nav-link {
    color: var(--text-muted, #5a7377);
    font-weight: 600;
    font-size: 0.85rem;
    padding: 8px 18px;
    border-radius: 10px;
    transition: all 0.18s ease;
    border: none;
    white-space: nowrap;
    flex-shrink: 0;
}
.maritime-nav-bar .nav-pills .nav-link:hover {
    color: var(--brand-primary, #0b9b98);
    background: rgba(11, 155, 152, 0.08);
}
.maritime-nav-bar .nav-pills .nav-link.active {
    background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);
    color: #ffffff !important;
    font-weight: 700;
    box-shadow: 0 4px 12px rgba(11, 155, 152, 0.25);
}

/* Core Info Item */
.info-field-box {
    background: #f8fafc;
    border: 1px solid #e8f0f1;
    border-radius: 12px;
    padding: 14px 16px;
    height: 100%;
    transition: background 0.15s ease;
}
.info-field-box:hover {
    background: #f1f7f8;
}
.info-field-label {
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: #64748b;
    margin-bottom: 4px;
}
.info-field-val {
    font-size: 0.95rem;
    font-weight: 700;
    color: var(--brand-dark, #064b55);
}

/* Quick Action Item */
.quick-action-btn {
    background: #ffffff;
    border: 1px solid #e0eded;
    border-radius: 12px;
    padding: 12px 16px;
    width: 100%;
    text-align: left;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    transition: all 0.18s ease;
    text-decoration: none;
}
.quick-action-btn:hover {
    background: #f4fafa;
    border-color: var(--brand-primary, #0b9b98);
    transform: translateY(-2px);
    box-shadow: 0 4px 16px rgba(11, 155, 152, 0.08);
}
.quick-action-btn .qa-icon {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
    flex-shrink: 0;
}

/* Schedule Card */
.schedule-block-card {
    border-left: 4px solid var(--brand-primary, #0b9b98);
    background: #ffffff;
    border-radius: 12px;
    padding: 14px 16px;
    border-top: 1px solid #e8f0f1;
    border-right: 1px solid #e8f0f1;
    border-bottom: 1px solid #e8f0f1;
    transition: all 0.15s ease;
}
.schedule-block-card:hover {
    box-shadow: 0 4px 16px rgba(11, 155, 152, 0.08);
    border-left-color: var(--brand-dark, #064b55);
}
</style>

<!-- =========================================================================
     PAGE HEADING & BREADCRUMBS
     ========================================================================= -->
<div class="page-heading mb-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb small mb-1">
                <li class="breadcrumb-item"><a href="sections" class="text-brand-primary text-decoration-none fw-semibold"><i class="bi bi-grid-3x3-gap me-1"></i>Class Sections</a></li>
                <li class="breadcrumb-item active text-navy fw-bold" aria-current="page"><?php echo htmlspecialchars($section['section_name'] ?? ('Section #' . $section['id'])); ?></li>
            </ol>
        </nav>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <h3 class="m-0 text-navy-alt fw-bold"><?php echo htmlspecialchars($section['section_name'] ?? ('Section #' . $section['id'])); ?></h3>
            <span class="badge <?php echo ($section['status'] ?? 'active') === 'active' ? 'bg-success-subtle text-success border border-success' : 'bg-secondary-subtle text-secondary border border-secondary'; ?> rounded-pill px-2.5 py-1" style="font-size: 0.72rem;">
                <i class="bi <?php echo ($section['status'] ?? 'active') === 'active' ? 'bi-check-circle-fill' : 'bi-pause-circle'; ?> me-1"></i><?php echo ucfirst($section['status'] ?? 'active'); ?>
            </span>
        </div>
        <p class="text-muted small m-0 mt-1">
            <span class="badge bg-light text-navy border px-2 py-0.5"><?php echo htmlspecialchars($section['program'] ?? 'N/A'); ?></span>
            &bull; <span class="badge bg-secondary-subtle text-dark px-2 py-0.5"><?php echo htmlspecialchars($section['year_level'] ?? 'N/A'); ?></span>
            &bull; AY <?php echo htmlspecialchars($section['school_year'] ?? 'N/A'); ?>
            &bull; <?php echo htmlspecialchars(ucfirst($section['semester'] ?? 'N/A')); ?> Semester
        </p>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <a href="sections" class="btn btn-outline-secondary d-flex align-items-center gap-1.5 px-3 py-2 fw-semibold">
            <i class="bi bi-arrow-left"></i> Back to Sections
        </a>
        <button type="button" class="btn btn-brand-primary d-flex align-items-center gap-1.5 shadow-sm px-3.5 py-2 fw-semibold" data-bs-toggle="modal" data-bs-target="#editSectionModal">
            <i class="bi bi-pencil-square"></i> Edit Section
        </button>
    </div>
</div>

<!-- =========================================================================
     KPI SUMMARY CARDS ROW
     ========================================================================= -->
<section class="row g-3 mb-4 section-kpi">
    <!-- Enrolled Students -->
    <div class="col-6 col-md-3">
        <div class="card card-stat stat-primary h-100 shadow-sm">
            <div class="card-body d-flex align-items-center justify-content-between p-3">
                <div>
                    <div class="text-muted small fw-bold text-uppercase" style="letter-spacing:0.05em;font-size:0.7rem;">Enrolled Cadets</div>
                    <div class="fs-2 fw-bold text-darker mt-1"><?php echo (int)$slotMetrics['total_taken']; ?> <span class="fs-6 text-muted fw-normal">/ <?php echo (int)$section['capacity']; ?></span></div>
                    <div class="small text-muted mt-0.5"><?php echo (int)$slotMetrics['enrolled']; ?> enrolled<?php if ($slotMetrics['reserved'] > 0): ?>, <span class="text-warning-emphasis fw-semibold"><?php echo (int)$slotMetrics['reserved']; ?> on hold</span><?php endif; ?></div>
                </div>
                <div class="stat-icon bg-primary-soft">
                    <i class="bi bi-people-fill"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Assigned Subjects -->
    <div class="col-6 col-md-3">
        <div class="card card-stat stat-secondary h-100 shadow-sm">
            <div class="card-body d-flex align-items-center justify-content-between p-3">
                <div>
                    <div class="text-muted small fw-bold text-uppercase" style="letter-spacing:0.05em;font-size:0.7rem;">Assigned Subjects</div>
                    <div class="fs-2 fw-bold text-darker mt-1"><?php echo count($sectionSubjects); ?></div>
                    <div class="small text-muted mt-0.5">Curriculum subjects</div>
                </div>
                <div class="stat-icon bg-secondary-soft">
                    <i class="bi bi-book-fill"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Total Units -->
    <div class="col-6 col-md-3">
        <div class="card card-stat stat-accent h-100 shadow-sm">
            <div class="card-body d-flex align-items-center justify-content-between p-3">
                <div>
                    <div class="text-muted small fw-bold text-uppercase" style="letter-spacing:0.05em;font-size:0.7rem;">Total Credits</div>
                    <div class="fs-2 fw-bold text-darker mt-1"><?php echo array_sum(array_column($sectionSubjects, 'units')); ?> <span class="fs-6 text-muted fw-normal">Units</span></div>
                    <div class="small text-muted mt-0.5">Total credit load</div>
                </div>
                <div class="stat-icon bg-accent-soft">
                    <i class="bi bi-mortarboard-fill"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Scheduled Blocks -->
    <div class="col-6 col-md-3">
        <div class="card card-stat stat-success h-100 shadow-sm">
            <div class="card-body d-flex align-items-center justify-content-between p-3">
                <div>
                    <div class="text-muted small fw-bold text-uppercase" style="letter-spacing:0.05em;font-size:0.7rem;">Active Days</div>
                    <div class="fs-2 fw-bold text-darker mt-1"><?php echo count(array_unique(array_filter(array_column($sectionSubjects, 'day_of_week')))); ?></div>
                    <div class="small text-muted mt-0.5">Weekly schedule blocks</div>
                </div>
                <div class="stat-icon bg-success-soft">
                    <i class="bi bi-calendar-check-fill"></i>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- =========================================================================
     NAV PILLS BAR
     ========================================================================= -->
<div class="maritime-nav-bar mb-4">
    <ul class="nav nav-pills flex-nowrap gap-1" id="sectionDetailTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="overview-tab" data-bs-toggle="tab" data-bs-target="#overview" type="button" role="tab">
                <i class="bi bi-info-circle me-1.5"></i> Section Overview
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="subjects-tab" data-bs-toggle="tab" data-bs-target="#subjects" type="button" role="tab">
                <i class="bi bi-book me-1.5"></i> Assigned Subjects (<?php echo count($sectionSubjects); ?>)
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="schedule-tab" data-bs-toggle="tab" data-bs-target="#schedule" type="button" role="tab">
                <i class="bi bi-calendar-week me-1.5"></i> Weekly Schedule
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="students-tab" data-bs-toggle="tab" data-bs-target="#students" type="button" role="tab">
                <i class="bi bi-people me-1.5"></i> Enrolled Cadets (<?php echo count($enrolledStudents); ?>)
            </button>
        </li>
    </ul>
</div>

<!-- =========================================================================
     TAB CONTENT CONTAINER
     ========================================================================= -->
<div class="tab-content" id="sectionDetailTabContent">

    <!-- =====================================================================
         TAB 1: SECTION OVERVIEW
         ===================================================================== -->
    <div class="tab-pane fade show active" id="overview" role="tabpanel">
        <div class="row g-4">
            <!-- Core Info Grid Card -->
            <div class="col-12 col-lg-8">
                <div class="card shadow-sm border-0 h-100" style="border-radius: 16px; overflow: hidden; border: 1px solid #e0eded !important; box-shadow: 0 4px 24px rgba(11,155,152,0.07) !important;">
                    <div class="d-flex justify-content-between align-items-center px-4 py-3" style="background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);">
                        <h5 class="m-0 fw-bold text-white d-flex align-items-center gap-2" style="font-size: 1rem;">
                            <i class="bi bi-card-heading"></i> Core Section Information
                        </h5>
                        <span class="badge" style="background: rgba(255,255,255,0.22); color: #ffffff !important; border: 1px solid rgba(255,255,255,0.35); border-radius: 999px; padding: 4px 12px; font-size: 0.72rem; font-weight: 700;">
                            ID #<?php echo $section['id']; ?>
                        </span>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-3">
                            <div class="col-12 col-sm-6">
                                <div class="info-field-box">
                                    <div class="info-field-label">Section Identifier</div>
                                    <div class="info-field-val"><?php echo htmlspecialchars($section['section_name'] ?? ('Section #' . $section['id'])); ?></div>
                                </div>
                            </div>
                            <div class="col-12 col-sm-6">
                                <div class="info-field-box">
                                    <div class="info-field-label">Degree Program</div>
                                    <div class="info-field-val">
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2.5 py-1 font-monospace">
                                            <?php echo htmlspecialchars($section['program'] ?? 'N/A'); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-12 col-sm-6">
                                <div class="info-field-box">
                                    <div class="info-field-label">Year Level</div>
                                    <div class="info-field-val text-dark"><?php echo htmlspecialchars($section['year_level'] ?? 'N/A'); ?></div>
                                </div>
                            </div>
                            <div class="col-12 col-sm-6">
                                <div class="info-field-box">
                                    <div class="info-field-label">Academic Term</div>
                                    <div class="info-field-val text-dark">AY <?php echo htmlspecialchars($section['school_year'] ?? 'N/A'); ?> &bull; <?php echo htmlspecialchars(ucfirst($section['semester'] ?? 'N/A')); ?> Sem</div>
                                </div>
                            </div>
                            <div class="col-12 col-sm-6">
                                <div class="info-field-box">
                                    <div class="info-field-label">Cadet Capacity</div>
                                    <div class="info-field-val text-dark">
                                        <?php echo (int)$slotMetrics['total_taken']; ?> / <?php echo (int)$section['capacity']; ?> cadets
                                        <?php if ($slotMetrics['reserved'] > 0): ?>
                                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle ms-1" style="font-size: 0.7rem;">(<?php echo (int)$slotMetrics['reserved']; ?> hold<?php echo $slotMetrics['reserved'] > 1 ? 's' : ''; ?>)</span>
                                        <?php endif; ?>
                                        <?php 
                                        $cap = (int)$section['capacity'];
                                        $fill = (int)$slotMetrics['total_taken'];
                                        $pct = $cap > 0 ? min(100, round(($fill / $cap) * 100)) : 0;
                                        $barColor = $pct >= 100 ? '#ef4444' : ($pct >= 80 ? '#f59e0b' : '#0b9b98');
                                        ?>
                                        <div class="progress mt-2" style="height: 6px; background-color: #e2e8f0; border-radius: 999px;">
                                            <div class="progress-bar" role="progressbar" style="width: <?php echo $pct; ?>%; background-color: <?php echo $barColor; ?>; border-radius: 999px;"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-12 col-sm-6">
                                <div class="info-field-box">
                                    <div class="info-field-label">Status</div>
                                    <div class="info-field-val">
                                        <span class="badge <?php echo ($section['status'] ?? 'active') === 'active' ? 'bg-success-subtle text-success border border-success' : 'bg-secondary-subtle text-secondary border border-secondary'; ?> px-2.5 py-1">
                                            <?php echo ucfirst($section['status'] ?? 'active'); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions Panel -->
            <div class="col-12 col-lg-4">
                <div class="card shadow-sm border-0 h-100" style="border-radius: 16px; overflow: hidden; border: 1px solid #e0eded !important; box-shadow: 0 4px 24px rgba(11,155,152,0.07) !important;">
                    <div class="px-4 py-3" style="background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);">
                        <h5 class="m-0 fw-bold text-white d-flex align-items-center gap-2" style="font-size: 1rem;">
                            <i class="bi bi-lightning-charge-fill"></i> Quick Actions
                        </h5>
                    </div>
                    <div class="card-body p-4 d-flex flex-column gap-3">
                        <button type="button" class="quick-action-btn" data-bs-toggle="modal" data-bs-target="#addSubjectModal">
                            <div class="d-flex align-items-center gap-2.5">
                                <div class="qa-icon bg-primary-soft text-brand-primary"><i class="bi bi-journal-plus"></i></div>
                                <div>
                                    <strong class="d-block text-navy" style="font-size: 0.9rem;">Add Subject</strong>
                                    <small class="text-muted" style="font-size: 0.76rem;">Schedule course class</small>
                                </div>
                            </div>
                            <i class="bi bi-chevron-right text-muted"></i>
                        </button>

                        <button type="button" class="quick-action-btn" onclick="document.getElementById('students-tab').click()">
                            <div class="d-flex align-items-center gap-2.5">
                                <div class="qa-icon bg-secondary-soft text-navy"><i class="bi bi-people"></i></div>
                                <div>
                                    <strong class="d-block text-navy" style="font-size: 0.9rem;">Cadet Class List</strong>
                                    <small class="text-muted" style="font-size: 0.76rem;"><?php echo (int)$slotMetrics['total_taken']; ?> registered / on hold</small>
                                </div>
                            </div>
                            <i class="bi bi-chevron-right text-muted"></i>
                        </button>

                        <button type="button" class="quick-action-btn" data-bs-toggle="modal" data-bs-target="#editSectionModal">
                            <div class="d-flex align-items-center gap-2.5">
                                <div class="qa-icon bg-light text-navy"><i class="bi bi-sliders"></i></div>
                                <div>
                                    <strong class="d-block text-navy" style="font-size: 0.9rem;">Edit Section Details</strong>
                                    <small class="text-muted" style="font-size: 0.76rem;">Capacity, term, and info</small>
                                </div>
                            </div>
                            <i class="bi bi-chevron-right text-muted"></i>
                        </button>

                        <?php if (($section['status'] ?? 'active') === 'active'): ?>
                            <button type="button" class="quick-action-btn mt-auto" onclick="confirmDeactivateSection(<?php echo $section['id']; ?>, '<?php echo htmlspecialchars($section['section_name'] ?? ('Section #' . $section['id']), ENT_QUOTES); ?>')">
                                <div class="d-flex align-items-center gap-2.5">
                                    <div class="qa-icon bg-warning-soft text-warning-dark"><i class="bi bi-pause-circle"></i></div>
                                    <div>
                                        <strong class="d-block text-dark" style="font-size: 0.9rem;">Deactivate Section</strong>
                                        <small class="text-muted" style="font-size: 0.76rem;">Temporarily close registration</small>
                                    </div>
                                </div>
                                <i class="bi bi-shield-lock text-muted"></i>
                            </button>
                        <?php else: ?>
                            <button type="button" class="quick-action-btn mt-auto" onclick="confirmActivateSection(<?php echo $section['id']; ?>, '<?php echo htmlspecialchars($section['section_name'] ?? ('Section #' . $section['id']), ENT_QUOTES); ?>')">
                                <div class="d-flex align-items-center gap-2.5">
                                    <div class="qa-icon bg-success-soft text-success"><i class="bi bi-play-circle"></i></div>
                                    <div>
                                        <strong class="d-block text-success" style="font-size: 0.9rem;">Reactivate Section</strong>
                                        <small class="text-muted" style="font-size: 0.76rem;">Open for class registration</small>
                                    </div>
                                </div>
                                <i class="bi bi-check2-circle text-muted"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- =====================================================================
         TAB 2: ASSIGNED SUBJECTS
         ===================================================================== -->
    <div class="tab-pane fade" id="subjects" role="tabpanel">
        <div class="card shadow-sm border-0 mb-4" style="border-radius: 16px; overflow: hidden; border: 1px solid #e0eded !important; box-shadow: 0 4px 24px rgba(11,155,152,0.07) !important;">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 px-4 py-3" style="background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);">
                <h5 class="m-0 fw-bold text-white d-flex align-items-center gap-2" style="font-size: 1rem;">
                    <i class="bi bi-book-half"></i> Assigned Curriculum Subjects
                </h5>
                <div class="d-flex gap-2">
                    <form action="../actions/section_subject_actions" method="POST" class="d-inline" onsubmit="return confirm('Auto-generate and attach matching curriculum subjects for this section?');">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                        <input type="hidden" name="action" value="generate_from_curriculum">
                        <input type="hidden" name="section_id" value="<?php echo $section['id']; ?>">
                        <button type="submit" class="btn btn-sm d-flex align-items-center gap-1.5" style="background: rgba(255,255,255,0.22); color: #ffffff !important; border: 1px solid rgba(255,255,255,0.35); border-radius: 8px; font-weight: 600;">
                            <i class="bi bi-magic"></i> Auto-Fill Curriculum
                        </button>
                    </form>
                    <button type="button" class="btn btn-sm d-flex align-items-center gap-1.5" data-bs-toggle="modal" data-bs-target="#addSubjectModal" style="background: #ffffff; color: var(--brand-dark, #064b55); font-weight: 700; border-radius: 8px; box-shadow: 0 2px 6px rgba(0,0,0,0.1);">
                        <i class="bi bi-plus-lg"></i> Add Subject
                    </button>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-maritime align-middle m-0" id="sectionSubjectsTable" style="width: 100%;">
                        <thead>
                            <tr>
                                <th class="ps-4" tabulator-field="subject_course">Subject / Course</th>
                                <th tabulator-field="credits">Credits</th>
                                <th tabulator-field="day">Day</th>
                                <th tabulator-field="time">Schedule Time</th>
                                <th tabulator-field="room">Room</th>
                                <th tabulator-field="instructor">Instructor</th>
                                <th class="pe-4 text-end" tabulator-field="actions">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($sectionSubjects)): foreach ($sectionSubjects as $ss): ?>
                                <tr>
                                    <td class="ps-4">
                                        <div class="fw-bold text-navy"><?php echo htmlspecialchars($ss['subject_code']); ?></div>
                                        <div class="text-muted small"><?php echo htmlspecialchars($ss['subject_name']); ?></div>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-navy border px-2.5 py-1 font-monospace fw-bold">
                                            <?php echo htmlspecialchars($ss['units']); ?> Units
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary-subtle text-dark border px-2.5 py-1">
                                            <i class="bi bi-calendar3 me-1 text-muted"></i><?php echo htmlspecialchars($ss['day_of_week']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-dark" style="font-size: 0.86rem;">
                                            <i class="bi bi-clock me-1 text-muted"></i><?php echo htmlspecialchars(date('g:i A', strtotime($ss['start_time']))); ?> – <?php echo htmlspecialchars(date('g:i A', strtotime($ss['end_time']))); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border px-2 py-1">
                                            <i class="bi bi-geo-alt me-1 text-muted"></i><?php echo htmlspecialchars($ss['room'] ?: 'TBA'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($ss['instructor_name']): ?>
                                            <div class="fw-semibold text-dark" style="font-size: 0.86rem;"><?php echo htmlspecialchars($ss['instructor_name']); ?></div>
                                            <div class="text-muted small" style="font-size: 0.72rem;">Faculty Instructor</div>
                                        <?php else: ?>
                                            <span class="text-muted small fst-italic">Unassigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="pe-4 text-end">
                                        <div class="d-inline-flex gap-1.5">
                                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="openEditSubject(<?php echo htmlspecialchars(json_encode($ss)); ?>)" title="Edit schedule">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="confirmSubjectDelete(<?php echo $ss['id']; ?>)" title="Remove subject">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- =====================================================================
         TAB 3: WEEKLY SCHEDULE
         ===================================================================== -->
    <div class="tab-pane fade" id="schedule" role="tabpanel">
        <div class="card shadow-sm border-0 mb-4" style="border-radius: 16px; overflow: hidden; border: 1px solid #e0eded !important; box-shadow: 0 4px 24px rgba(11,155,152,0.07) !important;">
            <div class="px-4 py-3" style="background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);">
                <h5 class="m-0 fw-bold text-white d-flex align-items-center gap-2" style="font-size: 1rem;">
                    <i class="bi bi-calendar3-week"></i> Weekly Class Timetable
                </h5>
            </div>
            <div class="card-body p-4">
                <?php if (empty($sectionSubjects)): ?>
                    <div class="text-center py-5 text-muted">
                        <div class="mb-3 fs-1 text-muted-light"><i class="bi bi-calendar-x"></i></div>
                        <h6 class="fw-bold text-dark">No Scheduled Classes</h6>
                        <p class="small text-muted mb-0">Assign subjects with days and times to populate the weekly schedule view.</p>
                    </div>
                <?php else: 
                    $days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday']; 
                    $grouped = []; 
                    foreach ($sectionSubjects as $ss) { 
                        $grouped[$ss['day_of_week']][] = $ss; 
                    } 
                    foreach ($days as $day): 
                        if (empty($grouped[$day])) continue; 
                ?>
                    <div class="mb-4">
                        <div class="d-flex align-items-center gap-2 mb-3">
                            <span class="badge bg-navy text-white px-3 py-1.5 fw-bold" style="background: var(--brand-dark); font-size: 0.82rem;">
                                <i class="bi bi-calendar-day me-1"></i><?php echo $day; ?>
                            </span>
                            <span class="text-muted small"><?php echo count($grouped[$day]); ?> class block<?php echo count($grouped[$day]) !== 1 ? 's' : ''; ?></span>
                        </div>
                        <div class="row g-3">
                            <?php foreach ($grouped[$day] as $ss): ?>
                                <div class="col-12 col-md-6 col-xl-4">
                                    <div class="schedule-block-card h-100 d-flex flex-column justify-content-between">
                                        <div>
                                            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle fw-bold">
                                                    <?php echo htmlspecialchars($ss['subject_code']); ?>
                                                </span>
                                                <span class="badge bg-light text-dark border font-monospace small">
                                                    <?php echo htmlspecialchars($ss['units']); ?> Units
                                                </span>
                                            </div>
                                            <div class="fw-bold text-dark" style="font-size: 0.92rem;"><?php echo htmlspecialchars($ss['subject_name']); ?></div>
                                            <?php if ($ss['instructor_name']): ?>
                                                <div class="text-muted small mt-1"><i class="bi bi-person me-1"></i><?php echo htmlspecialchars($ss['instructor_name']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="mt-3 pt-2.5 border-top border-light-subtle d-flex justify-content-between align-items-center small text-muted">
                                            <span><i class="bi bi-clock me-1 text-brand-primary"></i><?php echo htmlspecialchars(date('g:i A', strtotime($ss['start_time']))); ?> – <?php echo htmlspecialchars(date('g:i A', strtotime($ss['end_time']))); ?></span>
                                            <span><i class="bi bi-geo-alt me-1 text-muted"></i><?php echo htmlspecialchars($ss['room'] ?: 'TBA'); ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>

    <!-- =====================================================================
         TAB 4: CURRENT CADETS
         ===================================================================== -->
    <div class="tab-pane fade" id="students" role="tabpanel">
        <div class="card shadow-sm border-0 mb-4" style="border-radius: 16px; overflow: hidden; border: 1px solid #e0eded !important; box-shadow: 0 4px 24px rgba(11,155,152,0.07) !important;">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 px-4 py-3" style="background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);">
                <div>
                    <h5 class="m-0 fw-bold text-white d-flex align-items-center gap-2" style="font-size: 1rem;">
                        <i class="bi bi-people-fill"></i> Current Enrolled Cadets
                    </h5>
                    <div style="color: rgba(255,255,255,0.85); font-size: 0.76rem;"><?php echo count($enrolledStudents); ?> cadet(s) registered / on hold in this section</div>
                </div>
                <div class="input-group" style="max-width: 280px;">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" class="form-control border-start-0 ps-0" id="studentSearch" placeholder="Search cadets..." onkeyup="filterStudents()">
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-maritime align-middle m-0" id="studentsTable" style="width: 100%;">
                        <thead>
                            <tr>
                                <th class="ps-4" tabulator-field="cadet">Cadet Name</th>
                                <th tabulator-field="program">Program</th>
                                <th tabulator-field="year">Year Level</th>
                                <th tabulator-field="status">Registration Status</th>
                                <th class="pe-4 text-end" tabulator-field="actions">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($enrolledStudents)): foreach ($enrolledStudents as $st): 
                                $cadetName = htmlspecialchars($st['last_name'] . ', ' . $st['first_name'] . ($st['middle_name'] ? ' ' . $st['middle_name'] : '') . ($st['suffix'] ? ' ' . $st['suffix'] : ''));
                                $initials = strtoupper(substr($st['first_name'] ?? 'C', 0, 1) . substr($st['last_name'] ?? 'D', 0, 1));
                            ?>
                                <tr class="student-row">
                                    <td class="ps-4">
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold shadow-sm" style="width: 36px; height: 36px; font-size: 0.78rem; background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%); flex-shrink: 0;">
                                                <?php echo $initials; ?>
                                            </div>
                                            <div>
                                                <div class="fw-bold text-navy" style="font-size: 0.9rem;"><?php echo $cadetName; ?></div>
                                                <div class="text-muted small">@<?php echo htmlspecialchars($st['username']); ?> &bull; <?php echo htmlspecialchars($st['email']); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-navy border px-2.5 py-1 font-monospace">
                                            <?php echo htmlspecialchars($st['program_code'] ?? $st['program_applying_for'] ?? 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($st['year_level'] ?? 'N/A'); ?></td>
                                    <td>
                                        <?php if (($st['reservation_status'] ?? '') === 'active' && ($st['reservation_seconds_left'] ?? 0) > 0): 
                                            $hrsLeft = max(0.1, round($st['reservation_seconds_left'] / 3600, 1));
                                        ?>
                                            <span class="badge bg-warning text-dark border border-warning px-2.5 py-1 fw-bold" style="font-size: 0.68rem;" title="Temporary 48-Hour Slot Hold">
                                                <i class="bi bi-clock-history me-1"></i>Hold (<?php echo $hrsLeft; ?>h left)
                                            </span>
                                        <?php elseif (($st['enrollment_status'] ?? '') === 'pending'): ?>
                                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning px-2.5 py-1 text-uppercase fw-bold" style="font-size: 0.68rem;">
                                                Pending
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle px-2.5 py-1 text-uppercase fw-bold" style="font-size: 0.68rem;">
                                                <?php echo htmlspecialchars(ucfirst($st['enrollment_status'] ?? 'enrolled')); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="pe-4 text-end">
                                        <div class="d-inline-flex gap-1.5">
                                            <a href="../registrar/students?search=<?php echo urlencode($st['username']); ?>" class="btn btn-sm btn-outline-primary" title="View cadet profile">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <?php if (!empty($st['enrollment_id'])): ?>
                                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="confirmRemoveStudent(<?php echo $st['enrollment_id']; ?>, '<?php echo htmlspecialchars($st['last_name'] . ', ' . $st['first_name'], ENT_QUOTES); ?>')" title="Remove cadet from section">
                                                    <i class="bi bi-person-dash"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- =========================================================================
     MODAL: EDIT SECTION
     ========================================================================= -->
<div class="modal fade" id="editSectionModal" tabindex="-1" aria-labelledby="editSectionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 shadow-lg border-0">
            <div class="modal-header text-white rounded-top-4 py-3" style="background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);">
                <h5 class="modal-title fw-bold text-white d-flex align-items-center gap-2" id="editSectionModalLabel">
                    <i class="bi bi-pencil-square"></i> Edit Section Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="../actions/section_actions" method="POST" class="needs-validation" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="section_id" value="<?php echo $section['id']; ?>">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">Section Name</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($section['section_name'] ?? ('Section #' . $section['id'])); ?>" disabled>
                    </div>
                    <div class="row mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted">Program</label>
                            <input type="text" name="program" class="form-control text-uppercase" value="<?php echo htmlspecialchars($section['program'] ?? ''); ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted">Year Level</label>
                            <select name="year_level" class="form-select">
                                <option value="">-- Select --</option>
                                <?php foreach (['1st Year','2nd Year','3rd Year','4th Year'] as $yl): ?>
                                    <option value="<?php echo $yl; ?>" <?php echo $section['year_level'] === $yl ? 'selected' : ''; ?>><?php echo $yl; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted">Academic Year</label>
                            <input type="text" class="form-control bg-light text-muted" value="<?php echo htmlspecialchars($section['school_year'] ?? 'N/A'); ?>" readonly disabled>
                            <small class="text-muted" style="font-size: 0.72rem;">Linked from Academic Term</small>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted">Semester</label>
                            <input type="text" class="form-control bg-light text-muted" value="<?php echo htmlspecialchars(ucfirst($section['semester'] ?? 'N/A')); ?>" readonly disabled>
                            <small class="text-muted" style="font-size: 0.72rem;">Linked from Academic Term</small>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted">Capacity</label>
                            <input type="number" name="capacity" class="form-control" min="1" max="150" value="<?php echo (int)$section['capacity']; ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted">Status</label>
                            <select name="status" class="form-select">
                                <option value="active" <?php echo ($section['status'] ?? 'active') === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo ($section['status'] ?? 'active') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                    </div>
                    <input type="hidden" name="section_name" value="<?php echo htmlspecialchars($section['section_name'] ?? ('Section #' . $section['id'])); ?>">
                </div>
                <div class="modal-footer p-3 bg-light rounded-bottom-4">
                    <button type="button" class="btn btn-secondary px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brand-primary px-4 fw-semibold shadow-sm">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- =========================================================================
     MODAL: ADD SUBJECT
     ========================================================================= -->
<div class="modal fade" id="addSubjectModal" tabindex="-1" aria-labelledby="addSubjectModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 shadow-lg border-0">
            <div class="modal-header text-white rounded-top-4 py-3" style="background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);">
                <h5 class="modal-title fw-bold text-white d-flex align-items-center gap-2" id="addSubjectModalLabel">
                    <i class="bi bi-journal-plus"></i> Add Subject Schedule
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="../actions/section_subject_actions" method="POST" class="needs-validation" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="section_id" value="<?php echo $section['id']; ?>">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label for="add_subject_id" class="form-label small fw-bold text-muted">Subject <span class="text-danger">*</span></label>
                        <select name="subject_id" id="add_subject_id" class="form-select" required>
                            <option value="" selected disabled>Select Subject...</option>
                            <?php foreach ($allSubjects as $sub): ?>
                                <option value="<?php echo $sub['id']; ?>"><?php echo htmlspecialchars($sub['subject_code'] . ' - ' . $sub['subject_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="invalid-feedback">Please select a subject.</div>
                    </div>
                    <div class="mb-3">
                        <label for="add_day_of_week" class="form-label small fw-bold text-muted">Day of the Week <span class="text-danger">*</span></label>
                        <select name="day_of_week" id="add_day_of_week" class="form-select" required>
                            <option value="" selected disabled>Select Day...</option>
                            <?php foreach (['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'] as $day): ?>
                                <option value="<?php echo $day; ?>"><?php echo $day; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="invalid-feedback">Please select a day.</div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-6">
                            <label for="add_start_time" class="form-label small fw-bold text-muted">Start Time <span class="text-danger">*</span></label>
                            <input type="time" name="start_time" id="add_start_time" class="form-control" required>
                            <div class="invalid-feedback">Start time required.</div>
                        </div>
                        <div class="col-6">
                            <label for="add_end_time" class="form-label small fw-bold text-muted">End Time <span class="text-danger">*</span></label>
                            <input type="time" name="end_time" id="add_end_time" class="form-control" required>
                            <div class="invalid-feedback">End time required.</div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="add_room" class="form-label small fw-bold text-muted">Assigned Room</label>
                        <input type="text" name="room" id="add_room" class="form-control" placeholder="e.g. Room 301 / Nav Lab">
                    </div>
                    <div class="mb-3">
                        <label for="add_instructor_id" class="form-label small fw-bold text-muted">Faculty Instructor</label>
                        <select name="instructor_id" id="add_instructor_id" class="form-select">
                            <option value="">-- Unassigned --</option>
                            <?php foreach ($teachers as $t): ?>
                                <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['username'] . ' (' . $t['email'] . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">Schedule Preview</label>
                        <div id="subjectPreview" class="p-3 rounded-3 border bg-light" style="min-height: 50px;">
                            <div class="text-muted small">Select a subject to preview the schedule entry.</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer p-3 bg-light rounded-bottom-4">
                    <button type="button" class="btn btn-secondary px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brand-primary px-4 fw-semibold shadow-sm">Add Subject</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- =========================================================================
     MODAL: EDIT SUBJECT
     ========================================================================= -->
<div class="modal fade" id="editSubjectModal" tabindex="-1" aria-labelledby="editSubjectModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 shadow-lg border-0">
            <div class="modal-header text-white rounded-top-4 py-3" style="background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);">
                <h5 class="modal-title fw-bold text-white d-flex align-items-center gap-2" id="editSubjectModalLabel">
                    <i class="bi bi-pencil-square"></i> Edit Subject Schedule
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="../actions/section_subject_actions" method="POST" class="needs-validation" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="subject_schedule_id" id="edit_subject_schedule_id">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">Subject</label>
                        <input type="text" class="form-control" id="edit_subject_name" disabled>
                    </div>
                    <div class="mb-3">
                        <label for="edit_day_of_week" class="form-label small fw-bold text-muted">Day <span class="text-danger">*</span></label>
                        <select name="day_of_week" id="edit_day_of_week" class="form-select" required>
                            <option value="" selected disabled>Select Day...</option>
                            <?php foreach (['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'] as $day): ?>
                                <option value="<?php echo $day; ?>"><?php echo $day; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row mb-3">
                        <div class="col-6">
                            <label for="edit_start_time" class="form-label small fw-bold text-muted">Start Time <span class="text-danger">*</span></label>
                            <input type="time" name="start_time" id="edit_start_time" class="form-control" required>
                        </div>
                        <div class="col-6">
                            <label for="edit_end_time" class="form-label small fw-bold text-muted">End Time <span class="text-danger">*</span></label>
                            <input type="time" name="end_time" id="edit_end_time" class="form-control" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="edit_room" class="form-label small fw-bold text-muted">Room</label>
                        <input type="text" name="room" id="edit_room" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label for="edit_instructor_id" class="form-label small fw-bold text-muted">Instructor</label>
                        <select name="instructor_id" id="edit_instructor_id" class="form-select">
                            <option value="">-- Unassigned --</option>
                            <?php foreach ($teachers as $t): ?>
                                <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['username'] . ' (' . $t['email'] . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer p-3 bg-light rounded-bottom-4">
                    <button type="button" class="btn btn-secondary px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brand-primary px-4 fw-semibold shadow-sm">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<form id="removeStudentForm" action="../actions/section_actions" method="POST" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
    <input type="hidden" name="action" value="remove_student">
    <input type="hidden" name="enrollment_id" id="remove_enrollment_id">
</form>

<form id="deactivateSectionForm" action="../actions/section_actions" method="POST" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
    <input type="hidden" name="action" value="deactivate">
    <input type="hidden" name="section_id" id="deactivate_section_id">
</form>

<form id="activateSectionForm" action="../actions/section_actions" method="POST" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
    <input type="hidden" name="action" value="activate">
    <input type="hidden" name="section_id" id="activate_section_id">
</form>

<form id="deleteSubjectForm" action="../actions/section_subject_actions" method="POST" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="subject_schedule_id" id="delete_subject_schedule_id">
</form>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const modals = document.querySelectorAll('.modal');
    modals.forEach(m => document.body.appendChild(m));
    document.body.appendChild(document.getElementById('removeStudentForm'));
    document.body.appendChild(document.getElementById('deactivateSectionForm'));
    document.body.appendChild(document.getElementById('activateSectionForm'));
    document.body.appendChild(document.getElementById('deleteSubjectForm'));

    const addSubjectFields = ['add_subject_id','add_day_of_week','add_start_time','add_end_time','add_room','add_instructor_id'];
    addSubjectFields.forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.addEventListener('input', updateSubjectPreview);
            el.addEventListener('change', updateSubjectPreview);
        }
    });
});

function updateSubjectPreview() {
    const subjectSelect = document.getElementById('add_subject_id');
    const selectedOption = subjectSelect.options[subjectSelect.selectedIndex];
    const subjectText = selectedOption ? selectedOption.text : '';
    const subjectCode = subjectText.split(' - ')[0] || '';
    const subjectName = subjectText.split(' - ').slice(1).join(' - ') || '';
    const day = document.getElementById('add_day_of_week').value;
    const start = document.getElementById('add_start_time').value;
    const end = document.getElementById('add_end_time').value;
    const room = document.getElementById('add_room').value.trim();
    const instructorSelect = document.getElementById('add_instructor_id');
    const instructorText = instructorSelect.options[instructorSelect.selectedIndex]?.text || '';
    const preview = document.getElementById('subjectPreview');
    if (!preview) return;
    if (!subjectText || !day || !start || !end) {
        preview.innerHTML = '<div class="text-muted small">Select a subject and schedule details to preview the entry.</div>';
        return;
    }
    const timeStr = start && end ? start + ' - ' + end : '';
    preview.innerHTML = '<div class="d-flex flex-wrap gap-2 align-items-center">' +
        '<span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1">' + subjectCode.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</span>' +
        '<strong class="text-dark">' + subjectName.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</strong>' +
        (day ? '<span class="badge bg-light text-dark border"><i class="bi bi-calendar-day me-1"></i>' + day + '</span>' : '') +
        (timeStr ? '<span class="badge bg-light text-dark border"><i class="bi bi-clock me-1"></i>' + timeStr + '</span>' : '') +
        (room ? '<span class="badge bg-light text-dark border"><i class="bi bi-geo-alt me-1"></i>' + room.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</span>' : '') +
        (instructorText ? '<span class="text-muted small ms-1"><i class="bi bi-person me-1"></i>' + instructorText.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</span>' : '') +
    '</div>';
}

function openEditSubject(ss) {
    document.getElementById('edit_subject_schedule_id').value = ss.id;
    document.getElementById('edit_subject_name').value = ss.subject_code + ' - ' + ss.subject_name;
    document.getElementById('edit_day_of_week').value = ss.day_of_week;
    document.getElementById('edit_start_time').value = ss.start_time;
    document.getElementById('edit_end_time').value = ss.end_time;
    document.getElementById('edit_room').value = ss.room || '';
    document.getElementById('edit_instructor_id').value = ss.instructor_id || '';
    const modal = new bootstrap.Modal(document.getElementById('editSubjectModal'));
    modal.show();
}

function confirmSubjectDelete(subjectScheduleId) {
    Swal.fire({ 
        title: 'Remove Subject?', 
        text: 'This will remove the subject from this section schedule.', 
        icon: 'question', 
        iconColor: '#d9535f', 
        showCancelButton: true, 
        confirmButtonColor: 'var(--color-danger, #ef4444)', 
        cancelButtonColor: '#6c757d', 
        confirmButtonText: 'Yes, remove it', 
        cancelButtonText: 'Cancel', 
        background: '#ffffff', 
        color: '#1f2937' 
    }).then((result) => { 
        if (result.isConfirmed) { 
            document.getElementById('delete_subject_schedule_id').value = subjectScheduleId; 
            document.getElementById('deleteSubjectForm').submit(); 
        } 
    });
}

function confirmRemoveStudent(enrollmentId, studentName) {
    Swal.fire({ 
        title: 'Remove Student?', 
        text: 'This will remove ' + studentName + ' from this section. Their account will not be deleted.', 
        icon: 'question', 
        iconColor: '#d9535f', 
        showCancelButton: true, 
        confirmButtonColor: 'var(--color-danger, #ef4444)', 
        cancelButtonColor: '#6c757d', 
        confirmButtonText: 'Yes, remove', 
        cancelButtonText: 'Cancel', 
        background: '#ffffff', 
        color: '#1f2937' 
    }).then((result) => { 
        if (result.isConfirmed) { 
            document.getElementById('remove_enrollment_id').value = enrollmentId; 
            document.getElementById('removeStudentForm').submit(); 
        } 
    });
}

function confirmDeactivateSection(sectionId, sectionName) {
    Swal.fire({ 
        title: 'Deactivate Section?', 
        text: 'Are you sure you want to deactivate ' + sectionName + '?', 
        icon: 'question', 
        iconColor: '#d9535f', 
        showCancelButton: true, 
        confirmButtonColor: 'var(--color-danger, #ef4444)', 
        cancelButtonColor: '#6c757d', 
        confirmButtonText: 'Yes, deactivate', 
        cancelButtonText: 'Cancel', 
        background: '#ffffff', 
        color: '#1f2937' 
    }).then((result) => { 
        if (result.isConfirmed) { 
            document.getElementById('deactivate_section_id').value = sectionId; 
            document.getElementById('deactivateSectionForm').submit(); 
        } 
    });
}

function confirmActivateSection(sectionId, sectionName) {
    Swal.fire({ 
        title: 'Reactivate Section?', 
        text: 'Are you sure you want to reactivate ' + sectionName + '?', 
        icon: 'question', 
        iconColor: '#d9535f', 
        showCancelButton: true, 
        confirmButtonColor: 'var(--brand-primary, #0b9b98)', 
        cancelButtonColor: '#6c757d', 
        confirmButtonText: 'Yes, reactivate', 
        cancelButtonText: 'Cancel', 
        background: '#ffffff', 
        color: '#1f2937' 
    }).then((result) => { 
        if (result.isConfirmed) { 
            document.getElementById('activate_section_id').value = sectionId; 
            document.getElementById('activateSectionForm').submit(); 
        } 
    });
}

function stripHtml(html) {
    if (!html) return '';
    var tmp = document.createElement('DIV');
    tmp.innerHTML = html;
    return (tmp.textContent || tmp.innerText || '').trim();
}

let sectionSubjectsTable = null;
let sectionStudentsTable = null;

document.addEventListener("DOMContentLoaded", function() {
    if (typeof Tabulator === 'undefined') return;

    // 1. Section Subjects Table
    sectionSubjectsTable = new Tabulator("#sectionSubjectsTable", {
        layout: "fitColumns",
        responsiveLayout: "collapse",
        responsiveLayoutCollapseStartOpen: false,
        rowHeader: {
            formatter: "responsiveCollapse",
            width: 36,
            minWidth: 36,
            hozAlign: "center",
            resizable: false,
            headerSort: false
        },
        pagination: "local",
        paginationSize: 25,
        paginationSizeSelector: [10, 25, 50, 100],
        placeholder: "<div class='text-center py-5 text-muted'><div class='mb-3 fs-1 text-muted-light'><i class='bi bi-journal-x'></i></div><h6 class='fw-bold text-dark'>No Subjects Assigned Yet</h6><p class='small text-muted mb-0'>Use 'Auto-Fill Curriculum' or 'Add Subject' to schedule classes for this section.</p></div>",
        columns: [
            { title: "Subject / Course", field: "subject_course", widthGrow: 3.2, minWidth: 180, formatter: "html" },
            { title: "Credits", field: "credits", widthGrow: 0.9, minWidth: 75, formatter: "html" },
            { title: "Day", field: "day", widthGrow: 1.1, minWidth: 85, formatter: "html" },
            { title: "Schedule Time", field: "time", widthGrow: 1.8, minWidth: 130, formatter: "html" },
            { title: "Room", field: "room", widthGrow: 0.9, minWidth: 75, formatter: "html" },
            { title: "Instructor", field: "instructor", widthGrow: 1.8, minWidth: 120, formatter: "html" },
            { title: "Actions", field: "actions", widthGrow: 1.0, minWidth: 90, hozAlign: "center", headerSort: false, formatter: "html", responsive: 0 }
        ]
    });

    // 2. Section Students Table
    sectionStudentsTable = new Tabulator("#studentsTable", {
        layout: "fitColumns",
        responsiveLayout: "collapse",
        responsiveLayoutCollapseStartOpen: false,
        rowHeader: {
            formatter: "responsiveCollapse",
            width: 36,
            minWidth: 36,
            hozAlign: "center",
            resizable: false,
            headerSort: false
        },
        pagination: "local",
        paginationSize: 25,
        paginationSizeSelector: [10, 25, 50, 100],
        placeholder: "<div class='text-center py-5 text-muted'><div class='mb-3 fs-1 text-muted-light'><i class='bi bi-people'></i></div><h6 class='fw-bold text-dark'>No Cadets Enrolled Yet</h6><p class='small text-muted mb-0'>Cadets will appear here once they select and are confirmed in this section.</p></div>",
        columns: [
            { title: "Cadet Name", field: "cadet", widthGrow: 3.0, minWidth: 180, formatter: "html" },
            { title: "Program", field: "program", widthGrow: 1.4, minWidth: 110, formatter: "html" },
            { title: "Year Level", field: "year", widthGrow: 1.1, minWidth: 90, formatter: "html" },
            { title: "Registration Status", field: "status", widthGrow: 1.4, minWidth: 120, formatter: "html" },
            { title: "Actions", field: "actions", widthGrow: 1.1, minWidth: 95, hozAlign: "center", headerSort: false, formatter: "html", responsive: 0 }
        ]
    });

    // Redraw tables when their tab pane is activated
    document.querySelectorAll('button[data-bs-toggle="tab"]').forEach(function(tabEl) {
        tabEl.addEventListener('shown.bs.tab', function(event) {
            if (event.target.id === 'subjects-tab' && sectionSubjectsTable) {
                sectionSubjectsTable.redraw(true);
            } else if (event.target.id === 'students-tab' && sectionStudentsTable) {
                sectionStudentsTable.redraw(true);
            }
        });
    });

    // Search filter for students
    var studentSearch = document.getElementById('studentSearch');
    if (studentSearch) {
        studentSearch.addEventListener('input', function() {
            var term = this.value.trim().toLowerCase();
            if (!term) {
                sectionStudentsTable.clearFilter();
            } else {
                sectionStudentsTable.setFilter(function(data) {
                    return stripHtml(data.cadet).toLowerCase().includes(term) ||
                           stripHtml(data.program).toLowerCase().includes(term) ||
                           stripHtml(data.year).toLowerCase().includes(term) ||
                           stripHtml(data.status).toLowerCase().includes(term);
                });
            }
        });
    }
});
</script>
<?php require_once '../includes/footer.php'; ?>
