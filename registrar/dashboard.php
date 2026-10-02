<?php
/**
 * Registrar Dashboard Overview
 * Displays admissions & enrollment KPI metrics, quick registrar work desk actions,
 * recent pending applications queue preview, and active term status.
 * Uses NCST Maritime Academy complementary design system.
 */

require_once '../includes/auth_check.php';
checkRole(['registrar', 'admin']);

require_once '../config/database.php';
require_once '../includes/academic_terms.php';

/** @var PDO $pdo */
$activeTerm = getActiveAcademicTerm($pdo);

$dashboardMetrics = [
    'new_enrollments' => 0,
    'new_applications' => 0,
    'pending_verifications' => 0,
    'active_courses' => 0,
];

try {
    $dashboardMetricsStmt = $pdo->query("
        SELECT
            (SELECT COUNT(*)
             FROM enrollments e
             INNER JOIN academic_terms t ON t.id = e.academic_term_id
             WHERE t.is_active = 1 AND e.status = 'pending') AS new_enrollments,
            (SELECT COUNT(*)
             FROM students s
             INNER JOIN academic_terms t ON t.id = s.academic_term_id
             WHERE t.is_active = 1 AND s.application_status IN ('pending', 'under_review')) AS new_applications,
            (SELECT COUNT(*)
             FROM documents d
             INNER JOIN students s ON s.id = d.student_id
             INNER JOIN academic_terms t ON t.id = s.academic_term_id
             WHERE t.is_active = 1 AND d.status = 'pending') AS pending_verifications,
            (SELECT COUNT(DISTINCT sec.course_id)
             FROM sections sec
             INNER JOIN academic_terms t ON t.id = sec.academic_term_id
             WHERE t.is_active = 1) AS active_courses
    ");
    $row = $dashboardMetricsStmt->fetch();
    if ($row) {
        foreach ($dashboardMetrics as $metric => $val) {
            $dashboardMetrics[$metric] = (int)($row[$metric] ?? 0);
        }
    }
} catch (PDOException $e) {
    error_log('Registrar dashboard metrics failed: ' . $e->getMessage());
}

// Fetch recent pending applications preview (up to 5)
$recentApplications = [];
try {
    $recentStmt = $pdo->query("
        SELECT s.id, s.first_name, s.last_name, s.program_applying_for, s.program_code, s.year_level, s.created_at, u.email
        FROM students s
        JOIN users u ON s.user_id = u.id
        WHERE s.application_status IN ('pending', 'under_review')
        ORDER BY s.id DESC
        LIMIT 5
    ");
    $recentApplications = $recentStmt->fetchAll();
} catch (PDOException $e) {
    error_log("Fetch recent applications failed: " . $e->getMessage());
}

$page_title = 'Registrar Overview';
$page_class = 'page-dashboard page-registrar-dashboard';
require_once '../includes/header.php';
?>

<style>
/* ============================================================
   DASHBOARD MARITIME THEME DESIGN
   ============================================================ */

/* Hero Banner */
.dashboard-hero-card {
    background: #fff;
    border-radius: 16px;
    border: 1px solid #e0eded;
    box-shadow: 0 4px 20px rgba(11, 155, 152, 0.06);
    padding: 24px 28px;
    margin-bottom: 24px;
}

/* Stat Cards Hover Lift */
.dashboard-kpis .card-stat {
    transition: transform 0.18s ease, box-shadow 0.18s ease;
    border-radius: 14px;
}
.dashboard-kpis .card-stat:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 24px rgba(11, 155, 152, 0.13) !important;
}

/* Work Desk Panel */
.workdesk-card {
    background: #fff;
    border-radius: 16px;
    border: 1px solid #e0eded;
    box-shadow: 0 4px 20px rgba(11, 155, 152, 0.06);
    padding: 24px;
}

.quick-action-tile {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 18px;
    border-radius: 12px;
    background: #f7fcfc;
    border: 1px solid #e0eded;
    color: var(--text-darker, #10383f);
    text-decoration: none;
    transition: all 0.18s ease;
}
.quick-action-tile:hover {
    background: var(--brand-primary-soft, #d9efee);
    border-color: var(--brand-primary, #0b9b98);
    transform: translateY(-2px);
    box-shadow: 0 4px 14px rgba(11, 155, 152, 0.1);
    color: var(--brand-dark, #064b55);
}
.quick-action-tile .tile-icon {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    background: #fff;
    border: 1px solid #d0e8e7;
    color: var(--brand-primary, #0b9b98);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    flex-shrink: 0;
    transition: all 0.18s ease;
}
.quick-action-tile:hover .tile-icon {
    background: var(--brand-primary, #0b9b98);
    color: #fff;
    border-color: var(--brand-primary, #0b9b98);
}

/* ---- Applications Table Card (Identical to Applications Page) ---- */
.dashboard-table-card {
    border-radius: 16px;
    border: 1px solid #e0eded;
    box-shadow: 0 4px 24px rgba(11, 155, 152, 0.07);
    overflow: hidden;
    background: #fff;
}
.dashboard-table-header {
    background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);
    padding: 16px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}
.dashboard-table-header h5 {
    color: #ffffff !important;
    margin: 0;
    font-size: 1rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 8px;
    letter-spacing: 0.01em;
}
.dashboard-table-header .table-header-sub {
    color: rgba(255, 255, 255, 0.88) !important;
    font-size: 0.78rem;
    margin-top: 2px;
}
.btn-header-action {
    background: rgba(255, 255, 255, 0.22);
    color: #ffffff !important;
    border-radius: 999px;
    padding: 5px 16px;
    font-size: 0.78rem;
    font-weight: 700;
    border: 1px solid rgba(255, 255, 255, 0.35);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.18s ease;
}
.btn-header-action:hover {
    background: #ffffff;
    color: var(--brand-dark, #064b55) !important;
}

/* Table styling */
.table-dashboard-apps thead th {
    background: #f4fafa;
    color: var(--brand-dark, #064b55);
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    border-bottom: 2px solid #cde6e5;
    padding: 12px 16px;
}
.table-dashboard-apps tbody tr {
    border-bottom: 1px solid #f0f6f6;
    transition: background 0.13s;
}
.table-dashboard-apps tbody tr:hover {
    background: #f7fcfc;
}
.table-dashboard-apps tbody td {
    padding: 13px 16px;
    vertical-align: middle;
}

/* Avatar */
.dash-app-avatar {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);
    color: #ffffff !important;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.78rem;
    font-weight: 800;
    flex-shrink: 0;
    letter-spacing: 0.05em;
}

/* Review button */
.btn-view-app {
    background: transparent;
    border: 1.5px solid var(--brand-primary);
    color: var(--brand-primary);
    border-radius: 8px;
    padding: 5px 13px;
    font-size: 0.77rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    transition: all 0.18s;
    white-space: nowrap;
    text-decoration: none;
}
.btn-view-app:hover {
    background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);
    border-color: transparent;
    color: #ffffff !important;
}

/* Year Badge */
.yr-badge-dash {
    background: #e6f7f6;
    color: #064b55;
    border: 1px solid #99dedc;
    display: inline-flex;
    align-items: center;
    padding: 2px 9px;
    border-radius: 999px;
    font-size: 0.72rem;
    font-weight: 700;
}

/* Empty State */
.dash-apps-empty {
    padding: 50px 24px;
    text-align: center;
}
.dash-apps-empty .empty-icon {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    background: var(--brand-primary-soft, #d9efee);
    color: var(--brand-primary);
    font-size: 1.8rem;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 14px;
}
</style>

<!-- =========================================================================
     DASHBOARD HERO BANNER
     ========================================================================= -->
<section class="dashboard-hero-card d-flex justify-content-between align-items-center flex-wrap gap-3">
    <div>
        <div class="page-eyebrow mb-1"><i class="bi bi-grid-1x2 me-1"></i> Registrar Workspace</div>
        <h3 class="m-0 text-navy-alt fw-bold">Registrar Overview</h3>
        <p class="text-muted small m-0 mt-1">
            Welcome back, <strong class="text-navy"><?php echo htmlspecialchars($_SESSION['username'] ?? 'Registrar'); ?></strong>. 
            Active Term: <strong class="text-brand-primary"><?php echo $activeTerm ? htmlspecialchars($activeTerm['school_year'] . ' - ' . ucfirst($activeTerm['semester']) . ' Semester') : 'No Active Term'; ?></strong>
        </p>
    </div>
    <div class="dashboard-actions d-flex flex-wrap gap-2">
        <a href="enrollee_applications" class="btn btn-brand-primary d-flex align-items-center gap-1.5 shadow-sm px-3.5 py-2 fw-semibold">
            <i class="bi bi-person-vcard"></i> Review Applications
        </a>
        <a href="sections" class="btn btn-outline-secondary d-flex align-items-center gap-1.5 px-3 py-2 fw-semibold">
            <i class="bi bi-grid-3x3-gap"></i> Class Sections
        </a>
    </div>
</section>

<!-- =========================================================================
     KPI METRICS ROW (4 Cards in Theme Palette)
     ========================================================================= -->
<section class="row g-3 mb-4 dashboard-kpis">
    <!-- New Applications -->
    <div class="col-6 col-sm-6 col-xl-3">
        <a href="enrollee_applications" class="text-decoration-none">
            <div class="card card-stat stat-primary h-100 shadow-sm">
                <div class="card-body d-flex align-items-center justify-content-between p-3.5">
                    <div>
                        <div class="text-muted small fw-bold text-uppercase" style="letter-spacing: 0.05em; font-size: 0.72rem;">New Applications</div>
                        <div class="fs-2 fw-bold text-darker mt-1" id="metric-new_applications"><?php echo number_format($dashboardMetrics['new_applications']); ?></div>
                        <div class="small text-muted mt-0.5">Pending admissions</div>
                    </div>
                    <div class="stat-icon bg-primary-soft">
                        <i class="bi bi-person-badge"></i>
                    </div>
                </div>
            </div>
        </a>
    </div>

    <!-- Pending Enrollments -->
    <div class="col-6 col-sm-6 col-xl-3">
        <a href="enrollments" class="text-decoration-none">
            <div class="card card-stat stat-secondary h-100 shadow-sm">
                <div class="card-body d-flex align-items-center justify-content-between p-3.5">
                    <div>
                        <div class="text-muted small fw-bold text-uppercase" style="letter-spacing: 0.05em; font-size: 0.72rem;">Pending Enrollments</div>
                        <div class="fs-2 fw-bold text-darker mt-1" id="metric-new_enrollments"><?php echo number_format($dashboardMetrics['new_enrollments']); ?></div>
                        <div class="small text-muted mt-0.5">Section workloads</div>
                    </div>
                    <div class="stat-icon bg-secondary-soft">
                        <i class="bi bi-journal-plus"></i>
                    </div>
                </div>
            </div>
        </a>
    </div>

    <!-- Document Queue -->
    <div class="col-6 col-sm-6 col-xl-3">
        <a href="enrollee_applications" class="text-decoration-none">
            <div class="card card-stat stat-accent h-100 shadow-sm">
                <div class="card-body d-flex align-items-center justify-content-between p-3.5">
                    <div>
                        <div class="text-muted small fw-bold text-uppercase" style="letter-spacing: 0.05em; font-size: 0.72rem;">Document Queue</div>
                        <div class="fs-2 fw-bold text-darker mt-1" id="metric-pending_verifications"><?php echo number_format($dashboardMetrics['pending_verifications']); ?></div>
                        <div class="small text-muted mt-0.5">Awaiting verification</div>
                    </div>
                    <div class="stat-icon bg-accent-soft">
                        <i class="bi bi-file-earmark-check"></i>
                    </div>
                </div>
            </div>
        </a>
    </div>

    <!-- Active Courses & Sections -->
    <div class="col-6 col-sm-6 col-xl-3">
        <a href="sections" class="text-decoration-none">
            <div class="card card-stat stat-success h-100 shadow-sm">
                <div class="card-body d-flex align-items-center justify-content-between p-3.5">
                    <div>
                        <div class="text-muted small fw-bold text-uppercase" style="letter-spacing: 0.05em; font-size: 0.72rem;">Active Sections</div>
                        <div class="fs-2 fw-bold text-darker mt-1" id="metric-active_courses"><?php echo number_format($dashboardMetrics['active_courses']); ?></div>
                        <div class="small text-muted mt-0.5">Active term sections</div>
                    </div>
                    <div class="stat-icon bg-success-soft">
                        <i class="bi bi-building"></i>
                    </div>
                </div>
            </div>
        </a>
    </div>
</section>

<div id="dashboardFlashContainer" style="position:fixed;top:1rem;right:1rem;z-index:9999;max-width:360px;"></div>

<!-- =========================================================================
     MAIN WORK DESK & COMPLEMENTARY PROFILE LAYOUT
     ========================================================================= -->
<section class="row g-3 mb-4">
    <!-- Left Column: Quick Work Desk Grid -->
    <div class="col-12 col-xl-8">
        <div class="workdesk-card h-100">
            <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom border-light-subtle">
                <div>
                    <h5 class="m-0 fw-bold text-navy-alt">Registrar Work Desk</h5>
                    <p class="text-muted small m-0">Core administrative tasks and management portals</p>
                </div>
                <a href="enrollee_applications" class="text-brand-primary text-decoration-none fw-semibold small">
                    All Workflows <i class="bi bi-arrow-up-right"></i>
                </a>
            </div>

            <div class="row g-2.5">
                <div class="col-12 col-md-6">
                    <a href="enrollee_applications" class="quick-action-tile">
                        <div class="tile-icon"><i class="bi bi-person-vcard"></i></div>
                        <div>
                            <div class="fw-bold" style="font-size: 0.88rem;">Review Applications</div>
                            <div class="text-muted small" style="font-size: 0.75rem;">Verify submitted credentials</div>
                        </div>
                        <i class="bi bi-chevron-right ms-auto text-muted"></i>
                    </a>
                </div>
                <div class="col-12 col-md-6">
                    <a href="enrollments" class="quick-action-tile">
                        <div class="tile-icon"><i class="bi bi-list-check"></i></div>
                        <div>
                            <div class="fw-bold" style="font-size: 0.88rem;">Enrollment Queue</div>
                            <div class="text-muted small" style="font-size: 0.75rem;">Approve section workloads</div>
                        </div>
                        <i class="bi bi-chevron-right ms-auto text-muted"></i>
                    </a>
                </div>
                <div class="col-12 col-md-6">
                    <a href="sections" class="quick-action-tile">
                        <div class="tile-icon"><i class="bi bi-calendar3"></i></div>
                        <div>
                            <div class="fw-bold" style="font-size: 0.88rem;">Class Sections</div>
                            <div class="text-muted small" style="font-size: 0.75rem;">Schedules, rooms & faculty</div>
                        </div>
                        <i class="bi bi-chevron-right ms-auto text-muted"></i>
                    </a>
                </div>
                <div class="col-12 col-md-6">
                    <a href="subjects" class="quick-action-tile">
                        <div class="tile-icon"><i class="bi bi-book"></i></div>
                        <div>
                            <div class="fw-bold" style="font-size: 0.88rem;">Curriculum Subjects</div>
                            <div class="text-muted small" style="font-size: 0.75rem;">BSMT & BSMarE prerequisites</div>
                        </div>
                        <i class="bi bi-chevron-right ms-auto text-muted"></i>
                    </a>
                </div>
                <div class="col-12 col-md-6">
                    <a href="students" class="quick-action-tile">
                        <div class="tile-icon"><i class="bi bi-people"></i></div>
                        <div>
                            <div class="fw-bold" style="font-size: 0.88rem;">Student Directory</div>
                            <div class="text-muted small" style="font-size: 0.75rem;">Browse registered cadet master records</div>
                        </div>
                        <i class="bi bi-chevron-right ms-auto text-muted"></i>
                    </a>
                </div>
                <div class="col-12 col-md-6">
                    <a href="grade_approvals" class="quick-action-tile">
                        <div class="tile-icon"><i class="bi bi-clipboard2-check"></i></div>
                        <div>
                            <div class="fw-bold" style="font-size: 0.88rem;">Grade Approvals</div>
                            <div class="text-muted small" style="font-size: 0.75rem;">Review and verify faculty submissions</div>
                        </div>
                        <i class="bi bi-chevron-right ms-auto text-muted"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Right Column: Account Profile Card in Complementary Theme -->
    <div class="col-12 col-xl-4">
        <div class="h-100 rounded-4 p-4 shadow-sm border border-light-subtle position-relative overflow-hidden" 
             style="background: linear-gradient(135deg, var(--brand-dark) 0%, #08616c 50%, var(--brand-primary) 100%);">
            
            <!-- Soft Decorative Shapes -->
            <span style="position:absolute;top:-28px;right:-28px;width:120px;height:120px;border-radius:50%;background:rgba(255,255,255,0.06);pointer-events:none;"></span>
            <span style="position:absolute;bottom:-40px;left:-20px;width:160px;height:160px;border-radius:50%;background:rgba(255,255,255,0.04);pointer-events:none;"></span>

            <div class="d-flex align-items-start justify-content-between mb-3 position-relative">
                <div>
                    <div class="small fw-bold text-uppercase" style="letter-spacing: 0.08em; font-size: 0.68rem; color: rgba(255, 255, 255, 0.85) !important;">Account Overview</div>
                    <div class="small" style="color: rgba(255, 255, 255, 0.92) !important;">Registrar Portal Access</div>
                </div>
                <span class="badge border rounded-pill px-3 py-1 fw-bold" style="font-size: 0.68rem; backdrop-filter: blur(4px); background: rgba(255, 255, 255, 0.15); color: #ffffff !important; border-color: rgba(255, 255, 255, 0.3) !important;">
                    <i class="bi bi-check-circle-fill me-1" style="color: #34d399;"></i> Active
                </span>
            </div>

            <div class="d-flex align-items-center gap-3 pb-3 mb-3 border-bottom border-white-15 position-relative">
                <div class="rounded-circle d-flex align-items-center justify-content-center fw-bold shadow-sm flex-shrink-0"
                     style="width: 52px; height: 52px; font-size: 1.05rem; background: rgba(255, 255, 255, 0.22); border: 2.5px solid rgba(255, 255, 255, 0.45); color: #ffffff !important;">
                    <?php echo htmlspecialchars(strtoupper(substr($_SESSION['username'] ?? 'R', 0, 2))); ?>
                </div>
                <div>
                    <div class="fw-bold fs-6 lh-1" style="color: #ffffff !important;"><?php echo htmlspecialchars($_SESSION['username'] ?? 'Registrar'); ?></div>
                    <div class="small mt-1" style="font-size: 0.75rem; color: rgba(255, 255, 255, 0.85) !important;">Registrar Account</div>
                </div>
            </div>

            <div class="position-relative">
                <div class="d-flex justify-content-between align-items-center py-2 border-bottom border-white-10">
                    <span class="small" style="font-size: 0.77rem; color: rgba(255, 255, 255, 0.85) !important;">Role</span>
                    <span class="badge border rounded-pill px-2.5 py-1" style="font-size: 0.75rem; background: rgba(255, 255, 255, 0.18); color: #ffffff !important; border-color: rgba(255, 255, 255, 0.3) !important;">Registrar</span>
                </div>
                <div class="d-flex justify-content-between align-items-center py-2 border-bottom border-white-10">
                    <span class="small" style="font-size: 0.77rem; color: rgba(255, 255, 255, 0.85) !important;">Office</span>
                    <strong class="small" style="color: #ffffff !important;">Admissions & Registrar</strong>
                </div>
                <div class="d-flex justify-content-between align-items-center py-2">
                    <span class="small" style="font-size: 0.77rem; color: rgba(255, 255, 255, 0.85) !important;">Account ID</span>
                    <strong class="small font-monospace" style="color: #ffffff !important;">#<?php echo (int)($_SESSION['user_id'] ?? 0); ?></strong>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- =========================================================
     RECENT PENDING APPLICATIONS PREVIEW TABLE (Applications Page Style)
     ========================================================= -->
<section class="dashboard-table-card mb-4">
    <div class="dashboard-table-header">
        <div>
            <h5><i class="bi bi-clock-history"></i> Recent Admission Applications</h5>
            <div class="table-header-sub">Latest cadet submissions awaiting registrar review</div>
        </div>
        <a href="enrollee_applications" class="btn-header-action">
            View All Queue <i class="bi bi-arrow-right ms-1"></i>
        </a>
    </div>
    <div class="table-responsive">
        <table class="table table-dashboard-apps align-middle m-0">
            <thead>
                <tr>
                    <th class="ps-4" style="width:36%;">Applicant Cadet</th>
                    <th style="width:24%;">Program</th>
                    <th style="width:16%;">Year Level</th>
                    <th style="width:14%;">Submitted</th>
                    <th class="pe-4 text-end" style="width:10%;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentApplications)): ?>
                    <tr>
                        <td colspan="5">
                            <div class="dash-apps-empty">
                                <div class="empty-icon"><i class="bi bi-check2-circle"></i></div>
                                <h6 class="fw-bold text-dark mb-1">Queue is clear</h6>
                                <p class="text-muted small mb-0">No pending admission applications awaiting review.</p>
                            </div>
                        </td>
                    </tr>
                <?php else: foreach ($recentApplications as $app): 
                    $name = trim($app['first_name'] . ' ' . $app['last_name']);
                    $initials = strtoupper(mb_substr($app['first_name'] ?? '', 0, 1) . mb_substr($app['last_name'] ?? '', 0, 1));
                    $prog = $app['program_code'] ?: ($app['program_applying_for'] ?: 'BSMT');
                ?>
                    <tr>
                        <td class="ps-4">
                            <div class="d-flex align-items-center gap-3">
                                <div class="dash-app-avatar"><?php echo htmlspecialchars($initials ?: 'C'); ?></div>
                                <div>
                                    <div class="fw-bold text-navy" style="font-size: 0.9rem;">
                                        <?php echo htmlspecialchars($name); ?>
                                    </div>
                                    <div class="text-muted" style="font-size: 0.76rem;">
                                        <i class="bi bi-envelope text-brand-primary me-1" style="font-size: 0.7rem;"></i><?php echo htmlspecialchars($app['email']); ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="badge bg-secondary-subtle text-navy border fw-bold" style="font-size: 0.74rem; padding: 4px 10px;">
                                <?php echo htmlspecialchars($prog); ?>
                            </span>
                        </td>
                        <td>
                            <span class="yr-badge-dash"><?php echo htmlspecialchars($app['year_level'] ?: '1st Year'); ?></span>
                        </td>
                        <td>
                            <div class="text-dark" style="font-size: 0.82rem;"><?php echo date('M d, Y', strtotime($app['created_at'])); ?></div>
                            <div class="text-muted" style="font-size: 0.74rem;"><?php echo date('h:i A', strtotime($app['created_at'])); ?></div>
                        </td>
                        <td class="pe-4 text-end">
                            <a href="enrollee_applications?id=<?php echo $app['id']; ?>" class="btn-view-app">
                                <i class="bi bi-person-vcard"></i> Review
                            </a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- Realtime metrics refresh script -->
<script>
(function() {
    const apiUrl = 'dashboard_api';
    const refreshInterval = 4000;
    let timer = null;

    function updateMetrics() {
        fetch(apiUrl, { method: 'GET', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data && data.metrics) {
                    Object.keys(data.metrics).forEach(function(key) {
                        var el = document.getElementById('metric-' + key);
                        if (el) {
                            var value = data.metrics[key];
                            el.textContent = value === null ? '0' : value.toLocaleString();
                            el.style.transform = 'scale(1.08)';
                            setTimeout(function() { el.style.transform = 'scale(1)'; }, 180);
                        }
                    });
                }
                if (data && data.flash) {
                    renderFlash(data.flash);
                }
            })
            .catch(function(error) {
                // Polling failed — metrics will refresh on the next interval.
                // Error details are not logged to the console in production.
            });
    }

    function renderFlash(flash) {
        var container = document.getElementById('dashboardFlashContainer');
        if (!container) return;
        if (flash.success) {
            showToast(flash.success, 'success');
        }
        if (flash.error) {
            showToast(flash.error, 'danger');
        }
    }

    function showToast(message, type) {
        var container = document.getElementById('dashboardFlashContainer');
        if (!container) return;
        var toast = document.createElement('div');
        toast.className = 'alert alert-' + type + ' shadow-sm mb-2';
        toast.style.opacity = '0';
        toast.style.transition = 'opacity 0.3s ease';
        toast.textContent = message;
        container.appendChild(toast);
        requestAnimationFrame(function() { toast.style.opacity = '1'; });
        setTimeout(function() {
            toast.style.opacity = '0';
            setTimeout(function() { toast.remove(); }, 300);
        }, 3500);
    }

    document.addEventListener('DOMContentLoaded', function() {
        updateMetrics();
        timer = setInterval(updateMetrics, refreshInterval);
    });
})();
</script>

<?php require_once '../includes/footer.php'; ?>
