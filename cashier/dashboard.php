<?php
/** Cashier dashboard */
require_once '../includes/auth_check.php';
checkRole(['cashier']);
require_once '../config/database.php';

// 1. Live Daily Collections (Recorded today, validated only)
$dailyStmt = $pdo->query("
    SELECT COALESCE(SUM(amount), 0) 
    FROM payments 
    WHERE (payment_date = CURRENT_DATE OR DATE(created_at) = CURRENT_DATE) 
      AND or_status = 'validated'
");
$dailyCollections = (float)$dailyStmt->fetchColumn();

// 2. Live Payments Checked (Verified/validated transactions)
$checkedStmt = $pdo->query("
    SELECT COUNT(*) 
    FROM payments 
    WHERE or_status = 'validated'
");
$paymentsChecked = (int)$checkedStmt->fetchColumn();

// 3. Live Awaiting Payment (Admitted cadets with unpaid/partial status or outstanding balance)
$awaitingStmt = $pdo->query("
    SELECT COUNT(*) 
    FROM students 
    WHERE (enrollment_status IN ('approved', 'enrolled', 'section_chosen', 'walk_in_ready') OR application_status IN ('approved', 'eligible_to_enroll')) 
      AND (payment_status IN ('unpaid', 'partially_paid') OR outstanding_balance > 0)
");
$awaitingPayment = (int)$awaitingStmt->fetchColumn();

// 4. Live Receipts Issued (Official receipts generated and validated)
$receiptsStmt = $pdo->query("
    SELECT COUNT(*) 
    FROM payments 
    WHERE or_number IS NOT NULL AND or_number != '' 
      AND or_status = 'validated'
");
$receiptsIssued = (int)$receiptsStmt->fetchColumn();

// 5. Live Pending Online Payments Awaiting Cashier Review
$pendingStmt = $pdo->query("
    SELECT COUNT(*) 
    FROM payments 
    WHERE or_status = 'pending'
");
$pendingPayments = (int)$pendingStmt->fetchColumn();

$page_title = 'Cashier Overview';
$page_class = 'page-dashboard page-cashier-dashboard';
require_once '../includes/header.php';
?>

<section class="dashboard-hero mb-4">
    <div>
        <div class="page-eyebrow"><i class="bi bi-wallet2 me-1"></i> Cashier workspace</div>
        <h1 class="dashboard-title">Cashier Overview</h1>
        <p class="dashboard-subtitle">Welcome back, <?php echo htmlspecialchars($_SESSION['username'] ?? 'Cashier'); ?>. Manage payments, receipts, and daily collections.</p>
    </div>
    <div class="dashboard-actions d-flex flex-wrap gap-2">
        <a href="enrollment_queue" class="btn btn-dashboard-primary"><i class="bi bi-person-lines-fill me-2"></i>Enrollment Queue</a>
        <a href="payment_history" class="btn btn-dashboard-secondary"><i class="bi bi-clock-history me-2"></i>Payment History</a>
        <a href="receipts" class="btn btn-dashboard-secondary"><i class="bi bi-receipt me-2"></i>Official Receipts</a>
    </div>
</section>

<?php if ($pendingPayments > 0): ?>
<div class="alert alert-warning border border-warning-subtle d-flex align-items-center justify-content-between mb-4 shadow-sm" style="border-radius:12px;background:rgba(245,158,11,0.08);">
    <div class="d-flex align-items-center gap-3">
        <div class="rounded-circle bg-warning text-dark p-2 d-flex align-items-center justify-content-center shadow-sm" style="width:42px;height:42px;">
            <i class="bi bi-hourglass-split fs-5"></i>
        </div>
        <div>
            <div class="fw-bold text-dark" style="font-size:1rem;">Pending Online Payments Awaiting Review</div>
            <div class="text-muted small">There <?php echo $pendingPayments === 1 ? 'is 1 online payment' : "are {$pendingPayments} online payments"; ?> submitted by students waiting for cashier validation.</div>
        </div>
    </div>
    <a href="enrollment_queue?filter_pending=1" class="btn btn-warning fw-semibold px-3 shadow-sm">Review Queue <i class="bi bi-arrow-right ms-1"></i></a>
</div>
<?php endif; ?>

<section class="row g-3 mb-4 dashboard-kpis">
    <div class="col-12 col-sm-6 col-xl-3"><div class="dashboard-stat-card"><div class="stat-label">Daily Collections</div><div class="stat-value">₱<?php echo number_format($dailyCollections, 2); ?></div><div class="stat-trend <?php echo $dailyCollections > 0 ? 'trend-up' : ''; ?>"><i class="bi bi-arrow-up-right"></i> Recorded today</div></div></div>
    <div class="col-12 col-sm-6 col-xl-3"><div class="dashboard-stat-card"><div class="stat-label">Payments Checked</div><div class="stat-value"><?php echo number_format($paymentsChecked); ?></div><div class="stat-trend <?php echo $paymentsChecked > 0 ? 'trend-up' : ''; ?>"><i class="bi bi-check2-circle"></i> Verified transactions</div></div></div>
    <div class="col-12 col-sm-6 col-xl-3"><div class="dashboard-stat-card"><div class="stat-label">Awaiting Payment</div><div class="stat-value"><?php echo number_format($awaitingPayment); ?></div><div class="stat-trend"><i class="bi bi-clock"></i> Students to follow up</div></div></div>
    <div class="col-12 col-sm-6 col-xl-3"><div class="dashboard-stat-card"><div class="stat-label">Receipts Issued</div><div class="stat-value"><?php echo number_format($receiptsIssued); ?></div><div class="stat-trend <?php echo $receiptsIssued > 0 ? 'trend-up' : ''; ?>"><i class="bi bi-receipt"></i> Official receipts</div></div></div>
</section>

<section class="row g-3">
    <div class="col-12 col-xl-8">
        <div class="dashboard-panel h-100">
            <div class="panel-heading">
                <div>
                    <h3>Cashier Desk</h3>
                    <p>Common payment tasks</p>
                </div>
                <a href="receipts" class="panel-link">View Receipts <i class="bi bi-arrow-up-right"></i></a>
            </div>
            <div class="quick-actions-grid">
                <a href="enrollment_queue" class="quick-action" style="grid-column: 1 / -1;">
                    <span class="quick-action-icon"><i class="bi bi-person-lines-fill"></i></span>
                    <span><strong>Enrollment Queue & Progress</strong><small>Track enrollees from sectioning to subject finalization, assessment & payment</small></span>
                    <i class="bi bi-chevron-right ms-auto"></i>
                </a>
                <a href="payment_history" class="quick-action">
                    <span class="quick-action-icon"><i class="bi bi-clock-history"></i></span>
                    <span><strong>Payment History</strong><small>Review and search recorded transactions</small></span>
                    <i class="bi bi-chevron-right ms-auto"></i>
                </a>
                <a href="receipts" class="quick-action">
                    <span class="quick-action-icon"><i class="bi bi-receipt"></i></span>
                    <span><strong>Official Receipts</strong><small>Browse, open, and print payment receipts</small></span>
                    <i class="bi bi-chevron-right ms-auto"></i>
                </a>
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
                    <div style="color:#fff;font-size:.82rem;opacity:.8;">Your finance access</div>
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
                "><?php echo htmlspecialchars(strtoupper(substr($_SESSION['username'] ?? 'C', 0, 2))); ?></div>
                <div>
                    <div style="color:#fff;font-size:1rem;font-weight:750;line-height:1.2;"><?php echo htmlspecialchars($_SESSION['username'] ?? 'Cashier'); ?></div>
                    <div style="color:rgba(255,255,255,.6);font-size:.75rem;margin-top:.2rem;">Finance cashier account</div>
                </div>
            </div>

            <!-- Detail rows -->
            <div style="position:relative;">
                <div style="display:flex;justify-content:space-between;align-items:center;padding:.6rem 0;border-bottom:1px solid rgba(255,255,255,.12);">
                    <span style="color:rgba(255,255,255,.6);font-size:.77rem;">Role</span>
                    <strong style="color:#fff;font-size:.8rem;background:rgba(255,255,255,.15);padding:.2rem .65rem;border-radius:999px;border:1px solid rgba(255,255,255,.2);">Cashier</strong>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:.6rem 0;border-bottom:1px solid rgba(255,255,255,.12);">
                    <span style="color:rgba(255,255,255,.6);font-size:.77rem;">Workspace</span>
                    <strong style="color:#fff;font-size:.8rem;">Finance Office</strong>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:.6rem 0;">
                    <span style="color:rgba(255,255,255,.6);font-size:.77rem;">Account ID</span>
                    <strong style="color:#6ef5d4;font-size:.82rem;font-family:var(--font-mono, monospace);">#<?php echo (int)($_SESSION['user_id'] ?? 0); ?></strong>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once '../includes/footer.php'; ?>
