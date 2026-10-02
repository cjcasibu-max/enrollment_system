<?php
/**
 * Section Browser — Enrollee
 * Accessible only when application_status = 'eligible_to_enroll'
 * Displays available class sections filtered by the enrollee's program/year_level.
 * Includes Section Schedule & Subjects Preview Modal.
 */
require_once '../includes/auth_check.php';
checkRole(['enrollee']);
require_once '../config/database.php';
require_once '../includes/reservation.php';

$userId = (int)$_SESSION['user_id'];

// Load student
try {
    $stuStmt = $pdo->prepare("SELECT * FROM students WHERE user_id = :uid LIMIT 1");
    $stuStmt->execute(['uid' => $userId]);
    $student = $stuStmt->fetch();
} catch (\Throwable $e) {
    $student = null;
}

$activeReservation = $student ? getActiveStudentReservation($pdo, (int)$student['id']) : null;

if (!$student || $student['application_status'] !== 'eligible_to_enroll') {
    // Redirect back with message if not eligible
    $_SESSION['flash_error'] = 'You are not yet eligible to choose a section.';
    header('Location: dashboard');
    exit;
}

// If already chosen, only allow returning here if explicitly requesting to change section
$isChangingSection = (isset($_GET['change']) && $_GET['change'] === '1');
if (in_array($student['enrollment_status'], ['section_chosen'], true) && !$isChangingSection) {
    header('Location: my_section');
    exit;
}
// Students who are walk_in_ready / paid / enrolled cannot change section
if (in_array($student['enrollment_status'], ['walk_in_ready', 'paid', 'enrolled'], true)) {
    $_SESSION['flash_error'] = 'You cannot change your section at this stage of enrollment.';
    header('Location: my_section');
    exit;
}

// Fetch available sections — strictly filtered by student program and year level
try {
    // Resolve student program robustly
    $prog = trim($student['program_code'] ?? '');
    $progApp = trim($student['program_applying_for'] ?? '');
    if (stripos($prog, 'BSMarE') !== false || stripos($prog, 'Marine Engineering') !== false || stripos($progApp, 'Marine Engineering') !== false || stripos($progApp, 'BSMarE') !== false) {
        $studentProgram = 'BSMarE';
    } else {
        $studentProgram = 'BSMT';
    }

    // Resolve student year level
    $yl = trim($student['year_level'] ?? '');
    if ($yl === '') {
        $yl = '1st Year';
    }
    $studentYearLevel = $yl;

    $secQuery = "
        SELECT
            se.id,
            se.section_name,
            se.schedule,
            se.day_of_week,
            se.start_time,
            se.end_time,
            se.room,
            se.capacity,
            se.year_level   AS section_year_level,
            se.program      AS section_program,
            c.course_code,
            c.course_name,
            c.units,
            IFNULL(u.first_name, '') AS teacher_first,
            IFNULL(u.last_name, '')  AS teacher_last,
            (SELECT COUNT(*) FROM enrollments e WHERE e.section_id = se.id AND e.status IN ('pending','approved','enrolled')) AS enrolled_count
        FROM sections se
        LEFT JOIN courses c ON c.id = se.course_id
        LEFT JOIN users u ON u.id = se.teacher_id
        LEFT JOIN academic_terms at ON at.id = se.academic_term_id
        WHERE (at.is_active = 1 OR se.academic_term_id IS NULL)
          AND (se.program = :program OR (se.program IS NULL AND se.section_name LIKE :prog_prefix))
          AND se.year_level = :year_level
          AND se.status = 'active'
        ORDER BY se.section_name ASC
    ";
    $secStmt = $pdo->prepare($secQuery);
    $secStmt->execute([
        'program'     => $studentProgram,
        'prog_prefix' => ($studentProgram === 'BSMarE' ? 'BSMarE%' : 'BSMT%'),
        'year_level'  => $studentYearLevel
    ]);
    $sections = $secStmt->fetchAll();
} catch (\Throwable $e) {
    error_log('Sections page fetch error: ' . $e->getMessage());
    $sections = [];
}

