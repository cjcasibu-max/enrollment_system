<?php
/**
 * Academic Terms & Enrollment Period Administration
 * Configures school years, semesters, active enrollment periods, and registration deadlines.
 * Strictly protected with checkRole(['admin']) and anti-CSRF token validation.
 */

require_once '../includes/auth_check.php';
checkRole(['admin']);
require_once '../config/database.php';
require_once '../includes/academic_terms.php';

$terms = [];
$error = null;
try {
    $stmt = $pdo->query('
        SELECT id, school_year, semester, starts_on, ends_on,
               enrollment_starts_on, enrollment_ends_on, registration_deadline, late_registration_deadline, is_enrollment_open,
               payment_requirement_percent, downpayment_percentage, minimum_downpayment, max_units, is_active, is_archived
        FROM academic_terms
        ORDER BY starts_on DESC, id DESC
    ');
    $terms = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Academic term fetch failed: ' . $e->getMessage());
    $error = 'Academic terms are temporarily unavailable.';
}

$activeTerm = null;
foreach ($terms as $t) {
    if ((int)$t['is_active'] === 1 && (int)($t['is_archived'] ?? 0) === 0) {
        $activeTerm = $t;
        break;
    }
}
$activeEnrollmentStatus = getEnrollmentPeriodStatus($activeTerm);

$page_title = 'Academic Terms & Enrollment Periods';
$page_class = 'page-academic-terms';
require_once '../includes/header.php';
?>

<style>
.table-maritime td .btn-group .btn,
.table-maritime td .btn-sm {
    min-height: 40px;
    min-width: 40px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
</style>

<!-- =========================================================================
     PAGE HEADING
     ========================================================================= -->
<div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <div class="page-eyebrow"><i class="bi bi-shield-lock me-1"></i> Administration Desk • Academic Operations</div>
        <h3 class="m-0 text-navy-alt fw-bold">Academic Terms & Enrollment Periods</h3>
        <p class="text-muted small m-0">Configure academic calendars, active enrollment windows, and registration cutoffs.</p>
    </div>
    <button type="button" class="btn btn-brand-primary d-flex align-items-center gap-1.5 shadow-sm px-3.5 py-2 fw-semibold" data-bs-toggle="modal" data-bs-target="#addTermModal">
        <i class="bi bi-calendar-plus me-1"></i> Add Academic Term
    </button>
</div>

<!-- Flash Notifications -->
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
    <div class="alert alert-danger mb-4"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<!-- =========================================================================
     ACTIVE ENROLLMENT PERIOD & REGISTRATION DEADLINE HERO CARD
     ========================================================================= -->
<div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4" style="border: 1px solid #d0e7e7 !important; background: linear-gradient(135deg, #f0fdfa 0%, #e6f7f7 100%);">
    <div class="card-body p-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
            <div class="d-flex align-items-start gap-3">
                <div class="rounded-circle d-flex align-items-center justify-content-center shadow-sm" 
                     style="width: 52px; height: 52px; min-width: 52px; background: var(--brand-primary, #064b55); color: #ffffff; font-size: 1.5rem;">
                    <i class="bi bi-calendar-check"></i>
                </div>
                <div>
                    <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                        <span class="small text-muted fw-bold text-uppercase" style="letter-spacing: 0.5px;">Active Academic Term</span>
                        <?php if ($activeTerm): ?>
                            <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-0.5 fw-bold">Active</span>
                        <?php else: ?>
                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-0.5 fw-bold">None Active</span>
                        <?php endif; ?>
                    </div>
                    <h4 class="fw-bold text-navy mb-1">
                        <?php if ($activeTerm): ?>
                            AY <?php echo htmlspecialchars($activeTerm['school_year']); ?> • <?php echo htmlspecialchars(ucfirst($activeTerm['semester'])); ?> Semester
                        <?php else: ?>
                            No Active Term Set
                        <?php endif; ?>
                    </h4>
                    <div class="text-muted small">
                        <?php if ($activeTerm): ?>
                            Term Duration: <?php echo htmlspecialchars(($activeTerm['starts_on'] ? date('M j, Y', strtotime($activeTerm['starts_on'])) : 'TBD') . ' – ' . ($activeTerm['ends_on'] ? date('M j, Y', strtotime($activeTerm['ends_on'])) : 'TBD')); ?>
                        <?php else: ?>
                            Please activate a school year and semester below to accept registrations.
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($activeTerm): ?>
                <!-- Enrollment Status & Deadline Indicators -->
                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <div class="bg-white p-3 rounded-3 border border-light-subtle shadow-2xs">
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="text-muted small fw-semibold">Enrollment Window:</span>
                            <?php echo $activeEnrollmentStatus['badge_html']; ?>
                        </div>
                        <div class="small fw-bold text-dark font-monospace">
                            <i class="bi bi-clock-history text-brand-primary me-1"></i><?php echo htmlspecialchars($activeEnrollmentStatus['period_text']); ?>
                        </div>
                        <div class="mt-1 small text-muted">
                            <?php if ($activeEnrollmentStatus['deadline_date']): ?>
                                <span class="badge <?php echo $activeEnrollmentStatus['is_late'] ? 'bg-warning-subtle text-warning-emphasis' : 'bg-primary-soft text-brand-primary'; ?> border px-2 py-0.5 font-monospace">
                                    <?php echo htmlspecialchars($activeEnrollmentStatus['deadline_label']); ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">No registration deadline set</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Quick Admin Controls -->
                    <div class="d-flex flex-column gap-2">
                        <form action="../actions/academic_term_actions" method="POST" class="m-0">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                            <input type="hidden" name="action" value="toggle_enrollment">
                            <input type="hidden" name="term_id" value="<?php echo (int)$activeTerm['id']; ?>">
                            <?php if ((int)($activeTerm['is_enrollment_open'] ?? 1) === 1): ?>
                                <button type="submit" class="btn btn-outline-danger btn-sm w-100 fw-semibold d-flex align-items-center justify-content-center gap-1.5 shadow-2xs">
                                    <i class="bi bi-door-closed-fill"></i> Close Enrollment Now
                                </button>
                            <?php else: ?>
                                <button type="submit" class="btn btn-success btn-sm w-100 fw-semibold d-flex align-items-center justify-content-center gap-1.5 shadow-2xs">
                                    <i class="bi bi-door-open-fill"></i> Reopen Enrollment
                                </button>
                            <?php endif; ?>
                        </form>
                        <button type="button" class="btn btn-outline-secondary btn-sm fw-semibold" data-bs-toggle="modal" data-bs-target="#editTermModal<?php echo (int)$activeTerm['id']; ?>">
                            <i class="bi bi-pencil me-1"></i> Edit Configuration
                        </button>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- =========================================================================
     ACADEMIC TERMS DIRECTORY TABLE
     ========================================================================= -->
<div class="card card-premium shadow-sm border-0 rounded-4 overflow-hidden">
    <div class="card-header card-header-premium bg-white py-3 px-4 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h5 class="m-0 fw-bold text-navy-alt">Configured Terms & Deadlines</h5>
        <div class="d-flex align-items-center gap-2">
            <div class="input-group input-group-sm" style="width: 220px;">
                <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
                <input type="text" id="searchInput" class="form-control border-start-0" placeholder="Search terms...">
            </div>
            <span class="badge bg-light text-dark border"><?php echo count($terms); ?> Records</span>
        </div>
    </div>
    <div class="card-body card-body-premium p-0">
        <div>
            <table class="table table-hover table-maritime align-middle mb-0" id="termsTable" style="font-size: 0.86rem; width: 100%;">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4" tabulator-field="school_year" style="width: 140px;">School Year</th>
                        <th tabulator-field="semester" style="width: 120px;">Semester</th>
                        <th tabulator-field="duration">Term Duration</th>
                        <th tabulator-field="enrollment_period">Enrollment Period</th>
                        <th tabulator-field="deadlines">Registration Deadlines</th>
                        <th tabulator-field="status" style="width: 110px;">Status</th>
                        <th class="pe-4 text-end" tabulator-field="actions" style="width: 140px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!empty($terms)): ?>
                    <?php foreach ($terms as $term): 
                        $tStatus = getEnrollmentPeriodStatus($term);
                        $isActive = ((int)$term['is_active'] === 1);
                        $isEnrollOpen = ((int)($term['is_enrollment_open'] ?? 1) === 1);
                    ?>
                        <tr class="<?php echo $isActive ? 'table-light' : ''; ?>">
                            <td class="ps-4 fw-bold text-navy">
                                <?php echo htmlspecialchars($term['school_year']); ?>
                                <?php if ($isActive): ?>
                                    <span class="badge bg-brand-primary text-white ms-1" style="font-size: 0.65rem;">Active</span>
                                <?php endif; ?>
                            </td>
                            <td class="fw-semibold">
                                <?php echo htmlspecialchars(ucfirst($term['semester'])); ?> Semester
                            </td>
                            <td class="text-muted small">
                                <i class="bi bi-calendar3 me-1"></i>
                                <?php echo htmlspecialchars(($term['starts_on'] ? date('M j, Y', strtotime($term['starts_on'])) : 'No start') . ' to ' . ($term['ends_on'] ? date('M j, Y', strtotime($term['ends_on'])) : 'No end')); ?>
                                <div class="text-muted font-monospace mt-0.5" style="font-size: 0.72rem;">
                                    Max Load: <?php echo number_format((float)$term['max_units'], 1); ?> u · Req: <?php echo number_format((float)$term['payment_requirement_percent'], 0); ?>%
                                </div>
                            </td>
                            <td>
                                <?php if (!empty($term['enrollment_starts_on']) || !empty($term['enrollment_ends_on'])): ?>
                                    <div class="font-monospace small text-dark fw-semibold">
                                        <?php echo htmlspecialchars(($term['enrollment_starts_on'] ? date('M j, Y', strtotime($term['enrollment_starts_on'])) : 'Open') . ' – ' . ($term['enrollment_ends_on'] ? date('M j, Y', strtotime($term['enrollment_ends_on'])) : 'TBD')); ?>
                                    </div>
                                    <div class="mt-0.5">
                                        <?php if ($isEnrollOpen): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size: 0.68rem;">Open</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle" style="font-size: 0.68rem;">Closed</span>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="badge bg-light text-muted border" style="font-size: 0.72rem;">Not Set</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($term['registration_deadline'])): ?>
                                    <div class="small fw-semibold text-dark">
                                        <span class="text-muted small">Regular:</span> <?php echo date('M j, Y', strtotime($term['registration_deadline'])); ?>
                                    </div>
                                <?php else: ?>
                                    <span class="badge bg-light text-muted border" style="font-size: 0.7rem;">No Reg. Deadline</span>
                                <?php endif; ?>
                                <?php if (!empty($term['late_registration_deadline'])): ?>
                                    <div class="small text-muted mt-0.5">
                                        <span class="text-warning-emphasis fw-semibold">Late:</span> <?php echo date('M j, Y', strtotime($term['late_registration_deadline'])); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo $tStatus['badge_html']; ?>
                            </td>
                            <td class="pe-4 text-end">
                                <div class="btn-group btn-group-sm">
                                    <?php if (!$isActive && empty($term['is_archived'])): ?>
                                        <form action="../actions/academic_term_actions" method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                                            <input type="hidden" name="action" value="activate">
                                            <input type="hidden" name="term_id" value="<?php echo (int)$term['id']; ?>">
                                            <button type="submit" class="btn btn-outline-success btn-sm" title="Set as current active term">
                                                <i class="bi bi-check-circle me-1"></i>Activate
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    
                                    <?php if (empty($term['is_archived'])): ?>
                                        <form action="../actions/academic_term_actions" method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                                            <input type="hidden" name="action" value="toggle_enrollment">
                                            <input type="hidden" name="term_id" value="<?php echo (int)$term['id']; ?>">
                                            <button type="submit" class="btn btn-outline-secondary btn-sm" title="Toggle enrollment Open/Closed">
                                                <i class="bi <?php echo $isEnrollOpen ? 'bi-toggle-on text-success' : 'bi-toggle-off text-muted'; ?>"></i>
                                            </button>
                                        </form>

                                        <form action="../actions/academic_term_actions" method="POST" class="d-inline" onsubmit="return confirm('Archive/close this completed term? All LMS coursework and student grades will remain permanently viewable read-only.');">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                                            <input type="hidden" name="action" value="archive_term">
                                            <input type="hidden" name="term_id" value="<?php echo (int)$term['id']; ?>">
                                            <button type="submit" class="btn btn-outline-warning btn-sm" title="Archive / Close completed term">
                                                <i class="bi bi-archive"></i>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <form action="../actions/academic_term_actions" method="POST" class="d-inline" onsubmit="return confirm('Unarchive this academic term?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                                            <input type="hidden" name="action" value="unarchive_term">
                                            <input type="hidden" name="term_id" value="<?php echo (int)$term['id']; ?>">
                                            <button type="submit" class="btn btn-outline-info btn-sm" title="Unarchive term">
                                                <i class="bi bi-arrow-counterclockwise"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#editTermModal<?php echo (int)$term['id']; ?>" title="Edit term and deadlines">
                                        <i class="bi bi-pencil"></i>
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
     MODALS: EDIT ACADEMIC TERMS & ENROLLMENT DEADLINES
     ========================================================================= -->
<?php if (!empty($terms)): foreach ($terms as $term): ?>
    <div class="modal fade" id="editTermModal<?php echo (int)$term['id']; ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <form action="../actions/academic_term_actions" method="POST">
                    <div class="modal-header border-bottom py-3" style="background: var(--surface-tint);">
                        <h5 class="modal-title fw-bold text-navy">
                            <i class="bi bi-calendar-event me-1 text-brand-primary"></i> Edit Academic Term & Deadlines
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body p-4">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="term_id" value="<?php echo (int)$term['id']; ?>">

                        <!-- Section 1: Term Identity -->
                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label class="form-label fw-semibold small text-muted">School Year <span class="text-danger">*</span></label>
                                <input class="form-control font-monospace" name="school_year" value="<?php echo htmlspecialchars($term['school_year']); ?>" pattern="\d{4}-\d{4}" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-semibold small text-muted">Semester <span class="text-danger">*</span></label>
                                <select class="form-select" name="semester" required>
                                    <?php foreach (['1st', '2nd', 'summer'] as $sem): ?>
                                        <option value="<?php echo $sem; ?>" <?php echo $term['semester'] === $sem ? 'selected' : ''; ?>>
                                            <?php echo ucfirst($sem); ?> Semester
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Section 2: Term Official Dates -->
                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label class="form-label fw-semibold small text-muted">Term Starts On</label>
                                <input type="date" class="form-control" name="starts_on" value="<?php echo htmlspecialchars($term['starts_on'] ?? ''); ?>">
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-semibold small text-muted">Term Ends On</label>
                                <input type="date" class="form-control" name="ends_on" value="<?php echo htmlspecialchars($term['ends_on'] ?? ''); ?>">
                            </div>
                        </div>

                        <!-- Section 3: Active Enrollment Period & Registration Deadlines -->
                        <div class="p-3 bg-light rounded-3 border mb-3">
                            <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                                <h6 class="fw-bold text-navy m-0">
                                    <i class="bi bi-clock-history text-brand-primary me-1"></i> Enrollment Period & Deadlines
                                </h6>
                                <div class="form-check form-switch m-0">
                                    <input class="form-check-input" type="checkbox" name="is_enrollment_open" id="editEnrollOpenCheck<?php echo (int)$term['id']; ?>" value="1" <?php echo ((int)($term['is_enrollment_open'] ?? 1) === 1) ? 'checked' : ''; ?>>
                                    <label class="form-check-label small fw-semibold" for="editEnrollOpenCheck<?php echo (int)$term['id']; ?>">Enrollment Open</label>
                                </div>
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-6">
                                    <label class="form-label fw-semibold small text-muted">Enrollment Starts On</label>
                                    <input type="date" class="form-control" name="enrollment_starts_on" value="<?php echo htmlspecialchars($term['enrollment_starts_on'] ?? ''); ?>">
                                </div>
                                <div class="col-6">
                                    <label class="form-label fw-semibold small text-muted">Enrollment Ends On</label>
                                    <input type="date" class="form-control" name="enrollment_ends_on" value="<?php echo htmlspecialchars($term['enrollment_ends_on'] ?? ''); ?>">
                                </div>
                            </div>

                            <div class="row g-3">
                                <div class="col-6">
                                    <label class="form-label fw-semibold small text-muted">Regular Registration Deadline</label>
                                    <input type="date" class="form-control" name="registration_deadline" value="<?php echo htmlspecialchars($term['registration_deadline'] ?? ''); ?>">
                                </div>
                                <div class="col-6">
                                    <label class="form-label fw-semibold small text-muted">Late Registration Deadline</label>
                                    <input type="date" class="form-control" name="late_registration_deadline" value="<?php echo htmlspecialchars($term['late_registration_deadline'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>

                        <!-- Section 4: Academic & Financial Parameters -->
                        <div class="row g-3">
                            <div class="col-6 col-md-3">
                                <label class="form-label fw-semibold small text-muted">Payment Req. (%)</label>
                                <input type="number" class="form-control" name="payment_requirement_percent" min="0.01" max="100" step="0.01" value="<?php echo htmlspecialchars((float)($term['payment_requirement_percent'] ?? 100)); ?>" required>
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label fw-semibold small text-muted">Downpayment (%)</label>
                                <input type="number" class="form-control" name="downpayment_percentage" min="0" max="100" step="0.01" value="<?php echo htmlspecialchars((float)($term['downpayment_percentage'] ?? 30)); ?>" required>
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label fw-semibold small text-muted">Min. Downpayment (₱)</label>
                                <input type="number" class="form-control" name="minimum_downpayment" min="0" step="0.01" value="<?php echo htmlspecialchars((float)($term['minimum_downpayment'] ?? 0)); ?>" required>
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label fw-semibold small text-muted">Maximum Units</label>
                                <input type="number" class="form-control" name="max_units" min="1" max="60" step="0.5" value="<?php echo htmlspecialchars($term['max_units']); ?>" required>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-top py-2.5 px-4 bg-light">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-brand-primary fw-semibold"><i class="bi bi-check-lg me-1"></i> Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endforeach; endif; ?>

<!-- =========================================================================
     MODAL: ADD ACADEMIC TERM
     ========================================================================= -->
<div class="modal fade" id="addTermModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form action="../actions/academic_term_actions" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                <input type="hidden" name="action" value="create">
                <div class="modal-header border-bottom py-3" style="background: var(--surface-tint);">
                    <h5 class="modal-title fw-bold text-navy">
                        <i class="bi bi-calendar-plus me-1 text-brand-primary"></i> Add Academic Term
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <!-- Section 1: Term Identity -->
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold small text-muted">School Year <span class="text-danger">*</span></label>
                            <input class="form-control font-monospace" name="school_year" placeholder="2026-2027" pattern="\d{4}-\d{4}" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold small text-muted">Semester <span class="text-danger">*</span></label>
                            <select class="form-select" name="semester" required>
                                <option value="1st">1st Semester</option>
                                <option value="2nd">2nd Semester</option>
                                <option value="summer">Summer</option>
                            </select>
                        </div>
                    </div>

                    <!-- Section 2: Term Calendar Duration -->
                    <div class="row g-3 mb-4">
                        <div class="col-6">
                            <label class="form-label fw-semibold small text-muted">Semester Starts On</label>
                            <input type="date" class="form-control" name="starts_on">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold small text-muted">Semester Ends On</label>
                            <input type="date" class="form-control" name="ends_on">
                        </div>
                    </div>

                    <!-- Section 3: Active Enrollment Period & Registration Deadlines -->
                    <div class="p-3 bg-light rounded-3 border mb-4">
                        <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                            <h6 class="fw-bold text-navy m-0">
                                <i class="bi bi-clock-history text-brand-primary me-1"></i> Enrollment Period & Deadlines
                            </h6>
                            <div class="form-check form-switch m-0">
                                <input class="form-check-input" type="checkbox" name="is_enrollment_open" id="addEnrollOpenCheck" value="1" checked>
                                <label class="form-check-label small fw-semibold" for="addEnrollOpenCheck">Enrollment Open</label>
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label class="form-label fw-semibold small text-muted">Enrollment Starts On</label>
                                <input type="date" class="form-control" name="enrollment_starts_on">
                                <div class="form-text small">Date when enrollment opens for applicants/students.</div>
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-semibold small text-muted">Enrollment Ends On</label>
                                <input type="date" class="form-control" name="enrollment_ends_on">
                                <div class="form-text small">Final closing date of the enrollment window.</div>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-6">
                                <label class="form-label fw-semibold small text-muted">Regular Registration Deadline</label>
                                <input type="date" class="form-control" name="registration_deadline">
                                <div class="form-text small">Standard application cutoff date.</div>
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-semibold small text-muted">Late Registration Deadline</label>
                                <input type="date" class="form-control" name="late_registration_deadline">
                                <div class="form-text small">Final cutoff date for late registration.</div>
                            </div>
                        </div>
                    </div>

                    <!-- Section 4: Academic & Financial Parameters -->
                    <div class="row g-3">
                        <div class="col-6 col-md-3">
                            <label class="form-label fw-semibold small text-muted">Payment Req. (%)</label>
                            <input type="number" class="form-control" name="payment_requirement_percent" min="0.01" max="100" step="0.01" value="100" required>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label fw-semibold small text-muted">Downpayment (%)</label>
                            <input type="number" class="form-control" name="downpayment_percentage" min="0" max="100" step="0.01" value="30" required>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label fw-semibold small text-muted">Min. Downpayment (₱)</label>
                            <input type="number" class="form-control" name="minimum_downpayment" min="0" step="0.01" value="0" required>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label fw-semibold small text-muted">Maximum Units</label>
                            <input type="number" class="form-control" name="max_units" min="1" max="60" step="0.5" value="24" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top py-2.5 px-4 bg-light">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brand-primary fw-semibold"><i class="bi bi-check-lg me-1"></i> Create Term</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function stripHtml(html) {
        var tmp = document.createElement("DIV");
        tmp.innerHTML = html;
        return tmp.textContent || tmp.innerText || "";
    }

    let termsTable = null;
    document.addEventListener("DOMContentLoaded", function() {
        termsTable = new Tabulator("#termsTable", {
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
            pagination: true,
            paginationSize: 25,
            paginationSizeSelector: [10, 25, 50, 100],
            paginationCounter: "rows",
            placeholder: '<div class="text-center py-5 text-muted"><i class="bi bi-inbox fs-1 d-block mb-2 text-muted-light"></i>No academic terms found.</div>',
            columns: [
                {
                    title: "School Year",
                    field: "school_year",
                    formatter: "html",
                    minWidth: 140,
                    sorter: function(a, b) {
                        return stripHtml(a).localeCompare(stripHtml(b));
                    },
                    responsive: 0
                },
                {
                    title: "Semester",
                    field: "semester",
                    formatter: "html",
                    minWidth: 120,
                    sorter: function(a, b) {
                        return stripHtml(a).localeCompare(stripHtml(b));
                    },
                    responsive: 1
                },
                {
                    title: "Term Duration",
                    field: "duration",
                    formatter: "html",
                    minWidth: 160,
                    headerSort: false,
                    responsive: 2
                },
                {
                    title: "Enrollment Period",
                    field: "enrollment_period",
                    formatter: "html",
                    minWidth: 160,
                    headerSort: false,
                    responsive: 2
                },
                {
                    title: "Registration Deadlines",
                    field: "deadlines",
                    formatter: "html",
                    minWidth: 160,
                    headerSort: false,
                    responsive: 3
                },
                {
                    title: "Status",
                    field: "status",
                    formatter: "html",
                    minWidth: 110,
                    sorter: function(a, b) {
                        return stripHtml(a).localeCompare(stripHtml(b));
                    },
                    responsive: 1
                },
                {
                    title: "Actions",
                    field: "actions",
                    formatter: "html",
                    headerSort: false,
                    hozAlign: "right",
                    minWidth: 140,
                    responsive: 0
                }
            ]
        });

        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                const val = (this.value || '').toLowerCase().trim();
                if (!val) {
                    termsTable.clearFilter();
                } else {
                    termsTable.setFilter(function(data) {
                        return stripHtml(data.school_year || '').toLowerCase().includes(val) ||
                               stripHtml(data.semester || '').toLowerCase().includes(val) ||
                               stripHtml(data.status || '').toLowerCase().includes(val);
                    });
                }
            });
        }
    });
</script>

<?php require_once '../includes/footer.php'; ?>

