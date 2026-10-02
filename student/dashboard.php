<?php
/** Student dashboard */
require_once '../includes/auth_check.php';
checkRole(['student']);
require_once '../config/database.php';
require_once '../includes/academic_terms.php';
require_once '../includes/assessments.php';

$studentAdmissionStatus = $_SESSION['admission_status'] ?? 'draft';
$studentEnrollmentStatus = $_SESSION['enrollment_status'] ?? 'draft';
$studentId = 0;
$studentOutstandingBalance = 0.00;
$assessmentTotal = 0.00;
$assessmentBalance = 0.00;
$validatedPaid = 0.00;

$studentStmt = $pdo->prepare("SELECT id, academic_term_id, admission_status, enrollment_status, payment_status, outstanding_balance FROM students WHERE user_id = :user_id LIMIT 1");
$studentStmt->execute(['user_id' => (int)$_SESSION['user_id']]);
$studentRow = $studentStmt->fetch();

if ($studentRow) {
    $studentId = (int)$studentRow['id'];
    $studentAdmissionStatus = $studentRow['admission_status'] ?? $studentRow['application_status'] ?? 'draft';
    $studentEnrollmentStatus = $studentRow['enrollment_status'] ?? 'draft';
    $studentOutstandingBalance = (float)($studentRow['outstanding_balance'] ?? 0.00);
    $_SESSION['admission_status'] = $studentAdmissionStatus;
    $_SESSION['enrollment_status'] = $studentEnrollmentStatus;
    $_SESSION['student_id'] = $studentId;
}

if ($studentId > 0) {
    try {
        $termId = (int)($studentRow['academic_term_id'] ?? 0);
        if ($termId <= 0) {
            $activeTerm = getActiveAcademicTerm($pdo);
            $termId = (int)($activeTerm['id'] ?? 0);
        }
        if ($termId > 0) {
            $assessmentId = getOrCreateAssessment($pdo, $studentId, $termId);
            $assessment = getAssessmentTotals($pdo, $assessmentId);
            $assessmentTotal = (float)($assessment['total_amount'] ?? 0.00);
            $validatedPaid = (float)($assessment['validated_paid'] ?? 0.00);
            $assessmentBalance = (float)($assessment['balance'] ?? 0.00);
            $studentOutstandingBalance = $assessmentBalance;
        }
    } catch (Throwable $e) {
        error_log('Student Dashboard assessment summary failed: ' . $e->getMessage());
    }
}

$page_title = 'Student Overview';
$page_class = 'page-dashboard page-student-dashboard';
require_once '../includes/header.php';
?>

<section class="dashboard-hero mb-4">
    <div>
        <div class="page-eyebrow"><i class="bi bi-mortarboard me-1"></i> Student workspace</div>
        <h1 class="dashboard-title">Student Overview</h1>
        <p class="dashboard-subtitle">Welcome back, <?php echo htmlspecialchars($_SESSION['username'] ?? 'Student'); ?>. Manage your enrollment, payments, and student records.</p>
    </div>
    <div class="dashboard-actions d-flex flex-column flex-sm-row flex-wrap gap-2">
        <a href="my_profile" class="btn btn-dashboard-secondary"><i class="bi bi-person me-2"></i>My Profile</a>
        <a href="payment" class="btn btn-dashboard-primary"><i class="bi bi-wallet2 me-2"></i>Make Payment</a>
        <a href="my_enrollments" class="btn btn-dashboard-primary"><i class="bi bi-calendar-week me-2"></i>Class Schedule</a>
    </div>
</section>

