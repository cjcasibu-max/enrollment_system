<?php
/** Printable/downloadable Certificate of Registration for the signed-in student. */
require_once '../includes/auth_check.php';
checkRole(['student']);
require_once '../config/database.php';

$student = null;
$enrollments = [];
$term = null;
try {
    $studentStmt = $pdo->prepare('SELECT s.id, s.first_name, s.middle_name, s.last_name, s.program_applying_for, s.year_level, s.academic_term_id FROM students s WHERE s.user_id = :user_id LIMIT 1');
    $studentStmt->execute(['user_id' => (int)$_SESSION['user_id']]);
    $student = $studentStmt->fetch();
    if ($student) {
        $termStmt = $pdo->prepare('SELECT school_year, semester FROM academic_terms WHERE id = :id LIMIT 1');
        $termStmt->execute(['id' => (int)$student['academic_term_id']]);
        $term = $termStmt->fetch();
        $enrollmentStmt = $pdo->prepare(
            "SELECT 'subject' AS row_type,
                    sub.subject_code AS course_code,
                    sub.subject_name AS course_name,
                    sub.units,
                    sec.section_name, sec.schedule, sec.room,
                    u.username AS teacher_name
             FROM enrollments e
             JOIN sections sec        ON sec.id = e.section_id
             JOIN section_subjects ss ON ss.section_id = sec.id
             JOIN subjects sub        ON sub.id = ss.subject_id
             LEFT JOIN users u        ON u.id = sec.teacher_id
             WHERE e.student_id = :student_id_a
               AND e.academic_term_id = :term_id_a
               AND e.status = 'enrolled'
             UNION ALL
             SELECT 'legacy' AS row_type,
                    COALESCE(c.course_code, sec.section_name, 'Section') AS course_code,
                    COALESCE(c.course_name, sec.section_name, 'Section') AS course_name,
                    COALESCE(c.units, 0) AS units,
                    sec.section_name, sec.schedule, sec.room,
                    u.username AS teacher_name
             FROM enrollments e
             JOIN sections sec     ON sec.id = e.section_id
             LEFT JOIN courses c   ON c.id = sec.course_id
             LEFT JOIN users u     ON u.id = sec.teacher_id
             WHERE e.student_id = :student_id_b
               AND e.academic_term_id = :term_id_b
               AND e.status = 'enrolled'
               AND NOT EXISTS (SELECT 1 FROM section_subjects WHERE section_id = sec.id)
             ORDER BY section_name, course_code"
        );

        $enrollmentStmt->execute([
            'student_id_a' => (int)$student['id'],
            'term_id_a'    => (int)$student['academic_term_id'],
            'student_id_b' => (int)$student['id'],
            'term_id_b'    => (int)$student['academic_term_id'],
        ]);
        $enrollments = $enrollmentStmt->fetchAll();
    }
} catch (PDOException $e) {
    error_log('COR generation failed: ' . $e->getMessage());
}

if (isset($_GET['download'])) {
    if ($student && !empty($enrollments)) {
        require_once '../includes/pdf_helper.php';
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="certificate-of-registration.pdf"');
        echo generateCorPdf($student, $term, $enrollments);
        exit;
    }
}
$page_title = 'Certificate of Registration';
require_once '../includes/header.php';
?>
<style>@media print {.sidebar,.top-navbar,.footer,.no-print{display:none!important}.main-wrapper,.content-body{margin:0!important;padding:0!important}.cor-document{box-shadow:none!important;border-color:#000!important}}</style>
<div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2 no-print"><div><h3 class="m-0 text-navy-alt">Certificate of Registration</h3><p class="text-muted small m-0">Your currently approved sections for the active academic term.</p></div><div class="d-flex gap-2"><a class="btn btn-outline-secondary" href="my_enrollments"><i class="bi bi-calendar-week me-1"></i>Class Schedule</a><a class="btn btn-outline-secondary" href="cor?download=pdf"><i class="bi bi-download me-1"></i>Download PDF</a><button class="btn btn-brand-primary" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print / Save PDF</button></div></div>
<?php if (!$student): ?><div class="alert alert-warning">Your student profile could not be found.</div>
<?php elseif (!$enrollments): ?><div class="card card-premium shadow-sm"><div class="card-body card-body-premium text-center py-5"><i class="bi bi-journal-x fs-1 text-muted d-block mb-3"></i><h5>No approved registrations</h5><p class="text-muted mb-0">Your COR becomes available after the Registrar approves at least one section registration for the active term.</p></div></div>
<?php else: $totalUnits = array_sum(array_map(static fn($row) => (float)$row['units'], $enrollments)); ?>
<article class="cor-document card card-premium shadow-sm mx-auto" style="max-width:960px"><div class="card-body card-body-premium p-4 p-md-5"><div class="text-center border-bottom pb-3 mb-4"><h4 class="mb-1">NCST Maritime Academy</h4><div class="text-uppercase text-muted small fw-bold">Certificate of Registration</div><small><?php echo htmlspecialchars(($term['school_year'] ?? 'Current term') . ' · ' . ucfirst($term['semester'] ?? '')); ?> Semester</small></div><div class="row mb-4"><div class="col-md-6"><strong>Student:</strong> <?php echo htmlspecialchars(trim($student['first_name'] . ' ' . $student['middle_name'] . ' ' . $student['last_name'])); ?><br><strong>Student ID:</strong> #<?php echo (int)$student['id']; ?></div><div class="col-md-6"><strong>Program:</strong> <?php echo !empty($student['program_applying_for']) ? htmlspecialchars($student['program_applying_for']) : '<span class="text-muted">&mdash;</span>'; ?><br><strong>Year Level:</strong> <?php echo (!empty($student['year_level']) && strtolower($student['year_level']) !== 'year level') ? htmlspecialchars($student['year_level']) : '<span class="text-muted">&mdash;</span>'; ?></div></div><div class="table-responsive"><table class="table table-bordered align-middle"><thead class="table-light"><tr><th>Course</th><th>Schedule / Room</th><th>Instructor</th><th class="text-end">Units</th></tr></thead><tbody><?php foreach ($enrollments as $row): ?><tr><td><strong><?php echo htmlspecialchars($row['course_code']); ?></strong><br><small><?php echo htmlspecialchars($row['course_name']); ?></small></td><td><?php echo htmlspecialchars($row['schedule']); ?><br><small><?php echo htmlspecialchars($row['room'] ?: 'Room to be announced'); ?></small></td><td><?php echo htmlspecialchars($row['teacher_name'] ?: 'To be assigned'); ?></td><td class="text-end"><?php echo number_format((float)$row['units'], 2); ?></td></tr><?php endforeach; ?></tbody><tfoot><tr><th colspan="3" class="text-end">Total Units</th><th class="text-end"><?php echo number_format($totalUnits, 2); ?></th></tr></tfoot></table></div><p class="small text-muted mb-0">Generated on <?php echo htmlspecialchars(date('F j, Y g:i A')); ?>. This document reflects approved section registrations at the time it was generated.</p></div></article>
<?php endif; ?>
<?php require_once '../includes/footer.php'; ?>
