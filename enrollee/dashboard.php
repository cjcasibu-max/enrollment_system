<?php
require_once '../includes/auth_check.php';
checkRole(['enrollee']);

require_once '../config/database.php';
require_once '../includes/assessments.php';
global $pdo;

if (!($pdo instanceof PDO)) {
    throw new RuntimeException('Database connection is not initialized.');
}

$userId = (int)$_SESSION['user_id'];
$student = null;
$paymentStatus = 'Not started';
$showPayButton = false;
$uploadedDocsCount = 0;
$remarks = [];
$studentAssessment = null;

// Dynamic Hero Button HTML helper — $status = application_status, $enrStatus = enrollment_status
function getHeroActionBtn($status, $enrStatus) {
    // Post-approval stages are driven by enrollment_status
    if ($enrStatus === 'walk_in_ready') {
        return '<a href="my_section" class="btn btn-dashboard-primary" style="background:linear-gradient(90deg,#f59e0b,#d97706);"><i class="bi bi-cash-coin me-2"></i>Proceed to Cashier</a>';
    }
    if ($enrStatus === 'section_chosen') {
        return '<a href="my_section" class="btn btn-dashboard-primary" style="background:linear-gradient(90deg,#3b82f6,#2563eb);"><i class="bi bi-building-fill me-2"></i>View My Section</a>';
    }
    if (in_array($enrStatus, ['paid', 'enrolled'], true)) {
        return '<a href="my_section" class="btn btn-dashboard-primary"><i class="bi bi-check-circle-fill me-2"></i>Enrollment Complete</a>';
    }
    // Pre-section stages are driven by application_status
    if ($status === 'eligible_to_enroll') {
        return '<a href="sections" class="btn btn-dashboard-primary" style="background:linear-gradient(90deg,#00c98e,#00a878);"><i class="bi bi-grid-3x3-gap-fill me-2"></i>Choose Your Section</a>';
    }
    if ($status === 'pending' || $status === 'under_review') {
        return '<a href="apply" class="btn btn-dashboard-primary disabled" style="opacity: 0.75; cursor: not-allowed;"><i class="bi bi-send-check-fill me-2"></i>Application Under Review</a>';
    }
    if ($status === 'needs_revision') {
        return '<a href="apply" class="btn btn-dashboard-primary btn-pulse-warning"><i class="bi bi-exclamation-triangle-fill me-2"></i>Revise Application</a>';
    }
    if ($status === 'rejected') {
        return '<a href="apply" class="btn btn-dashboard-primary bg-danger border-danger"><i class="bi bi-x-circle-fill me-2"></i>Application Rejected</a>';
    }
    if ($status === 'draft') {
        return '<a href="apply" class="btn btn-dashboard-primary"><i class="bi bi-file-earmark-plus me-2"></i>Complete Application</a>';
    }
    return '<a href="apply" class="btn btn-dashboard-primary"><i class="bi bi-file-earmark-plus me-2"></i>Start Application</a>';
}

// Fetch initial data
try {
    $stmt = $pdo->prepare("SELECT * FROM students WHERE user_id = :user_id LIMIT 1");
    $stmt->execute(['user_id' => $userId]);
    $student = $stmt->fetch();

    if ($student) {
        $status        = $student['application_status'] ?? 'draft';
        $paymentStatus = $student['enrollment_status']  ?? 'draft';
        $showPayButton = in_array($paymentStatus, ['paid', 'enrolled'], true);

        // Fetch documents count
        $docCountStmt = $pdo->prepare("SELECT COUNT(*) FROM documents WHERE student_id = :student_id");
        $docCountStmt->execute(['student_id' => $student['id']]);
        $uploadedDocsCount = (int)$docCountStmt->fetchColumn();

        // Fetch remarks
        $remarkStmt = $pdo->prepare('SELECT ar.remark, ar.created_at, u.username FROM application_remarks ar JOIN users u ON u.id = ar.author_id WHERE ar.student_id = :student_id ORDER BY ar.created_at DESC');
        $remarkStmt->execute(['student_id' => (int)$student['id']]);
        $remarks = $remarkStmt->fetchAll();

        // Check if student has selected their section
        $hasSelectedSection = in_array($paymentStatus, ['section_chosen', 'walk_in_ready', 'paid', 'enrolled'], true);
        if (!$hasSelectedSection && !empty($student['id'])) {
            $secStmt = $pdo->prepare("SELECT 1 FROM enrollments WHERE student_id = :sid AND status != 'dropped' LIMIT 1");
            $secStmt->execute(['sid' => (int)$student['id']]);
            if ($secStmt->fetchColumn()) {
                $hasSelectedSection = true;
            }
        }

        // Fetch assessment summary only after student has selected their section
        $studentAssessment = null;
        if ($hasSelectedSection) {
            try {
                $assStmt = $pdo->prepare("SELECT id FROM assessments WHERE student_id = :sid AND status != 'cancelled' ORDER BY generated_at DESC, id DESC LIMIT 1");
                $assStmt->execute(['sid' => (int)$student['id']]);
                $assId = (int)($assStmt->fetchColumn() ?: 0);
                if ($assId > 0) {
                    $studentAssessment = getAssessmentTotals($pdo, $assId);
                }
            } catch (\Throwable $e) {}
        }
    }
} catch (Throwable $e) {
    error_log('Enrollee dashboard fetch failed: ' . $e->getMessage());
}

