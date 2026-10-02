/**
 * NCST Maritime Academy Enrollment System
 * Shared client-side interactions.
 *
 * This file intentionally preserves existing markup handlers and page flows while
 * reducing listener count, repeated DOM queries, and unnecessary work.
 */
(function () {
    'use strict';

    function initSidebar() {
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebar = document.querySelector('.sidebar');
        if (!sidebarToggle || !sidebar) return;

        const isMobile = () => window.matchMedia('(max-width: 991.98px)').matches;

        sidebarToggle.addEventListener('click', function (event) {
            event.stopPropagation();
            sidebar.classList.toggle('show');
        });

        document.addEventListener('click', function (event) {
            if (!isMobile() || !sidebar.classList.contains('show')) return;
            if (!sidebar.contains(event.target) && event.target !== sidebarToggle) {
                sidebar.classList.remove('show');
            }
        });
    }

    function initTooltips() {
        if (!window.bootstrap || typeof window.bootstrap.Tooltip !== 'function') return;
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (element) {
            if (!window.bootstrap.Tooltip.getInstance(element)) {
                new window.bootstrap.Tooltip(element);
            }
        });
    }

    function initPasswordToggles() {
        // One delegated listener covers every current and dynamically inserted toggle.
        document.addEventListener('click', function (event) {
            const button = event.target.closest('.toggle-password');
            if (!button) return;

            const input = document.getElementById(button.dataset.target);
            const icon = button.querySelector('i');
            if (!input || !icon) return;

            const showPassword = input.type === 'password';
            input.type = showPassword ? 'text' : 'password';
            icon.classList.toggle('bi-eye-fill', !showPassword);
            icon.classList.toggle('bi-eye-slash-fill', showPassword);
        });
    }

    function validateApplicantAgeFields() {
        const birthDateInput = document.getElementById('birthdate');
        const ageInput = document.getElementById('age');
        if (!birthDateInput || !ageInput) return;

        const birthValue = birthDateInput.value;
        const ageValue = ageInput.value.trim();
        let birthError = '';
        let ageError = '';

        if (!birthValue) {
            birthError = 'Birthdate is required.';
        } else {
            const birthDate = new Date(birthValue + 'T00:00:00');
            const today = new Date();
            today.setHours(0, 0, 0, 0);

            if (Number.isNaN(birthDate.getTime()) || birthDate >= today) {
                birthError = 'Birthdate must be a valid past date.';
            } else {
                let computedAge = today.getFullYear() - birthDate.getFullYear();
                const monthDiff = today.getMonth() - birthDate.getMonth();
                if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
                    computedAge -= 1;
                }

                if (computedAge < 15) {
                    birthError = 'Applicant must be at least 15 years old.';
                    ageError = 'Applicants must be at least 15 years old.';
                } else if (ageValue !== '' && Number(ageInput.value) !== computedAge) {
                    ageError = 'Age does not match the birthdate.';
                }
            }
        }

        birthDateInput.setCustomValidity(birthError);
        ageInput.setCustomValidity(ageError || (!ageValue ? 'Age is required.' : ''));

        birthDateInput.classList.toggle('is-invalid', !!birthError);
        ageInput.classList.toggle('is-invalid', !!ageError || (!ageValue && !birthValue));
        birthDateInput.classList.toggle('is-valid', !birthError && !!birthValue);
        ageInput.classList.toggle('is-valid', !ageError && ageValue !== '');
    }

    function initFormValidation() {
        document.querySelectorAll('form.needs-validation').forEach(function (form) {
            if (form.dataset.validationBound === 'true') return;
            form.dataset.validationBound = 'true';

            const birthDateInput = document.getElementById('birthdate');
            const ageInput = document.getElementById('age');
            if (birthDateInput) {
                birthDateInput.addEventListener('change', validateApplicantAgeFields);
                birthDateInput.addEventListener('input', validateApplicantAgeFields);
            }
            if (ageInput) {
                ageInput.addEventListener('input', validateApplicantAgeFields);
                ageInput.addEventListener('change', validateApplicantAgeFields);
            }

            form.addEventListener('submit', function (event) {
                if (form.classList.contains('register-form')) {
                    const password = document.getElementById('reg_password');
                    const confirmation = document.getElementById('confirm_password');
                    if (password && confirmation) {
                        confirmation.setCustomValidity(password.value === confirmation.value ? '' : 'Passwords do not match.');
                    }
                }

                validateApplicantAgeFields();

                if (!form.checkValidity()) {
                    event.preventDefault();
                    event.stopPropagation();
                    form.reportValidity();
                    return;
                }
                form.classList.add('was-validated');
            }, false);
        });
    }

    function deriveLoadingText(originalText) {
        const text = (originalText || '').trim().toLowerCase();
        if (text.includes('log in') || text.includes('login')) return 'Logging in...';
        if (text.includes('create account') || text.includes('create user') || text.includes('create term') || text.includes('create section') || text.includes('create program')) return 'Creating...';
        if (text.includes('save draft')) return 'Saving draft...';
        if (text.includes('save evaluation') || text.includes('save profile') || text.includes('save changes') || text.includes('save subject') || text.includes('save attendance') || text.includes('save')) return 'Saving...';
        if (text.includes('submit for approval')) return 'Submitting...';
        if (text.includes('submit application') || text.includes('submit payment') || text.includes('submit')) return 'Submitting...';
        if (text.includes('payment') || text.includes('pay') || text.includes('process payment') || text.includes('record payment')) return 'Processing payment...';
        if (text.includes('enroll') || text.includes('reservation')) return 'Enrolling...';
        if (text.includes('approve')) return 'Approving...';
        if (text.includes('restore')) return 'Restoring...';
        return 'Processing...';
    }

    function initFormSubmitProtection() {
        let lastSubmitter = null;

        document.addEventListener('click', function (event) {
            const button = event.target.closest('button[type="submit"], input[type="submit"]');
            if (button) {
                lastSubmitter = button;
            }
        }, true);

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' && event.target && event.target.matches('input:not([type="button"]):not([type="submit"]):not([type="reset"])')) {
                const form = event.target.form;
                if (form) {
                    lastSubmitter = form.querySelector('button[type="submit"], input[type="submit"]');
                }
            }
        }, true);

        document.addEventListener('submit', function (event) {
            const form = event.target;
            if (!form || form.tagName !== 'FORM') return;

            if ((form.method || '').toUpperCase() === 'GET' || form.hasAttribute('data-no-loading')) {
                return;
            }

            // 1. Check HTML5 constraint validity. If invalid, DO NOT disable or spinner!
            if (form.checkValidity && !form.checkValidity()) {
                return;
            }

            // 2. Check if cancelled by previous handler
            if (event.defaultPrevented) {
                return;
            }

            // 3. Double-submit prevention
            if (form.dataset.submitting === 'true') {
                event.preventDefault();
                event.stopPropagation();
                return false;
            }

            const submitter = event.submitter || lastSubmitter || form.querySelector('button[type="submit"], input[type="submit"]');
            if (!submitter) {
                form.dataset.submitting = 'true';
                return;
            }

            form.dataset.submitting = 'true';

            // 4. Critical POST Data Preservation for Multi-Submit Buttons
            if (submitter.name && !form.querySelector(`input[type="hidden"][data-injected-submitter="${submitter.name}"]`)) {
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = submitter.name;
                hidden.value = submitter.value || '';
                hidden.dataset.injectedSubmitter = submitter.name;
                form.appendChild(hidden);
            }

            // 5. Geometry lock to prevent layout shift
            const rect = submitter.getBoundingClientRect();
            if (rect && rect.width > 0) {
                submitter.style.minWidth = rect.width + 'px';
            }

            // 6. Contextual text resolution
            const customText = submitter.getAttribute('data-loading-text');
            let loadingText = customText;
            if (!loadingText) {
                const originalText = (submitter.textContent || submitter.value || '').trim();
                loadingText = deriveLoadingText(originalText);
            }

            // 7. Spinner render
            if (submitter.tagName === 'BUTTON') {
                submitter.innerHTML = `<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>${loadingText}`;
            } else if (submitter.tagName === 'INPUT') {
                submitter.value = loadingText;
            }
            submitter.classList.add('disabled');
            submitter.style.pointerEvents = 'none';

            // 8. Defer button disabling so standard form submission pipeline completes
            setTimeout(function () {
                submitter.disabled = true;
            }, 0);
        }, false);
    }

    document.addEventListener('DOMContentLoaded', function () {
        initSidebar();
        initTooltips();
        initPasswordToggles();
        initFormValidation();
        initFormSubmitProtection();
    }, { once: true });
}());
