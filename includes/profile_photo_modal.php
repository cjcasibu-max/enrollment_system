<?php
/**
 * Shared Profile Photo Upload Modal & Component
 * Included in My Profile and Edit Profile across all portals.
 */
if (!defined('APP_AVATAR_MODAL_INCLUDED')) {
    define('APP_AVATAR_MODAL_INCLUDED', true);

    $currentUserId = (int)($_SESSION['user_id'] ?? 0);
    $currentAvatar = $_SESSION['profile_picture'] ?? null;
    $currentVer = (int)($_SESSION['avatar_version'] ?? time());
    $hasPhoto = !empty($currentAvatar);
    $avatarSrc = $hasPhoto ? "{$base_path}actions/view_avatar?uid={$currentUserId}&v={$currentVer}" : '';
    $initials = strtoupper(substr($_SESSION['username'] ?? 'U', 0, 2));
    $rawCurrentUrl = $_SERVER['REQUEST_URI'] ?? '';
    $cleanCurrentUrl = preg_replace('/\.php(?=[\?#]|$)/i', '', $rawCurrentUrl);
    $currentUrl = htmlspecialchars($cleanCurrentUrl, ENT_QUOTES, 'UTF-8');
?>
<!-- Photo Upload Modal -->
<div class="modal fade" id="photoUploadModal" tabindex="-1" aria-labelledby="photoUploadModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 14px; overflow: hidden;">
            <div class="modal-header text-white" style="background: linear-gradient(135deg, #008080, #004c4c);">
                <h5 class="modal-title d-flex align-items-center gap-2 fs-6 fw-semibold" id="photoUploadModalLabel">
                    <i class="bi bi-camera"></i> Profile Photo
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <form action="<?php echo $base_path; ?>actions/user_profile_actions" method="POST" enctype="multipart/form-data" id="profilePhotoUploadForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="upload_photo">
                <input type="hidden" name="redirect_url" value="<?php echo $currentUrl; ?>">

                <div class="modal-body p-4 text-center">
                    <!-- Live circular preview -->
                    <div class="position-relative d-inline-block mx-auto mb-3">
                        <div id="modalAvatarPreviewBox" style="width: 130px; height: 130px; border-radius: 50%; border: 3px solid var(--brand-primary, #008080); overflow: hidden; background: #e6f2f2; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(0,128,128,0.15);">
                            <img id="modalAvatarPreviewImg" 
                                 src="<?php echo htmlspecialchars($avatarSrc); ?>" 
                                 alt="Avatar Preview" 
                                 class="w-100 h-100" 
                                 style="object-fit: cover; display: <?php echo $hasPhoto ? 'block' : 'none'; ?>;"
                                 onerror="this.style.display='none'; document.getElementById('modalAvatarFallback').style.display='flex';">
                            <span id="modalAvatarFallback" class="fw-bold fs-2 text-navy-alt" style="display: <?php echo $hasPhoto ? 'none' : 'flex'; ?>; align-items: center; justify-content: center; width: 100%; height: 100%;">
                                <?php echo htmlspecialchars($initials); ?>
                            </span>
                        </div>
                    </div>

                    <p class="text-muted small mb-3">Choose a photo in JPG, PNG, or WEBP format. Maximum size: 2 MB.</p>

                    <!-- Client error alert -->
                    <div id="modalPhotoError" class="alert alert-danger py-2 px-3 small d-none text-start" role="alert"></div>

                    <!-- File input -->
                    <div class="mb-3 text-start">
                        <label for="profilePhotoFileInput" class="form-label small fw-semibold text-secondary">Select Image File</label>
                        <input class="form-control" type="file" id="profilePhotoFileInput" name="profile_photo" accept="image/jpeg,image/png,image/webp" required>
                    </div>
                </div>

                <div class="modal-footer bg-light px-4 py-3 d-flex justify-content-between align-items-center">
                    <div>
                        <?php if ($hasPhoto): ?>
                            <button type="button" class="btn btn-outline-danger btn-sm" id="btnTriggerRemovePhoto">
                                <i class="bi bi-trash3 me-1"></i> Remove Photo
                            </button>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm px-3" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-brand-primary btn-sm px-3" id="btnSubmitPhoto" disabled>
                            <i class="bi bi-cloud-arrow-up me-1"></i> Save Photo
                        </button>
                    </div>
                </div>
            </form>

            <?php if ($hasPhoto): ?>
            <!-- Hidden Remove Photo Form -->
            <form action="<?php echo $base_path; ?>actions/user_profile_actions" method="POST" id="removePhotoForm" class="d-none">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="remove_photo">
                <input type="hidden" name="redirect_url" value="<?php echo $currentUrl; ?>">
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';
    const fileInput = document.getElementById('profilePhotoFileInput');
    const previewImg = document.getElementById('modalAvatarPreviewImg');
    const fallbackInitials = document.getElementById('modalAvatarFallback');
    const submitBtn = document.getElementById('btnSubmitPhoto');
    const errorAlert = document.getElementById('modalPhotoError');
    const removeBtn = document.getElementById('btnTriggerRemovePhoto');
    const removeForm = document.getElementById('removePhotoForm');

    if (fileInput) {
        fileInput.addEventListener('change', function() {
            errorAlert.classList.add('d-none');
            errorAlert.textContent = '';

            const file = this.files && this.files[0];
            if (!file) {
                submitBtn.disabled = true;
                return;
            }

            // Check size (2 MB = 2,097,152 bytes)
            const maxSize = 2 * 1024 * 1024;
            if (file.size > maxSize) {
                errorAlert.textContent = 'The selected file exceeds 2 MB. Please select a smaller photo.';
                errorAlert.classList.remove('d-none');
                fileInput.value = '';
                submitBtn.disabled = true;
                return;
            }

            // Check extension and mime
            const allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
            const fileName = file.name.toLowerCase();
            const validExt = fileName.endsWith('.jpg') || fileName.endsWith('.jpeg') || fileName.endsWith('.png') || fileName.endsWith('.webp');

            if (!allowedTypes.includes(file.type) && !validExt) {
                errorAlert.textContent = 'Invalid file format. Please upload a JPG, PNG, or WEBP image.';
                errorAlert.classList.remove('d-none');
                fileInput.value = '';
                submitBtn.disabled = true;
                return;
            }

            // Instant client-side preview
            const reader = new FileReader();
            reader.onload = function(e) {
                previewImg.src = e.target.result;
                previewImg.style.display = 'block';
                if (fallbackInitials) {
                    fallbackInitials.style.display = 'none';
                }
                submitBtn.disabled = false;
            };
            reader.readAsDataURL(file);
        });
    }

    if (removeBtn && removeForm) {
        removeBtn.addEventListener('click', function() {
            if (confirm('Are you sure you want to remove your profile photo and revert to default initials?')) {
                removeForm.submit();
            }
        });
    }
})();
</script>
<?php } ?>