// ── REALTIME AJAX STATS ENDPOINT ───────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    header('Content-Type: application/json');
    $response = [
        'success' => false,
        'data' => null
    ];
    try {
        $stmt = $pdo->prepare("SELECT * FROM students WHERE user_id = :user_id LIMIT 1");
        $stmt->execute(['user_id' => $userId]);
        $student = $stmt->fetch();

        if ($student) {
            $status        = $student['application_status'] ?? 'draft';
            $paymentStatus = $student['enrollment_status']  ?? 'draft';
            $showPayButton = in_array($paymentStatus, ['paid', 'enrolled'], true);

            $docCountStmt = $pdo->prepare("SELECT COUNT(*) FROM documents WHERE student_id = :student_id");
            $docCountStmt->execute(['student_id' => $student['id']]);
            $uploadedDocsCount = (int)$docCountStmt->fetchColumn();

            // Next step — post-section stages are driven by enrollment_status
            if ($paymentStatus === 'walk_in_ready') {
                $nextStep = 'Go to Cashier';
                $nextAction = 'Pay Enrollment Fees';
                $workspace  = 'Cashier\'s Office';
            } elseif ($paymentStatus === 'section_chosen') {
                $nextStep = 'Section Reserved';
                $nextAction = 'Await Next Enrollment Update';
                $workspace  = 'Registrar Review';
            } elseif (in_array($paymentStatus, ['paid','enrolled'], true)) {
                $nextStep = 'Enrolled!';
                $nextAction = 'Admitted & Enrolled';
                $workspace  = 'Student Academy';
            } elseif ($status === 'eligible_to_enroll') {
                $nextStep = 'Choose Section';
                $nextAction = 'Browse & Select Class Section';
                $workspace  = 'Online Portal';
            } elseif ($status === 'needs_revision') {
                $nextStep = 'Revise Docs';
                $nextAction = 'Submit Revisions';
                $workspace  = 'Admissions Office';
            } elseif ($status === 'pending' || $status === 'under_review') {
                $nextStep = 'Awaiting Review';
                $nextAction = 'Await Registrar Review';
                $workspace  = 'Registrar\'s Office';
            } else {
                $nextStep = 'Complete Form';
                $nextAction = 'Complete Form';
                $workspace  = 'Admissions Office';
            }

            // Check if student has selected their section
            $hasSelectedSection = in_array($paymentStatus, ['section_chosen', 'walk_in_ready', 'paid', 'enrolled'], true);
            if (!$hasSelectedSection && !empty($student['id'])) {
                $secStmt = $pdo->prepare("SELECT 1 FROM enrollments WHERE student_id = :sid AND status != 'dropped' LIMIT 1");
                $secStmt->execute(['sid' => (int)$student['id']]);
                if ($secStmt->fetchColumn()) {
                    $hasSelectedSection = true;
                }
            }

            // Fetch assessment for AJAX only after student has selected their section
            $ajaxAssessment = null;
            if ($hasSelectedSection) {
                try {
                    $assStmt = $pdo->prepare("SELECT id FROM assessments WHERE student_id = :sid AND status != 'cancelled' ORDER BY generated_at DESC, id DESC LIMIT 1");
                    $assStmt->execute(['sid' => (int)$student['id']]);
                    $assId = (int)($assStmt->fetchColumn() ?: 0);
                    if ($assId > 0) {
                        $ajaxAssessment = getAssessmentTotals($pdo, $assId);
                    }
                } catch (\Throwable $e) {}
            }

            $isTuitionFinalized = !empty($ajaxAssessment['is_finalized']);
            $tuitionAmountFormatted = ($hasSelectedSection && $ajaxAssessment) ? '₱' . number_format((float)$ajaxAssessment['total_amount'], 2) : '—';
            $tuitionLabel = $isTuitionFinalized ? 'Finalized Tuition Assessment' : 'Estimated Tuition Assessment';
            $tuitionBadge = $isTuitionFinalized 
                ? '<span class="badge bg-success text-white px-2.5 py-1" style="font-size:0.68rem; font-weight:700;">Finalized by Registrar</span>'
                : '<span class="badge bg-warning text-dark border border-warning px-2.5 py-1" style="font-size:0.68rem; font-weight:700;">Pending Finalization</span>';

            $response = [
                'success' => true,
                'data' => [
                    'application_status' => htmlspecialchars(ucwords(str_replace('_', ' ', $status))),
                    'payment_status'     => htmlspecialchars(ucwords(str_replace('_', ' ', $paymentStatus))),
                    'credentials_count'  => $uploadedDocsCount . ' Uploaded',
                    'next_step'          => $nextStep,
                    'next_action'        => $nextAction,
                    'workspace'          => $workspace,
                    'hero_btn_html'      => getHeroActionBtn($status, $paymentStatus),
                    'show_pay_button'    => $showPayButton,
                    'has_selected_section' => $hasSelectedSection,
                    'tuition_amount'     => $tuitionAmountFormatted,
                    'is_tuition_finalized' => $isTuitionFinalized,
                    'tuition_label'      => $tuitionLabel,
                    'tuition_badge'      => $tuitionBadge
                ]
            ];
        } else {
            $response = [
                'success' => true,
                'data' => [
                    'application_status' => 'Not Started',
                    'payment_status' => 'Not Started',
                    'credentials_count' => '0 Uploaded',
                    'next_step' => 'Complete Profile',
                    'next_action' => 'Submit Application',
                    'workspace' => 'Admissions Office',
                    'hero_btn_html' => getHeroActionBtn('draft', 'draft'),
                    'show_pay_button' => false
                ]
            ];
        }
    } catch (Throwable $e) {
        error_log('Enrollee dashboard AJAX error: ' . $e->getMessage());
        $response['error'] = 'An internal error occurred. Please try again.';
    }
    echo json_encode($response);
    exit;
}

