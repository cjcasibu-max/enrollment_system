<?php
/** Admin dashboard */
require_once '../includes/auth_check.php';
checkRole(['admin']);
require_once '../config/database.php';
require_once '../includes/academic_terms.php';

$activeTerm = getActiveAcademicTerm($pdo);
$enrollmentStatus = getEnrollmentPeriodStatus($activeTerm);

// Live KPI metrics
$activeAccountsCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn();
$pendingAppsCount = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE application_status IN ('pending', 'under_review')")->fetchColumn();

$page_title = 'Admin Overview';
$page_class = 'page-dashboard page-admin-dashboard';
require_once '../includes/header.php';
?>

<section class="dashboard-hero mb-4">
    <div>
        <div class="page-eyebrow"><i class="bi bi-shield-lock me-1"></i> Administration workspace</div>
        <h1 class="dashboard-title">Admin Overview</h1>
        <p class="dashboard-subtitle">Welcome back, <?php echo htmlspecialchars($_SESSION['username'] ?? 'Administrator'); ?>. Monitor enrollment periods, users, curricula, and system health.</p>
    </div>
    <div class="dashboard-actions d-flex flex-wrap gap-2">
        <a href="<?php echo $base_path; ?>admin/academic_terms" class="btn btn-dashboard-secondary"><i class="bi bi-calendar3 me-2"></i>Enrollment Period</a>
        <a href="<?php echo $base_path; ?>admin/curriculum" class="btn btn-dashboard-secondary"><i class="bi bi-journal-album me-2"></i>Curriculum</a>
        <a href="<?php echo $base_path; ?>admin/audit_log" class="btn btn-dashboard-secondary"><i class="bi bi-journal-text me-2"></i>View Audit Log</a>
        <a href="<?php echo $base_path; ?>admin/manage_users" class="btn btn-dashboard-primary"><i class="bi bi-person-plus me-2"></i>Manage Users</a>
    </div>
</section>

<section class="row g-3 mb-4 dashboard-kpis">
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="dashboard-stat-card">
            <div class="stat-label">Enrollment Period</div>
            <div class="stat-value" style="font-size: 1.15rem;"><?php echo htmlspecialchars($enrollmentStatus['label']); ?></div>
            <div class="stat-trend <?php echo $enrollmentStatus['is_open'] ? 'trend-up' : ''; ?>">
                <i class="bi bi-clock-history"></i> <?php echo htmlspecialchars($enrollmentStatus['deadline_label'] ?? 'No deadline set'); ?>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3"><div class="dashboard-stat-card"><div class="stat-label">Active Accounts</div><div class="stat-value"><?php echo number_format($activeAccountsCount); ?></div><div class="stat-trend trend-up"><i class="bi bi-people"></i> Accounts with access</div></div></div>
    <div class="col-12 col-sm-6 col-xl-3"><div class="dashboard-stat-card"><div class="stat-label">Pending Applications</div><div class="stat-value"><?php echo number_format($pendingAppsCount); ?></div><div class="stat-trend"><i class="bi bi-clock"></i> Requires attention</div></div></div>
    <div class="col-12 col-sm-6 col-xl-3"><div class="dashboard-stat-card"><div class="stat-label">System Status</div><div class="stat-value">Online</div><div class="stat-trend trend-up"><i class="bi bi-check2-circle"></i> All services available</div></div></div>
</section>

<section class="row g-3">
    <div class="col-12 col-xl-8"><div class="dashboard-panel h-100"><div class="panel-heading"><div><h3>Administration Desk</h3><p>Common system tasks</p></div><a href="<?php echo $base_path; ?>admin/manage_users" class="panel-link">Manage accounts <i class="bi bi-arrow-up-right"></i></a></div><div class="quick-actions-grid"><a href="<?php echo $base_path; ?>admin/academic_terms" class="quick-action"><span class="quick-action-icon"><i class="bi bi-calendar3"></i></span><span><strong>Enrollment & Terms</strong><small>Configure active period & deadlines</small></span><i class="bi bi-chevron-right ms-auto"></i></a><a href="<?php echo $base_path; ?>admin/curriculum" class="quick-action"><span class="quick-action-icon"><i class="bi bi-journal-album"></i></span><span><strong>Curriculum</strong><small>Manage degree matrices & courses</small></span><i class="bi bi-chevron-right ms-auto"></i></a><a href="<?php echo $base_path; ?>admin/fee_setup" class="quick-action"><span class="quick-action-icon"><i class="bi bi-cash-stack"></i></span><span><strong>Fee Setup</strong><small>Configure tuition & payment methods</small></span><i class="bi bi-chevron-right ms-auto"></i></a><a href="<?php echo $base_path; ?>admin/manage_users" class="quick-action"><span class="quick-action-icon"><i class="bi bi-people"></i></span><span><strong>Manage users</strong><small>Review account access and status</small></span><i class="bi bi-chevron-right ms-auto"></i></a><a href="<?php echo $base_path; ?>admin/manage_roles" class="quick-action"><span class="quick-action-icon"><i class="bi bi-shield-check"></i></span><span><strong>Manage roles</strong><small>Review role assignments</small></span><i class="bi bi-chevron-right ms-auto"></i></a><a href="<?php echo $base_path; ?>admin/audit_log" class="quick-action"><span class="quick-action-icon"><i class="bi bi-journal-text"></i></span><span><strong>Audit activity</strong><small>Track important system events</small></span><i class="bi bi-chevron-right ms-auto"></i></a><a href="<?php echo $base_path; ?>admin/trash_bin" class="quick-action"><span class="quick-action-icon"><i class="bi bi-archive"></i></span><span><strong>Trash bin</strong><small>Review archived records</small></span><i class="bi bi-chevron-right ms-auto"></i></a></div></div></div>
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
                    <div style="color:#fff;font-size:.82rem;opacity:.8;">System administration access</div>
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
                    <div style="color:#fff;font-size:1rem;font-weight:750;line-height:1.2;"><?php echo htmlspecialchars($_SESSION['username'] ?? 'Administrator'); ?></div>
                    <div style="color:rgba(255,255,255,.6);font-size:.75rem;margin-top:.2rem;">System administrator account</div>
                </div>
            </div>

            <!-- Detail rows -->
            <div style="position:relative;">
                <div style="display:flex;justify-content:space-between;align-items:center;padding:.6rem 0;border-bottom:1px solid rgba(255,255,255,.12);">
                    <span style="color:rgba(255,255,255,.6);font-size:.77rem;">Role</span>
                    <strong style="color:#fff;font-size:.8rem;background:rgba(255,255,255,.15);padding:.2rem .65rem;border-radius:999px;border:1px solid rgba(255,255,255,.2);">Administrator</strong>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:.6rem 0;border-bottom:1px solid rgba(255,255,255,.12);">
                    <span style="color:rgba(255,255,255,.6);font-size:.77rem;">Workspace</span>
                    <strong style="color:#fff;font-size:.8rem;">System Administration</strong>
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
