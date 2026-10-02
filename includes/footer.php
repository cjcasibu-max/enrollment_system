<?php
/**
 * Application Footer Template
 * Closes open HTML tags, renders the copyright footer, and imports Javascript files.
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
?>

<?php if ($showSidebar): ?>
            </div> <!-- /animated-fade-in -->
        </main>
        
        <!-- Dashboard Footer -->
        <footer class="footer mt-auto py-3 bg-white border-top">
            <div class="container-fluid text-center text-muted">
                <span class="small">&copy; <?php echo date('Y'); ?> NCST Maritime Academy. All rights reserved.</span>
            </div>
        </footer>
    </div> <!-- /main-wrapper -->
</div> <!-- /app-container -->

<?php else: ?>
    </main>
    
    <!-- Full-screen/Unauthenticated Page Footer -->
    <?php if (empty($suppressFooter)): ?>
    <footer class="footer mt-auto py-3 bg-white border-top">
        <div class="container text-center text-muted">
            <span class="small">&copy; <?php echo date('Y'); ?> NCST Maritime Academy. All rights reserved.</span>
        </div>
    </footer>
    <?php endif; ?>
</div> <!-- /app-container -->
<?php endif; ?>

<!-- Vendored scripts: safe for offline presentations. -->
<script src="<?php echo $base_path; ?>assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

<script src="<?php echo $base_path; ?>assets/vendor/sweetalert2/sweetalert2.all.min.js"></script>

<!-- Tabulator.js (Local vendored with CDN fallback) -->
<?php if (file_exists($base_path . 'assets/vendor/tabulator/js/tabulator.min.js')): ?>
<script src="<?php echo $base_path; ?>assets/vendor/tabulator/js/tabulator.min.js"></script>
<?php else: ?>
<script src="https://cdn.jsdelivr.net/npm/tabulator-tables@6.3.0/dist/js/tabulator.min.js"></script>
<?php endif; ?>

<!-- Custom Application Scripts -->
<script src="<?php echo $base_path; ?>assets/js/scripts.js?v=20260922-tabulator-ui"></script>

<!-- Global Logout Confirmation (SweetAlert2) -->
<script>
(function () {
    'use strict';

    // Fire after DOM is ready so all logout forms are in the tree
    document.addEventListener('DOMContentLoaded', function () {

        // Target every form whose action ends in auth/logout
        document.querySelectorAll('form[action*="auth/logout"]').forEach(function (form) {

            form.addEventListener('submit', function (e) {
                e.preventDefault();          // Stop the native POST
                var pendingForm = this;       // Keep reference for later submit

                Swal.fire({
                    title: 'Log Out?',
                    html: '<div style="font-size:0.95rem;color:#64748b;line-height:1.55;">You are about to end your session.<br>Any unsaved changes will be lost.</div>',
                    icon: 'question',
                    iconColor: '#0b9b98',
                    showCancelButton: true,
                    confirmButtonText: '<i class="bi bi-box-arrow-right me-1"></i> Yes, Log Out',
                    cancelButtonText: '<i class="bi bi-x me-1"></i> Stay',
                    confirmButtonColor: '#ef4444',
                    cancelButtonColor: '#6c757d',
                    reverseButtons: true,
                    focusCancel: true,
                    customClass: {
                        popup:          'swal-logout-popup',
                        title:          'swal-logout-title',
                        confirmButton:  'swal-logout-confirm',
                        cancelButton:   'swal-logout-cancel',
                    },
                    backdrop: 'rgba(11, 25, 44, 0.65)',
                    showClass: {
                        popup: 'swal2-show'
                    },
                    hideClass: {
                        popup: 'swal2-hide'
                    }
                }).then(function (result) {
                    if (result.isConfirmed) {
                        // User confirmed — submit the original POST form
                        pendingForm.submit();
                    }
                });
            });
        });
    });
}());
</script>

<style>
/* Logout SweetAlert popup tweaks */
.swal-logout-popup {
    border-radius: 18px !important;
    padding: 1.75rem 1.5rem 1.5rem !important;
    box-shadow: 0 25px 60px rgba(11, 25, 44, 0.22) !important;
    border: 1px solid rgba(11, 155, 152, 0.12) !important;
}
.swal-logout-title {
    font-family: Inter, -apple-system, sans-serif !important;
    font-size: 1.35rem !important;
    font-weight: 700 !important;
    color: #0B192C !important;
    margin-bottom: 0.25rem !important;
}
.swal-logout-confirm,
.swal-logout-cancel {
    font-family: Inter, -apple-system, sans-serif !important;
    font-size: 0.875rem !important;
    font-weight: 600 !important;
    border-radius: 10px !important;
    padding: 0.55rem 1.35rem !important;
    transition: transform 0.15s ease, box-shadow 0.15s ease !important;
}
.swal-logout-confirm:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 18px rgba(239, 68, 68, 0.35) !important;
}
.swal-logout-cancel:hover {
    transform: translateY(-1px);
}
</style>

</body>
</html>