$page_title = 'Applicant Dashboard';
$page_class = 'page-dashboard page-enrollee-dashboard';
require_once '../includes/header.php';

// Initial state — post-section stages are driven by enrollment_status
$initialEnrStatus = $student['enrollment_status'] ?? 'draft';
$initialStatus = $student['application_status'] ?? 'draft';
if ($initialEnrStatus === 'walk_in_ready') {
    $initialNextStep   = 'Go to Cashier';
    $initialNextAction = 'Pay Enrollment Fees';
    $initialWorkspace  = 'Cashier\'s Office';
    $initialStatus     = 'walk_in_ready'; // for statusConfig lookup
} elseif ($initialEnrStatus === 'section_chosen') {
    $initialNextStep   = 'Section Reserved';
    $initialNextAction = 'Await Next Enrollment Update';
    $initialWorkspace  = 'Registrar Review';
    $initialStatus     = 'section_chosen';
} elseif (in_array($initialEnrStatus, ['paid','enrolled'], true)) {
    $initialNextStep   = 'Enrolled!';
    $initialNextAction = 'Admitted & Enrolled';
    $initialWorkspace  = 'Student Academy';
    $initialStatus     = $initialEnrStatus;
} elseif ($initialStatus === 'eligible_to_enroll') {
    $initialNextStep   = 'Choose Section';
    $initialNextAction = 'Browse & Select Class Section';
    $initialWorkspace  = 'Online Portal';
} elseif ($initialStatus === 'needs_revision') {
    $initialNextStep   = 'Submit Revisions';
    $initialNextAction = 'Submit Revisions';
    $initialWorkspace  = 'Admissions Office';
} elseif ($initialStatus === 'pending' || $initialStatus === 'under_review') {
    $initialNextStep   = 'Awaiting Review';
    $initialNextAction = 'Await Registrar Review';
    $initialWorkspace  = 'Registrar\'s Office';
} else {
    $initialNextStep   = 'Complete Profile';
    $initialNextAction = 'Submit Application';
    $initialWorkspace  = 'Admissions Office';
}

// Configuration mappings for Modal Status Indicators
$statusConfigs = [
    'draft' => [
        'icon' => 'bi-pencil-square',
        'color' => '#6c757d',
        'bg' => '#f1f3f5',
        'title' => 'Application Saved as Draft',
        'desc' => 'Your application has not been submitted yet. You can edit and complete all mandatory fields, then click submit.'
    ],
    'pending' => [
        'icon' => 'bi-clock-history',
        'color' => '#d48d00',
        'bg' => '#fffbeb',
        'title' => 'Pending Registrar Auditing',
        'desc' => 'Your application details and document uploads are currently under administrative verification. Please wait for registrar feedback.'
    ],
    'under_review' => [
        'icon' => 'bi-search',
        'color' => '#17a2b8',
        'bg' => '#eef9fa',
        'title' => 'Application Under Active Review',
        'desc' => 'The Admissions team is reviewing your profile. No further edits can be made while the review process is active.'
    ],
    'needs_revision' => [
        'icon' => 'bi-exclamation-triangle-fill',
        'color' => '#dc3545',
        'bg' => '#fdf2f2',
        'title' => 'Revision Required',
        'desc' => 'The Registrar reviewed your documents and requested revisions. Please check the feedback notes and adjust your submissions.'
    ],
    'approved' => [
        'icon' => 'bi-shield-check',
        'color' => '#0d7a7a',
        'bg' => '#f0faf9',
        'title' => 'Admissions Profile Approved!',
        'desc' => 'Congratulations! Your profile is verified and approved. You may now choose your class section.'
    ],
    'eligible_to_enroll' => [
        'icon' => 'bi-grid-3x3-gap-fill',
        'color' => '#00c98e',
        'bg' => '#f0fdf9',
        'title' => 'You Are Eligible to Enroll!',
        'desc' => 'Your application has been approved. Browse available sections and select the one you want to enroll in.'
    ],
    'section_chosen' => [
        'icon' => 'bi-building-fill',
        'color' => '#3b82f6',
        'bg' => '#eff6ff',
        'title' => 'Section Chosen',
        'desc' => 'Your section has been reserved. Please wait for the next enrollment update from the Registrar.'
    ],
    'walk_in_ready' => [
        'icon' => 'bi-cash-coin',
        'color' => '#f59e0b',
        'bg' => '#fffbeb',
        'title' => 'Ready for Cashier Payment',
        'desc' => 'Your enrollment is ready for payment. Please proceed to the Cashier\'s office to pay your enrollment fee.'
    ],
    'paid' => [
        'icon' => 'bi-check2-circle',
        'color' => '#198754',
        'bg' => '#f0faf5',
        'title' => 'Enrollment Fee Paid',
        'desc' => 'Your payment receipt was verified. The Registrar office is completing your placement registration.'
    ],
    'enrolled' => [
        'icon' => 'bi-bookmark-check-fill',
        'color' => '#198754',
        'bg' => '#f0faf5',
        'title' => 'Enrollment Process Completed',
        'desc' => 'Welcome to NCST Maritime Academy! Your enrollment is active.'
    ],
    'rejected' => [
        'icon' => 'bi-x-circle-fill',
        'color' => '#c0392b',
        'bg' => '#fdf2f2',
        'title' => 'Application Rejected',
        'desc' => 'Unfortunately, your profile did not qualify. Please contact the admissions helpdesk.'
    ]
];

$activeConfig = $statusConfigs[$initialStatus] ?? $statusConfigs['draft'];
?>

