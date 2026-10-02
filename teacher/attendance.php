<?php
/**
 * Teacher Attendance Management
 * Records class session attendance for assigned sections.
 */

require_once '../includes/auth_check.php';
checkRole(['teacher']);
ensureCsrfToken();
require_once '../config/database.php';

$userId = (int)$_SESSION['user_id'];
$sectionId = (int)($_GET['section_id'] ?? 0);
$section = null;
$students = [];

// Fetch available sections for attendance
$availableSections = [];
try {
    $secListStmt = $pdo->prepare("
        SELECT s.id, s.section_name, s.schedule, s.room,
               COALESCE(c.course_code, s.section_name, 'Section') AS course_code,
               COALESCE(c.course_name, s.section_name, 'Block Section') AS course_name,
               COALESCE(c.units, 0) AS units,
               (SELECT COUNT(*) FROM enrollments e WHERE e.section_id = s.id AND e.status = 'enrolled') AS enrolled_count
        FROM sections s
        LEFT JOIN courses c ON c.id = s.course_id
        WHERE s.teacher_id = :tid
        ORDER BY course_code ASC, s.section_name ASC
    ");
    $secListStmt->execute(['tid' => $userId]);
    $availableSections = $secListStmt->fetchAll();
} catch (\PDOException $e) {
    error_log("Fetch attendance sections failed: " . $e->getMessage());
}

if ($sectionId > 0) {
    $q = $pdo->prepare('
        SELECT s.id, s.section_name, s.schedule, s.room,
               COALESCE(c.course_code, s.section_name, \'Section\') AS course_code,
               COALESCE(c.course_name, s.section_name, \'Block Section\') AS course_name,
               COALESCE(c.units, 0) AS units
        FROM sections s 
        LEFT JOIN courses c ON c.id = s.course_id 
        WHERE s.id = :id AND s.teacher_id = :teacher_id
    ');

    $q->execute([
        'id' => $sectionId,
        'teacher_id' => $userId
    ]);
    $section = $q->fetch();

    if ($section) {
        $q = $pdo->prepare("
            SELECT e.student_id, s.first_name, s.last_name,
                   u.id AS user_id, u.username, u.profile_picture
            FROM enrollments e 
            JOIN students s ON s.id = e.student_id 
            LEFT JOIN users u ON s.user_id = u.id
            WHERE e.section_id = :id AND e.status = 'enrolled' 
            ORDER BY s.last_name, s.first_name
        ");
        $q->execute(['id' => $sectionId]);
        $students = $q->fetchAll();
    }
}

$page_title = 'Attendance';
require_once '../includes/header.php';
?>

<div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h3 class="m-0 text-navy-alt fw-bold">Class Attendance</h3>
        <p class="text-muted small m-0">Record a session for your assigned section.</p>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <?php if ($sectionId > 0 && !empty($availableSections)): ?>
            <form method="get" action="attendance" class="d-inline-flex align-items-center gap-2 m-0">
                <label for="switchAttendanceSection" class="small fw-semibold text-secondary m-0">Section:</label>
                <select name="section_id" id="switchAttendanceSection" class="form-select form-select-sm bg-white shadow-sm" onchange="this.form.submit()" style="max-width: 260px; border: 1px solid #cbd5e1; border-radius: 6px;">
                    <?php foreach ($availableSections as $secOpt): ?>
                        <option value="<?php echo (int)$secOpt['id']; ?>" <?php echo (int)$secOpt['id'] === $sectionId ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($secOpt['course_code'] . ' (' . $secOpt['section_name'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>
        <a href="my_classes" class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-1 shadow-sm" style="border-radius: 6px;">
            <i class="bi bi-arrow-left"></i> Back to My Classes
        </a>
    </div>
</div>

<?php if (!$section): ?>
    <div class="card card-premium shadow-sm border-0 mb-4" style="border-radius: 12px;">
        <div class="card-body card-body-premium py-4 px-3 px-md-5">
            <div class="text-center mx-auto mb-4" style="max-width: 620px;">
                <div class="mb-3">
                    <span class="d-inline-flex align-items-center justify-content-center bg-secondary-subtle text-brand-primary rounded-circle" style="width: 56px; height: 56px; font-size: 1.5rem;">
                        <i class="bi bi-clipboard-check"></i>
                    </span>
                </div>
                <h4 class="text-navy-alt fw-bold mb-1">Select a Class Section</h4>
                <p class="text-muted small mb-4">Choose an assigned section from the dropdown or click a card below to record session attendance.</p>
                
                <?php if (!empty($availableSections)): ?>
                    <form method="get" action="attendance" class="d-flex flex-column flex-sm-row gap-2 justify-content-center">
                        <label for="attendanceSectionSelector" class="visually-hidden">Choose Section</label>
                        <select name="section_id" id="attendanceSectionSelector" class="form-select bg-white shadow-sm" required style="border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.92rem;">
                            <option value="">-- Choose a Section --</option>
                            <?php foreach ($availableSections as $secOpt): ?>
                                <option value="<?php echo (int)$secOpt['id']; ?>">
                                    <?php echo htmlspecialchars($secOpt['course_code'] . ' (' . $secOpt['section_name'] . ') — ' . $secOpt['course_name'] . ' [' . (int)$secOpt['enrolled_count'] . ' Students]'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-brand-primary text-nowrap px-4 shadow-sm" style="border-radius: 8px; font-size: 0.92rem;">
                            <i class="bi bi-arrow-right-circle me-1"></i> Open Attendance
                        </button>
                    </form>
                <?php else: ?>
                    <div class="alert alert-info d-inline-flex align-items-center gap-2 text-start">
                        <i class="bi bi-info-circle fs-5"></i>
                        <div>No lead sections available for attendance recording under your faculty account.</div>
                    </div>
                    <div class="mt-2">
                        <a href="my_classes" class="btn btn-outline-secondary btn-sm">Go to My Classes</a>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($availableSections)): ?>
                <hr class="my-4" style="border-color: #e2e8f0;">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="m-0 fw-semibold text-navy-alt">
                        <i class="bi bi-grid me-1.5 text-brand-primary"></i> Assigned Sections
                    </h6>
                    <span class="text-muted small"><?php echo count($availableSections); ?> Available</span>
                </div>
                <div class="row g-3">
                    <?php foreach ($availableSections as $secCard): ?>
                        <div class="col-12 col-md-6 col-lg-4">
                            <a href="attendance?section_id=<?php echo (int)$secCard['id']; ?>" class="card h-100 text-decoration-none section-card-hover border shadow-sm" style="border-radius: 10px; border-color: #e2e8f0; background: #ffffff;">
                                <div class="card-body p-3.5 d-flex flex-column justify-content-between">
                                    <div>
                                        <div class="d-flex justify-content-between align-items-start gap-2 mb-1.5">
                                            <span class="badge bg-secondary-subtle text-brand-primary border fw-semibold px-2 py-1" style="font-size: 0.72rem;">
                                                <?php echo htmlspecialchars($secCard['course_code']); ?>
                                            </span>
                                            <span class="badge bg-light text-secondary border px-2 py-1" style="font-size: 0.7rem;">
                                                Sec #<?php echo (int)$secCard['id']; ?>
                                            </span>
                                        </div>
                                        <h6 class="fw-bold text-navy-alt mb-1 text-truncate" title="<?php echo htmlspecialchars($secCard['course_name']); ?>">
                                            <?php echo htmlspecialchars($secCard['course_name']); ?>
                                        </h6>
                                        <div class="text-muted small mb-2 text-truncate" style="font-size: 0.78rem;">
                                            <?php echo htmlspecialchars($secCard['section_name']); ?>
                                        </div>
                                    </div>
                                    <div class="pt-2 border-top d-flex justify-content-between align-items-center text-muted small" style="font-size: 0.75rem; border-color: #f1f5f9 !important;">
                                        <span class="d-inline-flex align-items-center gap-1 text-truncate" style="max-width: 60%;">
                                            <i class="bi bi-clock text-brand-primary"></i> <?php echo htmlspecialchars($secCard['schedule'] ?: 'TBA'); ?>
                                        </span>
                                        <span class="fw-semibold text-brand-primary d-inline-flex align-items-center gap-1">
                                            <i class="bi bi-people-fill"></i> <?php echo (int)$secCard['enrolled_count']; ?> Enrolled
                                        </span>
                                    </div>
                                </div>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <!-- Course Info Card -->
    <div class="card card-premium shadow-sm mb-4 border-0" style="border-left: 4px solid var(--brand-primary, #008080) !important; border-radius: 10px; background: #ffffff;">
        <div class="card-body card-body-premium p-3.5">
            <div class="d-flex justify-content-between align-items-start align-items-md-center flex-wrap gap-3">
                <div>
                    <div class="text-muted small text-uppercase fw-bold mb-1" style="font-size: 0.7rem; letter-spacing: 0.5px;">
                        <?php echo htmlspecialchars($section['section_name']); ?> &middot; Section #<?php echo $sectionId; ?>
                    </div>
                    <h5 class="m-0 fw-bold text-navy-alt">
                        <?php echo htmlspecialchars($section['course_code'] . ' — ' . $section['course_name']); ?>
                    </h5>
                    <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
                        <span class="badge bg-light text-navy border px-2.5 py-1">
                            <i class="bi bi-clock text-brand-primary me-1"></i><?php echo htmlspecialchars($section['schedule'] ?: 'TBA'); ?>
                        </span>
                        <span class="badge bg-light text-navy border px-2.5 py-1">
                            <i class="bi bi-geo-alt text-brand-primary me-1"></i><?php echo htmlspecialchars($section['room'] ?: 'TBA'); ?>
                        </span>
                        <?php if ((int)($section['units'] ?? 0) > 0): ?>
                            <span class="badge bg-light text-navy border px-2.5 py-1">
                                <i class="bi bi-journal-check text-brand-primary me-1"></i><?php echo (int)$section['units']; ?> units
                            </span>
                        <?php endif; ?>
                        <span class="badge bg-secondary-subtle text-brand-primary border px-2.5 py-1 fw-semibold">
                            <i class="bi bi-people-fill me-1"></i><?php echo count($students); ?> Enrolled
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Attendance Recording Form -->
    <form method="post" action="../actions/academic_actions" id="attendanceForm">
        <input type="hidden" name="action" value="save_attendance">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="section_id" value="<?php echo $sectionId; ?>">

        <!-- Session Details Card -->
        <div class="card card-premium shadow-sm border-0 mb-4" style="border-radius: 12px;">
            <div class="card-body card-body-premium p-3.5">
                <h6 class="fw-semibold text-navy-alt mb-3">
                    <i class="bi bi-calendar-check text-brand-primary me-1.5"></i> Session Details
                </h6>
                <div class="row g-3">
                    <div class="col-12 col-md-6 col-lg-3">
                        <label class="form-label small fw-semibold text-secondary mb-1">
                            <i class="bi bi-calendar-event me-1 text-brand-primary"></i>Session Date <span class="text-danger">*</span>
                        </label>
                        <input type="date" class="form-control form-control-sm bg-white shadow-sm att-input" 
                               name="session_date" 
                               value="<?php echo date('Y-m-d'); ?>" 
                               max="<?php echo date('Y-m-d'); ?>" 
                               required 
                               style="border: 1px solid #cbd5e1; border-radius: 6px;">
                    </div>
                    <div class="col-6 col-md-6 col-lg-3">
                        <label class="form-label small fw-semibold text-secondary mb-1">
                            <i class="bi bi-clock me-1 text-brand-primary"></i>Start Time
                        </label>
                        <input type="time" class="form-control form-control-sm bg-white shadow-sm att-input" 
                               name="start_time" 
                               style="border: 1px solid #cbd5e1; border-radius: 6px;">
                    </div>
                    <div class="col-6 col-md-6 col-lg-3">
                        <label class="form-label small fw-semibold text-secondary mb-1">
                            <i class="bi bi-clock-history me-1 text-brand-primary"></i>End Time
                        </label>
                        <input type="time" class="form-control form-control-sm bg-white shadow-sm att-input" 
                               name="end_time" 
                               style="border: 1px solid #cbd5e1; border-radius: 6px;">
                    </div>
                    <div class="col-12 col-md-6 col-lg-3">
                        <label class="form-label small fw-semibold text-secondary mb-1">
                            <i class="bi bi-journal-text me-1 text-brand-primary"></i>Topic / Lesson
                        </label>
                        <input type="text" class="form-control form-control-sm bg-white shadow-sm att-input" 
                               name="topic" 
                               maxlength="255" 
                               placeholder="e.g. Navigation Rules &amp; Regulations" 
                               style="border: 1px solid #cbd5e1; border-radius: 6px;">
                    </div>
                </div>
            </div>
        </div>

        <!-- Student Attendance Table Card -->
        <div class="card card-premium shadow-sm border-0 mb-4" style="border-radius: 12px; overflow: hidden;">
            <!-- Header with Quick Actions and Live Counters -->
            <div class="card-header card-header-premium d-flex justify-content-between align-items-center flex-wrap gap-2.5 py-3">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <button type="button" class="btn btn-outline-success btn-sm d-inline-flex align-items-center gap-1 shadow-sm" id="btnMarkAllPresent" style="border-radius: 6px; font-weight: 500;" <?php echo empty($students) ? 'disabled' : ''; ?>>
                        <i class="bi bi-check-all fs-6"></i> Mark all Present
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-1 shadow-sm" id="btnResetAttendance" style="border-radius: 6px; font-weight: 500;" <?php echo empty($students) ? 'disabled' : ''; ?>>
                        <i class="bi bi-arrow-counterclockwise"></i> Reset
                    </button>
                </div>
                
                <!-- Live Counter Strip -->
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="badge counter-badge counter-present px-2.5 py-1.5 shadow-sm d-inline-flex align-items-center gap-1">
                        <i class="bi bi-check-circle-fill text-success"></i> Present: <span id="countPresent" class="fw-bold">0</span>
                    </span>
                    <span class="badge counter-badge counter-late px-2.5 py-1.5 shadow-sm d-inline-flex align-items-center gap-1">
                        <i class="bi bi-clock-fill text-warning"></i> Late: <span id="countLate" class="fw-bold">0</span>
                    </span>
                    <span class="badge counter-badge counter-absent px-2.5 py-1.5 shadow-sm d-inline-flex align-items-center gap-1">
                        <i class="bi bi-x-circle-fill text-danger"></i> Absent: <span id="countAbsent" class="fw-bold">0</span>
                    </span>
                    <span class="badge counter-badge counter-excused px-2.5 py-1.5 shadow-sm d-inline-flex align-items-center gap-1">
                        <i class="bi bi-shield-check text-info"></i> Excused: <span id="countExcused" class="fw-bold">0</span>
                    </span>
                    <span class="badge bg-light text-secondary border px-2.5 py-1.5 shadow-sm">
                        Total: <span id="countTotal" class="fw-bold"><?php echo count($students); ?></span>
                    </span>
                </div>
            </div>

            <!-- Table Body -->
            <div class="card-body card-body-premium p-0">
                <?php if (empty($students)): ?>
                    <div class="text-center py-5 px-3">
                        <div class="mb-3">
                            <span class="d-inline-flex align-items-center justify-content-center bg-secondary-subtle text-muted rounded-circle" style="width: 64px; height: 64px; font-size: 1.75rem;">
                                <i class="bi bi-people"></i>
                            </span>
                        </div>
                        <h5 class="text-navy-alt fw-bold mb-1">No enrolled students in this section yet</h5>
                        <p class="text-muted small mb-0" style="max-width: 440px; margin: 0 auto;">
                            Attendance can be recorded once students are officially enrolled in this section by the Registrar.
                        </p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive attendance-table-container">
                        <table class="table table-hover align-middle mb-0 attendance-table" style="width: 100%; table-layout: fixed;">
                            <thead class="attendance-thead">
                                <tr>
                                    <th class="ps-4" style="width: 34%;">Student</th>
                                    <th style="width: 38%;">Status</th>
                                    <th class="pe-4" style="width: 28%;">Remark</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($students as $student): ?>
                                    <?php
                                    $sid = (int)$student['student_id'];
                                    $studentUserId = (int)($student['user_id'] ?? 0);
                                    $hasPic = !empty($student['profile_picture']);
                                    $stInitials = strtoupper(substr($student['first_name'] ?? '', 0, 1) . substr($student['last_name'] ?? '', 0, 1));
                                    if (!$stInitials) {
                                        $stInitials = strtoupper(substr($student['username'] ?? 'S', 0, 2));
                                    }
                                    $picSrc = $hasPic ? "../actions/view_avatar?uid={$studentUserId}" : '';
                                    ?>
                                    <tr class="attendance-row">
                                        <!-- Student Avatar & Name -->
                                        <td class="ps-4 attendance-student-cell" data-label="Student">
                                            <div class="d-flex align-items-center gap-2.5 py-1">
                                                <div class="rounded-circle overflow-hidden flex-shrink-0 d-flex align-items-center justify-content-center" 
                                                     style="width: 36px; height: 36px; background: #e6f2f2; border: 1.5px solid rgba(0,128,128,0.2); font-size: 0.8rem; font-weight: 600; color: #005a5a;">
                                                    <?php if ($hasPic): ?>
                                                        <img src="<?php echo htmlspecialchars($picSrc); ?>" 
                                                             alt="<?php echo htmlspecialchars($stInitials); ?>" 
                                                             class="w-100 h-100" 
                                                             style="object-fit: cover;"
                                                             onerror="this.style.display='none'; if(this.nextElementSibling) this.nextElementSibling.style.display='flex';">
                                                        <span style="display: none; align-items: center; justify-content: center; width: 100%; height: 100%;"><?php echo htmlspecialchars($stInitials); ?></span>
                                                    <?php else: ?>
                                                        <span><?php echo htmlspecialchars($stInitials); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="d-flex flex-column text-truncate" style="line-height: 1.25;">
                                                    <span class="fw-semibold text-darker text-truncate" style="font-size: 0.9rem;" title="<?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name']); ?>">
                                                        <?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name']); ?>
                                                    </span>
                                                    <span class="text-muted small text-truncate" style="font-size: 0.76rem;">
                                                        @<?php echo htmlspecialchars($student['username'] ?: 'student'); ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </td>

                                        <!-- Segmented Status Button Group -->
                                        <td class="attendance-cell" data-label="Status">
                                            <div class="btn-group btn-group-sm segmented-attendance w-100" role="group" aria-label="Attendance Status for <?php echo htmlspecialchars($student['first_name']); ?>">
                                                <input type="radio" class="btn-check att-status-radio" name="status[<?php echo $sid; ?>]" id="st_pres_<?php echo $sid; ?>" value="present" checked autocomplete="off">
                                                <label class="btn btn-outline-present" for="st_pres_<?php echo $sid; ?>">
                                                    <i class="bi bi-check-lg me-0.5"></i> Present
                                                </label>

                                                <input type="radio" class="btn-check att-status-radio" name="status[<?php echo $sid; ?>]" id="st_late_<?php echo $sid; ?>" value="late" autocomplete="off">
                                                <label class="btn btn-outline-late" for="st_late_<?php echo $sid; ?>">
                                                    <i class="bi bi-clock me-0.5"></i> Late
                                                </label>

                                                <input type="radio" class="btn-check att-status-radio" name="status[<?php echo $sid; ?>]" id="st_abs_<?php echo $sid; ?>" value="absent" autocomplete="off">
                                                <label class="btn btn-outline-absent" for="st_abs_<?php echo $sid; ?>">
                                                    <i class="bi bi-x-lg me-0.5"></i> Absent
                                                </label>

                                                <input type="radio" class="btn-check att-status-radio" name="status[<?php echo $sid; ?>]" id="st_exc_<?php echo $sid; ?>" value="excused" autocomplete="off">
                                                <label class="btn btn-outline-excused" for="st_exc_<?php echo $sid; ?>">
                                                    <i class="bi bi-shield-check me-0.5"></i> Excused
                                                </label>
                                            </div>
                                        </td>

                                        <!-- Student Remark Input -->
                                        <td class="pe-4 attendance-cell" data-label="Remark">
                                            <input type="text" class="form-control form-control-sm bg-white shadow-sm remark-input" 
                                                   name="student_remarks[<?php echo $sid; ?>]" 
                                                   maxlength="255" 
                                                   placeholder="Optional remark..."
                                                   style="border: 1px solid #cbd5e1; border-radius: 6px;">
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Footer Action Bar -->
            <div class="card-footer bg-white d-flex justify-content-between align-items-center flex-wrap gap-2 py-3 attendance-footer" style="border-top: 1px solid #e2e8f0;">
                <div class="text-muted small d-flex align-items-center gap-1.5" id="attendanceSummaryText">
                    <i class="bi bi-people text-brand-primary"></i>
                    <span><strong id="markedCount"><?php echo count($students); ?></strong> of <strong><?php echo count($students); ?></strong> students marked</span>
                </div>
                <button type="submit" class="btn btn-brand-primary px-4 fw-semibold shadow-sm" style="border-radius: 6px;" <?php echo empty($students) ? 'disabled' : ''; ?>>
                    <i class="bi bi-check2-circle me-1"></i> Save Attendance
                </button>
            </div>
        </div>
    </form>
<?php endif; ?>

<style>
/* Section Card Hover */
.section-card-hover {
    transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
}
.section-card-hover:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(0, 128, 128, 0.12) !important;
    border-color: var(--brand-primary, #008080) !important;
}

/* Counter Badges */
.counter-badge {
    font-size: 0.78rem;
    font-weight: 500;
}
.counter-present {
    background-color: #dcfce7;
    color: #166534;
    border: 1px solid #bbf7d0;
}
.counter-late {
    background-color: #fef3c7;
    color: #92400e;
    border: 1px solid #fde68a;
}
.counter-absent {
    background-color: #fee2e2;
    color: #991b1b;
    border: 1px solid #fecaca;
}
.counter-excused {
    background-color: #e0f2fe;
    color: #075985;
    border: 1px solid #bae6fd;
}

/* Attendance Table Styling */
.attendance-thead {
    background: linear-gradient(180deg, #f0fdfa 0%, #e6f7f7 100%);
    color: #004c4c;
    border-bottom: 2px solid #ccfbf1;
}
.attendance-thead th {
    font-weight: 600;
    font-size: 0.82rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding-top: 0.85rem;
    padding-bottom: 0.85rem;
    border-color: #ccfbf1;
}
.attendance-row {
    transition: background-color 0.15s ease;
}
.attendance-row:hover {
    background-color: #f8fafc !important;
}
.attendance-row td {
    padding-top: 0.65rem;
    padding-bottom: 0.65rem;
    border-color: #f1f5f9;
}

/* Inputs */
.att-input:focus,
.remark-input:focus {
    background-color: #ffffff;
    border-color: var(--brand-primary, #008080) !important;
    box-shadow: 0 0 0 0.2rem rgba(0, 128, 128, 0.2) !important;
    outline: 0;
}
.remark-input {
    background-color: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    padding: 0.35rem 0.6rem;
    font-size: 0.85rem;
    width: 100%;
}

/* Segmented Buttons */
.segmented-attendance {
    border-radius: 6px;
    overflow: hidden;
    border: 1px solid #cbd5e1;
}
.segmented-attendance .btn {
    border: none;
    border-right: 1px solid #cbd5e1;
    background-color: #ffffff;
    font-size: 0.8rem;
    font-weight: 500;
    padding: 0.35rem 0.6rem;
    transition: background-color 0.15s ease, color 0.15s ease, box-shadow 0.15s ease;
}
.segmented-attendance .btn:last-child {
    border-right: none;
}

/* Present Option */
.segmented-attendance .btn-outline-present {
    color: #166534;
}
.segmented-attendance .btn-outline-present:hover {
    background-color: #f0fdf4;
}
.segmented-attendance .btn-check:checked + .btn-outline-present {
    background-color: #15803d !important;
    color: #ffffff !important;
    font-weight: 600;
    box-shadow: inset 0 1px 2px rgba(0,0,0,0.15);
}

/* Late Option */
.segmented-attendance .btn-outline-late {
    color: #b45309;
}
.segmented-attendance .btn-outline-late:hover {
    background-color: #fffbeb;
}
.segmented-attendance .btn-check:checked + .btn-outline-late {
    background-color: #d97706 !important;
    color: #ffffff !important;
    font-weight: 600;
    box-shadow: inset 0 1px 2px rgba(0,0,0,0.15);
}

/* Absent Option */
.segmented-attendance .btn-outline-absent {
    color: #b91c1c;
}
.segmented-attendance .btn-outline-absent:hover {
    background-color: #fef2f2;
}
.segmented-attendance .btn-check:checked + .btn-outline-absent {
    background-color: #dc2626 !important;
    color: #ffffff !important;
    font-weight: 600;
    box-shadow: inset 0 1px 2px rgba(0,0,0,0.15);
}

/* Excused Option */
.segmented-attendance .btn-outline-excused {
    color: #0369a1;
}
.segmented-attendance .btn-outline-excused:hover {
    background-color: #f0f9ff;
}
.segmented-attendance .btn-check:checked + .btn-outline-excused {
    background-color: #0284c7 !important;
    color: #ffffff !important;
    font-weight: 600;
    box-shadow: inset 0 1px 2px rgba(0,0,0,0.15);
}

/* Mobile Stacking View */
@media (max-width: 767px) {
    .attendance-table {
        table-layout: auto !important;
    }
    .attendance-thead {
        display: none;
    }
    .attendance-row {
        display: block;
        padding: 1rem 1rem 0.75rem 1rem;
        border-bottom: 2px solid #e2e8f0;
    }
    .attendance-row td {
        display: block;
        padding: 0.45rem 0 !important;
        border: none;
    }
    .attendance-student-cell {
        padding-bottom: 0.75rem !important;
        margin-bottom: 0.5rem;
        border-bottom: 1px solid #f1f5f9 !important;
    }
    .attendance-cell::before {
        content: attr(data-label);
        display: block;
        font-weight: 600;
        font-size: 0.75rem;
        text-transform: uppercase;
        color: #64748b;
        letter-spacing: 0.5px;
        margin-bottom: 0.35rem;
    }
    .segmented-attendance {
        display: flex;
        flex-wrap: wrap;
    }
    .segmented-attendance .btn {
        flex: 1 1 45%;
        border-bottom: 1px solid #cbd5e1;
    }
    .segmented-attendance .btn:nth-child(2),
    .segmented-attendance .btn:nth-child(4) {
        border-right: 1px solid #cbd5e1;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var radios = document.querySelectorAll('.att-status-radio');
    var countPresent = document.getElementById('countPresent');
    var countLate = document.getElementById('countLate');
    var countAbsent = document.getElementById('countAbsent');
    var countExcused = document.getElementById('countExcused');
    var markedCount = document.getElementById('markedCount');

    function updateAttendanceCounters() {
        var p = 0, l = 0, a = 0, e = 0;
        var checkedRadios = document.querySelectorAll('.att-status-radio:checked');
        checkedRadios.forEach(function (r) {
            if (r.value === 'present') p++;
            else if (r.value === 'late') l++;
            else if (r.value === 'absent') a++;
            else if (r.value === 'excused') e++;
        });

        if (countPresent) countPresent.textContent = p;
        if (countLate) countLate.textContent = l;
        if (countAbsent) countAbsent.textContent = a;
        if (countExcused) countExcused.textContent = e;
        if (markedCount) markedCount.textContent = (p + l + a + e);
    }

    radios.forEach(function (radio) {
        radio.addEventListener('change', updateAttendanceCounters);
    });

    var markAllPresentBtn = document.getElementById('btnMarkAllPresent');
    if (markAllPresentBtn) {
        markAllPresentBtn.addEventListener('click', function () {
            document.querySelectorAll('.att-status-radio[value="present"]').forEach(function (r) {
                r.checked = true;
            });
            updateAttendanceCounters();
        });
    }

    var resetBtn = document.getElementById('btnResetAttendance');
    if (resetBtn) {
        resetBtn.addEventListener('click', function () {
            document.querySelectorAll('.att-status-radio[value="present"]').forEach(function (r) {
                r.checked = true;
            });
            document.querySelectorAll('.remark-input').forEach(function (inp) {
                inp.value = '';
            });
            updateAttendanceCounters();
        });
    }

    // Initial calculation on page load
    updateAttendanceCounters();
});
</script>

<?php require_once '../includes/footer.php'; ?>