// Fetch all assigned subjects for candidate sections
$sectionSubjectsMap = [];
if (!empty($sections)) {
    $secIds = array_column($sections, 'id');
    $placeholders = implode(',', array_fill(0, count($secIds), '?'));
    try {
        $subStmt = $pdo->prepare("
            SELECT ss.id, ss.section_id, ss.subject_id, ss.day_of_week, ss.start_time, ss.end_time, ss.room,
                   sub.subject_code, sub.subject_name, sub.units,
                   IFNULL(u.username, '') AS teacher_username,
                   IFNULL(u.first_name, '') AS teacher_first,
                   IFNULL(u.last_name, '') AS teacher_last,
                   IFNULL(u.email, '') AS teacher_email
            FROM section_subjects ss
            JOIN subjects sub ON sub.id = ss.subject_id
            LEFT JOIN users u ON u.id = ss.instructor_id
            WHERE ss.section_id IN ($placeholders)
            ORDER BY ss.section_id ASC, 
                     FIELD(ss.day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'),
                     ss.start_time ASC
        ");
        $subStmt->execute($secIds);
        $subs = $subStmt->fetchAll();
        foreach ($subs as $row) {
            $sectionSubjectsMap[$row['section_id']][] = $row;
        }
    } catch (\Throwable $e) {
        error_log('Error fetching enrollee section subjects: ' . $e->getMessage());
    }
}

// Flash message helpers
$flashSuccess = $_SESSION['flash_success'] ?? null; unset($_SESSION['flash_success']);
$flashError   = $_SESSION['flash_error']   ?? null; unset($_SESSION['flash_error']);

$page_title = "Choose Your Section";
require_once '../includes/header.php';
?>

<style>
    /* -----------------------------------------------------------------------
       sections.php — NCST Maritime Academy Standard Section Selection
       ----------------------------------------------------------------------- */

    /* Enhanced Multi-step Progress / Loading Tracker */
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
        .progress-bar-track-wrapper {
            left: 2rem;
            right: 2rem;
        }
        .milestone-name {
            font-size: 0.7rem;
        }
        .milestone-sub {
            display: none;
        }
    }

    /* Hero bar at top of content area */
    .sections-hero-bar {
        background: linear-gradient(135deg, var(--brand-dark, #064b55) 0%, var(--brand-primary, #0b9b98) 100%);
        border-radius: 16px;
        padding: 1.6rem 1.8rem;
        margin-bottom: 1.5rem;
        color: #fff;
        box-shadow: 0 6px 24px rgba(11, 155, 152, 0.15);
    }
    .sections-hero-bar h1 {
        font-size: 1.45rem;
        font-weight: 700;
        margin: 0 0 .3rem;
        color: #fff !important;
    }
    .sections-hero-bar p {
        margin: 0;
        font-size: .9rem;
        opacity: .88;
        color: rgba(255,255,255,.92);
    }
    .badge-eligible-hero {
        display: inline-flex;
        align-items: center;
        gap: .3rem;
        background: rgba(255,255,255,.2);
        border: 1px solid rgba(255,255,255,.35);
        color: #fff;
        font-size: .72rem;
        padding: .3em .85em;
        border-radius: 50px;
        font-weight: 600;
        letter-spacing: .3px;
        margin-bottom: .75rem;
    }

    /* Section cards — light theme */
    .section-card {
        background: #fff;
        border: 1px solid #e0eded;
        border-radius: 14px;
        transition: transform .18s ease, border-color .18s ease, box-shadow .18s ease;
        overflow: hidden;
        height: 100%;
        display: flex;
        flex-direction: column;
        box-shadow: 0 2px 10px rgba(11,155,152,0.04);
    }
    .section-card:hover {
        transform: translateY(-3px);
        border-color: var(--brand-primary, #0b9b98);
        box-shadow: 0 8px 24px rgba(11, 155, 152, .12);
    }
    .section-card .card-header-bar {
        background: #f8fafc;
        border-bottom: 1px solid #e8f0f1;
        padding: .85rem 1.15rem;
    }
    .section-card .course-code {
        font-size: .74rem;
        color: var(--text-muted, #647b80);
        letter-spacing: .5px;
        text-transform: uppercase;
        font-weight: 600;
    }
    .section-card .course-name {
        font-size: 1.05rem;
        font-weight: 700;
        color: var(--brand-dark, #064b55);
    }
    .section-card .card-body-inner {
        padding: 1rem 1.15rem;
        display: flex;
        flex-direction: column;
        gap: .55rem;
        flex-grow: 1;
    }
    .section-card .meta-row {
        font-size: .84rem;
        color: var(--text-dark, #183f45);
        display: flex;
        align-items: center;
        gap: .5rem;
    }
    .section-card .meta-row i {
        color: var(--brand-primary, #0b9b98);
        width: 16px;
        flex-shrink: 0;
    }

    /* Capacity bar */
    .capacity-bar {
        height: 6px;
        border-radius: 6px;
        background: #e2e8f0;
        overflow: hidden;
    }
    .capacity-bar-fill {
        height: 100%;
        border-radius: 6px;
        background: linear-gradient(90deg, var(--brand-primary, #0b9b98), var(--brand-dark, #064b55));
        transition: width .5s ease;
    }
    .capacity-bar-fill.almost-full { background: linear-gradient(90deg, #f59e0b, #d97706); }
    .capacity-bar-fill.full         { background: linear-gradient(90deg, #ef4444, #b91c1c); }
    .capacity-label {
        font-size: .75rem;
        color: var(--text-muted, #647b80);
        display: flex;
        justify-content: space-between;
        margin-bottom: 4px;
    }

    /* Action buttons */
    .btn-preview-schedule {
        background: #ffffff;
        border: 1px solid #b8dfde;
        color: var(--brand-primary, #0b9b98);
        font-weight: 600;
        font-size: .84rem;
        border-radius: 8px;
        padding: .48rem 1rem;
        width: 100%;
        transition: all .18s ease;
    }
    .btn-preview-schedule:hover {
        background: #eaf4f5;
        border-color: var(--brand-primary, #0b9b98);
        color: var(--brand-dark, #064b55);
    }
    .btn-choose {
        background: linear-gradient(135deg, var(--brand-primary, #0b9b98) 0%, var(--brand-dark, #064b55) 100%);
        border: none;
        color: #fff;
        font-weight: 600;
        border-radius: 8px;
        font-size: .85rem;
        padding: .52rem 1.2rem;
        width: 100%;
        transition: opacity .15s, transform .15s, box-shadow .15s;
        box-shadow: 0 4px 12px rgba(11, 92, 96, .2);
    }
    .btn-choose:hover:not(:disabled) {
        opacity: .92;
        transform: translateY(-1px);
        color: #fff;
        box-shadow: 0 6px 18px rgba(11, 92, 96, .25);
    }
    .btn-choose:disabled {
        background: #e2e8f0;
        color: #94a3b8;
        cursor: not-allowed;
        box-shadow: none;
        transform: none;
    }

    /* Search bar */
    .search-wrap { position: relative; }
    .search-wrap .bi-search {
        position: absolute;
        left: .9rem;
        top: 50%;
        transform: translateY(-50%);
        color: var(--text-muted, #647b80);
        font-size: 1rem;
        pointer-events: none;
    }
    .search-wrap input { padding-left: 2.4rem; }

    /* Empty state */
    .empty-state {
        text-align: center;
        padding: 4rem 2rem;
        color: var(--text-muted, #647b80);
    }
    .empty-state i { font-size: 3.5rem; display: block; margin-bottom: 1rem; }
</style>

<!-- Hero / Page Title Area -->
<div class="sections-hero-bar">
    <span class="badge-eligible-hero">
        <i class="bi bi-check-circle-fill"></i> Eligible to Enroll &bull; <?= htmlspecialchars($studentProgram) ?> (<?= htmlspecialchars($studentYearLevel) ?>)
    </span>
    <h1><i class="bi bi-grid-3x3-gap-fill me-2"></i>Choose Your Class Section</h1>
    <p>Review the available class sections and timetable schedules below. You can preview all assigned subjects and class times before making your choice.</p>

    <!-- Multi-Step Progress Tracker Bar -->
    <div class="admission-progress-card">
        <div class="progress-bar-track-wrapper">
            <div class="progress-bar-base-line"></div>
            <div class="progress-bar-fill-line" style="width: 25%;"></div>
        </div>
        <div class="progress-milestones-row">
            <!-- Step 1: Done -->
            <div class="milestone-item is-done">
                <div class="milestone-circle">
                    <i class="bi bi-check-lg"></i>
                </div>
                <div class="milestone-text">
                    <span class="milestone-name">Application Approved</span>
                    <span class="milestone-sub">Completed</span>
                </div>
            </div>

            <!-- Step 2: Active -->
            <div class="milestone-item is-current">
                <div class="milestone-circle">
                    <i class="bi bi-grid-3x3-gap-fill"></i>
                </div>
                <div class="milestone-text">
                    <span class="milestone-name">Choose Section</span>
                    <span class="milestone-sub">Active Step</span>
                </div>
            </div>

            <!-- Step 3: Pending -->
            <div class="milestone-item is-pending">
                <div class="milestone-circle">
                    <span>3</span>
                </div>
                <div class="milestone-text">
                    <span class="milestone-name">Cashier Payment</span>
                    <span class="milestone-sub">Tuition Fee</span>
                </div>
            </div>

            <!-- Step 4: Pending -->
            <div class="milestone-item is-pending">
                <div class="milestone-circle">
                    <span>4</span>
                </div>
                <div class="milestone-text">
                    <span class="milestone-name">Enrolled</span>
                    <span class="milestone-sub">Official Cadet</span>
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

<?php if ($isChangingSection): ?>
<div class="card border-0 shadow-sm mb-4" style="border-radius: 14px; overflow: hidden; border: 1px solid #fde68a !important;">
    <div class="d-flex align-items-center gap-3 px-4 py-3" style="background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);">
        <div class="rounded-circle bg-warning text-white d-flex align-items-center justify-content-center flex-shrink-0" style="width: 40px; height: 40px;">
            <i class="bi bi-arrow-repeat fs-5"></i>
        </div>
        <div class="flex-fill">
            <div class="fw-bold text-dark" style="font-size: 0.92rem;">Changing Your Section</div>
            <div class="text-muted small">Your current section reservation will be <strong>replaced</strong> when you select a new section below. Your admission progress will continue after you confirm the new selection.</div>
        </div>
        <a href="my_section" class="btn btn-sm btn-outline-secondary fw-semibold px-3" style="white-space: nowrap;">
            <i class="bi bi-arrow-left me-1"></i> Keep Current Section
        </a>
    </div>
</div>
<?php endif; ?>

<?php if ($activeReservation): ?>
<div class="card border-0 shadow-sm mb-4" style="border-radius: 14px; overflow: hidden; border: 1px solid #99f6e4 !important; background: linear-gradient(135deg, #f0fdfa 0%, #ccfbf1 100%);">
    <div class="d-flex align-items-center gap-3 px-4 py-3 flex-wrap">
        <div class="rounded-circle text-white d-flex align-items-center justify-content-center flex-shrink-0" style="width: 42px; height: 42px; background: #0b9b98;">
            <i class="bi bi-clock-history fs-5"></i>
        </div>
        <div class="flex-fill">
            <div class="fw-bold text-dark" style="font-size: 0.95rem;">
                Active 48-Hour Temporary Slot Hold: <?= htmlspecialchars($activeReservation['section_name'] ?? 'Section') ?>
            </div>
            <div class="text-muted small">
                Your temporary reservation expires in <strong id="reservationCountdown" class="text-danger font-monospace">--:--:--</strong> (<?= date('M d, Y h:i A', strtotime($activeReservation['expires_at'])) ?>). Selecting another section below will release this hold.
            </div>
        </div>
        <a href="my_section" class="btn btn-sm btn-brand-primary fw-semibold px-3 shadow-sm">
            <i class="bi bi-check-circle me-1"></i> View Reserved Section
        </a>
    </div>
</div>
<?php endif; ?>

<!-- Toolbar -->
<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
    <div class="search-wrap flex-fill" style="max-width:380px;">
        <i class="bi bi-search"></i>
        <input type="text" id="sectionSearch" class="form-control" placeholder="Search by section name, subject, room…" />
    </div>
    <div class="text-muted small fw-semibold">
        <span id="sectionCount" class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1"><?= count($sections) ?></span> section(s) available for your program
    </div>
</div>

<?php if (empty($sections)): ?>
    <div class="empty-state card border-0 shadow-sm rounded-4 p-5">
        <i class="bi bi-calendar-x text-muted-light"></i>
        <h5 class="fw-bold text-dark">No Sections Available Yet</h5>
        <p class="text-muted mb-0">The Registrar has not published class sections for <strong><?= htmlspecialchars($studentProgram) ?> - <?= htmlspecialchars($studentYearLevel) ?></strong> yet.<br>Please check back shortly or visit the Registrar's Office.</p>
    </div>
<?php else: ?>
    <div class="row g-3" id="sectionGrid">
        <?php foreach ($sections as $sec):
            $secId         = (int)$sec['id'];
            $slotInfo      = getSectionAvailableSlots($pdo, $secId, (int)($student['id'] ?? 0));
            $enrolledCount = $slotInfo['enrolled'];
            $reservedCount = $slotInfo['reserved'];
            $totalTaken    = $slotInfo['total_taken'];
            $capacity      = $slotInfo['capacity'];
            $availableSlots= $slotInfo['available_slots'];
            $isFull        = $slotInfo['is_full'];
            $fillPct       = $capacity > 0 ? min(100, round($totalTaken / $capacity * 100)) : 0;
            $fillClass     = $fillPct >= 100 ? 'full' : ($fillPct >= 80 ? 'almost-full' : '');
            $sectionLabel  = $sec['section_name'] ?: ('Section #' . $secId);
            
            // Attached subjects and total units calculation
            $secSubjects   = $sectionSubjectsMap[$secId] ?? [];
            $subjectCount  = count($secSubjects);
            $totalUnits    = 0;
            $scheduledDays = [];
            foreach ($secSubjects as $ss) {
                $totalUnits += (float)($ss['units'] ?? 0);
                if (!empty($ss['day_of_week'])) {
                    $scheduledDays[] = $ss['day_of_week'];
                }
            }
            $uniqueDays = array_unique($scheduledDays);
            $daysDisplay = !empty($uniqueDays) ? implode(', ', $uniqueDays) : 'Schedule Pending';
        ?>
        <div class="col-12 col-md-6 col-lg-4 section-item">
            <div class="section-card">
                <div class="card-header-bar d-flex justify-content-between align-items-center">
                    <div>
                        <div class="course-code"><?= htmlspecialchars($sec['section_program'] ?? $studentProgram) ?> &bull; <?= htmlspecialchars($sec['section_year_level'] ?? $studentYearLevel) ?></div>
                        <div class="course-name"><?= htmlspecialchars($sectionLabel) ?></div>
                    </div>
                    <span class="badge <?= $isFull ? 'bg-danger-subtle text-danger border border-danger' : 'bg-success-subtle text-success border border-success' ?> rounded-pill px-2.5 py-1" style="font-size: 0.72rem;">
                        <?= $isFull ? 'Section Full' : ($availableSlots . ' Slots Left') ?>
                    </span>
                </div>
                <div class="card-body-inner">
                    <!-- Academic Workload Pill -->
                    <div class="d-flex align-items-center gap-1.5 mb-1">
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2.5 py-1 fw-semibold" style="font-size: 0.75rem;">
                            <i class="bi bi-book-half me-1"></i><?= $subjectCount ?> Assigned Subjects
                        </span>
                        <?php if ($totalUnits > 0): ?>
                            <span class="badge bg-light text-navy border px-2 py-1 font-monospace" style="font-size: 0.75rem;">
                                <?= $totalUnits ?> Total Units
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="meta-row">
                        <i class="bi bi-calendar3"></i>
                        <span class="text-truncate"><strong>Days:</strong> <?= htmlspecialchars($daysDisplay) ?></span>
                    </div>
                    <div class="meta-row">
                        <i class="bi bi-geo-alt-fill"></i>
                        <span><strong>Room:</strong> <?= htmlspecialchars($sec['room'] ?: 'Assigned per course') ?></span>
                    </div>

                    <!-- Capacity bar -->
                    <div class="mt-2">
                        <div class="capacity-label">
                            <span class="fw-medium">Class Capacity</span>
                            <span class="fw-bold text-dark"><?= $totalTaken ?> / <?= $capacity ?> cadets (<?= $availableSlots ?> available<?= $reservedCount > 0 ? ", {$reservedCount} held" : "" ?>)</span>
                        </div>
                        <div class="capacity-bar">
                            <div class="capacity-bar-fill <?= $fillClass ?>" style="width:<?= $fillPct ?>%;"></div>
                        </div>
                    </div>

                    <!-- Buttons Group -->
                    <div class="mt-3 pt-2 border-top border-light-subtle d-flex flex-column gap-2">
                        <button type="button" class="btn btn-preview-schedule" onclick="openSectionPreview(<?= $secId ?>)">
                            <i class="bi bi-eye me-1.5"></i> Preview Schedule &amp; Subjects
                        </button>

                        <?php if ($isFull): ?>
                            <button class="btn btn-choose" disabled>
                                <i class="bi bi-x-circle-fill me-1"></i> Section Full
                            </button>
                        <?php else: ?>
                            <button class="btn btn-choose" onclick="confirmChooseSection(<?= $secId ?>, '<?= htmlspecialchars(addslashes($sectionLabel)) ?>')">
                                <i class="bi bi-check-circle-fill me-1"></i> Enroll in this Section
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- =========================================================================
     MODAL: SECTION SCHEDULE & SUBJECTS PREVIEW
     ========================================================================= -->
<div class="modal fade" id="sectionScheduleModal" tabindex="-1" aria-labelledby="sectionScheduleModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content rounded-4 shadow-lg border-0">
            <div class="modal-header text-white rounded-top-4 py-3" style="background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);">
                <div>
                    <h5 class="modal-title fw-bold text-white d-flex align-items-center gap-2 mb-0" id="sectionScheduleModalLabel">
                        <i class="bi bi-calendar3-week"></i> <span id="modalSectionTitle">Section Schedule Preview</span>
                    </h5>
                    <small id="modalSectionSubtitle" style="color: rgba(255,255,255,0.85); font-size: 0.78rem;">Detailed subject timetable and faculty instructors</small>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <!-- Quick Meta Pills Banner -->
                <div class="p-3 rounded-3 bg-light border mb-3 d-flex flex-wrap gap-2 align-items-center justify-content-between" id="modalMetaBanner">
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2.5 py-1 font-monospace" id="modalProgramBadge">BSMT</span>
                        <span class="badge bg-secondary-subtle text-dark border px-2.5 py-1" id="modalYearBadge">1st Year</span>
                        <span class="badge bg-light text-navy border px-2.5 py-1" id="modalUnitsBadge">0 Units</span>
                    </div>
                    <div class="small text-muted fw-semibold" id="modalCapacityText">Capacity: 0 / 0</div>
                </div>

                <!-- Subjects Table / Timetable -->
                <div class="table-responsive" style="max-height: 380px; overflow-y: auto;">
                    <table class="table table-hover align-middle border mb-0">
                        <thead style="background: #f4fafa; color: var(--brand-dark); font-size: 0.74rem; text-transform: uppercase; font-weight: 700; position: sticky; top: 0; z-index: 1;">
                            <tr>
                                <th class="ps-3" style="width: 35%;">Subject / Course</th>
                                <th style="width: 10%;">Units</th>
                                <th style="width: 15%;">Day</th>
                                <th style="width: 20%;">Time</th>
                                <th style="width: 10%;">Room</th>
                                <th class="pe-3" style="width: 10%;">Instructor</th>
                            </tr>
                        </thead>
                        <tbody id="modalSubjectsBody">
                            <!-- Populated dynamically via JS -->
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer p-3 bg-light rounded-bottom-4 d-flex justify-content-between align-items-center">
                <button type="button" class="btn btn-secondary px-3" data-bs-dismiss="modal">Close</button>
                <div id="modalActionContainer">
                    <!-- Populated dynamically via JS -->
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Confirmation Form (hidden) -->
<form id="chooseSectionForm" method="POST" action="../actions/walk_in_actions" style="display:none;">
    <input type="hidden" name="action" value="choose_section" />
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>" />
    <input type="hidden" name="section_id" id="chooseSectionId" value="" />
    <input type="hidden" name="is_changing" value="<?= $isChangingSection ? '1' : '0' ?>" />
</form>

<script>
// JSON lookup data of all sections and their assigned subjects
const sectionsData = <?= json_encode($sections, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const sectionSubjectsData = <?= json_encode($sectionSubjectsMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

function formatTime(timeStr) {
    if (!timeStr) return '';
    const parts = timeStr.split(':');
    if (parts.length < 2) return timeStr;
    let h = parseInt(parts[0], 10);
    const m = parts[1];
    const ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12;
    h = h ? h : 12;
    return `${h}:${m} ${ampm}`;
}

function openSectionPreview(sectionId) {
    const sec = sectionsData.find(s => parseInt(s.id, 10) === parseInt(sectionId, 10));
    if (!sec) return;

    const subjects = sectionSubjectsData[sectionId] || [];
    const secLabel = sec.section_name || ('Section #' + sec.id);

    // Populate modal headers and badges
    document.getElementById('modalSectionTitle').textContent = secLabel;
    document.getElementById('modalProgramBadge').textContent = sec.section_program || '<?= htmlspecialchars($studentProgram) ?>';
    document.getElementById('modalYearBadge').textContent = sec.section_year_level || '<?= htmlspecialchars($studentYearLevel) ?>';
    
    let totalCredits = 0;
    subjects.forEach(sub => {
        totalCredits += parseFloat(sub.units || 0);
    });
    document.getElementById('modalUnitsBadge').textContent = totalCredits + ' Total Units';
    document.getElementById('modalCapacityText').textContent = `Enrolled: ${sec.enrolled_count} / ${sec.capacity} cadets`;

    // Populate subjects table
    const tbody = document.getElementById('modalSubjectsBody');
    if (subjects.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="6" class="text-center py-4 text-muted">
                    <i class="bi bi-info-circle fs-3 d-block mb-1 text-muted-light"></i>
                    <strong>No specific timetable assigned yet.</strong><br>
                    <span class="small">Standard curriculum courses for ${sec.section_program || '<?= htmlspecialchars($studentProgram) ?>'} will be registered upon confirmation.</span>
                </td>
            </tr>
        `;
    } else {
        let html = '';
        subjects.forEach(sub => {
            const timeRange = (sub.start_time && sub.end_time) 
                ? `${formatTime(sub.start_time)} – ${formatTime(sub.end_time)}`
                : 'TBA';
            const teacher = (sub.teacher_first || sub.teacher_last)
                ? `${sub.teacher_first} ${sub.teacher_last}`.trim()
                : (sub.teacher_username || 'Unassigned');

            html += `
                <tr>
                    <td class="ps-3">
                        <div class="fw-bold text-navy" style="font-size: 0.88rem;">${escapeHtml(sub.subject_code)}</div>
                        <div class="text-muted small" style="font-size: 0.78rem;">${escapeHtml(sub.subject_name)}</div>
                    </td>
                    <td>
                        <span class="badge bg-light text-navy border px-2 py-1 font-monospace">${escapeHtml(sub.units || '0')}</span>
                    </td>
                    <td>
                        <span class="badge bg-secondary-subtle text-dark border px-2 py-1" style="font-size: 0.74rem;">
                            ${escapeHtml(sub.day_of_week || 'TBA')}
                        </span>
                    </td>
                    <td>
                        <div class="fw-semibold text-dark" style="font-size: 0.82rem;">
                            <i class="bi bi-clock me-1 text-brand-primary"></i>${escapeHtml(timeRange)}
                        </div>
                    </td>
                    <td>
                        <span class="badge bg-light text-dark border px-2 py-1" style="font-size: 0.74rem;">
                            ${escapeHtml(sub.room || 'TBA')}
                        </span>
                    </td>
                    <td class="pe-3">
                        <div class="fw-semibold text-dark" style="font-size: 0.82rem;">${escapeHtml(teacher)}</div>
                    </td>
                </tr>
            `;
        });
        tbody.innerHTML = html;
    }

    // Modal action button
    const actionContainer = document.getElementById('modalActionContainer');
    const isFull = parseInt(sec.capacity, 10) > 0 && parseInt(sec.enrolled_count, 10) >= parseInt(sec.capacity, 10);
    if (isFull) {
        actionContainer.innerHTML = `
            <button class="btn btn-secondary px-4 fw-semibold" disabled>
                <i class="bi bi-x-circle-fill me-1"></i> Section Full
            </button>
        `;
    } else {
        actionContainer.innerHTML = `
            <button type="button" class="btn btn-brand-primary px-4 fw-bold shadow-sm" onclick="confirmChooseSection(${sec.id}, '${escapeHtml(secLabel)}')">
                <i class="bi bi-check-circle-fill me-1"></i> Enroll in this Section
            </button>
        `;
    }

    const modal = new bootstrap.Modal(document.getElementById('sectionScheduleModal'));
    modal.show();
}

function escapeHtml(text) {
    if (!text) return '';
    return String(text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

const IS_CHANGING_SECTION = <?= $isChangingSection ? 'true' : 'false' ?>;

function confirmChooseSection(sectionId, sectionLabel) {
    // Hide preview modal if open
    const modalEl = document.getElementById('sectionScheduleModal');
    const modalInstance = bootstrap.Modal.getInstance(modalEl);
    if (modalInstance) modalInstance.hide();

    const changeNote = IS_CHANGING_SECTION
        ? `<div style="background:#fff8e7;border:1px solid #fde68a;border-radius:10px;padding:10px 14px;margin-top:10px;font-size:.83rem;color:#92400e;">
               <i class="bi bi-arrow-repeat me-1"></i>
               <strong>Note:</strong> Your existing section reservation will be <strong>replaced</strong> with the new one.
           </div>`
        : `<p class="mt-2 mb-0" style="font-size:.85rem;color:var(--text-muted,#647b80);">After choosing this section, please proceed to the Registrar's Office for walk-in document validation.</p>`;

    Swal.fire({
        title: IS_CHANGING_SECTION ? 'Change Section Selection' : 'Confirm Section Selection',
        html: `<p class="mb-2">${IS_CHANGING_SECTION ? 'You are <strong>changing</strong> your class section to:' : 'You are selecting the following class section:'}</p>
               <div style="background:#f8fafc;border:1px solid #e0eded;border-radius:12px;padding:1.1rem;margin:.5rem 0;">
                 <strong style="font-size:1.15rem;color:var(--brand-dark,#064b55);">${sectionLabel}</strong><br>
                 <span style="color:var(--text-muted,#647b80);font-size:.85rem;"><?= htmlspecialchars($studentProgram) ?> &bull; <?= htmlspecialchars($studentYearLevel) ?></span>
               </div>
               ${changeNote}`,
        icon: IS_CHANGING_SECTION ? 'warning' : 'question',
        showCancelButton: true,
        confirmButtonText: IS_CHANGING_SECTION
            ? '<i class="bi bi-arrow-repeat me-1"></i>Yes, Change Section'
            : '<i class="bi bi-check-circle-fill me-1"></i>Yes, Choose This Section',
        cancelButtonText: 'Cancel',
        confirmButtonColor: IS_CHANGING_SECTION ? '#d97706' : 'var(--brand-primary, #0b9b98)',
        cancelButtonColor: '#6c757d',
        background: '#ffffff',
        color: 'var(--text-darker, #10383f)',
    }).then(result => {
        if (result.isConfirmed) {
            document.getElementById('chooseSectionId').value = sectionId;
            document.getElementById('chooseSectionForm').submit();
        }
    });
}

// Live search filter
const searchInput = document.getElementById('sectionSearch');
if (searchInput) {
    searchInput.addEventListener('input', () => {
        const q = searchInput.value.toLowerCase();
        const items = document.querySelectorAll('.section-item');
        let visible = 0;
        items.forEach(item => {
            const text = item.textContent.toLowerCase();
            const match = text.includes(q);
            item.style.display = match ? '' : 'none';
            if (match) visible++;
        });
        const countEl = document.getElementById('sectionCount');
        if (countEl) countEl.textContent = visible;
    });
}

<?php if ($activeReservation && (int)($activeReservation['seconds_left'] ?? 0) > 0): ?>
(function() {
    let secondsLeft = <?= (int)$activeReservation['seconds_left'] ?>;
    const el = document.getElementById('reservationCountdown');
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
<?php endif; ?>
</script>

<?php require_once '../includes/footer.php'; ?>