<style>
/* Clean pulse effect for revision status actions */
.btn-pulse-warning {
    animation: warningPulse 2s infinite alternate;
}
@keyframes warningPulse {
    0% { box-shadow: 0 0 0 0 rgba(220, 100, 30, 0.4); }
    100% { box-shadow: 0 0 0 8px rgba(220, 100, 30, 0); }
}

.clickable-stat-card {
    cursor: pointer;
    transition: transform 0.15s ease, box-shadow 0.15s ease !important;
}
.clickable-stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 22px rgba(10, 46, 53, 0.08) !important;
}
</style>

<section class="dashboard-hero mb-4">
    <div>
        <div class="page-eyebrow"><i class="bi bi-compass me-1"></i> Applicant workspace</div>
        <h1 class="dashboard-title">Applicant Dashboard</h1>
        <p class="dashboard-subtitle">Welcome back, <?php echo htmlspecialchars($_SESSION['username'] ?? 'Applicant'); ?>. Keep track of your admission status, required credentials, and next steps.</p>
    </div>
    <div class="dashboard-actions d-flex flex-wrap gap-2" id="dashboard-hero-actions">
        <button type="button" class="btn btn-dashboard-secondary" data-bs-toggle="modal" data-bs-target="#statusModal">
            <i class="bi bi-clipboard-check me-2"></i>View Status
        </button>
        <?php echo getHeroActionBtn($student['application_status'] ?? 'draft', $initialEnrStatus); ?>
    </div>
</section>

<section class="row g-3 mb-4 dashboard-kpis">
    <div class="col-12 col-sm-6 col-xl-3" data-bs-toggle="modal" data-bs-target="#statusModal">
        <div class="dashboard-stat-card clickable-stat-card">
            <div class="stat-label">Application Status</div>
            <div class="stat-value" id="kpi-application-status" style="transition: opacity 0.25s ease;"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $initialStatus ?? 'draft'))); ?></div>
            <div class="stat-trend"><i class="bi bi-clock"></i> Current admission stage</div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3" data-bs-toggle="modal" data-bs-target="#statusModal">
        <div class="dashboard-stat-card clickable-stat-card">
            <div class="stat-label">Payment Status</div>
            <div class="stat-value" id="kpi-payment-status" style="transition: opacity 0.25s ease;"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $paymentStatus))); ?></div>
            <div class="stat-trend"><i class="bi bi-wallet2"></i> Enrollment payment stage</div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3" data-bs-toggle="modal" data-bs-target="#statusModal">
        <div class="dashboard-stat-card clickable-stat-card">
            <div class="stat-label">Credentials</div>
            <div class="stat-value" id="kpi-credentials-count" style="transition: opacity 0.25s ease;"><?php echo $uploadedDocsCount; ?> Uploaded</div>
            <div class="stat-trend"><i class="bi bi-file-earmark-text"></i> Documents ready</div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3" data-bs-toggle="modal" data-bs-target="#statusModal">
        <div class="dashboard-stat-card clickable-stat-card">
            <div class="stat-label">Next Step</div>
            <div class="stat-value" id="kpi-next-step" style="transition: opacity 0.25s ease;"><?php echo $initialNextStep; ?></div>
            <div class="stat-trend trend-up"><i class="bi bi-arrow-right"></i> Follow enrollment steps</div>
        </div>
    </div>
</section>

<div class="card border-0 shadow-sm mb-4" id="dashboard-tuition-card" style="border-radius: 14px; overflow: hidden; <?php echo (!$studentAssessment || empty($hasSelectedSection)) ? 'display: none;' : ''; ?> border: 1px solid <?php echo !empty($studentAssessment['is_finalized']) ? '#bbf7d0' : '#fde68a'; ?> !important; background: <?php echo !empty($studentAssessment['is_finalized']) ? 'linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%)' : 'linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%)'; ?>;">
    <div class="d-flex align-items-center justify-content-between gap-3 px-4 py-3 flex-wrap">
        <div class="d-flex align-items-center gap-3">
            <div class="rounded-circle text-white d-flex align-items-center justify-content-center flex-shrink-0" id="kpi-tuition-icon-wrapper" style="width: 44px; height: 44px; background: <?php echo !empty($studentAssessment['is_finalized']) ? '#16a34a' : '#d97706'; ?>;">
                <i class="bi <?php echo !empty($studentAssessment['is_finalized']) ? 'bi-patch-check-fill' : 'bi-cash-coin'; ?> fs-5" id="kpi-tuition-icon"></i>
            </div>
            <div>
                <div class="d-flex align-items-center gap-2">
                    <span class="fw-bold text-dark fs-6" id="kpi-tuition-label">
                        <?php echo !empty($studentAssessment['is_finalized']) ? 'Finalized Tuition Assessment' : 'Estimated Tuition Assessment'; ?>
                    </span>
                    <span id="kpi-tuition-badge">
                        <?php if (!empty($studentAssessment['is_finalized'])): ?>
                            <span class="badge bg-success text-white px-2.5 py-1" style="font-size:0.68rem; font-weight:700;">Finalized by Registrar</span>
                        <?php else: ?>
                            <span class="badge bg-warning text-dark border border-warning px-2.5 py-1" style="font-size:0.68rem; font-weight:700;">Pending Finalization</span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="text-muted small" id="kpi-tuition-desc">
                    <?php if (!empty($studentAssessment['is_finalized'])): ?>
                        Confirmed total tuition billable: <strong class="text-success fs-6" id="kpi-tuition-amount">₱<?php echo number_format((float)$studentAssessment['total_amount'], 2); ?></strong>
                        <?php if (!empty($studentAssessment['finalization_notes'])): ?>
                            &bull; <em><?php echo htmlspecialchars($studentAssessment['finalization_notes']); ?></em>
                        <?php endif; ?>
                    <?php else: ?>
                        Auto-calculated tuition basis: <strong class="text-dark fs-6" id="kpi-tuition-amount">₱<?php echo number_format((float)($studentAssessment['calculated_amount'] ?? $studentAssessment['total_amount'] ?? 0), 2); ?></strong> (Official amount will be finalized by Registrar)
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <a href="payment" id="kpi-tuition-btn" class="btn btn-sm <?php echo !empty($studentAssessment['is_finalized']) ? 'btn-success' : 'btn-outline-dark'; ?> fw-semibold px-3 shadow-sm">
            <i class="bi bi-receipt me-1"></i> View Fee Breakdown
        </a>
    </div>
