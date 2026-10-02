<?php
/**
 * Flash Messages Renderer
 * Displays temporary session notifications (success, error, info, warning) using SweetAlert2.
 * Follows AI_GUIDE.md Section 2a.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Renders a SweetAlert2 Toast.
 *
 * @param string $icon 'success', 'error', 'warning', 'info'
 * @param string $message The message text to display
 */
function renderSwalToast(string $icon, string $message) {
    // Use json_encode with HEX flags to produce safe JS string literals.
    // This prevents all XSS vectors including </script> injection and unicode escapes.
    $flags   = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
    $safeMsg  = json_encode($message, $flags);   // produces a quoted, escaped JS string
    $safeIcon = json_encode($icon,    $flags);   // whitelist validated below, but encoded for safety

    // Choose corresponding accent border colors from assets/css/custom.css
    $colors = [
        'success' => '#22a06b',
        'error'   => '#d9535f',
        'warning' => '#d99a1d',
        'info'    => '#0b9b98'
    ];
    $accentColor = json_encode(isset($colors[$icon]) ? $colors[$icon] : '#008080', $flags);

    echo "<script>
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: " . $safeIcon . ",
                title: " . $safeMsg . ",
                toast: true,
                position: 'bottom-end',
                showConfirmButton: false,
                timer: 3500,
                timerProgressBar: true,
                iconColor: " . $accentColor . ",
                background: '#ffffff',
                color: '#1f2937',
                didOpen: (toast) => {
                    toast.addEventListener('mouseenter', Swal.stopTimer);
                    toast.addEventListener('mouseleave', Swal.resumeTimer);
                }
            });
        } else {
            var container = document.getElementById('flashMessageFallback');
            if (!container) {
                container = document.createElement('div');
                container.id = 'flashMessageFallback';
                container.style.cssText = 'position:fixed;bottom:1rem;right:1rem;z-index:1040;max-width:360px;width:calc(100% - 2rem);pointer-events:none;';
                document.body.appendChild(container);
            }
            var alertDiv = document.createElement('div');
            var bsType = " . ($icon === 'error' ? "'alert-danger'" : ($icon === 'warning' ? "'alert-warning'" : ($icon === 'success' ? "'alert-success'" : "'alert-info'"))) . ";
            alertDiv.className = 'alert ' + bsType + ' alert-dismissible fade show shadow-sm border mb-2';
            alertDiv.style.pointerEvents = 'auto';
            alertDiv.setAttribute('role', 'alert');
            
            var msgSpan = document.createElement('span');
            msgSpan.textContent = " . $safeMsg . ";
            alertDiv.appendChild(msgSpan);
            
            var closeBtn = document.createElement('button');
            closeBtn.type = 'button';
            closeBtn.className = 'btn-close';
            closeBtn.setAttribute('data-bs-dismiss', 'alert');
            closeBtn.setAttribute('aria-label', 'Close');
            alertDiv.appendChild(closeBtn);
            
            container.appendChild(alertDiv);
            setTimeout(function() {
                if (alertDiv && alertDiv.parentNode) {
                    alertDiv.classList.remove('show');
                    setTimeout(function() { if (alertDiv.parentNode) alertDiv.parentNode.removeChild(alertDiv); }, 250);
                }
            }, 4000);
        }
    });
    </script>";
}

// 1. Handle $_SESSION['flash'] array (standard format)
if (isset($_SESSION['flash']) && is_array($_SESSION['flash'])) {
    $type = isset($_SESSION['flash']['type']) ? $_SESSION['flash']['type'] : 'info';
    $message = isset($_SESSION['flash']['message']) ? $_SESSION['flash']['message'] : '';
    
    $icon = 'info';
    if ($type === 'success') {
        $icon = 'success';
    } elseif ($type === 'danger' || $type === 'error') {
        $icon = 'error';
    } elseif ($type === 'warning') {
        $icon = 'warning';
    }
    
    if (!empty($message)) {
        renderSwalToast($icon, $message);
    }
    unset($_SESSION['flash']);
}

// 2. Handle flat $_SESSION['flash_success']
if (isset($_SESSION['flash_success'])) {
    renderSwalToast('success', $_SESSION['flash_success']);
    unset($_SESSION['flash_success']);
}

// 3. Handle flat $_SESSION['flash_error']
if (isset($_SESSION['flash_error'])) {
    renderSwalToast('error', $_SESSION['flash_error']);
    unset($_SESSION['flash_error']);
}

// 4. Handle flat $_SESSION['success'] (legacy/fallback)
if (isset($_SESSION['success'])) {
    renderSwalToast('success', $_SESSION['success']);
    unset($_SESSION['success']);
}

// 5. Handle flat $_SESSION['error'] (legacy/fallback)
if (isset($_SESSION['error'])) {
    renderSwalToast('error', $_SESSION['error']);
    unset($_SESSION['error']);
}
?>
