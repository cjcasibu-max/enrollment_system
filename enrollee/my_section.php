<?php
/**
 * My Section — Enrollee Portal
 * Shows the section the enrollee has chosen, with assigned subjects schedule,
 * admission status, and next-step walk-in guidance.
 * Strict NCST Maritime Academy Standard Theme.
 */
require_once '../includes/auth_check.php';
checkRole(['enrollee']);
require_once '../config/database.php';
require_once '../includes/reservation.php';

$userId = (int)$_SESSION['user_id'];
$student = null;
$enrollment = null;
$section = null;
$sectionSubjects = [];
$activeReservation = null;

try {
    $stuStmt = $pdo->prepare("SELECT * FROM students WHERE user_id = :uid LIMIT 1");
    $stuStmt->execute(['uid' => $userId]);
    $student = $stuStmt->fetch();

    if ($student) {
        $activeReservation = getActiveStudentReservation($pdo, (int)$student['id']);
    }
} catch (\Throwable $e) { 
    $student = null; 
}

if (!$student) {
    header('Location: dashboard');
    exit;
}

$appStatus = $student['application_status'] ?? 'draft';
$enrStatus = $student['enrollment_status']  ?? 'draft';

// One-time walk-in document submission notice (pop-up) shown right after slot reservation.
$showWalkInNotice = !empty($_SESSION['walk_in_notice']);
if ($showWalkInNotice) {
    unset($_SESSION['walk_in_notice']);
}

// If not yet section_chosen, redirect appropriately
if (!in_array($appStatus, ['eligible_to_enroll'], true) &&
    !in_array($enrStatus, ['section_chosen','walk_in_ready','paid','enrolled'], true)) {
    header('Location: dashboard');
    exit;
}