</div>

<section class="row g-3">
    <div class="col-12 col-xl-8">
        <div class="dashboard-panel h-100">
            <div class="panel-heading">
                <div>
                    <h2>Applicant Desk</h2>
                    <p>Complete the required steps to finish admissions and enrollment.</p>
                </div>
            </div>
            <div class="quick-actions-grid">
                <a href="apply" class="quick-action"><span class="quick-action-icon"><i class="bi bi-file-earmark-plus"></i></span><span><strong>Application Form</strong><small>Update your personal and academic details</small></span><i class="bi bi-chevron-right ms-auto"></i></a>
                <a href="apply" class="quick-action"><span class="quick-action-icon"><i class="bi bi-eye"></i></span><span><strong>Review Application</strong><small>Preview your profile and check uploads</small></span><i class="bi bi-chevron-right ms-auto"></i></a>
                <a href="#" class="quick-action" data-bs-toggle="modal" data-bs-target="#statusModal"><span class="quick-action-icon"><i class="bi bi-clipboard-check"></i></span><span><strong>Application Status</strong><small>Track decisions and required revisions</small></span><i class="bi bi-chevron-right ms-auto"></i></a>
                <?php if ($showPayButton): ?>
                    <a href="payment" class="quick-action" id="quick-action-payment"><span class="quick-action-icon"><i class="bi bi-wallet2"></i></span><span><strong>Pay Now</strong><small>Review assessment and payment options to enroll</small></span><i class="bi bi-chevron-right ms-auto"></i></a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-4">
        <div class="h-100" style="
            background: linear-gradient(135deg, #0b4f5c 0%, #0d7a7a 50%, #12a89e 100%);
            border-radius: 14px;
            padding: 1.5rem 1.4rem;
            box-shadow: 0 8px 32px rgba(11,79,92,.28);
            position: relative;
            overflow: hidden;
        ">
            <!-- Decorative circles -->
            <span style="position:absolute;top:-28px;right:-28px;width:120px;height:120px;border-radius:50%;background:rgba(255,255,255,.07);pointer-events:none;"></span>
            <span style="position:absolute;bottom:-40px;left:-20px;width:160px;height:160px;border-radius:50%;background:rgba(255,255,255,.05);pointer-events:none;"></span>

            <!-- Heading -->
            <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:1.3rem;position:relative;">
                <div>
                    <div style="color:rgba(255,255,255,.6);font-size:.68rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;margin-bottom:.2rem;">Account Overview</div>
                    <div style="color:#fff;font-size:.82rem;opacity:.8;">Your current access level</div>
                </div>
                <span style="background:rgba(255,255,255,.18);backdrop-filter:blur(6px);border:1px solid rgba(255,255,255,.25);border-radius:999px;color:#fff;font-size:.68rem;font-weight:750;padding:.35rem .75rem;white-space:nowrap;">
                    <i class="bi bi-check-circle-fill me-1" style="color:#6ef5d4;"></i> Active
                </span>
            </div>

            <!-- Avatar + Name -->
            <div style="align-items:center;display:flex;gap:1rem;padding-bottom:1.15rem;border-bottom:1px solid rgba(255,255,255,.15);margin-bottom:1rem;position:relative;">
                <div style="
                    align-items:center;
                    background:rgba(255,255,255,.18);
                    border:2.5px solid rgba(255,255,255,.35);
                    border-radius:50%;
                    color:#fff;
                    display:flex;
                    font-size:1.05rem;
                    font-weight:800;
                    height:52px;
                    justify-content:center;
                    letter-spacing:-.02em;
                    width:52px;
                    flex-shrink:0;
                    box-shadow:0 4px 14px rgba(0,0,0,.18);
                "><?php echo htmlspecialchars(strtoupper(substr($_SESSION['username'] ?? 'A', 0, 2))); ?></div>
                <div>
                    <div style="color:#fff;font-size:1rem;font-weight:750;line-height:1.2;"><?php echo htmlspecialchars($_SESSION['username'] ?? 'Applicant'); ?></div>
                    <div style="color:rgba(255,255,255,.6);font-size:.75rem;margin-top:.2rem;">Applicant account</div>
                </div>
            </div>

            <!-- Detail rows -->
            <div style="position:relative;">
                <div style="display:flex;justify-content:space-between;align-items:center;padding:.6rem 0;border-bottom:1px solid rgba(255,255,255,.12);">
                    <span style="color:rgba(255,255,255,.6);font-size:.77rem;">Role</span>
                    <strong style="color:#fff;font-size:.8rem;background:rgba(255,255,255,.15);padding:.2rem .65rem;border-radius:999px;border:1px solid rgba(255,255,255,.2);">Enrollee</strong>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:.6rem 0;border-bottom:1px solid rgba(255,255,255,.12);">
                    <span style="color:rgba(255,255,255,.6);font-size:.77rem;">Workspace</span>
                    <strong style="color:#fff;font-size:.8rem;transition: opacity 0.25s ease;" id="overview-workspace"><?php echo $initialWorkspace; ?></strong>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:.6rem 0;">
                    <span style="color:rgba(255,255,255,.6);font-size:.77rem;">Next Action</span>
                    <strong style="color:#6ef5d4;font-size:.8rem;transition: opacity 0.25s ease;" id="overview-next-action"><?php echo $initialNextAction; ?></strong>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- =========================================================================
     APPLICATION STATUS MODAL (Merged from Check Status Page)
     ========================================================================= -->
<div class="modal fade" id="statusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg" style="border-radius:18px;overflow:hidden;">

            <!-- Header Gradient Toolbar -->
            <div class="modal-header border-0 px-4 py-4" style="background:linear-gradient(135deg,#0b4f5c 0%,#0d7a7a 60%,#12a89e 100%);">
                <div class="d-flex align-items-center gap-3">
                    <div style="background:rgba(255,255,255,.15);border-radius:12px;padding:.5rem .65rem;">
                        <i class="bi bi-info-circle-fill fs-5 text-white"></i>
                    </div>
                    <div>
                        <div style="font-size:.65rem;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:rgba(255,255,255,.6);">Status Tracker</div>
                        <div style="font-size:1.1rem;font-weight:800;color:#fff;line-height:1.2;">Admission Status Summary</div>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <!-- Modal Body -->
            <div class="modal-body px-4 py-4" style="background:#f8fafb;">

                <!-- Status Detail Banner -->
                <div class="p-4 mb-4 text-center rounded-3 border" id="modal-status-banner" style="background:<?php echo $activeConfig['bg']; ?>; border-color: rgba(13,122,122,.08);">
                    <div class="mb-3">
                        <i class="bi <?php echo $activeConfig['icon']; ?>" id="modal-status-icon" style="font-size: 3rem; color: <?php echo $activeConfig['color']; ?>;"></i>
                    </div>
                    <h4 class="fw-bold mb-2 text-navy" id="modal-status-title"><?php echo $activeConfig['title']; ?></h4>
                    <p class="text-muted small mx-auto mb-0" style="max-width:550px;" id="modal-status-desc"><?php echo $activeConfig['desc']; ?></p>
                </div>

                <!-- Revision notes box if revision is needed -->
                <?php if (!empty($student['revision_notes'])): ?>
                    <div class="alert alert-warning border-0 shadow-sm p-3 mb-4" id="modal-revision-notes-container">
                        <div class="d-flex align-items-start gap-2.5">
                            <i class="bi bi-exclamation-triangle-fill text-warning fs-5"></i>
                            <div>
                                <strong class="text-dark small d-block mb-1">Registrar Revision Feedback Notes:</strong>
                                <p class="mb-0 text-dark small"><?php echo nl2br(htmlspecialchars($student['revision_notes'])); ?></p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Registrar Remarks Section -->
                <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px; overflow:hidden;">
                    <div class="card-header bg-white py-3 border-0 d-flex align-items-center gap-2">
                        <i class="bi bi-chat-left-text text-brand-primary"></i>
                        <h6 class="m-0 fw-bold text-navy">Registrar Audit Comments</h6>
                    </div>
                    <div class="card-body p-3">
                        <?php if (empty($remarks)): ?>
                            <p class="text-muted small mb-0"><i class="bi bi-info-circle me-1"></i> No audit remarks have been posted yet.</p>
                        <?php else: ?>
                            <div style="max-height: 240px; overflow-y: auto;">
                                <?php foreach ($remarks as $remark): ?>
                                    <div class="border-bottom pb-2 mb-2 last-child-border-0">
                                        <p class="mb-1 text-dark small" style="line-height: 1.4;"><?php echo nl2br(htmlspecialchars($remark['remark'])); ?></p>
                                        <small class="text-muted" style="font-size: .68rem;">Auditor · <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($remark['created_at']))); ?></small>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Profile Summary Summary Table -->
                <?php if ($student): ?>
                    <div class="card border-0 shadow-sm" style="border-radius: 12px; overflow:hidden;">
                        <div class="card-header bg-white py-3 border-0">
                            <h6 class="m-0 fw-bold text-navy">Submitted Applicant Profile Info</h6>
                        </div>
                        <div class="card-body p-0">
                            <table class="table table-striped table-borderless m-0 table-sm small">
                                <tbody>
                                    <tr>
                                        <th class="ps-3 py-2 text-muted w-35">Reference ID</th>
                                        <td class="py-2 text-dark font-monospace fw-bold">#NCST-<?php echo date('Y'); ?>-<?php echo str_pad((int)($student['id']), 4, '0', STR_PAD_LEFT); ?></td>
                                    </tr>
                                    <tr>
                                        <th class="ps-3 py-2 text-muted">First Name</th>
                                        <td class="py-2 fw-semibold text-dark"><?php echo htmlspecialchars($student['first_name']); ?></td>
                                    </tr>
                                    <tr>
                                        <th class="ps-3 py-2 text-muted">Last Name</th>
                                        <td class="py-2 fw-semibold text-dark"><?php echo htmlspecialchars($student['last_name']); ?></td>
                                    </tr>
                                    <tr>
                                        <th class="ps-3 py-2 text-muted">Birthdate</th>
                                        <td class="py-2 text-dark"><?php echo date('F d, Y', strtotime($student['birthdate'])); ?></td>
                                    </tr>
                                    <tr>
                                        <th class="ps-3 py-2 text-muted">Applied Date</th>
                                        <td class="py-2 text-muted"><?php echo date('M d, Y h:i A', strtotime($student['created_at'])); ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

            </div>

            <!-- Modal Footer -->
            <div class="modal-footer border-0 px-4 py-3 bg-light">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                <?php if ($student && in_array($student['application_status'], ['draft', 'needs_revision'], true)): ?>
                    <a href="apply" class="btn btn-sm btn-brand-primary">
                        <i class="bi bi-pencil-square me-1"></i> Edit Application
                    </a>
                <?php elseif ($showPayButton): ?>
                    <a href="payment" class="btn btn-sm btn-brand-primary">
                        <i class="bi bi-wallet2 me-1"></i> Proceed to Payment
                    </a>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>

