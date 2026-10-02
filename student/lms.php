<?php
require_once '../includes/lms_access.php';

$lmsStudent = requireLmsAccess(['student']);
$page_title = 'My Courses';

$courses = fetchLmsEnrolledSubjects($pdo, (int)$lmsStudent['student_id']);

require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
    <div>
        <div class="text-uppercase small fw-bold text-brand-primary">Learning Management System</div>
        <h1 class="h3 fw-bold text-navy-alt mb-1">My Courses</h1>
        <p class="text-muted mb-0">Subjects from your confirmed section enrollment.</p>
    </div>
</div>

<?php if ($courses): ?>
    <div class="row g-3">
        <?php foreach ($courses as $course): ?>
            <div class="col-12 col-md-6 col-xl-4">
                <article class="card h-100 shadow-sm border-0">
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex justify-content-between gap-3">
                            <span class="badge text-bg-light border align-self-start"><?php echo htmlspecialchars($course['subject_code']); ?></span>
                            <span class="small text-muted text-end"><?php echo htmlspecialchars($course['school_year'] . ' / ' . $course['semester']); ?></span>
                        </div>
                        <h2 class="h5 fw-bold mt-3"><?php echo htmlspecialchars($course['subject_name']); ?></h2>
                        <dl class="small text-muted mb-4">
                            <div><dt class="d-inline">Section:</dt> <dd class="d-inline"><?php echo htmlspecialchars($course['section_name'] ?: 'Confirmed section'); ?></dd></div>
                            <div><dt class="d-inline">Units:</dt> <dd class="d-inline"><?php echo htmlspecialchars((string)$course['units']); ?></dd></div>
                            <div><dt class="d-inline">Instructor:</dt> <dd class="d-inline"><?php echo htmlspecialchars($course['instructor_name'] ?: 'Not assigned'); ?></dd></div>
                            <div><dt class="d-inline">Schedule:</dt> <dd class="d-inline"><?php echo htmlspecialchars(trim(($course['day_of_week'] ?? '') . ' ' . ($course['start_time'] ?? '') . ' - ' . ($course['end_time'] ?? '')) ?: 'Not scheduled'); ?></dd></div>
                        </dl>
                        <?php
                            $courseProgress = fetchLmsCourseProgress($pdo, (int)$lmsStudent['student_id'], (int)$course['subject_id']);
                        ?>
                        <div class="mb-3" aria-label="Course progress based on completed learning activities">
                            <div class="d-flex justify-content-between align-items-center gap-2 small mb-1">
                                <span class="fw-semibold">Course progress</span>
                                <span><?php echo (int)$courseProgress['percent']; ?>%</span>
                            </div>
                            <div class="progress" role="progressbar" aria-label="<?php echo htmlspecialchars($course['subject_name']); ?> course progress" aria-valuenow="<?php echo (int)$courseProgress['percent']; ?>" aria-valuemin="0" aria-valuemax="100" style="height: 7px;">
                                <div class="progress-bar" style="width: <?php echo (int)$courseProgress['percent']; ?>%; background-color: var(--brand-primary);"></div>
                            </div>
                            <div class="small text-muted mt-1"><?php echo (int)$courseProgress['completed']; ?> of <?php echo (int)$courseProgress['total']; ?> learning activities complete</div>
                        </div>
                        <a class="btn btn-brand-primary mt-auto" href="lms_course?subject_id=<?php echo (int)$course['subject_id']; ?>">
                            Open Course <i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>
                        </a>
                    </div>
                </article>
            </div>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="border rounded p-4 bg-white">
        <h2 class="h5 fw-bold">No confirmed subjects yet</h2>
        <p class="text-muted mb-0">Your courses will appear here after the Registrar confirms your section enrollment.</p>
    </div>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
