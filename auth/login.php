<?php
require_once '../includes/auth_check.php';
if (isset($_SESSION['user_id'], $_SESSION['role'])) { header('Location: ' . resolveAppUrl('index')); exit; }
$mode = (isset($_GET['mode']) && $_GET['mode'] === 'register') ? 'register' : 'login';
$formData = $_SESSION['form_data'] ?? []; unset($_SESSION['form_data']);
function authValue($data, $key) { return isset($data[$key]) ? htmlspecialchars($data[$key], ENT_QUOTES, 'UTF-8') : ''; }
$page_title = $mode === 'register' ? 'Create Account' : 'Log In'; require_once '../includes/header.php';
?>
<style>
:root {
    --marine: var(--brand-primary, #008080);
    --marine-dark: var(--brand-secondary-dark, #004c4c);
    --marine-light: var(--brand-accent, #55b9b5);
    --marine-tint: var(--brand-primary-soft, #d9efee);
    --marine-ink: var(--brand-dark, #064b55);
    --form-bg: var(--surface-soft, #f7f9fa);
}

.auth-page {
    position: relative;
    isolation: isolate;
    background: transparent;
    min-height: calc(100vh - 58px);
    padding: clamp(1.5rem, 3.5vw, 3rem);
    display: grid;
    place-items: center;
}

.auth-page::before,
.auth-page::after {
    content: '';
    position: fixed;
    inset: -12px;
    pointer-events: none;
}

.auth-page::before {
    z-index: 0;
    background: url('../assets/img/auth-bg.jpg') center / cover no-repeat;
    filter: blur(8px);
    transform: scale(1.03);
}

.auth-page::after {
    z-index: 1;
    background: rgba(0, 76, 76, .46);
}

.auth-shell {
    z-index: 2;
    width: min(1120px, 100%);
    min-height: 720px;
    position: relative;
    overflow: hidden;
    border-radius: var(--radius-lg, 24px);
    box-shadow: 0 24px 60px rgba(0, 27, 27, .38), 0 8px 24px rgba(0, 76, 76, .16);
    background: var(--form-bg);
    transition: min-height .45s ease;
}

.mode-register.auth-shell {
    min-height: 840px;
}

.auth-panel {
    width: 50%;
    height: 100%;
    min-height: 100%;
    position: absolute;
    inset: 0 auto 0 0;
    transition: transform .7s cubic-bezier(.65, 0, .35, 1);
}

/* Form Panel */
.auth-form-panel {
    left: 50%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 56px 48px;
    background: var(--form-bg);
    overflow-y: auto;
}

.mode-register .auth-form-panel {
    transform: translateX(-100%);
    padding: 56px 48px;
}

/* Welcome / Branding Panel */
.auth-welcome-panel {
    z-index: 2;
    background: var(--marine);
    color: #fff;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 56px 48px;
    overflow: hidden;
}

.auth-welcome-panel:before,
.auth-welcome-panel:after {
    content: '';
    position: absolute;
    border: 1px solid var(--marine-light);
    border-radius: var(--radius-circle, 50%);
    opacity: .36;
}

.auth-welcome-panel:before {
    width: 22rem;
    height: 22rem;
    right: -9rem;
    top: -8rem;
}

.auth-welcome-panel:after {
    width: 16rem;
    height: 16rem;
    left: -8rem;
    bottom: -8rem;
    background: var(--marine-dark);
    border: 0;
    opacity: .55;
}

.mode-register .auth-welcome-panel {
    transform: translateX(100%);
    padding: 56px 48px;
}

/* Welcome Content Spacing */
.welcome-content {
    position: relative;
    z-index: 1;
    width: min(100%, 360px);
    text-align: center;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}

.maritime-mark {
    color: var(--marine-tint);
    font-size: 3.8rem;
    line-height: 1;
    margin-bottom: 20px; /* 16px to 24px between logo and heading */
}

.welcome-copy {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    width: 100%;
    transition: opacity .3s ease, transform .35s ease;
}

.welcome-copy-login {
    display: flex;
}

.welcome-copy-register {
    display: none;
    opacity: 0;
    transform: translateY(12px);
}

.mode-register .welcome-copy-login {
    display: none;
    opacity: 0;
    transform: translateY(-12px);
}

.mode-register .welcome-copy-register {
    display: flex;
    opacity: 1;
    transform: none;
}

.welcome-copy h1 {
    color: #ffffff !important;
    text-shadow: 0 2px 8px rgba(0, 30, 30, .35);
    font-size: clamp(2rem, 2.6vw, 2.4rem);
    font-weight: 700;
    letter-spacing: -.035em;
    margin: 0 0 8px; /* 8px between title and subtitle */
}

.welcome-copy p {
    font-size: 0.98rem;
    line-height: 1.55;
    margin: 0 0 24px; /* 24px between subtitle and button */
    color: rgba(255, 255, 255, 0.92);
}

.switch-mode {
    color: #fff;
    border: 1.5px solid var(--marine-tint);
    background: transparent;
    border-radius: var(--radius-sm, 8px);
    min-width: 140px;
    padding: .72rem 1.4rem;
    font-size: 0.92rem;
    font-weight: 600;
    transition: background .2s ease, color .2s ease, border-color .2s ease, transform .15s ease;
}

.switch-mode:hover,
.switch-mode:focus {
    background: var(--marine-tint);
    border-color: var(--marine-tint);
    color: var(--marine-ink);
    transform: translateY(-1px);
}

/* Form Styles & Spacing */
.forms {
    width: 100%;
    max-width: 460px;
    position: relative;
}

.auth-form {
    transition: opacity .28s ease, transform .36s ease;
}

.register-form {
    position: absolute;
    inset: 0;
    opacity: 0;
    transform: translateY(16px);
    pointer-events: none;
}

.mode-register .login-form {
    opacity: 0;
    transform: translateY(-16px);
    pointer-events: none;
    position: absolute;
    inset: 0;
}

.mode-register .register-form {
    position: relative;
    opacity: 1;
    transform: none;
    pointer-events: auto;
}

/* Typography Hierarchy */
.form-header-group {
    margin-bottom: 24px; /* 24px space before fields */
}

.form-kicker {
    color: var(--marine);
    font-size: .78rem;
    letter-spacing: .12em;
    text-transform: uppercase;
    font-weight: 700;
    margin-bottom: 8px;
}

.form-heading {
    color: #173b47;
    font-size: 1.85rem;
    letter-spacing: -.03em;
    font-weight: 700;
    margin: 0 0 8px; /* 8px between title and subtitle */
}

.form-intro {
    color: #64748b;
    margin: 0;
    font-size: .94rem;
    line-height: 1.5;
}

/* Fields & Inputs */
.auth-field {
    position: relative;
    margin-bottom: 18px; /* 16px to 20px consistent gap between fields */
}

.auth-field > i {
    position: absolute;
    left: .95rem;
    top: 2.38rem;
    color: var(--marine);
    z-index: 1;
    font-size: 1rem;
}

.auth-label {
    color: #294550;
    display: block;
    font-size: .86rem;
    font-weight: 600;
    margin-bottom: 6px; /* keep labels about 6px above their inputs */
}

.auth-input {
    min-height: 46px;
    width: 100%;
    padding: .65rem .95rem .65rem 2.65rem;
    border: 1px solid #cbd9df;
    border-radius: var(--radius-sm, 8px);
    background: #fff;
    color: #173b47;
    font-size: 0.95rem;
    transition: border-color .2s, box-shadow .2s;
}

.auth-input:focus {
    outline: 0;
    border-color: var(--marine-light);
    box-shadow: 0 0 0 .22rem rgba(0, 128, 128, .22);
}

.password-field .auth-input {
    padding-right: 2.45rem;
    padding-left: 2.65rem;
    font-size: 0.95rem;
}

.password-toggle {
    position: absolute;
    right: .55rem;
    top: 2.05rem;
    border: 0;
    background: transparent;
    color: var(--marine);
    padding: .35rem;
    line-height: 1;
    font-size: 1.05rem;
}

.password-toggle:hover {
    color: var(--marine-ink);
}

.auth-hint {
    color: #64748b;
    font-size: .75rem;
    line-height: 1.35;
    margin-top: 5px;
    display: block;
}

.auth-form .invalid-feedback {
    font-size: .75rem;
    margin-top: 4px;
}

.was-validated .auth-input:invalid {
    border-color: #ef4444;
}

.was-validated .auth-input:valid {
    border-color: #22c55e;
}

/* Action Group & Buttons */
.auth-action-group {
    margin-top: 24px; /* about 24px space above primary button */
}

.auth-submit {
    background: var(--marine);
    border: 1px solid var(--marine);
    color: #fff;
    width: 100%;
    min-height: 48px;
    border-radius: var(--radius-sm, 8px);
    font-size: 1rem;
    font-weight: 600;
    transition: background .2s, border-color .2s, transform .2s, box-shadow .2s;
}

.auth-submit:hover {
    background: var(--marine-dark);
    border-color: var(--marine-dark);
    transform: translateY(-1px);
    box-shadow: 0 4px 14px rgba(0, 128, 128, .25);
}

.auth-submit:active {
    transform: translateY(0);
}

.forgot-link {
    color: var(--marine);
    font-size: .85rem;
    font-weight: 600;
    text-decoration: none;
}

.forgot-link:hover {
    color: var(--marine-ink);
    text-decoration: underline;
}

.register-note {
    color: #6c7d85;
    font-size: .78rem;
    line-height: 1.45;
    text-align: center;
    margin-top: 8px;
    margin-bottom: 0;
}

.auth-compact-note {
    color: #6c7d85;
    font-size: .86rem;
    text-align: center;
    margin-top: 16px; /* about 16px space above login link */
    margin-bottom: 0;
}

.auth-compact-note a {
    color: var(--marine);
    font-weight: 600;
    text-decoration: none;
}

.auth-compact-note a:hover {
    text-decoration: underline;
    color: var(--marine-dark);
}

/* Back Link */
.auth-back-link {
    position: absolute;
    z-index: 10;
    top: 1.25rem;
    left: 1.25rem;
    width: 38px;
    height: 38px;
    display: inline-grid;
    place-items: center;
    border: 1px solid rgba(0, 76, 76, .18);
    border-radius: 50%;
    background: rgba(255, 255, 255, .92);
    color: #004c4c;
    text-decoration: none;
    box-shadow: 0 4px 12px rgba(0, 76, 76, .14);
    transition: transform .2s ease, background .2s ease;
}

.auth-back-link:hover,
.auth-back-link:focus {
    background: #b2d8d8;
    color: #004c4c;
    transform: translateX(-2px);
}

/* Tablet & Mobile Responsiveness */
@media (max-width: 991.98px) {
    .auth-page {
        padding: 1.25rem 0.75rem;
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 100vh;
    }
    
    .auth-shell {
        min-height: auto !important;
        width: 100%;
        max-width: 540px;
        border-radius: var(--radius-lg, 20px);
        overflow: hidden;
        display: flex;
        flex-direction: column;
        box-shadow: 0 16px 40px rgba(0, 27, 27, .32);
    }
    
    .auth-panel {
        position: relative !important;
        width: 100% !important;
        min-height: auto !important;
        height: auto !important;
        transform: none !important;
    }
    
    /* Branding panel stacks on top */
    .auth-welcome-panel {
        order: 1;
        padding: 32px 24px !important;
        min-height: auto;
    }
    
    .auth-welcome-panel:before {
        width: 14rem;
        height: 14rem;
        right: -6rem;
        top: -6rem;
    }
    
    .auth-welcome-panel:after {
        width: 10rem;
        height: 10rem;
        left: -5rem;
        bottom: -5rem;
    }
    
    .maritime-mark {
        font-size: 2.6rem;
        margin-bottom: 12px;
    }
    
    .welcome-copy h1 {
        font-size: 1.7rem;
        margin-bottom: 6px;
    }
    
    .welcome-copy p {
        font-size: .88rem;
        margin-bottom: 16px;
    }
    
    .switch-mode {
        min-width: 120px;
        padding: .5rem 1.1rem;
        font-size: .85rem;
    }
    
    /* Form panel stacks below */
    .auth-form-panel {
        order: 2;
        left: 0 !important;
        padding: 32px 24px 36px !important;
    }
    
    .forms {
        max-width: 100%;
    }
    
    .auth-form {
        position: relative !important;
        transform: none !important;
    }
    
    .login-form {
        display: block;
        opacity: 1;
    }
    
    .register-form {
        display: none;
        opacity: 0;
    }
    
    .mode-register .login-form {
        display: none !important;
        opacity: 0;
    }
    
    .mode-register .register-form {
        display: block !important;
        opacity: 1;
    }
}

@media (max-width: 575.98px) {
    .auth-page {
        padding: 0.75rem 0.5rem;
    }
    
    .auth-shell {
        border-radius: var(--radius-md, 16px);
    }
    
    .auth-welcome-panel {
        padding: 28px 18px !important;
    }
    
    .auth-form-panel {
        padding: 28px 18px 32px !important;
    }
    
    .form-heading {
        font-size: 1.55rem;
    }
}

@media (prefers-reduced-motion: reduce) {
    .auth-panel,
    .auth-form,
    .welcome-copy,
    .auth-shell {
        transition: none !important;
    }
}
</style>

<section class="auth-page">
    <div class="auth-shell <?php echo $mode === 'register' ? 'mode-register' : ''; ?>" id="authShell">
        <a class="auth-back-link" href="../index" aria-label="Back to home" title="Back to home">
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
        </a>
        
        <!-- Branding / Welcome Panel -->
        <aside class="auth-panel auth-welcome-panel" aria-label="Account access options">
            <div class="welcome-content">
                <div class="maritime-mark" aria-hidden="true"><i class="bi bi-compass"></i></div>
                <div class="welcome-copy welcome-copy-login">
                    <h1>Welcome back</h1>
                    <p>Log in to access your enrollment portal</p>
                    <button type="button" class="switch-mode" data-mode="register">Create Account</button>
                </div>
                <div class="welcome-copy welcome-copy-register">
                    <h1>New here?</h1>
                    <p>Create an account to start your application</p>
                    <button type="button" class="switch-mode" data-mode="login">I already have an account</button>
                </div>
            </div>
        </aside>
        
        <!-- Forms Panel -->
        <div class="auth-panel auth-form-panel">
            <div class="forms">
                <!-- Login Form -->
                <form action="../actions/auth_actions" method="POST" class="auth-form login-form needs-validation" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="form-header-group">
                        <div class="form-kicker">NCST Maritime Academy</div>
                        <h2 class="form-heading">Portal Login</h2>
                        <p class="form-intro">Enter your credentials to continue.</p>
                    </div>
                    <div class="auth-field">
                        <label class="auth-label" for="login_username">Username or Email</label>
                        <i class="bi bi-person" aria-hidden="true"></i>
                        <input class="auth-input" type="text" name="username" id="login_username" placeholder="Enter username or email" value="<?php echo authValue($formData, 'username'); ?>" required autocomplete="username">
                        <div class="invalid-feedback">Please enter your username.</div>
                    </div>
                    <div class="auth-field password-field">
                        <label class="auth-label" for="login_password">Password</label>
                        <i class="bi bi-lock" aria-hidden="true"></i>
                        <input class="auth-input" type="password" name="password" id="login_password" placeholder="Enter password" required autocomplete="current-password">
                        <button class="password-toggle" type="button" data-target="login_password" aria-label="Show password"><i class="bi bi-eye" aria-hidden="true"></i></button>
                        <div class="invalid-feedback">Please enter your password.</div>
                    </div>
                    <div class="d-flex justify-content-end mb-3">
                        <a class="forgot-link" href="#" id="forgotPasswordLink">Forgot password?</a>
                    </div>
                    <div class="auth-action-group">
                        <button class="auth-submit" type="submit">Log In</button>
                        <p class="auth-compact-note">No account? <a href="?mode=register" data-mode-link="register">Create one</a></p>
                    </div>
                </form>

                <!-- Register Form -->
                <form action="../actions/auth_actions" method="POST" class="auth-form register-form needs-validation" novalidate>
                    <input type="hidden" name="action" value="register">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                    
                    <div class="form-header-group">
                        <div class="form-kicker">NCST Maritime Academy</div>
                        <h2 class="form-heading">Create Your Account</h2>
                        <p class="form-intro">Begin your enrollment application.</p>
                    </div>

                    <!-- Related fields in 2-column grid on desktop: First Name & Last Name (16px gap) -->
                    <div class="row gx-3">
                        <div class="col-12 col-sm-6">
                            <div class="auth-field">
                                <label class="auth-label" for="first_name">First Name</label>
                                <i class="bi bi-person-vcard" aria-hidden="true"></i>
                                <input class="auth-input" type="text" name="first_name" id="first_name" placeholder="First name" pattern="^[a-zA-Z\s]+$" value="<?php echo authValue($formData, 'first_name'); ?>" required autocomplete="given-name">
                                <div class="invalid-feedback">Letters and spaces only.</div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6">
                            <div class="auth-field">
                                <label class="auth-label" for="last_name">Last Name</label>
                                <i class="bi bi-person-vcard" aria-hidden="true"></i>
                                <input class="auth-input" type="text" name="last_name" id="last_name" placeholder="Last name" pattern="^[a-zA-Z\s]+$" value="<?php echo authValue($formData, 'last_name'); ?>" required autocomplete="family-name">
                                <div class="invalid-feedback">Letters and spaces only.</div>
                            </div>
                        </div>
                    </div>

                    <!-- Related fields in 2-column grid on desktop: Email & Contact Number (16px gap) -->
                    <div class="row gx-3">
                        <div class="col-12 col-sm-6">
                            <div class="auth-field">
                                <label class="auth-label" for="email">Email Address</label>
                                <i class="bi bi-envelope" aria-hidden="true"></i>
                                <input class="auth-input" type="email" name="email" id="email" placeholder="name@example.com" value="<?php echo authValue($formData, 'email'); ?>" required autocomplete="email">
                                <div class="invalid-feedback">Please enter a valid email.</div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6">
                            <div class="auth-field">
                                <label class="auth-label" for="contact_number">Mobile Number</label>
                                <i class="bi bi-telephone" aria-hidden="true"></i>
                                <input class="auth-input" type="text" name="contact_number" id="contact_number" placeholder="09XXXXXXXXX" inputmode="numeric" pattern="^09[0-9]{9}$" maxlength="11" value="<?php echo authValue($formData, 'contact_number'); ?>" required autocomplete="tel" oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,11);">
                                <div class="invalid-feedback">Enter an 11-digit number starting with 09.</div>
                            </div>
                        </div>
                    </div>

                    <!-- Username: Long field spanning full width -->
                    <div class="auth-field">
                        <label class="auth-label" for="reg_username">Username</label>
                        <i class="bi bi-person" aria-hidden="true"></i>
                        <input class="auth-input" type="text" name="username" id="reg_username" placeholder="Create username (min. 4 characters)" pattern="^[a-zA-Z0-9]+$" minlength="4" value="<?php echo authValue($formData, 'username'); ?>" required autocomplete="username">
                        <div class="invalid-feedback">Use letters and numbers only and at least 4 characters.</div>
                    </div>

                    <!-- Password: Long field spanning full width -->
                    <div class="auth-field password-field">
                        <label class="auth-label" for="reg_password">Password</label>
                        <i class="bi bi-lock" aria-hidden="true"></i>
                        <input class="auth-input" type="password" name="password" id="reg_password" placeholder="Create a strong password" pattern="(?=.*[A-Za-z])(?=.*\d).{8,}" title="Must be at least 8 characters and include a letter and a number." required autocomplete="new-password">
                        <button class="password-toggle" type="button" data-target="reg_password" aria-label="Show password"><i class="bi bi-eye" aria-hidden="true"></i></button>
                        <div class="auth-hint">Must be at least 8 characters and include a letter and a number.</div>
                        <div class="invalid-feedback">Use 8+ characters with letters and numbers.</div>
                    </div>

                    <!-- Confirm Password: Long field spanning full width -->
                    <div class="auth-field password-field">
                        <label class="auth-label" for="confirm_password">Confirm Password</label>
                        <i class="bi bi-shield-lock" aria-hidden="true"></i>
                        <input class="auth-input" type="password" name="confirm_password" id="confirm_password" placeholder="Re-enter your password to confirm" title="Must match the password entered above." required autocomplete="new-password">
                        <button class="password-toggle" type="button" data-target="confirm_password" aria-label="Show password"><i class="bi bi-eye" aria-hidden="true"></i></button>
                        <div class="auth-hint">Re-enter your password to confirm.</div>
                        <div class="invalid-feedback">Passwords must match.</div>
                    </div>

                    <!-- Action group with 24px space above button -->
                    <div class="auth-action-group">
                        <button class="auth-submit" type="submit">Create Account</button>
                        <p class="register-note">You'll complete your full application after registering.</p>
                        <p class="auth-compact-note">Already have an account? <a href="?mode=login" data-mode-link="login">Log in</a></p>
                    </div>
                </form>
            </div>
        </div>
    </div>
</section>
<script>
(function () {
    'use strict';

    const shell = document.getElementById('authShell');
    if (!shell) return;

    function setMode(mode, updateUrl) {
        shell.classList.toggle('mode-register', mode === 'register');
        if (updateUrl) history.replaceState({}, '', '?mode=' + mode);
    }

    document.addEventListener('click', function (event) {
        const forgotLink = event.target.closest('#forgotPasswordLink');
        if (forgotLink) {
            event.preventDefault();
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    title: 'Account Password Reset',
                    html: '<p class="text-muted mb-3" style="font-size:0.92rem;">To protect student and cadet academic records, account credentials cannot be reset unverified.</p>' +
                          '<div class="text-start p-3 rounded border" style="background:var(--surface-soft, #f7f9fa);font-size:0.88rem;line-height:1.6;">' +
                          '<strong><i class="bi bi-mortarboard text-brand-primary me-1"></i> Students & Enrollees:</strong><br>Visit the Registrar\'s Office or email admissions at <code class="text-dark">registrar@school.edu</code>.<br><br>' +
                          '<strong><i class="bi bi-person-badge text-brand-primary me-1"></i> Faculty & Staff:</strong><br>Contact the School IT Administrator to reset your password.' +
                          '</div>',
                    icon: 'info',
                    iconColor: '#0b9b98',
                    confirmButtonText: 'Got it',
                    confirmButtonColor: '#0b9b98',
                    background: '#ffffff',
                    color: '#1f2937'
                });
            } else {
                alert('For account assistance or password resets, please contact the Registrar’s Office (registrar@school.edu) or IT Administrator.');
            }
            return;
        }

        const modeControl = event.target.closest('[data-mode], [data-mode-link]');
        if (modeControl) {
            if (modeControl.hasAttribute('data-mode-link')) event.preventDefault();
            setMode(modeControl.dataset.mode || modeControl.dataset.modeLink, true);
            return;
        }

        const button = event.target.closest('.password-toggle');
        if (!button) return;
        const input = document.getElementById(button.dataset.target);
        const icon = button.querySelector('i');
        if (!input || !icon) return;

        const reveal = input.type === 'password';
        input.type = reveal ? 'text' : 'password';
        button.setAttribute('aria-label', reveal ? 'Hide password' : 'Show password');
        icon.className = reveal ? 'bi bi-eye-slash' : 'bi bi-eye';
    });

    const regPassword = document.getElementById('reg_password');
    const confirmPassword = document.getElementById('confirm_password');
    if (regPassword && confirmPassword) {
        function checkPasswordMatch() {
            if (confirmPassword.value && regPassword.value !== confirmPassword.value) {
                confirmPassword.setCustomValidity('Passwords do not match.');
            } else {
                confirmPassword.setCustomValidity('');
            }
        }
        regPassword.addEventListener('input', checkPasswordMatch);
        confirmPassword.addEventListener('input', checkPasswordMatch);
    }
}());
</script>
<?php require_once '../includes/footer.php'; ?>
