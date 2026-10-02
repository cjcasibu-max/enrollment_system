<?php
/**
 * Application Header Template
 * Renders HTML head, CSS linkages, and base layout shells (navbar and sidebar wrapper).
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

$isLoggedIn = isset($_SESSION['user_id']);
$showSidebar = $isLoggedIn && !isset($no_sidebar);

// Sync user avatar profile picture if not yet set in session
if ($isLoggedIn && !array_key_exists('profile_picture', $_SESSION)) {
    try {
        if (!isset($pdo)) {
            require_once $base_path . 'config/database.php';
        }
        $avatarCheckStmt = $pdo->prepare("SELECT profile_picture FROM users WHERE id = :id LIMIT 1");
        $avatarCheckStmt->execute(['id' => (int)$_SESSION['user_id']]);
        $_SESSION['profile_picture'] = $avatarCheckStmt->fetchColumn() ?: null;
        if (!isset($_SESSION['avatar_version'])) {
            $_SESSION['avatar_version'] = time();
        }
    } catch (\Throwable $e) {
        $_SESSION['profile_picture'] = null;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title) . " — NCST Maritime Academy" : "NCST Maritime Academy Enrollment System"; ?></title>
    
    <!-- Meta tags for SEO -->
    <meta name="description" content="NCST Maritime Academy Enrollment System. Secure and modern monolith portal for enrollee, student, admin, registrar, cashier, and teacher roles.">
    <meta name="theme-color" content="#008080">
    
    <!-- Vendored frontend dependencies: safe for offline presentations. -->
    <link href="<?php echo $base_path; ?>assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?php echo $base_path; ?>assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
    
    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Tabulator.js Stylesheet (Local vendored with CDN fallback) -->
    <?php if (file_exists($base_path . 'assets/vendor/tabulator/css/tabulator_bootstrap5.min.css')): ?>
    <link href="<?php echo $base_path; ?>assets/vendor/tabulator/css/tabulator_bootstrap5.min.css" rel="stylesheet">
    <?php else: ?>
    <link href="https://cdn.jsdelivr.net/npm/tabulator-tables@6.3.0/dist/css/tabulator_bootstrap5.min.css" rel="stylesheet">
    <?php endif; ?>
    
    <!-- Custom Brand Stylesheet -->
    <link href="<?php echo $base_path; ?>assets/css/custom.css?v=20261001-bell" rel="stylesheet">
</head>
<body class="d-flex flex-column min-vh-100 <?php echo htmlspecialchars($page_class ?? ''); ?>">

<?php if ($showSidebar): ?>
<div class="app-container d-flex">
    <!-- Sidebar Navigation -->
    <?php include_once $base_path . 'includes/sidebar.php'; ?>
    
    <!-- Main Right-side Wrapper -->
    <div class="main-wrapper flex-grow-1 d-flex flex-column">
        
        <!-- Top Navbar Header -->
        <nav class="navbar navbar-expand top-navbar d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center">
                <!-- Mobile Toggle Button -->
                <button class="btn btn-link text-dark d-lg-none me-3 p-0" id="sidebarToggle" type="button" aria-label="Toggle Navigation">
                    <i class="bi bi-list fs-2"></i>
                </button>
                
                <!-- Welcome/Context Heading -->
                <div class="d-none d-sm-block">
                    <span class="text-muted small"><?php echo htmlspecialchars(ucfirst($_SESSION['role'] ?? 'User')); ?> Portal</span>
                    <h5 class="m-0 text-navy font-semibold">NCST Maritime Academy</h5>
                </div>
            </div>
            
            <!-- User Status & Actions -->
            <div class="d-flex align-items-center gap-3">

                <!-- Username + role text (hidden on mobile) -->
                <div class="text-end d-none d-md-block">
                    <div class="fw-semibold text-darker"><?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?></div>
                    <div class="text-muted small text-uppercase fw-bold" style="font-size: 0.65rem; letter-spacing: 0.5px;">
                        <?php echo htmlspecialchars($_SESSION['role'] ?? ''); ?>
                    </div>
                </div>

                <!-- Bell + Avatar: tight inner group -->
                <div class="d-flex align-items-center gap-2">

                    <?php
                    // Notifications bell — student and enrollee only
                    $__role = $_SESSION['role'] ?? '';
                    if ($__role === 'student' || $__role === 'enrollee'):
                        $__notifUrl = $base_path . ($__role === 'student' ? 'student/notifications' : 'enrollee/notifications');
                        $__notifPage = $__role === 'student' ? 'student/notifications.php' : 'enrollee/notifications.php';
                        $__notifActive = (isset($_SERVER['SCRIPT_NAME']) && str_contains(str_replace('\\','/',$_SERVER['SCRIPT_NAME']), '/' . $__notifPage)) ? 'topbar-bell--active' : '';
                        $__bellCount = isset($unreadNotificationsCount) ? (int)$unreadNotificationsCount : 0;
                        $__bellLabel = $__bellCount > 99 ? '99+' : (string)$__bellCount;
                    ?>
                    <a href="<?php echo htmlspecialchars($__notifUrl); ?>"
                       class="topbar-bell <?php echo $__notifActive; ?>"
                       aria-label="Notifications"
                       title="Notifications">
                        <i class="bi bi-bell-fill" aria-hidden="true"></i>
                        <?php if ($__bellCount > 0): ?>
                            <span class="topbar-bell-badge" aria-label="<?php echo $__bellCount; ?> unread notifications"><?php echo htmlspecialchars($__bellLabel); ?></span>
                        <?php endif; ?>
                    </a>
                    <?php endif; ?>

                    <!-- Profile Dropdown (avatar circle) -->
                    <div class="dropdown navbar-user-dropdown">
                        <a href="#" class="dropdown-toggle" id="profileDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <div class="user-avatar shadow-sm overflow-hidden position-relative">
                                <?php
                                    $initials = strtoupper(substr($_SESSION['username'] ?? 'U', 0, 2));
                                    $hasAvatar = !empty($_SESSION['profile_picture']);
                                    $avatarUid = (int)($_SESSION['user_id'] ?? 0);
                                    $avatarVer = (int)($_SESSION['avatar_version'] ?? 1);
                                ?>
                                <?php if ($hasAvatar): ?>
                                    <img src="<?php echo $base_path; ?>actions/view_avatar?uid=<?php echo $avatarUid; ?>&v=<?php echo $avatarVer; ?>" 
                                         alt="<?php echo htmlspecialchars($initials); ?>" 
                                         class="user-avatar-img w-100 h-100" 
                                         style="object-fit:cover; border-radius:50%; display:block;"
                                         onerror="this.style.display='none'; if(this.nextElementSibling) this.nextElementSibling.style.display='flex';">
                                    <span class="user-avatar-initials align-items-center justify-content-center w-100 h-100" style="display:none;"><?php echo htmlspecialchars($initials); ?></span>
                                <?php else: ?>
                                    <span class="user-avatar-initials align-items-center justify-content-center w-100 h-100" style="display:flex;"><?php echo htmlspecialchars($initials); ?></span>
                                <?php endif; ?>
                            </div>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end border-0 shadow-lg mt-2 py-2" aria-labelledby="profileDropdown" style="border-radius: 10px;">
                            <li class="dropdown-header d-md-none border-bottom pb-2 mb-2">
                                <div class="fw-bold"><?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?></div>
                                <div class="text-muted small text-uppercase fw-semibold" style="font-size: 0.75rem;">
                                    <?php echo htmlspecialchars($_SESSION['role'] ?? ''); ?>
                                </div>
                            </li>
                            <?php if (!empty($_SESSION['role'])): ?>
                                <li>
                                    <a class="dropdown-item py-2 d-flex align-items-center gap-2" href="<?php echo $base_path; ?><?php echo $_SESSION['role']; ?>/my_profile">
                                        <i class="bi bi-person text-muted fs-5"></i> My Profile
                                    </a>
                                </li>
                                <li><hr class="dropdown-divider bg-light"></li>
                            <?php endif; ?>
                            <li>
                                <form method="POST" action="<?php echo $base_path; ?>auth/logout" style="margin:0;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                    <button type="submit" class="dropdown-item py-2 d-flex align-items-center gap-2 text-danger border-0 bg-transparent w-100" style="cursor:pointer;">
                                        <i class="bi bi-box-arrow-right fs-5"></i> Log Out
                                    </button>
                                </form>
                            </li>
                        </ul>
                    </div>

                </div><!-- /bell + avatar group -->
            </div>
        </nav>
        
        <!-- Main Content Area -->
        <main class="content-body p-4 flex-grow-1">
            <!-- Render Flash Messages -->
            <?php include_once $base_path . 'includes/flash_messages.php'; ?>
            
            <!-- Animated page body wrapper -->
            <div class="animated-fade-in">
<?php else: ?>
<!-- Fallback structural wrapper for unauthenticated/full-screen pages -->
<div class="app-container d-flex flex-column min-vh-100">
    <main class="flex-grow-1">
        <?php include_once $base_path . 'includes/flash_messages.php'; ?>
<?php endif; ?>
