<?php
/**
 * Public Landing Page
 * NCST Maritime Academy Portal Homepage.
 * If user is authenticated, redirects to their dashboard.
 * If guest, displays the public landing page.
 */

require_once 'includes/auth_check.php';

// Redirect authenticated users to their corresponding dashboards
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    $role = $_SESSION['role'];
    $dashboards = [
        'admin'     => 'admin/dashboard',
        'registrar' => 'registrar/dashboard',
        'cashier'   => 'cashier/dashboard',
        'teacher'   => 'teacher/dashboard',
        'student'   => 'student/dashboard',
        'enrollee'  => 'enrollee/dashboard'
    ];
    $target = isset($dashboards[$role]) ? $dashboards[$role] : 'auth/login';
    header("Location: " . resolveAppUrl($target));
    exit;
}

$no_sidebar = true;
$public_page = true;
$page_title = "Welcome";

require_once 'config/database.php';
require_once 'includes/academic_terms.php';

$activeTerm = getActiveAcademicTerm($pdo);
$enrollmentStatus = getEnrollmentPeriodStatus($activeTerm);
$isEnrollmentOpen = !empty($enrollmentStatus['is_open']);
$enrollmentBadgeText = $isEnrollmentOpen ? 'ENROLLMENT IS OPEN' : 'ENROLLMENT IS CLOSED';

require_once 'includes/header.php';
?>

