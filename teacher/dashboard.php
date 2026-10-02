<?php
/** Teacher dashboard */
require_once '../includes/auth_check.php';
checkRole(['teacher']);
require_once '../config/database.php';
require_once '../includes/academic_terms.php';

$userId = (int)$_SESSION['user_id'];

// 1. Live faculty account status
$teacherStmt = $pdo->prepare("SELECT is_active FROM users WHERE id = :id AND role = 'teacher' LIMIT 1");
$teacherStmt->execute(['id' => $userId]);
$teacherUser = $teacherStmt->fetch();
$facultyStatus = ($teacherUser && (int)$teacherUser['is_active'] === 1) ? 'Active' : 'Inactive';
$facultyStatusSubtext = ($facultyStatus === 'Active') ? 'Account in good standing' : 'Account is deactivated';
$facultyStatusIcon = ($facultyStatus === 'Active') ? 'bi-check2-circle' : 'bi-exclamation-triangle';

// 2. Live assigned classes count (sections where teacher is lead or subject instructor)
$classStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM sections s 
    WHERE s.teacher_id = :teacher_id_a 
       OR EXISTS (
           SELECT 1 FROM section_subjects ss 
           WHERE ss.section_id = s.id AND ss.instructor_id = :teacher_id_b
       )
");
$classStmt->execute(['teacher_id_a' => $userId, 'teacher_id_b' => $userId]);
$assignedClassesCount = (int)$classStmt->fetchColumn();

