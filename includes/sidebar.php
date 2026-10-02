<?php
/**
 * Sidebar Navigation Component
 * Renders a role-specific vertical navigation menu with active highlights.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Dynamically compute the path to the project root directory
$base_path = './';
if (file_exists('index.php')) {
    $base_path = './';
} elseif (file_exists('../index.php')) {
    $base_path = '../';
} elseif (file_exists('../../index.php')) {
    $base_path = '../../';
}

$userRole = $_SESSION['role'] ?? '';

/**
 * Checks if a given script is the active page.
 * 
 * @param string $pageRelativePath e.g. "admin/dashboard.php"
 * @return string CSS class "active" or empty string
 */
if (!function_exists('isActivePage')) {
    function isActivePage(string $pageRelativePath): string {
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $uri = str_replace('\\', '/', $_SERVER['REQUEST_URI'] ?? '');
        $target = str_replace('\\', '/', $pageRelativePath);
        $baseName = basename($target, '.php');

        if (str_contains($script, '/' . $target) || 
            str_contains($script, '/' . $baseName . '.php') ||
            (bool)preg_match('#/' . preg_quote($baseName, '#') . '(\?|/|$)#', $uri)) {
            return 'active';
        }
        return '';
    }
}

$unreadNotificationsCount = 0;
if (isset($_SESSION['user_id'])) {
    try {
        global $pdo;
        if (!isset($pdo)) {
            require_once __DIR__ . '/../config/database.php';
        }
        if (isset($pdo)) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND is_read = 0");
            $stmt->execute(['user_id' => (int)$_SESSION['user_id']]);
            $unreadNotificationsCount = (int)$stmt->fetchColumn();
        }
    } catch (Throwable $e) {
        // Silent fall
    }
}
?>
<aside class="sidebar d-flex flex-column justify-content-between">
    <div>
        <!-- Sidebar Brand Header -->
        <div class="sidebar-header">
            <a href="<?php echo $base_path; ?>index" class="sidebar-logo">
                <i class="bi bi-compass-fill"></i>
                <div class="d-flex flex-column leading-none">
                    <span class="lh-1">NCST MARITIME</span>
                    <span class="small text-muted-on-dark fw-light" style="font-size: 0.65rem; letter-spacing: 0.5px;">ACADEMY PORTAL</span>
                </div>
            </a>
        </div>
        
        <!-- Sidebar Menus depending on role -->
        <nav class="sidebar-menu">
            <?php if ($userRole === 'admin'): ?>
                <div class="menu-label">Administrator</div>
                
                <a href="<?php echo $base_path; ?>admin/dashboard" class="nav-link-custom <?php echo isActivePage('admin/dashboard.php'); ?>">
                    <i class="bi bi-grid-fill"></i>
                    <span>Dashboard</span>
                </a>
                
                <a href="<?php echo $base_path; ?>admin/manage_users" class="nav-link-custom <?php echo isActivePage('admin/manage_users.php'); ?>">
                    <i class="bi bi-people-fill"></i>
                    <span>Manage Users</span>
                </a>

                <a href="<?php echo $base_path; ?>admin/lms_users" class="nav-link-custom <?php echo (
                    isActivePage('admin/lms_users.php') ||
                    isActivePage('admin/lms_courses.php') ||
                    isActivePage('admin/lms_terms.php') ||
                    isActivePage('admin/lms_settings.php') ||
                    isActivePage('admin/lms_oversight.php') ||
                    isActivePage('admin/lms_reports.php') ||
                    isActivePage('admin/lms_audit.php')
                ) ? 'active' : ''; ?>">
                    <i class="bi bi-mortarboard-fill"></i>
                    <span>LMS Administration</span>
                </a>
                
                <a href="<?php echo $base_path; ?>admin/manage_roles" class="nav-link-custom <?php echo isActivePage('admin/manage_roles.php'); ?>">
                    <i class="bi bi-shield-lock-fill"></i>
                    <span>Manage Roles</span>
                </a>
                <a href="<?php echo $base_path; ?>admin/academic_terms" class="nav-link-custom <?php echo isActivePage('admin/academic_terms.php'); ?>">
                    <i class="bi bi-calendar3"></i>
                    <span>Academic Terms</span>
                </a>
                <a href="<?php echo $base_path; ?>admin/curriculum" class="nav-link-custom <?php echo isActivePage('admin/curriculum.php'); ?>">
                    <i class="bi bi-journal-album"></i>
                    <span>Curriculum</span>
                </a>
                <a href="<?php echo $base_path; ?>admin/fee_setup" class="nav-link-custom <?php echo isActivePage('admin/fee_setup.php'); ?>">
                    <i class="bi bi-cash-stack"></i>
                    <span>Fee Setup</span>
                </a>
                <a href="<?php echo $base_path; ?>admin/trash_bin" class="nav-link-custom <?php echo isActivePage('admin/trash_bin.php'); ?>">
                    <i class="bi bi-trash3-fill"></i>
                    <span>Trash Bin</span>
                </a>
                <a href="<?php echo $base_path; ?>admin/audit_log" class="nav-link-custom <?php echo isActivePage('admin/audit_log.php'); ?>">
                    <i class="bi bi-clock-history"></i>
                    <span>Audit Log</span>
                </a>

            <?php elseif ($userRole === 'registrar'): ?>
                <div class="menu-label">Registrar Office</div>
                
                <a href="<?php echo $base_path; ?>registrar/dashboard" class="nav-link-custom <?php echo isActivePage('registrar/dashboard.php'); ?>">
                    <i class="bi bi-grid-fill"></i>
                    <span>Dashboard</span>
                </a>

                <div class="menu-label mt-2">Admissions &amp; Enrollment</div>
                
                <a href="<?php echo $base_path; ?>registrar/enrollee_applications" class="nav-link-custom <?php echo isActivePage('registrar/enrollee_applications.php'); ?>">
                    <i class="bi bi-file-earmark-person-fill"></i>
                    <span>Applications</span>
                </a>

                <a href="<?php echo $base_path; ?>registrar/credit_evaluation" class="nav-link-custom <?php echo isActivePage('registrar/credit_evaluation.php'); ?>">
                    <i class="bi bi-patch-check-fill"></i>
                    <span>Credit Evaluation</span>
                </a>
                
                <a href="<?php echo $base_path; ?>registrar/students" class="nav-link-custom <?php echo (isActivePage('registrar/students.php') || isActivePage('registrar/student_selection_review.php')) ? 'active' : ''; ?>">
                    <i class="bi bi-mortarboard-fill"></i>
                    <span>Students</span>
                </a>
                
                <a href="<?php echo $base_path; ?>registrar/enrollments" class="nav-link-custom <?php echo isActivePage('registrar/enrollments.php'); ?>">
                    <i class="bi bi-clipboard-check-fill"></i>
                    <span>Enrollments</span>
                </a>

                <div class="menu-label mt-2">Academic Offerings</div>

                <a href="<?php echo $base_path; ?>registrar/curriculum" class="nav-link-custom <?php echo isActivePage('registrar/curriculum.php'); ?>">
                    <i class="bi bi-journal-album"></i>
                    <span>Curriculum</span>
                </a>

                <a href="<?php echo $base_path; ?>registrar/subjects" class="nav-link-custom <?php echo isActivePage('registrar/subjects.php'); ?>">
                    <i class="bi bi-book-fill"></i>
                    <span>Subjects</span>
                </a>
                
                <a href="<?php echo $base_path; ?>registrar/sections" class="nav-link-custom <?php echo (isActivePage('registrar/sections.php') || isActivePage('registrar/section_details.php')) ? 'active' : ''; ?>">
                    <i class="bi bi-list-columns-reverse"></i>
                    <span>Sections</span>
                </a>

                <div class="menu-label mt-2">Grades &amp; Records</div>

                <a href="<?php echo $base_path; ?>registrar/grade_approvals" class="nav-link-custom <?php echo isActivePage('registrar/grade_approvals.php'); ?>">
                    <i class="bi bi-clipboard2-check-fill"></i>
                    <span>Grade Approvals</span>
                </a>

                <a href="<?php echo $base_path; ?>registrar/lms_enrollments" class="nav-link-custom <?php echo (
                    isActivePage('registrar/lms_enrollments.php') ||
                    isActivePage('registrar/lms_subjects.php') ||
                    isActivePage('registrar/lms_sections.php') ||
                    isActivePage('registrar/lms_grades.php') ||
                    isActivePage('registrar/lms_reports.php')
                ) ? 'active' : ''; ?>">
                    <i class="bi bi-journal-bookmark-fill"></i>
                    <span>LMS Records</span>
                </a>

            <?php elseif ($userRole === 'cashier'): ?>
                <div class="menu-label">Cashier Desk</div>
                
                <a href="<?php echo $base_path; ?>cashier/dashboard" class="nav-link-custom <?php echo isActivePage('cashier/dashboard.php'); ?>">
                    <i class="bi bi-grid-fill"></i>
                    <span>Dashboard</span>
                </a>
                
                <a href="<?php echo $base_path; ?>cashier/enrollment_queue" class="nav-link-custom <?php echo (isActivePage('cashier/enrollment_queue.php') || isActivePage('cashier/payments.php') || isActivePage('cashier/assessment.php')) ? 'active' : ''; ?>">
                    <i class="bi bi-person-lines-fill"></i>
                    <span>Enrollment Queue</span>
                </a>
                
                <a href="<?php echo $base_path; ?>cashier/receipts" class="nav-link-custom <?php echo isActivePage('cashier/receipts.php'); ?>">
                    <i class="bi bi-file-earmark-text-fill"></i>
                    <span>Official Receipts</span>
                </a>
                
                <a href="<?php echo $base_path; ?>cashier/payment_history" class="nav-link-custom <?php echo isActivePage('cashier/payment_history.php'); ?>">
                    <i class="bi bi-clock-history"></i>
                    <span>Payment History</span>
                </a>

            <?php elseif ($userRole === 'student'): ?>
                <div class="menu-label">Student Portal</div>
                
                <a href="<?php echo $base_path; ?>student/dashboard" class="nav-link-custom <?php echo isActivePage('student/dashboard.php'); ?>">
                    <i class="bi bi-grid-fill"></i>
                    <span>Dashboard</span>
                </a>



                <a href="<?php echo $base_path; ?>student/lms" class="nav-link-custom <?php echo (isActivePage('student/lms.php') || isActivePage('student/lms_course.php')) ? 'active' : ''; ?>">
                    <i class="bi bi-journal-bookmark-fill"></i>
                    <span>My Courses</span>
                </a>

                <a href="<?php echo $base_path; ?>student/academic_records" class="nav-link-custom <?php echo isActivePage('student/academic_records.php'); ?>"><i class="bi bi-award-fill"></i><span>Academic Records</span></a>
                
                <a href="<?php echo $base_path; ?>student/my_enrollments" class="nav-link-custom <?php echo isActivePage('student/my_enrollments.php'); ?>">
                    <i class="bi bi-calendar-week"></i>
                    <span>Class Schedule</span>
                </a>
                
                <a href="<?php echo $base_path; ?>student/payment" class="nav-link-custom <?php echo isActivePage('student/payment.php'); ?>">
                    <i class="bi bi-wallet2"></i>
                    <span>Payment Center</span>
                </a>
                
                <a href="<?php echo $base_path; ?>student/my_payments" class="nav-link-custom <?php echo isActivePage('student/my_payments.php'); ?>">
                    <i class="bi bi-cash-coin"></i>
                    <span>My Payments</span>
                </a>

                <a href="<?php echo $base_path; ?>student/my_profile" class="nav-link-custom <?php echo (isActivePage('student/my_profile.php') || isActivePage('student/profile_edit.php')) ? 'active' : ''; ?>">
                    <i class="bi bi-person-circle"></i>
                    <span>My Profile</span>
                </a>


            <?php elseif ($userRole === 'teacher'): ?>
                <div class="menu-label">Instructor Portal</div>

                <a href="<?php echo $base_path; ?>teacher/dashboard" class="nav-link-custom <?php echo isActivePage('teacher/dashboard.php'); ?>">
                    <i class="bi bi-grid-fill"></i>
                    <span>Dashboard</span>
                </a>

                <a href="<?php echo $base_path; ?>teacher/my_classes" class="nav-link-custom <?php echo isActivePage('teacher/my_classes.php'); ?>">
                    <i class="bi bi-door-open-fill"></i>
                    <span>My Classes</span>
                </a>

                <a href="<?php echo $base_path; ?>teacher/class_list" class="nav-link-custom <?php echo isActivePage('teacher/class_list.php'); ?>">
                    <i class="bi bi-list-stars"></i>
                    <span>Class Lists</span>
                </a>

                <a href="<?php echo $base_path; ?>teacher/attendance" class="nav-link-custom <?php echo isActivePage('teacher/attendance.php'); ?>">
                    <i class="bi bi-clipboard-check-fill"></i>
                    <span>Attendance</span>
                </a>

                <!-- ── Instructor Management (LMS) ──────────────────── -->
                <div class="menu-label mt-2">Instructor Management</div>

                <a href="<?php echo $base_path; ?>teacher/lms_assignments"
                   class="nav-link-custom <?php echo isActivePage('teacher/lms_assignments.php'); ?>">
                    <i class="bi bi-pencil-square"></i>
                    <span>Assignments</span>
                </a>

                <a href="<?php echo $base_path; ?>teacher/lms_quizzes"
                   class="nav-link-custom <?php echo isActivePage('teacher/lms_quizzes.php'); ?>">
                    <i class="bi bi-patch-question-fill"></i>
                    <span>Quizzes</span>
                </a>

                <a href="<?php echo $base_path; ?>teacher/lms_submissions"
                   class="nav-link-custom <?php echo isActivePage('teacher/lms_submissions.php'); ?>">
                    <i class="bi bi-inbox-fill"></i>
                    <span>Submissions</span>
                </a>

                <a href="<?php echo $base_path; ?>teacher/lms_materials"
                   class="nav-link-custom <?php echo isActivePage('teacher/lms_materials.php'); ?>">
                    <i class="bi bi-file-earmark-text-fill"></i>
                    <span>Learning Materials</span>
                </a>

                <a href="<?php echo $base_path; ?>teacher/lms_grades"
                   class="nav-link-custom <?php echo isActivePage('teacher/lms_grades.php'); ?>">
                    <i class="bi bi-award-fill"></i>
                    <span>Grades</span>
                </a>

                <a href="<?php echo $base_path; ?>teacher/lms_announcements"
                   class="nav-link-custom <?php echo isActivePage('teacher/lms_announcements.php'); ?>">
                    <i class="bi bi-megaphone-fill"></i>
                    <span>Announcements</span>
                </a>

                <a href="<?php echo $base_path; ?>teacher/lms_progress"
                   class="nav-link-custom <?php echo isActivePage('teacher/lms_progress.php'); ?>">
                    <i class="bi bi-graph-up-arrow"></i>
                    <span>Student Progress</span>
                </a>

                <a href="<?php echo $base_path; ?>teacher/lms"
                   class="nav-link-custom <?php echo (
                       isActivePage('teacher/lms.php') ||
                       isActivePage('teacher/lms_subject.php')
                   ) ? 'active' : ''; ?>">
                    <i class="bi bi-journal-bookmark-fill"></i>
                    <span>My Subjects</span>
                </a>
                <!-- ────────────────────────────────────────────────── -->

                <a href="<?php echo $base_path; ?>teacher/my_profile" class="nav-link-custom <?php echo isActivePage('teacher/my_profile.php'); ?>">
                    <i class="bi bi-person-circle"></i>
                    <span>My Profile</span>
                </a>

            <?php elseif ($userRole === 'enrollee'):
                // Load enrollee's current status for context-aware menu
                $__enrolleeAppStatus = 'draft';
                $__enrolleeEnrStatus = 'draft';
                if (isset($_SESSION['user_id']) && isset($pdo)) {
                    try {
                        $__s = $pdo->prepare('SELECT application_status, enrollment_status FROM students WHERE user_id = :uid LIMIT 1');
                        $__s->execute(['uid' => (int)$_SESSION['user_id']]);
                        $__sr = $__s->fetch();
                        if ($__sr) {
                            $__enrolleeAppStatus = $__sr['application_status'];
                            $__enrolleeEnrStatus = $__sr['enrollment_status'];
                        }
                    } catch (\Throwable $__e) {}
                }
            ?>
                <div class="menu-label">Applicant Portal</div>

                <a href="<?php echo $base_path; ?>enrollee/dashboard" class="nav-link-custom <?php echo (isActivePage('enrollee/dashboard.php') || isActivePage('enrollee/welcome.php')) ? 'active' : ''; ?>">
                    <i class="bi bi-grid-fill"></i>
                    <span>Dashboard</span>
                </a>

                <?php if (in_array($__enrolleeAppStatus, ['draft','pending','under_review','needs_revision','approved'])): ?>
                <a href="<?php echo $base_path; ?>enrollee/apply" class="nav-link-custom <?php echo (isActivePage('enrollee/apply.php') || isActivePage('enrollee/review.php')) ? 'active' : ''; ?>">
                    <i class="bi bi-file-earmark-diff-fill"></i>
                    <span>Application Form</span>
                </a>
                <?php endif; ?>

                <?php if ($__enrolleeAppStatus === 'eligible_to_enroll' && !in_array($__enrolleeEnrStatus, ['section_chosen','paid','enrolled'])): ?>
                <a href="<?php echo $base_path; ?>enrollee/sections" class="nav-link-custom <?php echo isActivePage('enrollee/sections.php'); ?>" style="color:#6ee7b7;">
                    <i class="bi bi-grid-3x3-gap-fill" style="color:#00c98e;"></i>
                    <span>Choose Section</span>
                    <span class="badge ms-auto" style="background:#00c98e;color:#fff;font-size:.65rem;border-radius:50px;">New</span>
                </a>
                <?php endif; ?>

                <?php if (in_array($__enrolleeEnrStatus, ['section_chosen','paid','enrolled'])): ?>
                <a href="<?php echo $base_path; ?>enrollee/my_section" class="nav-link-custom <?php echo isActivePage('enrollee/my_section.php'); ?>">
                    <i class="bi bi-journal-bookmark-fill" style="color:#00c98e;"></i>
                    <span>My Section</span>
                </a>
                <?php endif; ?>

                <?php if ($__enrolleeEnrStatus === 'section_chosen'): ?>
                <a href="<?php echo $base_path; ?>enrollee/sections?change=1" class="nav-link-custom <?php echo (isActivePage('enrollee/sections.php') && isset($_GET['change'])) ? 'active' : ''; ?>">
                    <i class="bi bi-arrow-repeat" style="color:#38bdf8;"></i>
                    <span>Switch Section</span>
                </a>
                <?php endif; ?>

                <?php if ($__enrolleeEnrStatus === 'section_chosen'): ?>
                <!-- Section chosen but NOT yet registrar-validated: show locked payment indicator -->
                <span class="nav-link-custom opacity-60" style="cursor:default;" title="Awaiting registrar validation before payment is unlocked">
                    <i class="bi bi-lock-fill" style="color:#ca8a04;"></i>
                    <span>Pay Now</span>
                    <span class="badge ms-auto" style="background:#fde68a;color:#78350f;font-size:.65rem;border-radius:50px;">Locked</span>
                </span>
                <?php elseif (in_array($__enrolleeEnrStatus, ['walk_in_ready', 'paid', 'enrolled'], true)): ?>
                <a href="<?php echo $base_path; ?>enrollee/payment" class="nav-link-custom <?php echo isActivePage('enrollee/payment.php'); ?>">
                    <i class="bi bi-wallet2"></i>
                    <span>Pay Now</span>
                </a>
                <?php endif; ?>


            <?php endif; ?>
        </nav>
    </div>
    
    <!-- Sidebar Footer -->
    <div class="sidebar-footer">
        <form method="POST" action="<?php echo $base_path; ?>auth/logout" style="margin:0;">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
            <button type="submit" class="nav-link-custom text-danger mb-0 py-2 w-100 border-0 bg-transparent text-start" style="cursor:pointer;">
                <i class="bi bi-box-arrow-right text-danger"></i>
                <span>Log Out</span>
            </button>
        </form>
    </div>
</aside>