<style>
    html {
        scroll-behavior: auto;
    }
    /* Public Landing Page Custom Styles */
    .public-navbar {
        position: sticky;
        top: 0;
        z-index: 1050;
        width: 100%;
        margin: 0 auto;
        padding: 1rem clamp(1rem, 3vw, 2.5rem);
        background: var(--brand-dark, #064b55) !important;
        border: 0;
        border-radius: 0;
        transition: width .35s ease, margin .35s ease, padding .35s ease, border-radius .35s ease, background-color .35s ease, box-shadow .35s ease;
    }
    .public-navbar.scrolled {
        width: min(900px, calc(100% - 2rem));
        margin-top: .75rem;
        padding: .55rem 1.15rem;
        border-radius: var(--radius-pill, 999px);
        background: #ffffff !important;
        backdrop-filter: blur(16px);
        -webkit-backdrop-filter: blur(16px);
        border: 1px solid rgba(11, 155, 152, 0.2);
        box-shadow: 0 12px 36px rgba(6, 75, 85, 0.2);
    }
    .public-nav-inner { display: flex; align-items: center; justify-content: space-between; gap: 1rem; max-width: 1320px; margin: auto; }
    .public-navbar .navbar-brand { color: #fff; font-family: Inter, ui-sans-serif, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; font-weight: 800; display: flex; align-items: center; gap: .6rem; text-decoration: none; }
    .public-brand-icon { color: #8bcac6; font-size: 1.45rem; }
    .public-brand-name { font-size: .98rem; letter-spacing: -.015em; }
    .public-navbar.scrolled .navbar-brand, .public-navbar.scrolled .public-brand-name { color: var(--brand-dark, #064b55) !important; }
    .public-navbar.scrolled .public-brand-icon { color: var(--brand-primary, #0b9b98); }
    .public-nav-menu { display: flex; align-items: center; gap: 1.25rem; }
    .public-navbar .nav-link { color: rgba(255,255,255,.92); font-weight: 600; font-size: .85rem; letter-spacing: .03em; text-decoration: none; transition: color .2s ease; }
    .public-navbar .nav-link:hover { color: #8bcac6; }
    .public-navbar.scrolled .nav-link { color: var(--brand-dark, #064b55); }
    .public-navbar.scrolled .nav-link:hover { color: var(--brand-primary, #0b9b98); }
    .public-nav-actions { display: flex; gap: .5rem; }
    .public-nav-button { border-radius: var(--radius-pill, 999px); padding: .5rem .9rem; font-size: .8rem; font-weight: 700; text-decoration: none; transition: background-color .2s ease, color .2s ease; }
    .public-nav-login { border: 1px solid rgba(255,255,255,.72); color: #fff; }
    .public-nav-apply { border: 1px solid #8bcac6; background: #8bcac6; color: var(--brand-dark, #064b55); }
    .public-nav-login:hover { background: var(--brand-dark, #064b55); border-color: var(--brand-dark, #064b55); color: #fff; }
    .public-nav-apply:hover { background: #fff; border-color: #fff; color: var(--brand-dark, #064b55); }
    .public-navbar.scrolled .public-nav-login { border-color: var(--brand-primary, #0b9b98); color: var(--brand-dark, #064b55); }
    .public-navbar.scrolled .public-nav-apply { background: var(--brand-primary, #0b9b98); border-color: var(--brand-primary, #0b9b98); color: #fff; }
    .public-nav-toggle { display: none; border: 0; background: transparent; color: #fff; font-size: 1.45rem; padding: .2rem; }
    .public-navbar.scrolled .public-nav-toggle { color: var(--brand-dark, #064b55); }
    @media (max-width: 767.98px) {
        .public-navbar {
            padding: .8rem 1rem;
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
        }
        .public-navbar.scrolled {
            width: calc(100% - 1.25rem);
            margin-top: .5rem;
            border-radius: var(--radius-md, 14px);
            background: #ffffff !important;
            border: 1px solid rgba(11, 155, 152, 0.24) !important;
            box-shadow: 0 10px 30px rgba(6, 75, 85, 0.28) !important;
        }
        .public-brand-name { font-size: .88rem; }
        .public-nav-toggle { display: block; }
        .public-nav-menu { display: none; width: 100%; flex-direction: column; align-items: stretch; gap: .7rem; padding: 1rem 0 .25rem; }
        .public-navbar.menu-open .public-nav-menu { display: flex; }
        .public-navbar .nav-link { padding: .45rem .2rem; }
        .public-nav-actions { padding-top: .35rem; border-top: 1px solid rgba(255,255,255,.24); }
        .public-navbar.scrolled .public-nav-actions { border-top-color: #8bcac6; }
        .public-nav-button { flex: 1; text-align: center; }
        .public-nav-inner { flex-wrap: wrap; }
    }

    .landing-wrapper {
        min-height: calc(100vh - 120px);
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 2.5rem 0;
        background-color: var(--surface-soft);
    }

    .card.timeline-card {
        min-height: 600px;
        background-color: rgba(0, 76, 76, 0.55) !important;
        background: rgba(0, 76, 76, 0.55) !important;
        backdrop-filter: blur(14px);
        -webkit-backdrop-filter: blur(14px);
        border-radius: var(--radius-lg, 24px) !important;
        position: relative;
        overflow: hidden;
        border: 1px solid rgba(255, 255, 255, 0.18);
    }

    .timeline-bg-img {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-size: cover;
        background-position: center;
        z-index: 0;
        transition: none;
    }

    .card.timeline-card:hover .timeline-bg-img {
        transform: none;
    }

    .timeline-overlay {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: linear-gradient(to bottom, rgba(0, 128, 128, 0.34) 0%, rgba(0, 76, 76, 0.9) 100%);
        z-index: 1;
    }

    .glass-timeline-card {
        background: rgba(146, 234, 234, 0.25);
        border: 1px solid rgba(255, 255, 255, 0.25);
        backdrop-filter: blur(4px);
        -webkit-backdrop-filter: blur(4px);
        box-shadow: 0 8px 22px rgba(0, 0, 0, 0.22);
    }

    .border-white-10 {
        border-color: rgba(255, 255, 255, 0.15) !important;
    }

    .status-dot-pulse {
        width: 10px;
        height: 10px;
        background-color: var(--color-success, #22c55e);
        border-radius: 50%;
        display: inline-block;
        box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.7);
        animation: pulse-green 2s infinite;
    }

    .status-dot-closed {
        width: 10px;
        height: 10px;
        background-color: var(--color-danger, #ef4444);
        border-radius: 50%;
        display: inline-block;
        box-shadow: 0 0 0 2px rgba(239, 68, 68, 0.25);
    }

    @keyframes pulse-green {
        0% {
            transform: scale(0.95);
            box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.7);
        }
        70% {
            transform: scale(1);
            box-shadow: 0 0 0 6px rgba(34, 197, 94, 0);
        }
        100% {
            transform: scale(0.95);
            box-shadow: 0 0 0 0 rgba(34, 197, 94, 0);
        }
    }

    .main-info-card {
        border-radius: var(--radius-lg, 24px) !important;
        background-color: #ffffff;
        box-shadow: 0 15px 35px rgba(11, 37, 56, 0.06) !important;
    }

    .tagline-dash {
        width: 25px;
        height: 3px;
        background-color: #008080;
        display: inline-block;
    }

    .teal-divider-line {
        width: 100%;
        height: 4px;
        background-color: #008080;
        border-radius: 2px;
    }

    .btn-navy-pill {
        background-color: var(--brand-primary);
        color: #ffffff;
        border-radius: 50px;
        font-size: 0.85rem;
        font-weight: 700;
        letter-spacing: 0.5px;
        padding: 0.85rem 1.8rem;
        transition: all 0.3s ease;
        border: 2px solid var(--brand-primary);
        box-shadow: 0 4px 12px rgba(11, 155, 152, 0.2);
    }

    .btn-navy-pill:hover {
        background-color: var(--brand-secondary-dark);
        border-color: var(--brand-secondary-dark);
        color: #ffffff;
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(11, 155, 152, 0.3);
    }

    .btn-teal-pill {
        background-color: #66b2b2;
        color: #ffffff;
        border-radius: 50px;
        font-size: 0.85rem;
        font-weight: 700;
        letter-spacing: 0.5px;
        padding: 0.85rem 1.8rem;
        transition: all 0.3s ease;
        border: 2px solid #66b2b2;
        box-shadow: 0 4px 12px rgba(0, 168, 181, 0.15);
    }

    .btn-teal-pill:hover {
        background-color: #006666;
        color: #ffffff;
        border-color: #006666;
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(0, 168, 181, 0.25);
    }

    .program-img-card {
        position: relative;
        cursor: pointer;
        display: block;
        height: 140px;
        border-radius: 1rem;
        overflow: hidden;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    }

    .program-card-img {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-size: cover;
        background-position: center;
        transition: transform 0.4s ease;
    }

    .program-img-card:hover .program-card-img {
        transform: scale(1.08);
    }

    .program-card-overlay {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: linear-gradient(to bottom, rgba(0,0,0,0.1) 30%, rgba(0,0,0,0.75) 100%);
        z-index: 1;
        transition: background 0.3s ease;
    }

    .program-img-card:hover .program-card-overlay {
        background: linear-gradient(to bottom, rgba(0,0,0,0.1) 15%, rgba(0,0,0,0.85) 100%);
    }

    /* Modal customizations */
    .modal-content-custom {
        border-radius: 1.5rem;
        border: none;
        overflow: hidden;
        box-shadow: 0 20px 50px rgba(11, 37, 56, 0.25);
    }
    
    .modal-header-custom {
        background-color: #004c4c;
        color: #ffffff;
        border-bottom: 2px solid #008080;
        padding: 1.25rem 1.5rem;
    }
    
    .modal-body-custom {
        padding: 2rem 1.5rem;
        background-color: #ffffff;
    }

    /* Large screens alignment/sizing */
    @media (min-width: 992px) {
        .landing-wrapper {
            padding: 2rem 0;
        }
        .card.timeline-card, .main-info-card {
            min-height: 600px;
        }
    }

    /* Scroll Reveal Animations */
    .reveal-left {
        opacity: 0;
        transform: translateX(-50px);
        transition: opacity 0.8s ease-out, transform 0.8s ease-out;
    }
    
    .reveal-right {
        opacity: 0;
        transform: translateX(50px);
        transition: opacity 0.8s ease-out, transform 0.8s ease-out;
    }
    
    .reveal-active {
        opacity: 1 !important;
        transform: translateX(0) !important;
    }

    /* Modern Footer Styles */
    .modern-footer {
        background-color: #071723;
        color: rgba(255, 255, 255, 0.7);
        border-top: 3px solid #008080;
    }
    .modern-footer h5 {
        color: #ffffff !important;
        font-family: Inter, ui-sans-serif, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        font-weight: 700;
    }
    .modern-footer a {
        color: rgba(255, 255, 255, 0.7);
        text-decoration: none;
        transition: all 0.2s ease;
        display: inline-block;
    }
    .modern-footer a:hover {
        color: #66b2b2;
        transform: translateX(3px);
    }
    .modern-footer .social-icon {
        width: 38px;
        height: 38px;
        background-color: rgba(255, 255, 255, 0.05);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.3s ease;
        color: rgba(255, 255, 255, 0.7);
    }
    .modern-footer .social-icon:hover {
        background-color: #008080;
        color: #ffffff;
        transform: translateY(-3px) rotate(360deg);
    }
    .modern-footer-bottom {
        background-color: #05101a;
        border-top: 1px solid rgba(255, 255, 255, 0.06);
    }
    
    /* Hide default template footer on landing page */
    footer.bg-white.border-top {
        display: none !important;
    }

    /* Scroll-expansion hero: animation values are supplied by vanilla JS. */
    .expand-hero {
        --hero-progress: 0;
        background: var(--surface-soft);
        height: 175vh;
        position: relative;
    }
    .expand-hero-sticky {
        height: 100vh;
        min-height: 560px;
        position: sticky;
        top: 0;
        overflow: hidden;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .expand-hero-media {
        width: calc(76% + (24% * var(--hero-progress)));
        height: calc(62vh + (38vh * var(--hero-progress)));
        position: relative;
        overflow: hidden;
        border-radius: calc(26px * (1 - var(--hero-progress)));
        background: #004c4c;
        box-shadow: 0 20px 45px rgba(0, 76, 76, calc(.2 * (1 - var(--hero-progress))));
    }
    .expand-hero-media img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }
    .expand-hero-scrim {
        position: absolute;
        inset: 0;
        background: rgba(0, 76, 76, calc(.18 + (.56 * var(--hero-progress))));
    }
    .expand-hero-copy {
        position: absolute;
        inset: 0;
        z-index: 1;
        display: grid;
        place-content: center;
        color: #fff;
        text-align: center;
        padding: 1.5rem;
        opacity: var(--hero-progress);
        transform: translateY(calc(18px * (1 - var(--hero-progress))));
    }
    .expand-hero-copy h1,
    .expand-hero-copy h2 {
        color: #ffffff !important;
        font-size: clamp(2.2rem, 6vw, 5rem);
        font-weight: 800;
        letter-spacing: -.04em;
        margin: 0 0 .55rem;
        text-shadow: none !important;
        -webkit-text-stroke: 0 !important;
        filter: none !important;
    }
    .expand-hero-copy p {
        color: #ffffff !important;
        font-size: clamp(1rem, 2vw, 1.35rem);
        margin: 0;
        text-shadow: none !important;
        -webkit-text-stroke: 0 !important;
        filter: none !important;
    }
    .expand-hero-after {
        position: absolute;
        z-index: 2;
        bottom: 2rem;
        left: 50%;
        width: min(92%, 700px);
        transform: translate(-50%, calc(18px * (1 - var(--hero-progress))));
        opacity: var(--hero-progress);
        text-align: center;
        color: #ffffff !important;
        background: transparent !important;
        backdrop-filter: none !important;
        -webkit-backdrop-filter: none !important;
        border: none !important;
        border-radius: 0 !important;
        padding: 1.25rem 1.75rem;
        box-shadow: none !important;
    }
    .expand-hero-after p {
        margin: 0 auto 1rem;
        max-width: 620px;
        color: #ffffff !important;
        font-size: 1rem;
        line-height: 1.55;
        text-shadow: none !important;
    }
    .expand-hero-login { background: var(--brand-primary); border: 2px solid var(--brand-primary); color: #fff; font-weight: 700; }
    .expand-hero-register { background: transparent; border: 2px solid #ffffff; color: #ffffff; font-weight: 700; }
    .expand-hero-login:hover { background: var(--brand-secondary-dark); border-color: var(--brand-secondary-dark); color: #fff; }
    .expand-hero-register:hover { background: #ffffff; border-color: #ffffff; color: var(--brand-dark, #064b55); }
    @media (max-width: 767.98px) {
        .expand-hero { height: 135vh; }
        .expand-hero-sticky { min-height: 500px; }
        .expand-hero-media { width: calc(88% + (12% * var(--hero-progress))); height: calc(52vh + (48vh * var(--hero-progress))); }
        .expand-hero-copy {
            padding: 1.25rem 1rem;
        }
        .expand-hero-after {
            bottom: 1.25rem;
            width: calc(100% - 2rem);
            padding: 1rem 1.25rem;
        }
    }
    @media (prefers-reduced-motion: reduce) {
        .expand-hero { height: auto; }
        .expand-hero-sticky { height: 100vh; position: relative; }
        .expand-hero-media { width: 100%; height: 100%; border-radius: 0; }
        .expand-hero-copy, .expand-hero-after { opacity: 1; transform: translate(-50%, 0); }
        .expand-hero-copy { transform: none; }
    }

    /* Landing-page typography: aligned with the Registrar Dashboard */
    .public-navbar,
    .landing-wrapper,
    .modern-footer,
    .public-navbar .nav-link,
    .public-nav-button,
    .landing-wrapper h1,
    .landing-wrapper h2,
    .landing-wrapper h3,
    .landing-wrapper h4,
    .landing-wrapper h5,
    .landing-wrapper h6,
    .landing-wrapper p,
    .landing-wrapper span,
    .landing-wrapper a,
    .modern-footer h5,
    .modern-footer a {
        font-family: Inter, ui-sans-serif, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    }
    .landing-wrapper h1,
    .landing-wrapper h2,
    .landing-wrapper h3,
    .landing-wrapper h4,
    .landing-wrapper h5,
    .landing-wrapper h6 {
        letter-spacing: -0.025em;
    }
    .landing-wrapper p { letter-spacing: 0; line-height: 1.65; }
    .public-brand-name { letter-spacing: -0.02em; }
    .public-navbar .nav-link,
    .public-nav-button { letter-spacing: 0.01em; }

    /* Explicit navbar contrast states */
    .public-navbar:not(.scrolled) .navbar-brand,
    .public-navbar:not(.scrolled) .public-brand-name,
    .public-navbar:not(.scrolled) .nav-link,
    .public-navbar:not(.scrolled) .public-nav-toggle { color:#ffffff!important; }
    .public-navbar:not(.scrolled) .public-brand-icon { color:#8bcac6!important; }
    .public-navbar:not(.scrolled) .nav-link:hover { color:#c4e9e6!important; }
    .public-navbar:not(.scrolled) .public-nav-login { color:#ffffff!important; }
    .public-navbar:not(.scrolled) .public-nav-apply { color:#123f43!important; }
    .public-navbar.scrolled .navbar-brand,
    .public-navbar.scrolled .public-brand-name,
    .public-navbar.scrolled .nav-link,
    .public-navbar.scrolled .public-nav-toggle { color: var(--brand-dark, #064b55) !important; }
    .public-navbar.scrolled .public-brand-icon { color: var(--brand-primary, #0b9b98) !important; }
    .public-navbar.scrolled .nav-link:hover { color: var(--brand-primary, #0b9b98) !important; }
    .public-navbar.scrolled .public-nav-login { color: var(--brand-dark, #064b55) !important; }
</style>

<!-- Public Navigation Header -->
<header class="public-navbar" id="publicNavbar">
    <div class="public-nav-inner">
        <a href="index" class="navbar-brand" aria-label="NCST Maritime Academy home">
            <i class="bi bi-anchor public-brand-icon" aria-hidden="true"></i>
            <span class="public-brand-name">NCST Maritime Academy</span>
        </a>
        <button class="public-nav-toggle" id="publicNavToggle" type="button" aria-expanded="false" aria-controls="publicNavMenu" aria-label="Toggle navigation menu">
            <i class="bi bi-list" aria-hidden="true"></i>
        </button>
        <nav class="public-nav-menu" id="publicNavMenu" aria-label="Public navigation">
            <a href="#about" class="nav-link">About</a>
            <a href="#programs-section" class="nav-link">Programs</a>
            <div class="public-nav-actions">
                <a href="auth/login" class="public-nav-button public-nav-login">Login</a>
                <a href="auth/login?mode=register" class="public-nav-button public-nav-apply">Apply</a>
            </div>
        </nav>
    </div>
</header>

<!-- Main Landing Container Split Layout -->
<section class="landing-wrapper">
    <div class="container px-3 px-md-5" style="max-width: 1350px;">
        <div class="row g-4 align-items-stretch justify-content-center">
            
            <!-- Left Column: Timeline Card -->
            <div class="col-12 col-lg-5 col-xl-4 d-flex">
                <div class="card timeline-card w-100 border-0 shadow-lg text-white d-flex flex-column">
                    <!-- Background Image and Overlay -->
                    <div class="timeline-bg-img" style="background-image: url('assets/img/timeline-bg.jpg');"></div>
                    <div class="timeline-overlay"></div>
                    
                    <!-- Content (relative z-index to stay above overlay) -->
                    <div class="card-body p-4 d-flex flex-column justify-content-between h-100 position-relative z-3">
                        <div>
                            <h3 class="fw-extrabold mb-1" style="color: rgb(247, 247, 247); font-size: 2.1rem; font-weight: 800; font-family: Inter, ui-sans-serif, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;">READY TO SET</h3>
                            <h2 class="fw-extrabold text-white mb-2" style="font-size: 2.1rem; font-weight: 800; line-height: 1.1; font-family: Inter, ui-sans-serif, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;">SAIL?</h2>
                            <p class="text-white-50 mb-3 fw-normal" style="font-size: 0.95rem; line-height: 1.4;">
                                A quick overview of what NCST Maritime Academy offers and how enrollment works.
                            </p>
                        </div>
                        
                        <!-- Semi-transparent overlay statistics card -->
                        <div class="glass-timeline-card p-4 rounded-4 mt-auto">
                            <!-- Enrollment Status (UI-011) -->
                            <div class="d-flex align-items-center gap-2 mb-4">
                                <span class="<?php echo $isEnrollmentOpen ? 'status-dot-pulse' : 'status-dot-closed'; ?>" aria-hidden="true"></span>
                                <span class="fw-bold text-white text-uppercase" style="font-size: 0.85rem; letter-spacing: 0.5px;"><?php echo htmlspecialchars($enrollmentBadgeText); ?></span>
                            </div>
                            
                            <!-- Grid details -->
                            <div class="row g-3 mb-4 border-bottom border-white-10 pb-4">
                                <div class="col-6">
                                    <span class="text-white-50 text-uppercase fw-bold d-block mb-1" style="font-size: 0.65rem; letter-spacing: 0.5px;">Campus</span>
                                    <p class="m-0 fw-semibold text-white small" style="line-height: 1.3;">National College of Science and Technology</p>
                                </div>
                                <div class="col-6">
                                    <span class="text-white-50 text-uppercase fw-bold d-block mb-1" style="font-size: 0.65rem; letter-spacing: 0.5px;">Admission Type</span>
                                    <p class="m-0 fw-semibold text-white small" style="line-height: 1.3;">Walk-in and Online Review</p>
                                </div>
                                <div class="col-6 mt-3">
                                    <span class="text-white-50 text-uppercase fw-bold d-block mb-1" style="font-size: 0.65rem; letter-spacing: 0.5px;">Programs</span>
                                    <p class="m-0 fw-semibold text-white small" style="line-height: 1.3;">Marine Transportation<br>Marine Engineering</p>
                                </div>
                                <div class="col-6 mt-3">
                                    <span class="text-white-50 text-uppercase fw-bold d-block mb-1" style="font-size: 0.65rem; letter-spacing: 0.5px;">Support</span>
                                    <p class="m-0 fw-semibold text-white small" style="line-height: 1.3;">Admin Review, Payment, and Enrollment</p>
                                </div>
                            </div>
                            
                            <!-- Stats counters -->
                            <div class="row g-3">
                                <div class="col-6">
                                    <h3 class="m-0 fw-extrabold text-white fs-4">2</h3>
                                    <span class="text-white-50 small" style="font-size: 0.72rem;">Marine Programs</span>
                                </div>
                                <div class="col-6">
                                    <h3 class="m-0 fw-extrabold text-white fs-4">IMO</h3>
                                    <span class="text-white-50 small" style="font-size: 0.72rem;">Verified</span>
                                </div>
                                <div class="col-6 mt-2">
                                    <h3 class="m-0 fw-extrabold text-white fs-4">3 Days</h3>
                                    <span class="text-white-50 small" style="font-size: 0.72rem;">Payment Deadline</span>
                                </div>
                                <div class="col-6 mt-2">
                                    <h3 class="m-0 fw-extrabold text-white fs-4">30 Years</h3>
                                    <span class="text-white-50 small" style="font-size: 0.72rem;">Established 30 Years</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Right Column: Main Info Card -->
            <div class="col-12 col-lg-7 col-xl-8 d-flex">
                <div class="card main-info-card w-100 border-0 shadow-lg d-flex flex-column">
                    <div class="card-body p-4 d-flex flex-column justify-content-between h-100">
                        <!-- Tagline -->
                        <div class="d-flex align-items-center gap-2">
                            <span class="tagline-dash"></span>
                            <span class="text-uppercase fw-bold" style="color: var(--brand-primary, #0b9b98); font-size: 0.8rem; letter-spacing: 1px;">LEARN AND GROW, BECOME ONE OF US!</span>
                        </div>
                        
                        <!-- Large Headline -->
                        <h1 class="fw-extrabold text-navy m-0" style="font-size: 2.3rem; font-weight: 800; line-height: 1.15; color: var(--brand-dark, #064b55); font-family: Inter, ui-sans-serif, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;">
                            Navigate Your Future and Command Your Career with NCST!
                        </h1>
                        
                        <!-- Solid line separator -->
                        <div class="teal-divider-line m-0"></div>
                        
                        <!-- Description copy -->
                        <p class="text-muted m-0" style="font-size: 0.98rem; line-height: 1.5; color: var(--text-muted-light, #64748b);">
                            NCST Maritime Academy is a premier institution offering high-caliber training and academic pathways in marine engineering and marine transportation. Start your maritime journey today.
                        </p>
                        
                        <!-- Three programmatic container cards at the bottom -->
                        <div id="programs" class="row g-3 justify-content-center">
                            <!-- BSMT card -->
                            <div class="col-12 col-md-4">
                                <a href="#" class="program-img-card text-decoration-none" data-bs-toggle="modal" data-bs-target="#bsmtModal">
                                    <div class="program-card-img" style="background-image: url('assets/img/marine-transportation.jpg');"></div>
                                    <div class="program-card-overlay"></div>
                                    <div class="program-card-content position-absolute bottom-0 start-0 w-100 p-3 z-3 text-center">
                                        <h6 class="m-0 fw-bold text-white text-uppercase" style="font-size: 0.8rem; letter-spacing: 0.5px; color: #ffffff !important;">BS Marine Transportation (BSMT)</h6>
                                    </div>
                                </a>
                            </div>
                            
                            <!-- BSMarE card -->
                            <div class="col-12 col-md-4">
                                <a href="#" class="program-img-card text-decoration-none" data-bs-toggle="modal" data-bs-target="#bsmareModal">
                                    <div class="program-card-img" style="background-image: url('assets/img/marine-engineering.jpg');"></div>
                                    <div class="program-card-overlay"></div>
                                    <div class="program-card-content position-absolute bottom-0 start-0 w-100 p-3 z-3 text-center">
                                        <h6 class="m-0 fw-bold text-white text-uppercase" style="font-size: 0.8rem; letter-spacing: 0.5px; color: #ffffff !important;">BS Marine Engineering (BSMarE)</h6>
                                    </div>
                                </a>
                            </div>
                            
                            <!-- About Academy card -->
                            <div class="col-12 col-md-4">
                                <a href="#" class="program-img-card text-decoration-none" data-bs-toggle="modal" data-bs-target="#aboutModal">
                                    <div class="program-card-img" style="background-image: url('assets/img/maritime-academy.jpg');"></div>
                                    <div class="program-card-overlay"></div>
                                    <div class="program-card-content position-absolute bottom-0 start-0 w-100 p-3 z-3 text-center">
                                        <h6 class="m-0 fw-bold text-white text-uppercase" style="font-size: 0.75rem; letter-spacing: 0.5px; color: #ffffff !important;">About NCST Maritime Academy</h6>
                                    </div>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</section>

<!-- Scroll-expansion public hero -->
<section class="expand-hero" id="top-hero" aria-labelledby="hero-title">
    <div class="expand-hero-sticky">
        <div class="expand-hero-media">
            <img src="assets/img/Maritime_Day_Container_ship_Commercial_Port_Thailand_Bing_4K_3840x2160.jpg" alt="NCST Maritime Academy maritime training">
            <div class="expand-hero-scrim"></div>
            <div class="expand-hero-copy">
                <h2 id="hero-title">NCST Maritime Academy</h2>
                <p>Charting your course to a maritime career</p>
            </div>
        </div>
        <div class="expand-hero-after">
            <p>NCST Maritime Academy prepares future maritime professionals through focused academic training and practical industry readiness.</p>
            <div class="d-flex flex-wrap justify-content-center gap-2">
                <a href="auth/login" class="btn expand-hero-login px-4 py-2 fw-semibold">Portal Login</a>
                <a href="auth/login?mode=register" class="btn expand-hero-register px-4 py-2 fw-semibold">Apply / Register</a>
            </div>
        </div>
    </div>
</section>

<!-- About Section -->
<section id="about" class="about-section py-5" style="background-color: var(--surface-soft); border-top: 1px solid var(--gray-200);">
    <div class="container py-4">
        <div class="row g-5 align-items-center">
            <div class="col-12 col-md-6 col-lg-7 reveal-left">
                <!-- Tagline -->
                <div class="d-flex align-items-center gap-2 mb-3">
                    <span class="tagline-dash"></span>
                    <span class="text-uppercase fw-bold" style="color: var(--brand-primary, #0b9b98); font-size: 0.85rem; letter-spacing: 1px;">ABOUT OUR ACADEMY</span>
                </div>
                
                <!-- Headline -->
                <h2 class="fw-extrabold text-navy mb-4" style="font-size: 2.2rem; font-weight: 800; color: var(--brand-dark, #064b55); font-family: Inter, ui-sans-serif, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;">
                    Pioneering Maritime Education
                </h2>
                
                <!-- Description -->
                <p class="text-muted mb-3" style="font-size: 1rem; line-height: 1.6; color: var(--text-muted-light, #64748b);">
                    NCST Maritime Academy is dedicated to molding world-class maritime professionals. Established with advanced laboratory standards, simulated bridging environments, and veteran marine instructors, our academy delivers rigorous curricula aligned with local and international STCW standards.
                </p>
                <p class="text-muted mb-4" style="font-size: 1rem; line-height: 1.6; color: var(--text-muted-light, #64748b);">
                    Our mission is to produce disciplined, technically skilled, and safety-conscious officers ready to conquer global maritime sectors. We emphasize hands-on navigation, automated engine management, and absolute maritime integrity.
                </p>
                
                <!-- Metrics -->
                <div class="d-flex align-items-center gap-4 py-3 border-top border-bottom border-light">
                    <div>
                        <h4 class="fw-bold m-0" style="color: var(--brand-primary, #0b9b98); font-weight: 800;">100%</h4>
                        <span class="text-muted small fw-semibold">STCW Compliant</span>
                    </div>
                    <div class="border-start ps-4">
                        <h4 class="fw-bold m-0" style="color: var(--brand-primary, #0b9b98); font-weight: 800;">Modern</h4>
                        <span class="text-muted small fw-semibold">Simulation Labs</span>
                    </div>
                    <div class="border-start ps-4">
                        <h4 class="fw-bold m-0" style="color: var(--brand-primary, #0b9b98); font-weight: 800;">Experienced</h4>
                        <span class="text-muted small fw-semibold">Marine Officers</span>
                    </div>
                </div>
            </div>
            
            <div class="col-12 col-md-6 col-lg-5 reveal-right">
                <!-- Admissions Contact card -->
                <div class="card border-0 shadow-sm p-4 rounded-4" style="background-color: var(--surface-soft); border: 1px solid var(--gray-200) !important;">
                    <h5 class="fw-extrabold text-navy mb-4" style="font-family: Inter, ui-sans-serif, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: var(--brand-dark, #064b55);">Admissions & Support</h5>
                    
                    <div class="d-flex gap-3 align-items-start mb-4">
                        <div class="fs-4 text-brand-primary mt-1"><i class="bi bi-telephone-fill" style="color: var(--brand-primary, #0b9b98);"></i></div>
                        <div>
                            <span class="text-muted d-block small uppercase fw-bold" style="font-size: 0.65rem; letter-spacing: 0.5px;">Admissions Hotline</span>
                            <span class="fw-semibold text-dark" style="font-size: 0.95rem;">(046) 416-4779</span>
                        </div>
                    </div>
                    
                    <div class="d-flex gap-3 align-items-start mb-4">
                        <div class="fs-4 text-brand-primary mt-1"><i class="bi bi-envelope-fill" style="color: #008080;"></i></div>
                        <div>
                            <span class="text-muted d-block small uppercase fw-bold" style="font-size: 0.65rem; letter-spacing: 0.5px;">Email Support</span>
                            <span class="fw-semibold text-dark" style="font-size: 0.95rem;">ncstmaritimeacademy@gmail.com</span>
                        </div>
                    </div>
                    
                    <div class="d-flex gap-3 align-items-start mb-4">
                        <div class="fs-4 text-brand-primary mt-1"><i class="bi bi-geo-alt-fill" style="color: #008080;"></i></div>
                        <div>
                            <span class="text-muted d-block small uppercase fw-bold" style="font-size: 0.65rem; letter-spacing: 0.5px;">Campus Address</span>
                            <span class="fw-semibold text-dark" style="font-size: 0.95rem;">Imus, Cavite, Philippines</span>
                        </div>
                    </div>
                    
                    <div class="mt-2">
                        <a href="auth/login?mode=register" class="btn btn-navy-pill w-100 text-center text-uppercase py-2.5">
                            Apply for Admission
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Programs Section -->
<section id="programs-section" class="programs-section py-5" style="background-color: var(--surface-soft); border-top: 1px solid var(--gray-200);">
    <div class="container py-4 text-center">
        <!-- Tagline -->
        <div class="d-flex justify-content-center align-items-center gap-2 mb-3">
            <span class="tagline-dash"></span>
            <span class="text-uppercase fw-bold" style="color: #008080; font-size: 0.85rem; letter-spacing: 1px;">ACADEMIC PATHWAYS</span>
        </div>
        
        <!-- Headline -->
        <h2 class="fw-extrabold text-navy mb-5" style="font-size: 2.2rem; font-weight: 800; color: #004c4c; font-family: Inter, ui-sans-serif, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;">
            Maritime Programs Offered
        </h2>
        
        <div class="row g-4 justify-content-center text-start">
            <!-- Program 1: BSMT -->
            <div class="col-12 col-md-6 col-lg-5 reveal-left">
                <div class="card h-100 border-0 shadow-sm overflow-hidden" style="border-radius: 1.5rem; background-color: #ffffff;">
                    <!-- Card Image Header -->
                    <div style="height: 180px; background-image: url('assets/img/marine-transportation.jpg'); background-size: cover; background-position: center;"></div>
                    <div class="card-body p-4 d-flex flex-column justify-content-between">
                        <div>
                            <div class="fs-2 mb-3" style="color: #008080;"><i class="bi bi-compass"></i></div>
                            <h4 class="fw-bold text-navy" style="font-family: Inter, ui-sans-serif, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;">BS Marine Transportation (BSMT)</h4>
                            <span class="badge bg-secondary-subtle text-secondary mb-3 text-uppercase fw-bold px-2 py-1" style="font-size: 0.65rem;">BSMT Program</span>
                            <p class="text-muted mb-4 small" style="line-height: 1.5;">
                                A four-year standard baccalaureate program designed to equip cadets with competency in navigation, cargo handling, ship stowage, and watchkeeping under international STCW regulations. Preparing future deck officers.
                            </p>
                        </div>
                        <div>
                            <a href="auth/login?mode=register" class="btn btn-navy-pill w-100 text-center py-2">Apply for BSMT</a>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Program 2: BSMarE -->
            <div class="col-12 col-md-6 col-lg-5 reveal-right">
                <div class="card h-100 border-0 shadow-sm overflow-hidden" style="border-radius: 1.5rem; background-color: #ffffff;">
                    <!-- Card Image Header -->
                    <div style="height: 180px; background-image: url('assets/img/marine-engineering.jpg'); background-size: cover; background-position: center;"></div>
                    <div class="card-body p-4 d-flex flex-column justify-content-between">
                        <div>
                            <div class="fs-2 mb-3" style="color: #008080;"><i class="bi bi-gear-wide-connected"></i></div>
                            <h4 class="fw-bold text-navy" style="font-family: Inter, ui-sans-serif, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;">BS Marine Engineering (BSMarE)</h4>
                            <span class="badge bg-secondary-subtle text-secondary mb-3 text-uppercase fw-bold px-2 py-1" style="font-size: 0.65rem;">BSMarE Program</span>
                            <p class="text-muted mb-4 small" style="line-height: 1.5;">
                                A professional engineering discipline focused on marine propulsion, auxiliary machinery operations, electrical control systems, and maintenance protocols inside modern shipboard engine rooms. Preparing engine officers.
                            </p>
                        </div>
                        <div>
                            <a href="auth/login?mode=register" class="btn btn-navy-pill w-100 text-center py-2">Apply for BSMarE</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- About Academy Modal -->
<div class="modal fade" id="aboutModal" tabindex="-1" aria-labelledby="aboutModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content modal-content-custom">
            <div class="modal-header modal-header-custom d-flex justify-content-between align-items-center">
                <h5 class="modal-title fw-bold" style="color: white;" id="aboutModalLabel">
                    <i class="bi bi-info-circle-fill me-2"></i>About NCST Maritime Academy
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body modal-body-custom">
                <div class="row g-4">
                    <div class="col-12 col-md-7">
                        <h4 class="fw-bold text-navy mb-3">Pioneering Maritime Education</h4>
                        <p class="text-muted mb-3" style="line-height: 1.6;">
                            NCST Maritime Academy is dedicated to molding world-class maritime professionals. Established with advanced laboratory standards, simulated bridging environments, and veteran marine instructors, our academy delivers rigorous curricula aligned with local and international STCW standards.
                        </p>
                        <p class="text-muted mb-4" style="line-height: 1.6;">
                            Our mission is to produce disciplined, technically skilled, and safety-conscious officers ready to conquer global maritime sectors. We emphasize hands-on navigation, automated engine management, and absolute maritime integrity.
                        </p>
                        <div class="row g-3 py-3 border-top border-bottom border-light">
                            <div class="col-6">
                                <h5 class="fw-bold text-brand-primary m-0">100%</h5>
                                <span class="text-muted small">STCW Compliant</span>
                            </div>
                            <div class="col-6 border-start ps-3">
                                <h5 class="fw-bold text-brand-primary m-0">Modern</h5>
                                <span class="text-muted small">Simulation Labs</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-5">
                        <div class="p-4 rounded-4 bg-light shadow-sm h-100 d-flex flex-column justify-content-between">
                            <div>
                                <h5 class="fw-bold text-navy mb-3">Admissions Contact</h5>
                                <div class="mb-3">
                                    <span class="text-muted d-block small uppercase fw-bold">Telephone</span>
                                    <span class="fw-semibold text-dark"><i class="bi bi-telephone-fill me-2 text-brand-primary"></i>(046) 416-4779</span>
                                </div>
                                <div class="mb-3">
                                    <span class="text-muted d-block small uppercase fw-bold">Email</span>
                                    <span class="fw-semibold text-dark"><i class="bi bi-envelope-fill me-2 text-brand-primary"></i>ncstmaritimeacademy@gmail.com</span>
                                </div>
                                <div class="mb-3">
                                    <span class="text-muted d-block small uppercase fw-bold">Campus Address</span>
                                    <span class="fw-semibold text-dark"><i class="bi bi-geo-alt-fill me-2 text-brand-primary"></i>Imus, Cavite, Philippines</span>
                                </div>
                            </div>
                            <div class="mt-4">
                                <a href="auth/login?mode=register" class="btn btn-navy-pill w-100 text-center py-2.5">Apply Now</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- BSMT Program Modal -->
<div class="modal fade" id="bsmtModal" tabindex="-1" aria-labelledby="bsmtModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content modal-content-custom">
            <div class="modal-header modal-header-custom d-flex justify-content-between align-items-center">
                <h5 class="modal-title fw-bold" style="color: white;" id="bsmtModalLabel">
                    <i class="bi bi-compass-fill me-2"></i>BS Marine Transportation (BSMT)
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body modal-body-custom">
                <span class="badge bg-info-subtle text-info mb-3 text-uppercase fw-bold px-3 py-2" style="font-size: 0.75rem;">4-Year Degree Program</span>
                <p class="text-muted mb-4" style="line-height: 1.6;">
                    A four-year standard baccalaureate program designed to equip cadets with competency in navigation, cargo handling, ship stowage, and watchkeeping under international STCW regulations. Preparing future deck officers.
                </p>
                <h6 class="fw-bold text-navy mb-2">Key Focus Areas:</h6>
                <ul class="text-muted mb-4 ps-3" style="line-height: 1.6;">
                    <li>Celestial and Terrestrial Navigation</li>
                    <li>Meteorology and Oceanography</li>
                    <li>Cargo Operations & Stowage</li>
                    <li>Maritime Law & Ship Safety Administration</li>
                </ul>
                <div class="d-flex gap-2">
                    <a href="auth/login?mode=register" class="btn btn-navy-pill flex-grow-1 text-center py-2.5">Register & Apply</a>
                    <button type="button" class="btn btn-outline-secondary px-4 rounded-pill" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- BSMarE Program Modal -->
<div class="modal fade" id="bsmareModal" tabindex="-1" aria-labelledby="bsmareModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content modal-content-custom">
            <div class="modal-header modal-header-custom d-flex justify-content-between align-items-center">
                <h5 class="modal-title fw-bold" style="color: white;" id="bsmareModalLabel">
                    <i class="bi bi-gear-wide-connected me-2"></i>BS Marine Engineering (BSMarE)
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body modal-body-custom">
                <span class="badge bg-info-subtle text-info mb-3 text-uppercase fw-bold px-3 py-2" style="font-size: 0.75rem;">4-Year Degree Program</span>
                <p class="text-muted mb-4" style="line-height: 1.6;">
                    A professional engineering discipline focused on marine propulsion, auxiliary machinery operations, electrical control systems, and maintenance protocols inside modern shipboard engine rooms. Preparing engine officers.
                </p>
                <h6 class="fw-bold text-navy mb-2">Key Focus Areas:</h6>
                <ul class="text-muted mb-4 ps-3" style="line-height: 1.6;">
                    <li>Marine Propulsion and Steam Plants</li>
                    <li>Auxiliary Machinery & Pumps</li>
                    <li>Shipboard Electrical Systems & Automation</li>
                    <li>Engine Room Watchkeeping and Simulator Drills</li>
                </ul>
                <div class="d-flex gap-2">
                    <a href="auth/login?mode=register" class="btn btn-navy-pill flex-grow-1 text-center py-2.5">Register & Apply</a>
                    <button type="button" class="btn btn-outline-secondary px-4 rounded-pill" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modern Footer Section -->
<footer class="modern-footer pt-5 pb-0">
    <div class="container pb-4">
        <div class="row g-4 justify-content-between">
            <!-- Brand Info Column -->
            <div class="col-12 col-md-5 col-lg-4">
                <a href="index" class="d-flex align-items-center gap-2 mb-3 text-white text-decoration-none">
                    <i class="bi bi-anchor text-white fs-3"></i>
                    <div class="d-flex flex-column text-start">
                        <span class="fw-bold lh-1 fs-5">NCST MARITIME ACADEMY</span>
                        <span class="small fw-semibold mt-1 lh-1" style="font-size: 0.7rem; letter-spacing: 0.5px; color: #66b2b2 !important;">ENROLLMENT SYSTEM</span>
                    </div>
                </a>
                <p class="text-white-50 small mb-4" style="line-height: 1.6;">
                    Dedicated deck and engine officer training since establishment. Shaping global seafaring leaders through audited STCW standards.
                </p>
                <div class="d-flex gap-2">
                    <a href="#" class="social-icon" aria-label="Facebook"><i class="bi bi-facebook"></i></a>
                    <a href="#" class="social-icon" aria-label="Twitter"><i class="bi bi-twitter"></i></a>
                    <a href="#" class="social-icon" aria-label="LinkedIn"><i class="bi bi-linkedin"></i></a>
                    <a href="#" class="social-icon" aria-label="Instagram"><i class="bi bi-instagram"></i></a>
                </div>
            </div>
            
            <!-- Quick Links Column -->
            <div class="col-12 col-md-3 col-lg-3">
                <h5 class="mb-3 text-uppercase fw-bold text-white" style="font-size: 0.85rem; letter-spacing: 1px;">Quick Links</h5>
                <ul class="list-unstyled d-flex flex-column gap-2 small">
                    <li><a href="#about"><i class="bi bi-chevron-right me-1 small"></i> About Our Academy</a></li>
                    <li><a href="#programs-section"><i class="bi bi-chevron-right me-1 small"></i> Maritime Programs</a></li>
                    <li><a href="auth/login?mode=register"><i class="bi bi-chevron-right me-1 small"></i> Apply for Admission</a></li>
                    <li><a href="auth/login"><i class="bi bi-chevron-right me-1 small"></i> Portal Login</a></li>
                </ul>
            </div>
            
            <!-- Admissions Contact Column -->
            <div class="col-12 col-md-4 col-lg-3">
                <h5 class="mb-3 text-uppercase fw-bold text-white" style="font-size: 0.85rem; letter-spacing: 1px;">Get in Touch</h5>
                <ul class="list-unstyled d-flex flex-column gap-3 small text-white-50">
                    <li class="d-flex gap-2 align-items-start">
                        <i class="bi bi-telephone-fill text-info"></i>
                        <span>(046) 416-4779</span>
                    </li>
                    <li class="d-flex gap-2 align-items-start">
                        <i class="bi bi-envelope-fill text-info"></i>
                        <a href="mailto:ncstmaritimeacdemy@gmail.com" class="p-0 hover:text-white" style="transform: none !important;">ncstmaritimeacademy@gmail.com</a>
                    </li>
                    <li class="d-flex gap-2 align-items-start">
                        <i class="bi bi-geo-alt-fill text-info"></i>
                        <span>Imus, Cavite, Philippines</span>
                    </li>
                </ul>
            </div>
        </div>
    </div>
    
    <!-- Footer Bottom Copyright Bar -->
    <div class="modern-footer-bottom py-3 text-center">
        <div class="container text-white-50 small">
            <span>&copy; <?php echo date('Y'); ?> NCST Maritime Academy. All rights reserved.</span>
        </div>
    </div>
</footer>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const publicNavbar = document.getElementById('publicNavbar');
    const publicNavToggle = document.getElementById('publicNavToggle');

    let navbarFramePending = false;

    function updatePublicNavbar() {
        navbarFramePending = false;
        if (publicNavbar) {
            publicNavbar.classList.toggle('scrolled', window.scrollY > 40);
        }
    }

    function requestNavbarUpdate() {
        if (!navbarFramePending) {
            navbarFramePending = true;
            window.requestAnimationFrame(updatePublicNavbar);
        }
    }

    updatePublicNavbar();
    window.addEventListener('scroll', requestNavbarUpdate, { passive: true });

    if (publicNavToggle && publicNavbar) {
        publicNavToggle.addEventListener('click', function () {
            const isOpen = publicNavbar.classList.toggle('menu-open');
            publicNavToggle.setAttribute('aria-expanded', String(isOpen));
        });
        document.querySelectorAll('#publicNavMenu a').forEach(function (link) {
            link.addEventListener('click', function () {
                publicNavbar.classList.remove('menu-open');
                publicNavToggle.setAttribute('aria-expanded', 'false');
            });
        });
    }

    const expandHero = document.querySelector('.expand-hero');
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    let heroFramePending = false;

    function updateExpandHero() {
        heroFramePending = false;
        if (!expandHero || reducedMotion) return;

        const scrollDistance = Math.max(1, expandHero.offsetHeight - window.innerHeight);
        const progress = Math.max(0, Math.min(1, (window.scrollY - expandHero.offsetTop) / scrollDistance));
        expandHero.style.setProperty('--hero-progress', progress.toFixed(4));
    }

    function requestHeroUpdate() {
        if (!heroFramePending) {
            heroFramePending = true;
            window.requestAnimationFrame(updateExpandHero);
        }
    }

    if (expandHero && !reducedMotion) {
        updateExpandHero();
        window.addEventListener('scroll', requestHeroUpdate, { passive: true });
        window.addEventListener('resize', requestHeroUpdate);
    }

    const observerOptions = {
        root: null,
        rootMargin: "0px",
        threshold: 0.15
    };
    
    const observer = new IntersectionObserver((entries, observerInstance) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add("reveal-active");
                observerInstance.unobserve(entry.target);
            }
        });
    }, observerOptions);
    
    document.querySelectorAll(".reveal-left, .reveal-right").forEach(el => {
        observer.observe(el);
    });
});
</script>

<?php
$suppressFooter = true;
require_once 'includes/footer.php';
?>