<section class="row g-3 mb-4 dashboard-kpis">
    <?php
    $admTone = in_array($studentAdmissionStatus, ['admitted','approved']) ? 'success' : (in_array($studentAdmissionStatus, ['pending','under_review']) ? 'warning' : (in_array($studentAdmissionStatus, ['needs_revision']) ? 'info' : 'secondary'));
    $enrTone = in_array($studentEnrollmentStatus, ['paid','enrolled']) ? 'success' : (in_array($studentEnrollmentStatus, ['pending','approved']) ? 'warning' : (in_array($studentEnrollmentStatus, ['needs_revision']) ? 'info' : 'secondary'));
    ?>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="dashboard-stat-card">
            <div class="stat-label">Admission Status</div>
            <div class="stat-value" style="margin: 0.45rem 0 0.35rem;">
                <span class="badge bg-<?php echo $admTone; ?>-subtle text-<?php echo $admTone; ?> border border-<?php echo $admTone; ?>" style="padding: 0.5rem 1.25rem; font-size: 1.05rem; font-weight: 750; border-radius: 999px; letter-spacing: -0.01em; display: inline-flex; align-items: center; gap: 0.45rem;">
                    <i class="bi bi-patch-check-fill" style="font-size: 0.95rem;"></i> <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $studentAdmissionStatus ?: 'Draft'))); ?>
                </span>
            </div>
            <div class="stat-trend"><i class="bi bi-person-check"></i> Admission progress</div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="dashboard-stat-card">
            <div class="stat-label">Enrollment Status</div>
            <div class="stat-value" style="margin: 0.45rem 0 0.35rem;">
                <span class="badge bg-<?php echo $enrTone; ?>-subtle text-<?php echo $enrTone; ?> border border-<?php echo $enrTone; ?>" style="padding: 0.5rem 1.25rem; font-size: 1.05rem; font-weight: 750; border-radius: 999px; letter-spacing: -0.01em; display: inline-flex; align-items: center; gap: 0.45rem;">
                    <i class="bi bi-bookmark-check-fill" style="font-size: 0.95rem;"></i> <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $studentEnrollmentStatus ?: 'Draft'))); ?>
                </span>
            </div>
            <div class="stat-trend"><i class="bi bi-shield-check"></i> Current academic status</div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3"><div class="dashboard-stat-card"><div class="stat-label">Assessment Total</div><div class="stat-value">₱<?php echo number_format($assessmentTotal, 2); ?></div><div class="stat-trend trend-up"><i class="bi bi-calculator"></i> Current term assessment</div></div></div>
    <div class="col-12 col-sm-6 col-xl-3"><div class="dashboard-stat-card"><div class="stat-label">Remaining Balance</div><div class="stat-value text-danger">₱<?php echo number_format($assessmentBalance, 2); ?></div><div class="stat-trend trend-up"><i class="bi bi-cash-coin"></i> Outstanding due</div></div></div>
</section>

<section class="row g-3">
    <div class="col-12 col-xl-8"><div class="dashboard-panel h-100"><div class="panel-heading"><div><h3>Student Desk</h3><p>Common student tasks</p></div><a href="my_enrollments" class="panel-link">View schedule <i class="bi bi-arrow-up-right"></i></a></div><div class="quick-actions-grid"><a href="payment" class="quick-action"><span class="quick-action-icon"><i class="bi bi-wallet2"></i></span><span><strong>Make Payment</strong><small>Review assessment, balance, and payment options</small></span><i class="bi bi-chevron-right ms-auto"></i></a><a href="my_enrollments" class="quick-action"><span class="quick-action-icon"><i class="bi bi-calendar-week"></i></span><span><strong>Class Schedule</strong><small>View your weekly class timetable and subjects</small></span><i class="bi bi-chevron-right ms-auto"></i></a><a href="academic_records" class="quick-action"><span class="quick-action-icon"><i class="bi bi-award-fill"></i></span><span><strong>Academic Records</strong><small>View completed grades, units, and GWA</small></span><i class="bi bi-chevron-right ms-auto"></i></a><a href="my_payments" class="quick-action"><span class="quick-action-icon"><i class="bi bi-receipt"></i></span><span><strong>My Payments</strong><small>View payment history and receipts</small></span><i class="bi bi-chevron-right ms-auto"></i></a></div></div></div>
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
                    <div style="color:#fff;font-size:.82rem;opacity:.8;">Your student access</div>
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
                "><?php echo htmlspecialchars(strtoupper(substr($_SESSION['username'] ?? 'S', 0, 2))); ?></div>
                <div>
                    <div style="color:#fff;font-size:1rem;font-weight:750;line-height:1.2;"><?php echo htmlspecialchars($_SESSION['username'] ?? 'Student'); ?></div>
                    <div style="color:rgba(255,255,255,.6);font-size:.75rem;margin-top:.2rem;">Cadet student account</div>
                </div>
            </div>

            <!-- Detail rows -->
            <div style="position:relative;">
                <div style="display:flex;justify-content:space-between;align-items:center;padding:.6rem 0;border-bottom:1px solid rgba(255,255,255,.12);">
                    <span style="color:rgba(255,255,255,.6);font-size:.77rem;">Student ID</span>
                    <strong style="color:#fff;font-size:.8rem;">#<?php echo htmlspecialchars((string)($_SESSION['student_id'] ?? 'Not assigned')); ?></strong>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:.6rem 0;border-bottom:1px solid rgba(255,255,255,.12);">
                    <span style="color:rgba(255,255,255,.6);font-size:.77rem;">Outstanding Balance</span>
                    <strong style="color:#<?php echo $studentOutstandingBalance > 0 ? 'ffb3b3' : '6ef5d4'; ?>;font-size:.82rem;">₱<?php echo number_format($studentOutstandingBalance, 2); ?></strong>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:.6rem 0;">
                    <span style="color:rgba(255,255,255,.6);font-size:.77rem;">Role</span>
                    <strong style="color:#fff;font-size:.8rem;background:rgba(255,255,255,.15);padding:.2rem .65rem;border-radius:999px;border:1px solid rgba(255,255,255,.2);">Student</strong>
                </div>
            </div>
        </div>
    </div>

<?php require_once '../includes/footer.php'; ?>