<!-- Real-time Dashboard Polling Script -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Dismiss active floating toast notifications when any modal opens to guarantee unobstructed close buttons
    const dismissActiveToasts = function () {
        if (typeof Swal !== 'undefined' && Swal.isVisible()) {
            Swal.close();
        }
    };
    document.addEventListener('show.bs.modal', dismissActiveToasts, true);
    const statusModalEl = document.getElementById('statusModal');
    if (statusModalEl) {
        statusModalEl.addEventListener('show.bs.modal', dismissActiveToasts);
    }
    document.querySelectorAll('[data-bs-toggle="modal"]').forEach(function (btn) {
        btn.addEventListener('click', dismissActiveToasts);
    });

    const kpiElements = {
        applicationStatus: document.getElementById('kpi-application-status'),
        paymentStatus: document.getElementById('kpi-payment-status'),
        credentialsCount: document.getElementById('kpi-credentials-count'),
        nextStep: document.getElementById('kpi-next-step'),
        overviewNextAction: document.getElementById('overview-next-action'),
        overviewWorkspace: document.getElementById('overview-workspace')
    };
    const actionsContainer = document.getElementById('dashboard-hero-actions');
    const deskGrid = document.querySelector('.quick-actions-grid');

    // Modal elements
    const modalStatusIcon = document.getElementById('modal-status-icon');
    const modalStatusTitle = document.getElementById('modal-status-title');
    const modalStatusDesc = document.getElementById('modal-status-desc');
    const modalStatusBanner = document.getElementById('modal-status-banner');

    let currentShowPayButton = <?php echo $showPayButton ? 'true' : 'false'; ?>;
    let currentHeroBtnHtml = '';
    let currentApplicationStatus = '<?php echo $initialStatus ?? 'draft'; ?>';

    // Status styling configs for Javascript live modal rendering
    const jsStatusConfigs = {
        'draft': {
            icon: 'bi-pencil-square',
            color: '#6c757d',
            bg: '#f1f3f5',
            title: 'Application Saved as Draft',
            desc: 'Your application has not been submitted yet. You can edit and complete all mandatory fields, then click submit.'
        },
        'pending': {
            icon: 'bi-clock-history',
            color: '#d48d00',
            bg: '#fffbeb',
            title: 'Pending Registrar Auditing',
            desc: 'Your application details and document uploads are currently under administrative verification. Please wait for registrar feedback.'
        },
        'under_review': {
            icon: 'bi-search',
            color: '#17a2b8',
            bg: '#eef9fa',
            title: 'Application Under Active Review',
            desc: 'The Admissions team is reviewing your profile. No further edits can be made while the review process is active.'
        },
        'needs_revision': {
            icon: 'bi-exclamation-triangle-fill',
            color: '#dc3545',
            bg: '#fdf2f2',
            title: 'Revision Required',
            desc: 'The Registrar reviewed your documents and requested revisions. Please check the feedback notes and adjust your submissions.'
        },
        'approved': {
            icon: 'bi-shield-check',
            color: '#0d7a7a',
            bg: '#f0faf9',
            title: 'Admissions Profile Approved!',
            desc: 'Congratulations! Your profile is verified and approved. You are ready to complete your enrollment fee payment.'
        },
        'paid': {
            icon: 'bi-check2-circle',
            color: '#198754',
            bg: '#f0faf5',
            title: 'Enrollment Fee Paid',
            desc: 'Your payment receipt was verified. The Registrar office is completing your placement registration.'
        },
        'enrolled': {
            icon: 'bi-bookmark-check-fill',
            color: '#198754',
            bg: '#f0faf5',
            title: 'Enrollment Process Completed',
            desc: 'Welcome to BSMT/BSMarE! Your student enrollment is active. You will soon receive academic section listings.'
        },
        'rejected': {
            icon: 'bi-x-circle-fill',
            color: '#c0392b',
            bg: '#fdf2f2',
            title: 'Application Rejected',
            desc: 'Unfortunately, your profile did not qualify for BSMT/BSMarE programs. Please contact the admissions helpdesk.'
        }
    };

    function updateElement(el, newVal) {
        if (!el) return;
        const currentVal = el.textContent.trim();
        if (currentVal !== newVal) {
            el.style.opacity = '0';
            setTimeout(() => {
                el.textContent = newVal;
                el.style.opacity = '1';
            }, 250);
        }
    }

    function updateModalConfig(statusKey) {
        const config = jsStatusConfigs[statusKey.toLowerCase().replace(' ', '_')] || jsStatusConfigs['draft'];
        
        if (modalStatusIcon) {
            modalStatusIcon.className = 'bi ' + config.icon;
            modalStatusIcon.style.color = config.color;
        }
        if (modalStatusTitle) modalStatusTitle.textContent = config.title;
        if (modalStatusDesc) modalStatusDesc.textContent = config.desc;
        if (modalStatusBanner) modalStatusBanner.style.background = config.bg;
    }

    function pollDashboardStats() {
        fetch('dashboard?ajax=1')
            .then(response => response.json())
            .then(res => {
                if (res.success && res.data) {
                    const d = res.data;
                    updateElement(kpiElements.applicationStatus, d.application_status);
                    updateElement(kpiElements.paymentStatus, d.payment_status);
                    updateElement(kpiElements.credentialsCount, d.credentials_count);
                    updateElement(kpiElements.nextStep, d.next_step === 'Pay Enrollment' ? 'Pay Now' : d.next_step);
                    updateElement(kpiElements.overviewNextAction, d.next_action);
                    updateElement(kpiElements.overviewWorkspace, d.workspace);

                    const tuitionCardEl = document.getElementById('dashboard-tuition-card');
                    const tuitionAmountEl = document.getElementById('kpi-tuition-amount');
                    const tuitionLabelEl = document.getElementById('kpi-tuition-label');
                    const tuitionBadgeEl = document.getElementById('kpi-tuition-badge');
                    const tuitionIconWrapEl = document.getElementById('kpi-tuition-icon-wrapper');
                    const tuitionIconEl = document.getElementById('kpi-tuition-icon');
                    const tuitionBtnEl = document.getElementById('kpi-tuition-btn');

                    if (tuitionCardEl) {
                        if (d.has_selected_section && d.tuition_amount && d.tuition_amount !== '—') {
                            tuitionCardEl.style.display = 'block';
                            if (tuitionAmountEl) tuitionAmountEl.textContent = d.tuition_amount;
                            if (tuitionLabelEl && d.tuition_label) tuitionLabelEl.textContent = d.tuition_label;
                            if (tuitionBadgeEl && d.tuition_badge) tuitionBadgeEl.innerHTML = d.tuition_badge;

                            if (d.is_tuition_finalized) {
                                tuitionCardEl.style.border = '1px solid #bbf7d0';
                                tuitionCardEl.style.background = 'linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%)';
                                if (tuitionIconWrapEl) tuitionIconWrapEl.style.background = '#16a34a';
                                if (tuitionIconEl) tuitionIconEl.className = 'bi bi-patch-check-fill fs-5';
                                if (tuitionBtnEl) {
                                    tuitionBtnEl.className = 'btn btn-sm btn-success fw-semibold px-3 shadow-sm';
                                }
                            } else {
                                tuitionCardEl.style.border = '1px solid #fde68a';
                                tuitionCardEl.style.background = 'linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%)';
                                if (tuitionIconWrapEl) tuitionIconWrapEl.style.background = '#d97706';
                                if (tuitionIconEl) tuitionIconEl.className = 'bi bi-cash-coin fs-5';
                                if (tuitionBtnEl) {
                                    tuitionBtnEl.className = 'btn btn-sm btn-outline-dark fw-semibold px-3 shadow-sm';
                                }
                            }
                        } else {
                            tuitionCardEl.style.display = 'none';
                        }
                    }

                    // Update modal status indicators if status changed
                    const cleanStatus = d.application_status.toLowerCase().replace(' ', '_');
                    if (currentApplicationStatus !== cleanStatus) {
                        currentApplicationStatus = cleanStatus;
                        updateModalConfig(cleanStatus);
                        // If revision notes/remarks update in DB, a simple page reload is ideal to pull deep tables.
                        // Or we can just prompt the user if they'd like to reload.
                    }

                    // Hero action button update
                    if (currentHeroBtnHtml !== d.hero_btn_html) {
                        currentHeroBtnHtml = d.hero_btn_html;
                        // Replace the second button in actionsContainer dynamically
                        const viewStatusBtn = actionsContainer.querySelector('.btn-dashboard-secondary') || actionsContainer.querySelector('button');
                        actionsContainer.innerHTML = '';
                        if (viewStatusBtn) {
                            actionsContainer.appendChild(viewStatusBtn);
                        }
                        const tempDiv = document.createElement('div');
                        tempDiv.innerHTML = d.hero_btn_html;
                        const newBtn = tempDiv.firstElementChild;
                        if (newBtn) {
                            actionsContainer.appendChild(newBtn);
                        }
                    }

                    // If the pay button configuration changes, update quick actions desk
                    if (currentShowPayButton !== d.show_pay_button) {
                        currentShowPayButton = d.show_pay_button;
                        if (d.show_pay_button) {
                            if (deskGrid && !document.getElementById('quick-action-payment')) {
                                const payAction = document.createElement('a');
                                payAction.href = 'payment';
                                payAction.id = 'quick-action-payment';
                                payAction.className = 'quick-action';
                                payAction.innerHTML = `
                                    <span class="quick-action-icon"><i class="bi bi-wallet2"></i></span>
                                    <span><strong>Pay Now</strong><small>Review assessment and payment options to enroll</small></span>
                                    <i class="bi bi-chevron-right ms-auto"></i>
                                `;
                                deskGrid.appendChild(payAction);
                            }
                        } else {
                            const payAction = document.getElementById('quick-action-payment');
                            if (payAction) payAction.remove();
                        }
                    }
                }
            })
            .catch(function() {
                // Poll failed — will retry on next interval.
            });
    }

    // Poll every 4 seconds
    setInterval(pollDashboardStats, 4000);

    // Auto-open status modal if open_status=1 is present in URL query string
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('open_status') === '1') {
        const statusModal = new bootstrap.Modal(document.getElementById('statusModal'));
        statusModal.show();
        // Clean URL to avoid reopening on manual refresh
        const cleanUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
        window.history.replaceState({path: cleanUrl}, '', cleanUrl);
    }
});
</script>

<?php require_once '../includes/footer.php'; ?>