// 3. Live total students enrolled across assigned sections
$studStmt = $pdo->prepare("
    SELECT COUNT(DISTINCT e.student_id) 
    FROM enrollments e 
    WHERE e.status = 'enrolled' 
      AND e.section_id IN (
          SELECT s.id 
          FROM sections s 
          WHERE s.teacher_id = :teacher_id_a 
             OR EXISTS (
                 SELECT 1 FROM section_subjects ss 
                 WHERE ss.section_id = s.id AND ss.instructor_id = :teacher_id_b
             )
      )
");
$studStmt->execute(['teacher_id_a' => $userId, 'teacher_id_b' => $userId]);
$totalStudentsCount = (int)$studStmt->fetchColumn();

// 4. Live term progress from active academic term
$activeTerm = getActiveAcademicTerm($pdo);
$termProgressLabel = $activeTerm 
    ? (!empty($activeTerm['semester']) ? (stripos($activeTerm['semester'], 'sem') !== false ? ucfirst($activeTerm['semester']) : ucfirst($activeTerm['semester']) . ' Sem') : 'Active Term')
    : 'No Active Term';
$termSubLabel = $activeTerm ? ('AY ' . htmlspecialchars($activeTerm['school_year'])) : 'Academic Year';

$page_title = 'Teacher Overview';
$page_class = 'page-dashboard page-teacher-dashboard';
require_once '../includes/header.php';
?>

<section class="dashboard-hero mb-4">
    <div>
        <div class="page-eyebrow"><i class="bi bi-person-workspace me-1"></i> Faculty workspace</div>
        <h1 class="dashboard-title">Teacher Overview</h1>
        <p class="dashboard-subtitle">Welcome back, <?php echo htmlspecialchars($_SESSION['username'] ?? 'Teacher'); ?>. Manage your classes, LMS content, and student assessments from here.</p>
    </div>
    <div class="dashboard-actions d-flex flex-wrap gap-2">
        <a href="my_profile" class="btn btn-dashboard-secondary"><i class="bi bi-person me-2"></i>My Profile</a>
        <a href="my_classes" class="btn btn-dashboard-secondary"><i class="bi bi-calendar3 me-2"></i>View Classes</a>
        <a href="lms" class="btn btn-dashboard-primary" id="teacher-dash-lms-btn"><i class="bi bi-journal-bookmark-fill me-2"></i>Instructor LMS</a>
    </div>
</section>

<section class="row g-3 mb-4 dashboard-kpis">
    <div class="col-12 col-sm-6 col-xl-3"><div class="dashboard-stat-card"><div class="stat-label">Assigned Classes</div><div class="stat-value"><?php echo number_format($assignedClassesCount); ?></div><div class="stat-trend trend-up"><i class="bi bi-easel2"></i> Current assignments</div></div></div>
    <div class="col-12 col-sm-6 col-xl-3"><div class="dashboard-stat-card"><div class="stat-label">Total Students</div><div class="stat-value"><?php echo number_format($totalStudentsCount); ?></div><div class="stat-trend trend-up"><i class="bi bi-people"></i> Across assigned sections</div></div></div>
    <div class="col-12 col-sm-6 col-xl-3"><div class="dashboard-stat-card"><div class="stat-label">Term Progress</div><div class="stat-value" style="font-size: 1.15rem;"><?php echo htmlspecialchars($termProgressLabel); ?></div><div class="stat-trend"><i class="bi bi-calendar-check"></i> <?php echo htmlspecialchars($termSubLabel); ?></div></div></div>
    <div class="col-12 col-sm-6 col-xl-3"><div class="dashboard-stat-card"><div class="stat-label">Faculty Status</div><div class="stat-value"><?php echo htmlspecialchars($facultyStatus); ?></div><div class="stat-trend <?php echo $facultyStatus === 'Active' ? 'trend-up' : ''; ?>"><i class="bi <?php echo $facultyStatusIcon; ?>"></i> <?php echo htmlspecialchars($facultyStatusSubtext); ?></div></div></div>
</section>

<section class="row g-3">
    <div class="col-12 col-xl-8"><div class="dashboard-panel h-100"><div class="panel-heading"><div><h3>Faculty Desk</h3><p>Common teaching tasks</p></div><a href="my_classes" class="panel-link">View all classes <i class="bi bi-arrow-up-right"></i></a></div><div class="quick-actions-grid"><a href="lms" class="quick-action" id="dash-qa-lms"><span class="quick-action-icon"><i class="bi bi-journal-bookmark-fill"></i></span><span><strong>Instructor LMS</strong><small>Manage subjects, materials, and grades</small></span><i class="bi bi-chevron-right ms-auto"></i></a><a href="my_classes" class="quick-action"><span class="quick-action-icon"><i class="bi bi-calendar3"></i></span><span><strong>My Classes</strong><small>Review assigned subjects and schedules</small></span><i class="bi bi-chevron-right ms-auto"></i></a><a href="class_list" class="quick-action"><span class="quick-action-icon"><i class="bi bi-people"></i></span><span><strong>Class Lists</strong><small>View students in each section</small></span><i class="bi bi-chevron-right ms-auto"></i></a><a href="lms_grades" class="quick-action"><span class="quick-action-icon"><i class="bi bi-award"></i></span><span><strong>Grades</strong><small>Enter and review student grades</small></span><i class="bi bi-chevron-right ms-auto"></i></a><a href="my_profile" class="quick-action"><span class="quick-action-icon"><i class="bi bi-person-vcard"></i></span><span><strong>My Profile</strong><small>View faculty account details</small></span><i class="bi bi-chevron-right ms-auto"></i></a></div></div></div>
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
                    <div style="color:#fff;font-size:.82rem;opacity:.8;">Your faculty access</div>
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
                "><?php echo htmlspecialchars(strtoupper(substr($_SESSION['username'] ?? 'T', 0, 2))); ?></div>
                <div>
                    <div style="color:#fff;font-size:1rem;font-weight:750;line-height:1.2;"><?php echo htmlspecialchars($_SESSION['username'] ?? 'Teacher'); ?></div>
                    <div style="color:rgba(255,255,255,.6);font-size:.75rem;margin-top:.2rem;">Faculty instructor account</div>
                </div>
            </div>

            <!-- Detail rows -->
            <div style="position:relative;">
                <div style="display:flex;justify-content:space-between;align-items:center;padding:.6rem 0;border-bottom:1px solid rgba(255,255,255,.12);">
                    <span style="color:rgba(255,255,255,.6);font-size:.77rem;">Role</span>
                    <strong style="color:#fff;font-size:.8rem;background:rgba(255,255,255,.15);padding:.2rem .65rem;border-radius:999px;border:1px solid rgba(255,255,255,.2);">Teacher</strong>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:.6rem 0;border-bottom:1px solid rgba(255,255,255,.12);">
                    <span style="color:rgba(255,255,255,.6);font-size:.77rem;">Workspace</span>
                    <strong style="color:#fff;font-size:.8rem;">Faculty Office</strong>
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