// Load the enrollment + section details
try {
    $enrStmt = $pdo->prepare("
        SELECT
            en.id AS enrollment_id,
            en.status AS enrollment_status,
            en.school_year,
            en.semester,
            en.created_at AS enrolled_at,
            se.id AS section_id,
            se.section_name,
            se.schedule,
            se.day_of_week,
            se.start_time,
            se.end_time,
            se.room,
            se.capacity,
            se.year_level AS section_year_level,
            se.program AS section_program,
            c.course_code,
            c.course_name,
            c.units,
            IFNULL(u.first_name,'') AS teacher_first,
            IFNULL(u.last_name,'')  AS teacher_last,
            (SELECT COUNT(*) FROM enrollments e2 WHERE e2.section_id = se.id AND e2.status IN ('pending','approved','enrolled')) AS enrolled_count
        FROM enrollments en
        JOIN sections se ON se.id = en.section_id
        LEFT JOIN courses c ON c.id = se.course_id
        LEFT JOIN users u ON u.id = se.teacher_id
        WHERE en.student_id = :sid
        ORDER BY en.id DESC
        LIMIT 1
    ");
    $enrStmt->execute(['sid' => $student['id']]);
    $enrollment = $enrStmt->fetch();

    // Fallback: If not yet in enrollments table, load from active temporary reservation
    if (!$enrollment) {
        $resStmt = $pdo->prepare("
            SELECT
                NULL AS enrollment_id,
                'reserved' AS enrollment_status,
                at.school_year,
                at.semester,
                sr.reserved_at AS enrolled_at,
                se.id AS section_id,
                se.section_name,
                se.schedule,
                se.day_of_week,
                se.start_time,
                se.end_time,
                se.room,
                se.capacity,
                se.year_level AS section_year_level,
                se.program AS section_program,
                c.course_code,
                c.course_name,
                c.units,
                IFNULL(u.first_name,'') AS teacher_first,
                IFNULL(u.last_name,'')  AS teacher_last,
                (SELECT COUNT(*) FROM enrollments e2 WHERE e2.section_id = se.id AND e2.status IN ('pending','approved','enrolled')) AS enrolled_count
            FROM section_reservations sr
            JOIN sections se ON se.id = sr.section_id
            LEFT JOIN academic_terms at ON at.id = se.academic_term_id
            LEFT JOIN courses c ON c.id = se.course_id
            LEFT JOIN users u ON u.id = se.teacher_id
            WHERE sr.student_id = :sid AND sr.status = 'active' AND sr.expires_at > NOW()
            ORDER BY sr.id DESC
            LIMIT 1
        ");
        $resStmt->execute(['sid' => $student['id']]);
        $enrollment = $resStmt->fetch();
    }

    if ($enrollment && !empty($enrollment['section_id'])) {
        $subStmt = $pdo->prepare("
            SELECT ss.id, ss.day_of_week, ss.start_time, ss.end_time, ss.room,
                   sub.subject_code, sub.subject_name, sub.units,
                   IFNULL(u.username, '') AS teacher_username,
                   IFNULL(u.first_name, '') AS teacher_first,
                   IFNULL(u.last_name, '') AS teacher_last,
                   IFNULL(u.email, '') AS teacher_email
            FROM section_subjects ss
            JOIN subjects sub ON sub.id = ss.subject_id
            LEFT JOIN users u ON u.id = ss.instructor_id
            WHERE ss.section_id = :sec_id
            ORDER BY FIELD(ss.day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'),
                     ss.start_time ASC
        ");
        $subStmt->execute(['sec_id' => $enrollment['section_id']]);
        $sectionSubjects = $subStmt->fetchAll();
    }
} catch (\Throwable $e) { 
    error_log("My Section fetch error: " . $e->getMessage());
    $enrollment = null; 
}

$flashSuccess = $_SESSION['flash_success'] ?? null; unset($_SESSION['flash_success']);
$flashError   = $_SESSION['flash_error']   ?? null; unset($_SESSION['flash_error']);

$page_title = "My Section & Schedule";
require_once '../includes/header.php';
?>

<style>
/* -----------------------------------------------------------------------
   my_section.php — NCST Maritime Academy Standard Styling
   ----------------------------------------------------------------------- */

/* Hero Banner */
.mysection-hero-bar {
    background: linear-gradient(135deg, var(--brand-dark, #064b55) 0%, var(--brand-primary, #0b9b98) 100%);
    border-radius: 16px;
    padding: 1.6rem 1.8rem;
    margin-bottom: 1.5rem;
    color: #fff;
    box-shadow: 0 6px 24px rgba(11, 155, 152, 0.15);
}
.mysection-hero-bar h1 {
    font-size: 1.45rem;
    font-weight: 700;
    margin: 0 0 .3rem;
    color: #fff !important;
}
.mysection-hero-bar p {
    margin: 0;
    font-size: .9rem;
    opacity: .88;
    color: rgba(255,255,255,.92);
}

/* Multi-Step Progress Loading Tracker */
.admission-progress-card {
    background: rgba(0, 0, 0, 0.18);
    border: 1px solid rgba(255, 255, 255, 0.22);
    border-radius: 14px;
    padding: 1.25rem 1.4rem 1rem;
    margin-top: 1.25rem;
    position: relative;
}
.progress-bar-track-wrapper {
    position: absolute;
    top: 2.25rem;
    left: 4rem;
    right: 4rem;
    height: 6px;
    z-index: 1;
}
.progress-bar-base-line {
    position: absolute;
    width: 100%;
    height: 100%;
    background: rgba(255, 255, 255, 0.22);
    border-radius: 999px;
}
.progress-bar-fill-line {
    position: absolute;
    height: 100%;
    background: linear-gradient(90deg, #10b981 0%, #34d399 70%, #ffffff 100%);
    border-radius: 999px;
    box-shadow: 0 0 10px rgba(52, 211, 153, 0.6);
    transition: width 0.6s ease;
}
.progress-milestones-row {
    position: relative;
    z-index: 2;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 0.5rem;
}
.milestone-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    flex: 1;
    min-width: 0;
}
.milestone-circle {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.85rem;
    font-weight: 700;
    margin-bottom: 0.5rem;
    transition: all 0.2s ease;
}
.milestone-item.is-done .milestone-circle {
    background: #10b981;
    color: #ffffff;
    box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.28);
}
.milestone-item.is-current .milestone-circle {
    background: #ffffff;
    color: var(--brand-dark, #064b55);
    box-shadow: 0 0 0 5px rgba(255, 255, 255, 0.35), 0 4px 12px rgba(0,0,0,0.25);
    animation: pulse-ring 2s infinite ease-in-out;
}
.milestone-item.is-pending .milestone-circle {
    background: rgba(255, 255, 255, 0.18);
    color: rgba(255, 255, 255, 0.8);
    border: 2px solid rgba(255, 255, 255, 0.32);
}
@keyframes pulse-ring {
    0%, 100% { box-shadow: 0 0 0 4px rgba(255, 255, 255, 0.35), 0 4px 12px rgba(0,0,0,0.25); }
    50% { box-shadow: 0 0 0 8px rgba(255, 255, 255, 0.5), 0 4px 16px rgba(255,255,255,0.4); }
}
.milestone-text {
    display: flex;
    flex-direction: column;
    align-items: center;
}
.milestone-name {
    font-size: 0.78rem;
    line-height: 1.2;
    margin-bottom: 2px;
    white-space: normal;
    word-break: keep-all;
}
.milestone-item.is-done .milestone-name {
    color: #a7f3d0;
    font-weight: 600;
}
.milestone-item.is-current .milestone-name {
    color: #ffffff;
    font-weight: 700;
    text-shadow: 0 1px 3px rgba(0,0,0,0.4);
}
.milestone-item.is-pending .milestone-name {
    color: rgba(255, 255, 255, 0.75);
    font-weight: 500;
}
.milestone-sub {
    font-size: 0.66rem;
    opacity: 0.85;
    letter-spacing: 0.02em;
}
.milestone-item.is-done .milestone-sub { color: #6ee7b7; }
.milestone-item.is-current .milestone-sub { 
    color: #ffffff; 
    background: rgba(255,255,255,0.22); 
    padding: 1px 7px; 
    border-radius: 999px;
    font-weight: 600;
}
.milestone-item.is-pending .milestone-sub { color: rgba(255, 255, 255, 0.6); }

@media (max-width: 768px) {
    .progress-bar-track-wrapper { left: 2rem; right: 2rem; }
    .milestone-name { font-size: 0.7rem; }
    .milestone-sub { display: none; }
}

/* Info field box */
.info-field-box {
    background: #f8fafc;
    border: 1px solid #e8f0f1;
    border-radius: 12px;
    padding: 14px 16px;
    height: 100%;
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

/* Next Action Cards */
.action-guide-card {
    border-radius: 16px;
    overflow: hidden;
    border: 1px solid #e0eded;
    box-shadow: 0 4px 24px rgba(11,155,152,0.07);
}
.action-guide-card .card-header-accent {
    padding: 18px 20px 14px;
}
.action-guide-card .card-body-content {
    padding: 18px 20px 20px;
    background: #fff;
}
.stage-walk-in .card-header-accent  { background: linear-gradient(135deg, #eaf9f5 0%, #d1fae5 100%); border-bottom: 1px solid #a7f3d0; }
.stage-cashier .card-header-accent  { background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%); border-bottom: 1px solid #fde68a; }
.stage-enrolled .card-header-accent { background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%); border-bottom: 1px solid #6ee7b7; }
.step-icon-circle {
    width: 42px; height: 42px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.1rem; flex-shrink: 0;
}
.step-icon-circle.teal  { background: var(--brand-primary, #0b9b98); color: #fff; }
.step-icon-circle.amber { background: #f59e0b; color: #fff; }
.step-icon-circle.green { background: #10b981; color: #fff; }
.requirement-item {
    display: flex; align-items: flex-start; gap: 10px;
    padding: 10px 12px; background: #f8fafc;
    border: 1px solid #e8f0f1; border-radius: 10px;
    margin-bottom: 8px; font-size: 0.84rem;
}
.requirement-item:last-child { margin-bottom: 0; }
.requirement-item .req-icon {
    width: 24px; height: 24px; border-radius: 50%;
    background: #eaf9f5; border: 1px solid #a7f3d0;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; font-size: 0.7rem; color: var(--brand-primary, #0b9b98);
    font-weight: 700; margin-top: 1px;
}
</style>

<!-- Hero / Page Title Area -->
<div class="mysection-hero-bar">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
        <span class="badge" style="background: rgba(255,255,255,.2); border: 1px solid rgba(255,255,255,.35); color: #fff; font-size: 0.74rem; padding: 5px 12px; border-radius: 50px; font-weight: 600;">
            <i class="bi bi-shield-check me-1"></i> Section Reserved &bull; <?= htmlspecialchars($student['program_code'] ?? 'BSMT') ?>
        </span>
        <?php if ($enrollment): ?>
            <span class="badge bg-light text-navy px-3 py-1.5 fw-bold shadow-sm" style="font-size: 0.8rem; border-radius: 50px;">
                <i class="bi bi-tag-fill me-1 text-brand-primary"></i> <?= htmlspecialchars($enrollment['section_name'] ?: 'Class Section') ?>
            </span>
        <?php endif; ?>
    </div>
    <h1><i class="bi bi-journal-bookmark-fill me-2"></i>My Class Section &amp; Schedule</h1>
    <p>View your registered section details, assigned curriculum timetable, and admission clearance steps.</p>

    <!-- Calculate Dynamic Step States -->
    <?php
    // Progress calculation based on enrollment status
    $isStep1Done = true; // Application approved
    $isStep2Done = $enrollment !== null; // Section chosen
    $isStep3Done = in_array($enrStatus, ['walk_in_ready', 'paid', 'enrolled'], true);
    $isStep4Done = in_array($enrStatus, ['paid', 'enrolled'], true);
    $isStep5Done = $enrStatus === 'enrolled';

    $stepFillPct = 25;
    if ($isStep5Done) {
        $stepFillPct = 100;
    } elseif ($isStep4Done) {
        $stepFillPct = 75;
    } elseif ($isStep3Done) {
        $stepFillPct = 50;
    } elseif ($isStep2Done) {
        $stepFillPct = 25;
    }
    ?>

    <!-- Multi-Step Progress Loading Tracker Bar -->
    <div class="admission-progress-card">
        <div class="progress-bar-track-wrapper">
            <div class="progress-bar-base-line"></div>
            <div class="progress-bar-fill-line" style="width: <?= $stepFillPct ?>%;"></div>
        </div>
        <div class="progress-milestones-row">
            <!-- Step 1: Approved -->
            <div class="milestone-item is-done">
                <div class="milestone-circle">
                    <i class="bi bi-check-lg"></i>
                </div>
                <div class="milestone-text">
                    <span class="milestone-name">Application Approved</span>
                    <span class="milestone-sub">Completed</span>
                </div>
            </div>

            <!-- Step 2: Section Chosen -->
            <div class="milestone-item <?= $isStep2Done ? 'is-done' : 'is-current' ?>">
                <div class="milestone-circle">
                    <?php if ($isStep2Done): ?>
                        <i class="bi bi-check-lg"></i>
                    <?php else: ?>
                        <i class="bi bi-grid-3x3-gap-fill"></i>
                    <?php endif; ?>
                </div>
                <div class="milestone-text">
                    <span class="milestone-name">Section Chosen</span>
                    <span class="milestone-sub"><?= $isStep2Done ? 'Reserved' : 'In Progress' ?></span>
                </div>
            </div>

            <!-- Step 3: Section Reserved -->
            <div class="milestone-item <?= $isStep3Done ? 'is-done' : ($enrStatus === 'section_chosen' ? 'is-current' : 'is-pending') ?>">
                <div class="milestone-circle">
                    <?php if ($isStep3Done): ?>
                        <i class="bi bi-check-lg"></i>
                    <?php else: ?>
                        <span>3</span>
                    <?php endif; ?>
                </div>
                <div class="milestone-text">
                    <span class="milestone-name">Section Reserved</span>
                    <span class="milestone-sub"><?= $isStep3Done ? 'Confirmed' : ($enrStatus === 'section_chosen' ? 'Active Step' : 'Awaiting') ?></span>
                </div>
            </div>

            <!-- Step 4: Cashier Payment -->
            <div class="milestone-item <?= $isStep4Done ? 'is-done' : ($enrStatus === 'walk_in_ready' ? 'is-current' : 'is-pending') ?>">
                <div class="milestone-circle">
                    <?php if ($isStep4Done): ?>
                        <i class="bi bi-check-lg"></i>
                    <?php else: ?>
                        <span>4</span>
                    <?php endif; ?>
                </div>
                <div class="milestone-text">
                    <span class="milestone-name">Cashier Payment</span>
                    <span class="milestone-sub"><?= $isStep4Done ? 'Paid' : ($enrStatus === 'walk_in_ready' ? 'Active Step' : 'Tuition') ?></span>
                </div>
            </div>

            <!-- Step 5: Enrolled -->
            <div class="milestone-item <?= $isStep5Done ? 'is-done' : 'is-pending' ?>">
                <div class="milestone-circle">
                    <?php if ($isStep5Done): ?>
                        <i class="bi bi-check-lg"></i>
                    <?php else: ?>
                        <span>5</span>
                    <?php endif; ?>
                </div>
                <div class="milestone-text">
                    <span class="milestone-name">Enrolled</span>
                    <span class="milestone-sub"><?= $isStep5Done ? 'Official Cadet' : 'Pending' ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($flashSuccess): ?>
    <div class="alert alert-success border-0 rounded-3 mb-3 shadow-sm"><i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($flashSuccess) ?></div>
<?php endif; ?>
<?php if ($flashError): ?>
    <div class="alert alert-danger border-0 rounded-3 mb-3 shadow-sm"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($flashError) ?></div>
<?php endif; ?>

<?php if (!$enrollment): ?>
    <!-- No Section Selected State -->
    <div class="card border-0 shadow-sm rounded-4 p-5 text-center my-4">
        <div class="mb-3 text-muted-light" style="font-size: 3.5rem;">
            <i class="bi bi-calendar-x"></i>
        </div>
        <h4 class="fw-bold text-navy mb-2">No Section Chosen Yet</h4>
        <p class="text-muted mx-auto mb-4" style="max-width: 460px;">
            You have not selected an active class section for this semester. Choose an available section to reserve your slot and proceed with admission.
        </p>
        <div>
            <a href="sections" class="btn btn-brand-primary px-4 py-2 fw-semibold shadow-sm">
                <i class="bi bi-grid-3x3-gap-fill me-1.5"></i> Browse Available Sections
            </a>
        </div>
    </div>
<?php else:
    $sectionLabel  = $enrollment['section_name'] ?: ($enrollment['course_code'] . '-A');
    $enrolledCount = (int)$enrollment['enrolled_count'];
    $capacity      = (int)$enrollment['capacity'];
    $fillPct       = $capacity > 0 ? min(100, round(($enrolledCount / $capacity) * 100)) : 0;
    $barColor      = $fillPct >= 100 ? '#ef4444' : ($fillPct >= 80 ? '#f59e0b' : '#0b9b98');
    
    $totalCredits = 0;
    foreach ($sectionSubjects as $ss) {
        $totalCredits += (float)($ss['units'] ?? 0);
    }
?>

<div class="row g-4">
    <!-- Left Column: Core Section Info & Subjects -->
    <div class="col-12 col-lg-8">
        <!-- Core Section Information Card -->
        <div class="card shadow-sm border-0 mb-4" style="border-radius: 16px; overflow: hidden; border: 1px solid #e0eded !important; box-shadow: 0 4px 24px rgba(11,155,152,0.07) !important;">
            <div class="d-flex justify-content-between align-items-center px-4 py-3" style="background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);">
                <h5 class="m-0 fw-bold text-white d-flex align-items-center gap-2" style="font-size: 1rem;">
                    <i class="bi bi-info-circle"></i> Registered Section Information
                </h5>
                <div class="d-flex align-items-center gap-2">
                    <?php if ($enrStatus === 'section_chosen'): ?>
                        <a href="sections?change=1" class="btn btn-sm btn-light fw-semibold text-navy shadow-sm" style="font-size: 0.75rem; border-radius: 999px; padding: 4px 12px;" title="Browse and switch to another available section">
                            <i class="bi bi-arrow-repeat me-1 text-primary"></i> Switch / Change Section
                        </a>
                    <?php endif; ?>
                    <span class="badge" style="background: rgba(255,255,255,0.22); color: #ffffff !important; border: 1px solid rgba(255,255,255,0.35); border-radius: 999px; padding: 4px 12px; font-size: 0.72rem; font-weight: 700;">
                        <?= htmlspecialchars($sectionLabel) ?>
                    </span>
                </div>
            </div>
            <div class="card-body p-4">
                <div class="row g-3">
                    <div class="col-12 col-sm-6">
                        <div class="info-field-box">
                            <div class="info-field-label">Section Identifier</div>
                            <div class="info-field-val"><?= htmlspecialchars($sectionLabel) ?></div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6">
                        <div class="info-field-box">
                            <div class="info-field-label">Degree Program</div>
                            <div class="info-field-val">
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2.5 py-1 font-monospace">
                                    <?= htmlspecialchars($enrollment['section_program'] ?? $student['program_code'] ?? 'BSMT') ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6">
                        <div class="info-field-box">
                            <div class="info-field-label">Year Level</div>
                            <div class="info-field-val text-dark"><?= htmlspecialchars($enrollment['section_year_level'] ?? $student['year_level'] ?? '1st Year') ?></div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6">
                        <div class="info-field-box">
                            <div class="info-field-label">Academic Term</div>
                            <div class="info-field-val text-dark">AY <?= htmlspecialchars($enrollment['school_year'] ?? '') ?> &bull; <?= htmlspecialchars(ucfirst($enrollment['semester'] ?? '')) ?> Sem</div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6">
                        <div class="info-field-box">
                            <div class="info-field-label">Section Capacity</div>
                            <div class="info-field-val text-dark">
                                <?= $enrolledCount ?> / <?= $capacity ?> cadets enrolled
                                <div class="progress mt-2" style="height: 6px; background-color: #e2e8f0; border-radius: 999px;">
                                    <div class="progress-bar" role="progressbar" style="width: <?= $fillPct ?>%; background-color: <?= $barColor ?>; border-radius: 999px;"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6">
                        <div class="info-field-box">
                            <div class="info-field-label">Date Selected</div>
                            <div class="info-field-val text-dark"><?= date('F j, Y g:i A', strtotime($enrollment['enrolled_at'])) ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Assigned Subjects Timetable Card -->
        <div class="card shadow-sm border-0 mb-4" style="border-radius: 16px; overflow: hidden; border: 1px solid #e0eded !important; box-shadow: 0 4px 24px rgba(11,155,152,0.07) !important;">
            <div class="d-flex justify-content-between align-items-center px-4 py-3" style="background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);">
                <div>
                    <h5 class="m-0 fw-bold text-white d-flex align-items-center gap-2" style="font-size: 1rem;">
                        <i class="bi bi-calendar3-week"></i> Assigned Curriculum Subjects &amp; Timetable
                    </h5>
                    <div style="color: rgba(255,255,255,0.85); font-size: 0.76rem;"><?= count($sectionSubjects) ?> subject course(s) &bull; <?= $totalCredits ?> Total Units</div>
                </div>
                <span class="badge" style="background: rgba(255,255,255,0.22); color: #ffffff !important; border: 1px solid rgba(255,255,255,0.35); border-radius: 999px; padding: 4px 12px; font-weight: 600;">
                    <?= $totalCredits ?> Units Load
                </span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-maritime align-middle m-0">
                        <thead>
                            <tr>
                                <th class="ps-4" style="width: 35%;">Subject / Course</th>
                                <th style="width: 10%;">Credits</th>
                                <th style="width: 15%;">Day</th>
                                <th style="width: 20%;">Schedule Time</th>
                                <th style="width: 10%;">Room</th>
                                <th class="pe-4" style="width: 10%;">Instructor</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($sectionSubjects)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-5 text-muted">
                                        <div class="mb-3 fs-1 text-muted-light"><i class="bi bi-journal-x"></i></div>
                                        <h6 class="fw-bold text-dark">Schedule Being Finalized</h6>
                                        <p class="small text-muted mb-0">Specific class block hours are being configured by the Registrar's Office. Standard curriculum subjects will be credited.</p>
                                    </td>
                                </tr>
                            <?php else: foreach ($sectionSubjects as $ss): 
                                $timeStr = ($ss['start_time'] && $ss['end_time']) 
                                    ? date('g:i A', strtotime($ss['start_time'])) . ' – ' . date('g:i A', strtotime($ss['end_time']))
                                    : 'TBA';
                                $instructorName = ($ss['teacher_first'] || $ss['teacher_last'])
                                    ? trim($ss['teacher_first'] . ' ' . $ss['teacher_last'])
                                    : ($ss['teacher_username'] ?: 'Unassigned');
                            ?>
                                <tr>
                                    <td class="ps-4">
                                        <div class="fw-bold text-navy"><?= htmlspecialchars($ss['subject_code']) ?></div>
                                        <div class="text-muted small"><?= htmlspecialchars($ss['subject_name']) ?></div>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-navy border px-2.5 py-1 font-monospace fw-bold">
                                            <?= htmlspecialchars($ss['units']) ?> Units
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary-subtle text-dark border px-2.5 py-1" style="font-size: 0.74rem;">
                                            <i class="bi bi-calendar3 me-1 text-muted"></i><?= htmlspecialchars($ss['day_of_week'] ?: 'TBA') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-dark" style="font-size: 0.85rem;">
                                            <i class="bi bi-clock me-1 text-brand-primary"></i><?= htmlspecialchars($timeStr) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border px-2 py-1" style="font-size: 0.74rem;">
                                            <?= htmlspecialchars($ss['room'] ?: 'TBA') ?>
                                        </span>
                                    </td>
                                    <td class="pe-4">
                                        <div class="fw-semibold text-dark" style="font-size: 0.84rem;"><?= htmlspecialchars($instructorName) ?></div>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Right Column: Admission Stage Guide & Action -->
    <div class="col-12 col-lg-4">

        <!-- ======================== STAGE: Section Reserved ======================== -->
        <?php if ($enrStatus === 'section_chosen'): ?>
        <div class="action-guide-card stage-walk-in mb-4">
            <div class="card-header-accent d-flex align-items-center gap-3">
                <div class="step-icon-circle teal">
                    <i class="bi bi-clock-history"></i>
                </div>
                <div>
                    <div class="fw-bold text-navy" style="font-size: 0.98rem;">Step 3: Section Slot Reserved</div>
                    <?php if ($activeReservation): ?>
                        <span class="badge bg-warning text-dark border border-warning px-2.5 py-1" style="font-size: 0.7rem; border-radius: 999px; font-weight: 700;">
                            48-Hour Temporary Hold Active
                        </span>
                    <?php else: ?>
                        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle" style="font-size: 0.65rem; border-radius: 999px; padding: 2px 10px; font-weight: 700;">
                            Awaiting Registrar Finalization
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body-content">
                <?php if ($activeReservation): ?>
                    <div class="p-3 rounded-3 border border-warning mb-3" style="background: #fffbeb;">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="small fw-bold text-dark"><i class="bi bi-stopwatch text-warning me-1"></i> Hold Countdown:</span>
                            <span id="myReservationCountdown" class="badge bg-danger text-white font-monospace px-2.5 py-1 fs-6 shadow-sm">--:--:--</span>
                        </div>
                        <div class="small text-muted mb-1">
                            Expires on: <strong><?= date('M d, Y h:i A', strtotime($activeReservation['expires_at'])) ?></strong>
                        </div>
                        <div class="small text-danger fw-semibold" style="line-height:1.4;">
                            ⚠️ Required Action: Complete your walk-in document submission at the Registrar's Office before this timer expires. If not submitted, your slot will automatically be released.
                        </div>
                    </div>
                <?php else: ?>
                    <p class="text-muted small mb-3">
                        Your section slot is reserved. Please proceed with walk-in document submission at the Registrar's Office to finalize your enrollment and subject workload.
                    </p>
                <?php endif; ?>

                <div class="p-3 rounded-3 border border-light-subtle mb-3" style="background: #f8fafc;">
                    <div class="fw-bold text-dark small mb-2"><i class="bi bi-file-earmark-check-fill text-brand-primary me-1"></i> Required Physical Photocopies:</div>
                    <ul class="small text-muted mb-0 ps-3" style="line-height:1.6;">
                        <li>Form 137 / SF-10 (School Record)</li>
                        <li>SHS Diploma / Graduation Certificate</li>
                        <li>Good Moral Certificate</li>
                        <li>PSA Birth Certificate<?php if (($student['civil_status'] ?? '') === 'Married'): ?> & Marriage Cert<?php endif; ?></li>
                        <li>Medical Screening Clearance</li>
                        <li>2x2 ID Photo</li>
                    </ul>
                </div>

                <a href="sections?change=1" class="btn btn-sm btn-outline-secondary w-100 py-2 fw-semibold" style="border-radius: 10px;">
                    <i class="bi bi-arrow-repeat me-1"></i> Change Selected Section
                </a>
            </div>
        </div>

        <!-- ======================== STAGE: Cashier Payment ======================== -->
        <?php elseif ($enrStatus === 'walk_in_ready'): ?>
        <div class="action-guide-card stage-cashier mb-4">
            <div class="card-header-accent d-flex align-items-center gap-3">
                <div class="step-icon-circle amber">
                    <i class="bi bi-cash-coin"></i>
                </div>
                <div>
                    <div class="fw-bold text-dark" style="font-size: 0.98rem;">Step 4: Cashier Payment</div>
                    <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size: 0.65rem; border-radius: 999px; padding: 2px 10px; font-weight: 700;">
                        <i class="bi bi-check2 me-1"></i>Ready for Payment
                    </span>
                </div>
            </div>
            <div class="card-body-content">
                <p class="text-muted small mb-3">
                    Your enrollment is ready for payment. You can pay online, or in person at the Cashier's Office, to pay your enrollment tuition fee and finalize your admission.
                </p>

                <div class="small fw-semibold text-muted text-uppercase mb-1" style="font-size:0.68rem;letter-spacing:.05em;">Pay in person</div>
                <div class="p-3 rounded-3 border border-warning-subtle mb-3" style="background: #fffbeb;">
                    <div class="d-flex align-items-center gap-2 text-dark small fw-semibold mb-1">
                        <i class="bi bi-geo-alt-fill text-warning"></i> Cashier's Office — Admin Hall, Ground Floor
                    </div>
                    <div class="d-flex align-items-center gap-2 text-muted small">
                        <i class="bi bi-clock-fill"></i> Mon–Fri &bull; 8:00 AM – 4:30 PM
                    </div>
                </div>

                <div class="pt-1">
                    <a href="payment" class="btn btn-brand-primary fw-semibold shadow-sm py-2 px-3 w-100 w-md-auto d-inline-flex align-items-center justify-content-center" style="border-radius: 10px;">
                        <i class="bi bi-wallet2 me-2"></i>Pay Online
                    </a>
                </div>
            </div>
        </div>

        <!-- ======================== STAGE: Enrolled / Paid ======================== -->
        <?php elseif (in_array($enrStatus, ['paid','enrolled'])): ?>
        <div class="action-guide-card stage-enrolled mb-4">
            <div class="card-header-accent d-flex align-items-center gap-3">
                <div class="step-icon-circle green">
                    <i class="bi bi-patch-check-fill"></i>
                </div>
                <div>
                    <div class="fw-bold text-navy" style="font-size: 0.98rem;">Official Cadet Enrollment</div>
                    <span class="badge bg-success text-white" style="font-size: 0.65rem; border-radius: 999px; padding: 2px 10px; font-weight: 700;">
                        <i class="bi bi-check2-all me-1"></i>Officially Enrolled
                    </span>
                </div>
            </div>
            <div class="card-body-content">
                <p class="text-muted small mb-3">
                    🎉 Congratulations, <strong><?= htmlspecialchars(trim($student['first_name'] ?? '')) ?: 'Cadet' ?></strong>! Your tuition payment is confirmed and your admission is finalized for this academic term.
                </p>
                <div class="p-3 rounded-3 border border-success-subtle mb-3" style="background: #f0fdf4;">
                    <div class="small text-dark fw-semibold mb-1"><i class="bi bi-star-fill text-warning me-1"></i> Welcome to NCST Maritime Academy!</div>
                    <div class="small text-muted">Access your full student academy portal to view your class schedule, grades, and other services.</div>
                </div>
                <div class="d-grid">
                    <a href="../student/dashboard" class="btn btn-brand-primary fw-semibold shadow-sm py-2" style="border-radius: 10px;">
                        <i class="bi bi-mortarboard-fill me-2"></i>Go to Student Academy Portal
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Support / Contact Card -->
        <div class="card shadow-sm border-0" style="border-radius: 16px; overflow: hidden; border: 1px solid #e0eded !important; box-shadow: 0 4px 24px rgba(11,155,152,0.07) !important;">
            <div class="px-4 py-3" style="background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);">
                <h6 class="m-0 fw-bold text-white d-flex align-items-center gap-2">
                    <i class="bi bi-headset"></i> Enrollment Assistance
                </h6>
            </div>
            <div class="card-body p-4">
                <p class="text-muted small mb-3">
                    Need help or have questions regarding your class schedule, document requirements, or enrollment process?
                </p>
                <div class="d-flex flex-column gap-2 small">
                    <div class="d-flex align-items-center gap-2 text-dark">
                        <i class="bi bi-envelope-fill text-brand-primary"></i>
                        <span>registrar@ncst.edu.ph</span>
                    </div>
                    <div class="d-flex align-items-center gap-2 text-dark">
                        <i class="bi bi-telephone-fill text-brand-primary"></i>
                        <span>+63 (46) 416-0123</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>

<?php if ($showWalkInNotice): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    Swal.fire({
        title: '<span style="font-weight:800;color:#064b55;"><i class="bi bi-clock-history me-1 text-warning"></i> 48-Hour Slot Hold Active</span>',
        html:
            '<div style="text-align:left;font-size:.9rem;color:#10383f;">' +
                '<div class="alert alert-warning py-2 px-3 mb-3 border-warning small fw-bold" style="border-radius:8px;">' +
                    '<i class="bi bi-exclamation-circle-fill me-1"></i> Temporary Hold Policy: Valid for 48 Hours Only' +
                '</div>' +
                '<p class="mb-2" style="line-height:1.5;">Your slot in <strong><?= htmlspecialchars(addslashes($section['section_name'] ?? 'your chosen section')) ?></strong> is temporarily reserved. You must complete an <strong>in-person walk-in submission</strong> of your physical document photocopies to the Registrar&#39;s Office within 48 hours to confirm this enrollment.</p>' +
                '<p class="mb-2 fw-semibold text-dark">Required Physical Photocopies:</p>' +
                '<ul style="padding-left:1.2rem;margin-bottom:.8rem;line-height:1.7;">' +
                    '<li>Form 137 / SF-10 (High School Record)</li>' +
                    '<li>SHS Diploma / Completion Certificate</li>' +
                    '<li>Good Moral Certificate</li>' +
                    '<li>PSA Birth Certificate<?php if (($student['civil_status'] ?? '') === 'Married'): ?> & Marriage Certificate<?php endif; ?></li>' +
                    '<li>Medical Screening Clearance</li>' +
                    '<li>2x2 ID Photo (White Background)</li>' +
                '</ul>' +
                '<p class="mb-0 small text-danger" style="line-height:1.4;"><strong>Warning:</strong> If physical photocopies are not submitted within 48 hours, this temporary slot reservation will automatically expire and release back to other applicants.</p>' +
            '</div>',
        icon: 'info',
        confirmButtonText: 'I Understand My Deadline',
        confirmButtonColor: '#0b9b98',
        allowOutsideClick: false
    });
});
</script>
<?php endif; ?>

<?php if ($activeReservation && (int)($activeReservation['seconds_left'] ?? 0) > 0): ?>
<script>
(function() {
    let secondsLeft = <?= (int)$activeReservation['seconds_left'] ?>;
    const el = document.getElementById('myReservationCountdown');
    if (!el) return;
    function updateTimer() {
        if (secondsLeft <= 0) {
            el.textContent = 'Expired';
            setTimeout(() => window.location.reload(), 2000);
            return;
        }
        const hours = Math.floor(secondsLeft / 3600);
        const minutes = Math.floor((secondsLeft % 3600) / 60);
        const seconds = secondsLeft % 60;
        el.textContent = 
            String(hours).padStart(2, '0') + ':' + 
            String(minutes).padStart(2, '0') + ':' + 
            String(seconds).padStart(2, '0');
        secondsLeft--;
    }
    updateTimer();
    setInterval(updateTimer, 1000);
})();
</script>
<?php endif; ?>
