<?php
/**
 * LMS System Settings & Configuration (TASK 4.1)
 * Administrator portal view for managing institutional defaults:
 *   - Default quiz rules (time limit, attempts, passing score)
 *   - File upload limits and permitted extensions
 *   - System-wide notification triggers
 *   - Central grading scale and passing threshold
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/lms_access.php';
require_once __DIR__ . '/../includes/lms_settings.php';

// Strict Admin-only access enforcement
$adminSession = requireLmsAdminAccess();
$adminUserId  = (int)$adminSession['user_id'];

ensureCsrfToken();

// Load current settings from database
$settings = getAllLmsSettings($pdo);

// Setting defaults if not in DB yet
$quizTimeLimit    = (int)($settings['quiz_default_time_limit'] ?? 30);
$quizAttempts     = (int)($settings['quiz_default_allowed_attempts'] ?? 1);
$quizPassingScore = (float)($settings['quiz_default_passing_score'] ?? 75.00);

$matMaxMb   = (int)($settings['materials_max_upload_mb'] ?? 50);
$matExts    = (string)($settings['materials_allowed_exts'] ?? 'pdf,doc,docx,ppt,pptx,xls,xlsx,txt,csv,rtf,jpg,png,gif,webp,mp4,webm,zip');
$assignMaxMb = (int)($settings['assignments_max_upload_mb'] ?? 20);
$assignExts  = (string)($settings['assignments_allowed_exts'] ?? 'pdf,doc,docx,ppt,pptx,xls,xlsx,txt,csv,rtf,jpg,png,gif,webp,mp4,webm,zip');

$notifyAssignment = ($settings['notify_assignment_submission'] ?? '1') === '1';
$notifyGrade      = ($settings['notify_grade_posted'] ?? '1') === '1';
$notifyAnnounce   = ($settings['notify_announcement'] ?? '1') === '1';
$enableEmail      = ($settings['enable_email_notifications'] ?? '1') === '1';

$gradeMin         = (float)($settings['grading_scale_min'] ?? 0.00);
$gradeMax         = (float)($settings['grading_scale_max'] ?? 100.00);
$passingThreshold = (float)($settings['passing_grade_threshold'] ?? 75.00);

$page_title = 'LMS System Settings — Administrator';
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
            <a href="lms_settings" class="btn btn-sm text-white fw-bold active text-nowrap" style="background:rgba(255,255,255,.2);border-radius:6px;opacity:1;" aria-current="page">
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
            <a href="dashboard" class="btn btn-sm text-white text-nowrap ms-auto" style="opacity:.85;" onmouseenter="this.style.opacity=1" onmouseleave="this.style.opacity='.85'" title="Admin Main Dashboard">
                <i class="bi bi-speedometer2 me-1"></i>Admin Dashboard
            </a>
        </div>
    </div>
</nav>

<!-- ── BREADCRUMB ──────────────────────────────────────────────────────────── -->
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb mb-0" style="font-size:.82rem;">
        <li class="breadcrumb-item"><a href="dashboard" class="text-decoration-none text-brand-primary"><i class="bi bi-shield-shaded me-1"></i>Administrator</a></li>
        <li class="breadcrumb-item active" aria-current="page">LMS System Settings</li>
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
            <i class="bi bi-gear-fill me-1"></i>Central LMS Policy & Governance
        </div>
        <h1 class="h3 fw-bold text-navy-alt mb-1">
            <i class="bi bi-sliders me-2 text-brand-primary"></i>LMS System Settings
        </h1>
        <p class="text-muted small mb-0">
            Configure system-wide defaults for quizzes, upload limits, notification alerts, and institutional grading scales.
        </p>
    </div>
    <div class="d-flex gap-2">
        <button type="submit" form="lmsSettingsForm" class="btn btn-brand-primary shadow-sm px-4 fw-semibold d-flex align-items-center gap-2">
            <i class="bi bi-save2-fill"></i> Save System Settings
        </button>
    </div>
</div>

<!-- ── FORM CONTAINER ─────────────────────────────────────────────────────── -->
<form id="lmsSettingsForm" action="../actions/lms_settings_actions" method="POST">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
    <input type="hidden" name="action" value="save_settings">

    <div class="row g-4 mb-5">
        <!-- ── 1. Default Quiz Settings Card ──────────────────────────────── -->
        <div class="col-12 col-lg-6">
            <div class="card shadow-sm border-0 rounded-4 h-100 overflow-hidden">
                <div class="card-header bg-white py-3 px-4 border-bottom d-flex align-items-center gap-2">
                    <div class="rounded p-2 bg-primary-subtle text-primary">
                        <i class="bi bi-question-circle-fill fs-5"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-navy">Default Quiz & Exam Settings</h5>
                        <div class="text-muted small">Global baselines that pre-populate teacher quiz creation forms.</div>
                    </div>
                </div>
                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label small fw-bold text-navy">Default Time Limit (Minutes) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0"><i class="bi bi-clock-history text-muted"></i></span>
                                <input type="number" name="quiz_default_time_limit" class="form-control" value="<?php echo (int)$quizTimeLimit; ?>" min="1" max="600" required>
                                <span class="input-group-text bg-light">minutes</span>
                            </div>
                            <div class="form-text small">Recommended: 30–60 minutes. Instructors may override this per quiz.</div>
                        </div>

                        <div class="col-12">
                            <label class="form-label small fw-bold text-navy">Default Allowed Attempts <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0"><i class="bi bi-arrow-repeat text-muted"></i></span>
                                <input type="number" name="quiz_default_allowed_attempts" class="form-control" value="<?php echo (int)$quizAttempts; ?>" min="1" max="50" required>
                                <span class="input-group-text bg-light">attempts</span>
                            </div>
                            <div class="form-text small">Number of times a student can take the assessment by default.</div>
                        </div>

                        <div class="col-12">
                            <label class="form-label small fw-bold text-navy">Default Passing Score (%) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0"><i class="bi bi-award-fill text-muted"></i></span>
                                <input type="number" step="0.01" name="quiz_default_passing_score" class="form-control" value="<?php echo number_format($quizPassingScore, 2, '.', ''); ?>" min="0" max="100" required>
                                <span class="input-group-text bg-light">%</span>
                            </div>
                            <div class="form-text small">Institutional passing standard for automatic scoring feedback.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── 2. File Upload Limits Card ─────────────────────────────────── -->
        <div class="col-12 col-lg-6">
            <div class="card shadow-sm border-0 rounded-4 h-100 overflow-hidden">
                <div class="card-header bg-white py-3 px-4 border-bottom d-flex align-items-center gap-2">
                    <div class="rounded p-2 bg-info-subtle text-info-emphasis">
                        <i class="bi bi-cloud-arrow-up-fill fs-5"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-navy">File Upload Limits & Storage</h5>
                        <div class="text-muted small">Controls storage quotas and valid extensions for materials & submissions.</div>
                    </div>
                </div>
                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label small fw-bold text-navy">Learning Materials Maximum Size <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0"><i class="bi bi-file-earmark-arrow-up text-muted"></i></span>
                                <input type="number" name="materials_max_upload_mb" class="form-control" value="<?php echo (int)$matMaxMb; ?>" min="1" max="500" required>
                                <span class="input-group-text bg-light">MB</span>
                            </div>
                            <div class="form-text small">Maximum file size permitted for course modules and instructor lecture materials.</div>
                        </div>

                        <div class="col-12">
                            <label class="form-label small fw-bold text-navy">Allowed Extensions for Materials <span class="text-danger">*</span></label>
                            <textarea name="materials_allowed_exts" rows="2" class="form-control font-monospace" style="font-size: 0.82rem;" required><?php echo htmlspecialchars($matExts); ?></textarea>
                            <div class="form-text small">Comma-separated list (e.g. pdf, doc, docx, ppt, pptx, xls, mp4, zip).</div>
                        </div>

                        <div class="col-12">
                            <label class="form-label small fw-bold text-navy">Assignment Submissions Maximum Size <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0"><i class="bi bi-file-earmark-check text-muted"></i></span>
                                <input type="number" name="assignments_max_upload_mb" class="form-control" value="<?php echo (int)$assignMaxMb; ?>" min="1" max="200" required>
                                <span class="input-group-text bg-light">MB</span>
                            </div>
                            <div class="form-text small">Maximum file size allowed for cadet assignment uploads.</div>
                        </div>

                        <div class="col-12">
                            <label class="form-label small fw-bold text-navy">Allowed Extensions for Submissions <span class="text-danger">*</span></label>
                            <textarea name="assignments_allowed_exts" rows="2" class="form-control font-monospace" style="font-size: 0.82rem;" required><?php echo htmlspecialchars($assignExts); ?></textarea>
                            <div class="form-text small">Comma-separated list of formats cadets are allowed to submit.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── 3. Notification Settings Card ──────────────────────────────── -->
        <div class="col-12 col-lg-6">
            <div class="card shadow-sm border-0 rounded-4 h-100 overflow-hidden">
                <div class="card-header bg-white py-3 px-4 border-bottom d-flex align-items-center gap-2">
                    <div class="rounded p-2 bg-warning-subtle text-warning-emphasis">
                        <i class="bi bi-bell-fill fs-5"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-navy">LMS Notification Preferences</h5>
                        <div class="text-muted small">Enable or disable system-wide alerts for key academic events.</div>
                    </div>
                </div>
                <div class="card-body p-4">
                    <div class="d-flex flex-column gap-3">
                        <div class="form-check form-switch p-0 d-flex justify-content-between align-items-center">
                            <div>
                                <label class="form-check-label fw-bold small text-navy d-block" for="notifyAssignmentSwitch">
                                    Cadet Assignment Submissions
                                </label>
                                <span class="text-muted small">Notify instructors when a student uploads an assignment or activity.</span>
                            </div>
                            <input class="form-check-input ms-3" type="checkbox" role="switch" id="notifyAssignmentSwitch" name="notify_assignment_submission" value="1" <?php echo $notifyAssignment ? 'checked' : ''; ?>>
                        </div>

                        <hr class="my-1 text-muted opacity-25">

                        <div class="form-check form-switch p-0 d-flex justify-content-between align-items-center">
                            <div>
                                <label class="form-check-label fw-bold small text-navy d-block" for="notifyGradeSwitch">
                                    Grade Publication Alerts
                                </label>
                                <span class="text-muted small">Notify students when prelim, midterm, or final grades are verified and posted.</span>
                            </div>
                            <input class="form-check-input ms-3" type="checkbox" role="switch" id="notifyGradeSwitch" name="notify_grade_posted" value="1" <?php echo $notifyGrade ? 'checked' : ''; ?>>
                        </div>

                        <hr class="my-1 text-muted opacity-25">

                        <div class="form-check form-switch p-0 d-flex justify-content-between align-items-center">
                            <div>
                                <label class="form-check-label fw-bold small text-navy d-block" for="notifyAnnounceSwitch">
                                    Course & Subject Announcements
                                </label>
                                <span class="text-muted small">Alert enrolled cadets immediately when an instructor broadcasts an announcement.</span>
                            </div>
                            <input class="form-check-input ms-3" type="checkbox" role="switch" id="notifyAnnounceSwitch" name="notify_announcement" value="1" <?php echo $notifyAnnounce ? 'checked' : ''; ?>>
                        </div>

                        <hr class="my-1 text-muted opacity-25">

                        <div class="form-check form-switch p-0 d-flex justify-content-between align-items-center">
                            <div>
                                <label class="form-check-label fw-bold small text-navy d-block" for="enableEmailSwitch">
                                    Supplementary Email Delivery
                                </label>
                                <span class="text-muted small">Dispatch supplementary email notifications (via PHPMailer) alongside in-app alerts.</span>
                            </div>
                            <input class="form-check-input ms-3" type="checkbox" role="switch" id="enableEmailSwitch" name="enable_email_notifications" value="1" <?php echo $enableEmail ? 'checked' : ''; ?>>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── 4. Grading Scale & Conventions Card ────────────────────────── -->
        <div class="col-12 col-lg-6">
            <div class="card shadow-sm border-0 rounded-4 h-100 overflow-hidden">
                <div class="card-header bg-white py-3 px-4 border-bottom d-flex align-items-center gap-2">
                    <div class="rounded p-2 bg-success-subtle text-success">
                        <i class="bi bi-mortarboard-fill fs-5"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-navy">Institutional Grading Conventions</h5>
                        <div class="text-muted small">Governs standard grade scale boundaries and the passing threshold.</div>
                    </div>
                </div>
                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <label class="form-label small fw-bold text-navy">Grading Scale Minimum <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" name="grading_scale_min" class="form-control font-monospace" value="<?php echo number_format($gradeMin, 2, '.', ''); ?>" min="0" max="100" required>
                            <div class="form-text small">Default baseline: 0.00.</div>
                        </div>

                        <div class="col-sm-6">
                            <label class="form-label small fw-bold text-navy">Grading Scale Maximum <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" name="grading_scale_max" class="form-control font-monospace" value="<?php echo number_format($gradeMax, 2, '.', ''); ?>" min="1" max="100" required>
                            <div class="form-text small">Default ceiling: 100.00.</div>
                        </div>

                        <div class="col-12">
                            <label class="form-label small fw-bold text-navy">Institutional Passing Mark Score <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0"><i class="bi bi-check2-circle text-success"></i></span>
                                <input type="number" step="0.01" name="passing_grade_threshold" class="form-control font-monospace" value="<?php echo number_format($passingThreshold, 2, '.', ''); ?>" min="0" max="100" required>
                                <span class="input-group-text bg-light">Points / %</span>
                            </div>
                            <div class="form-text small">Standard maritime academy passing threshold (e.g., 75.00 for 75%). Scores at or above this mark are categorized as Passed.</div>
                        </div>

                        <div class="col-12 mt-4">
                            <div class="p-3 bg-light rounded-3 border small text-muted">
                                <i class="bi bi-shield-check text-brand-primary me-1"></i>
                                <strong>Centralized Grading Scale Policy:</strong>
                                Grading scale min and max feed into the institutional transcript calculator, grade approval matrices, and GWA computation algorithms across the Registrar and Student portals.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── BOTTOM ACTION BAR ──────────────────────────────────────────────── -->
    <div class="card shadow-sm border-0 rounded-4 p-3 bg-white mb-5 d-flex flex-row justify-content-between align-items-center">
        <div class="text-muted small">
            <i class="bi bi-info-circle me-1 text-primary"></i>All changes take effect immediately across all student and teacher LMS workspaces.
        </div>
        <button type="submit" class="btn btn-brand-primary px-4 py-2 fw-semibold shadow-sm d-flex align-items-center gap-2">
            <i class="bi bi-save2-fill"></i> Save System Settings
        </button>
    </div>
</form>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
