<?php
/**
 * Class Roster
 * Read-only list of enrolled students for a specific section.
 * Verifies section ownership before displaying data.
 */

require_once '../includes/auth_check.php';
checkRole(['teacher', 'admin']);

require_once '../config/database.php';

$sectionId = isset($_GET['section_id']) ? (int)$_GET['section_id'] : 0;
$userId = (int)$_SESSION['user_id'];
$userRole = $_SESSION['role'];
$section = null;
$students = [];

if ($sectionId > 0) {
    try {
        // Fetch section with ownership check built into the query for teachers
        if ($userRole === 'teacher') {
            $secStmt = $pdo->prepare("
                SELECT s.id, s.schedule, s.room, s.capacity, s.teacher_id,
                       COALESCE(c.course_code, s.section_name, 'Section') AS course_code,
                       COALESCE(c.course_name, s.section_name, 'Block Section') AS course_name,
                       COALESCE(c.units, 0) AS units
                FROM sections s
                LEFT JOIN courses c ON s.course_id = c.id
                WHERE s.id = :section_id
                  AND (
                      s.teacher_id = :teacher_id_a
                      OR EXISTS (
                          SELECT 1 FROM section_subjects ss
                          WHERE ss.section_id = s.id AND ss.instructor_id = :teacher_id_b
                      )
                  )
                LIMIT 1
            ");
            $secStmt->execute([
                'section_id'   => $sectionId,
                'teacher_id_a' => $userId,
                'teacher_id_b' => $userId,
            ]);
        } else {
            $secStmt = $pdo->prepare("
                SELECT s.id, s.schedule, s.room, s.capacity, s.teacher_id,
                       COALESCE(c.course_code, s.section_name, 'Section') AS course_code,
                       COALESCE(c.course_name, s.section_name, 'Block Section') AS course_name,
                       COALESCE(c.units, 0) AS units,
                       u.username AS teacher_username
                FROM sections s
                LEFT JOIN courses c ON s.course_id = c.id
                LEFT JOIN users u ON s.teacher_id = u.id
                WHERE s.id = :section_id
                LIMIT 1
            ");
            $secStmt->execute(['section_id' => $sectionId]);
        }

        $section = $secStmt->fetch();

        if ($section) {
            $stuStmt = $pdo->prepare("
                SELECT st.first_name, st.last_name, st.contact_number,
                       u.email, u.username, u.profile_picture, u.id AS user_id
                FROM enrollments e
                JOIN students st ON e.student_id = st.id
                JOIN users u ON st.user_id = u.id
                WHERE e.section_id = :section_id
                  AND e.status = 'enrolled'
                ORDER BY st.last_name ASC, st.first_name ASC
            ");
            $stuStmt->execute(['section_id' => $sectionId]);
            $students = $stuStmt->fetchAll();
        }

    } catch (\PDOException $e) {
        error_log("Fetch class list failed: " . $e->getMessage());
        $section = null;
        $students = [];
    }
}

// Fetch available sections for selector/switcher
$availableSections = [];
try {
    if ($userRole === 'teacher') {
        $secListStmt = $pdo->prepare("
            SELECT s.id, s.section_name, s.schedule, s.room, s.capacity,
                   COALESCE(c.course_code, s.section_name, 'Section') AS course_code,
                   COALESCE(c.course_name, s.section_name, 'Block Section') AS course_name,
                   COALESCE(c.units, 0) AS units,
                   (SELECT COUNT(*) FROM enrollments e WHERE e.section_id = s.id AND e.status = 'enrolled') AS enrolled_count
            FROM sections s
            LEFT JOIN courses c ON s.course_id = c.id
            WHERE s.teacher_id = :tid_a
               OR EXISTS (
                   SELECT 1 FROM section_subjects ss
                   WHERE ss.section_id = s.id AND ss.instructor_id = :tid_b
               )
            ORDER BY course_code ASC, s.section_name ASC
        ");
        $secListStmt->execute(['tid_a' => $userId, 'tid_b' => $userId]);
    } else {
        $secListStmt = $pdo->query("
            SELECT s.id, s.section_name, s.schedule, s.room, s.capacity,
                   COALESCE(c.course_code, s.section_name, 'Section') AS course_code,
                   COALESCE(c.course_name, s.section_name, 'Block Section') AS course_name,
                   COALESCE(c.units, 0) AS units,
                   (SELECT COUNT(*) FROM enrollments e WHERE e.section_id = s.id AND e.status = 'enrolled') AS enrolled_count
            FROM sections s
            LEFT JOIN courses c ON s.course_id = c.id
            ORDER BY course_code ASC, s.section_name ASC
        ");
    }
    $availableSections = $secListStmt->fetchAll();
} catch (\PDOException $e) {
    error_log("Fetch available sections for class list failed: " . $e->getMessage());
}

// Teacher attempted to access a section not assigned to them
if ($sectionId > 0 && !$section && $userRole === 'teacher') {
    $_SESSION['flash_error'] = "Access denied: This section is not assigned to you.";
    header("Location: my_classes");
    exit;
}

$page_title = "Class Lists";
require_once '../includes/header.php';
?>

<div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h3 class="m-0 text-navy-alt">Class Lists</h3>
        <p class="text-muted small m-0">View enrolled students for an assigned class section.</p>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <?php if ($sectionId > 0 && !empty($availableSections)): ?>
            <form method="get" action="class_list" class="d-inline-flex align-items-center gap-2 m-0">
                <label for="switchSection" class="small fw-semibold text-secondary m-0">Section:</label>
                <select name="section_id" id="switchSection" class="form-select form-select-sm bg-white shadow-sm" onchange="this.form.submit()" style="max-width: 260px; border: 1px solid #cbd5e1; border-radius: 6px;">
                    <?php foreach ($availableSections as $secOpt): ?>
                        <option value="<?php echo (int)$secOpt['id']; ?>" <?php echo (int)$secOpt['id'] === $sectionId ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($secOpt['course_code'] . ' (' . $secOpt['section_name'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>
        <a href="my_classes" class="btn btn-outline-secondary btn-sm d-flex align-items-center gap-1 shadow-sm">
            <i class="bi bi-arrow-left"></i> Back to My Classes
        </a>
    </div>
</div>

<?php if ($sectionId === 0): ?>
    <div class="card card-premium shadow-sm border-0 mb-4" style="border-radius: 12px;">
        <div class="card-body card-body-premium py-4 px-3 px-md-5">
            <div class="text-center mx-auto mb-4" style="max-width: 620px;">
                <div class="mb-3">
                    <span class="d-inline-flex align-items-center justify-content-center bg-secondary-subtle text-brand-primary rounded-circle" style="width: 56px; height: 56px; font-size: 1.5rem;">
                        <i class="bi bi-list-stars"></i>
                    </span>
                </div>
                <h4 class="text-navy-alt fw-bold mb-1">Select a Class Section</h4>
                <p class="text-muted small mb-4">Choose an assigned section from the dropdown or click a card below to view its enrolled student class list.</p>
                
                <?php if (!empty($availableSections)): ?>
                    <form method="get" action="class_list" class="d-flex flex-column flex-sm-row gap-2 justify-content-center">
                        <label for="sectionSelector" class="visually-hidden">Choose Section</label>
                        <select name="section_id" id="sectionSelector" class="form-select bg-white shadow-sm" required style="border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.92rem;">
                            <option value="">-- Choose a Section --</option>
                            <?php foreach ($availableSections as $secOpt): ?>
                                <option value="<?php echo (int)$secOpt['id']; ?>">
                                    <?php echo htmlspecialchars($secOpt['course_code'] . ' (' . $secOpt['section_name'] . ') — ' . $secOpt['course_name'] . ' [' . (int)$secOpt['enrolled_count'] . ' Enrolled]'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-brand-primary text-nowrap px-4 shadow-sm" style="border-radius: 8px; font-size: 0.92rem;">
                            <i class="bi bi-arrow-right-circle me-1"></i> View Class List
                        </button>
                    </form>
                <?php else: ?>
                    <div class="alert alert-info d-inline-flex align-items-center gap-2 text-start">
                        <i class="bi bi-info-circle fs-5"></i>
                        <div>No assigned sections found for your faculty account.</div>
                    </div>
                    <div class="mt-2">
                        <a href="my_classes" class="btn btn-outline-secondary btn-sm">Go to My Classes</a>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($availableSections)): ?>
                <hr class="my-4" style="border-color: #e2e8f0;">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="m-0 fw-semibold text-navy-alt">
                        <i class="bi bi-grid me-1.5 text-brand-primary"></i> Assigned Sections
                    </h6>
                    <span class="text-muted small"><?php echo count($availableSections); ?> Available</span>
                </div>
                <div class="row g-3">
                    <?php foreach ($availableSections as $secCard): ?>
                        <div class="col-12 col-md-6 col-lg-4">
                            <a href="class_list?section_id=<?php echo (int)$secCard['id']; ?>" class="card h-100 text-decoration-none section-card-hover border shadow-sm" style="border-radius: 10px; border-color: #e2e8f0; background: #ffffff;">
                                <div class="card-body p-3.5 d-flex flex-column justify-content-between">
                                    <div>
                                        <div class="d-flex justify-content-between align-items-start gap-2 mb-1.5">
                                            <span class="badge bg-secondary-subtle text-brand-primary border fw-semibold px-2 py-1" style="font-size: 0.72rem;">
                                                <?php echo htmlspecialchars($secCard['course_code']); ?>
                                            </span>
                                            <span class="badge bg-light text-secondary border px-2 py-1" style="font-size: 0.7rem;">
                                                Sec #<?php echo (int)$secCard['id']; ?>
                                            </span>
                                        </div>
                                        <h6 class="fw-bold text-navy-alt mb-1 text-truncate" title="<?php echo htmlspecialchars($secCard['course_name']); ?>">
                                            <?php echo htmlspecialchars($secCard['course_name']); ?>
                                        </h6>
                                        <div class="text-muted small mb-2 text-truncate" style="font-size: 0.78rem;">
                                            <?php echo htmlspecialchars($secCard['section_name']); ?>
                                        </div>
                                    </div>
                                    <div class="pt-2 border-top d-flex justify-content-between align-items-center text-muted small" style="font-size: 0.75rem; border-color: #f1f5f9 !important;">
                                        <span class="d-inline-flex align-items-center gap-1 text-truncate" style="max-width: 60%;">
                                            <i class="bi bi-clock text-brand-primary"></i> <?php echo htmlspecialchars($secCard['schedule'] ?: 'TBA'); ?>
                                        </span>
                                        <span class="fw-semibold text-brand-primary d-inline-flex align-items-center gap-1">
                                            <i class="bi bi-people-fill"></i> <?php echo (int)$secCard['enrolled_count']; ?> Enrolled
                                        </span>
                                    </div>
                                </div>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php elseif (!$section): ?>
    <div class="card card-premium shadow-sm border-0" style="border-radius: 12px;">
        <div class="card-body card-body-premium text-center py-5">
            <i class="bi bi-exclamation-triangle fs-1 text-warning d-block mb-3 opacity-75"></i>
            <h5 class="text-navy-alt">Section Not Found</h5>
            <p class="text-muted mb-0">No section exists for ID #<?php echo $sectionId; ?>.</p>
        </div>
    </div>

<?php else: ?>
    <!-- Section Summary Card -->
    <div class="card card-premium shadow-sm mb-4 border-0" style="border-left: 4px solid var(--brand-primary, #008080) !important; border-radius: 10px; background: #ffffff;">
        <div class="card-body card-body-premium p-3.5">
            <div class="row g-3 align-items-center">
                <div class="col-12 col-md-8">
                    <div class="text-muted small text-uppercase fw-bold mb-1" style="font-size: 0.7rem; letter-spacing: 0.5px;">Course &amp; Section</div>
                    <h5 class="m-0 fw-bold text-navy-alt">
                        <?php echo htmlspecialchars($section['course_code'] . ' — ' . $section['course_name']); ?>
                    </h5>
                    <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
                        <span class="badge bg-light text-navy border px-2.5 py-1">
                            <i class="bi bi-clock text-brand-primary me-1"></i><?php echo htmlspecialchars($section['schedule'] ?: 'TBA'); ?>
                        </span>
                        <span class="badge bg-light text-navy border px-2.5 py-1">
                            <i class="bi bi-geo-alt text-brand-primary me-1"></i><?php echo htmlspecialchars($section['room'] ?: 'TBA'); ?>
                        </span>
                        <span class="badge bg-light text-navy border px-2.5 py-1">
                            <i class="bi bi-journal-check text-brand-primary me-1"></i><?php echo (int)$section['units']; ?> units
                        </span>
                        <span class="badge bg-secondary-subtle text-brand-primary border px-2.5 py-1 fw-semibold">
                            <i class="bi bi-people-fill me-1"></i><?php echo count($students); ?> Enrolled
                        </span>
                    </div>
                </div>
                <?php if ($userRole === 'admin' && !empty($section['teacher_username'])): ?>
                    <div class="col-12 col-md-4 text-md-end">
                        <div class="text-muted small">
                            Instructor: <strong><?php echo htmlspecialchars($section['teacher_username']); ?></strong>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Enrolled Students Table Card -->
    <div class="card card-premium shadow-sm border-0" style="border-radius: 12px; overflow: hidden;">
        <div class="card-header card-header-premium d-flex justify-content-between align-items-center gap-3 flex-wrap">
            <h5 class="card-title m-0 fw-semibold text-navy-alt">
                <i class="bi bi-people me-1"></i> Enrolled Students
            </h5>
            <div class="d-flex align-items-center gap-2 ms-auto">
                <label class="visually-hidden" for="classRosterSearch">Search students</label>
                <div class="input-group input-group-sm" style="min-width: 260px;">
                    <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                    <input type="search" id="classRosterSearch" class="form-control" placeholder="Search student or email..." aria-label="Search students">
                </div>
            </div>
        </div>

        <div class="card-body card-body-premium p-0" style="overflow-x: hidden;">
            <div class="w-100" style="overflow-x: hidden;">
                <table class="table table-hover align-middle m-0" id="classRosterTable" style="width: 100%;">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4" tabulator-field="row_num" style="padding-left: 24px !important;">#</th>
                            <th tabulator-field="student">Student Name</th>
                            <th tabulator-field="email">Email</th>
                            <th class="pe-4" tabulator-field="contact_number" style="padding-right: 24px !important;">Contact Number</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($students)): ?>
                            <?php foreach ($students as $i => $st): ?>
                                <?php
                                $studentUserId = (int)($st['user_id'] ?? 0);
                                $hasPic = !empty($st['profile_picture']);
                                $stInitials = strtoupper(substr($st['first_name'], 0, 1) . substr($st['last_name'], 0, 1));
                                if (!$stInitials) {
                                    $stInitials = strtoupper(substr($st['username'] ?? 'S', 0, 2));
                                }
                                $picSrc = $hasPic ? "../actions/view_avatar?uid={$studentUserId}" : '';
                                ?>
                                <tr>
                                    <td class="ps-4 text-muted small fw-medium" style="padding-left: 24px !important;"><?php echo $i + 1; ?></td>
                                    <td>
                                        <div class="d-flex align-items-center py-1" style="gap: 12px;">
                                            <div class="rounded-circle overflow-hidden flex-shrink-0 d-flex align-items-center justify-content-center" 
                                                 style="width: 36px; height: 36px; background: #e6f2f2; border: 1.5px solid rgba(0,128,128,0.2); font-size: 0.8rem; font-weight: 600; color: #005a5a;">
                                                <?php if ($hasPic): ?>
                                                    <img src="<?php echo htmlspecialchars($picSrc); ?>" 
                                                         alt="<?php echo htmlspecialchars($stInitials); ?>" 
                                                         class="w-100 h-100" 
                                                         style="object-fit: cover;"
                                                         onerror="this.style.display='none'; if(this.nextElementSibling) this.nextElementSibling.style.display='flex';">
                                                    <span style="display: none; align-items: center; justify-content: center; width: 100%; height: 100%;"><?php echo htmlspecialchars($stInitials); ?></span>
                                                <?php else: ?>
                                                    <span><?php echo htmlspecialchars($stInitials); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="d-flex flex-column" style="line-height: 1.25;">
                                                <span class="fw-semibold text-darker" style="font-size: 0.9rem;">
                                                    <?php echo htmlspecialchars($st['first_name'] . ' ' . $st['last_name']); ?>
                                                </span>
                                                <span class="text-muted small" style="font-size: 0.76rem;">
                                                    @<?php echo htmlspecialchars($st['username']); ?>
                                                </span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if (!empty($st['email'])): ?>
                                            <a href="mailto:<?php echo htmlspecialchars($st['email']); ?>" class="email-link d-inline-flex align-items-center gap-1.5 text-decoration-none small">
                                                <i class="bi bi-envelope text-brand-primary opacity-75"></i>
                                                <span><?php echo htmlspecialchars($st['email']); ?></span>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="pe-4" style="padding-right: 24px !important;">
                                        <?php if (!empty($st['contact_number'])): ?>
                                            <span class="text-secondary small fw-medium d-inline-flex align-items-center gap-1">
                                                <i class="bi bi-telephone text-muted opacity-75"></i>
                                                <?php echo htmlspecialchars($st['contact_number']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" class="text-center py-5 text-muted">
                                    <i class="bi bi-person-x fs-1 d-block mb-2 text-muted-light"></i>
                                    No students enrolled in this section yet.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<style>
.section-card-hover {
    transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
}
.section-card-hover:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0, 128, 128, 0.12) !important;
    border-color: rgba(0, 128, 128, 0.4) !important;
}
.email-link {
    color: #475569 !important;
    transition: color 0.15s ease, text-decoration 0.15s ease;
}
.email-link:hover {
    color: #008080 !important;
    text-decoration: underline !important;
}
.tabulator {
    font-family: inherit;
    border: none;
    background-color: transparent;
    width: 100% !important;
    min-width: 0 !important;
    overflow-x: hidden !important;
}
.tabulator .tabulator-tableholder {
    overflow-x: hidden !important;
}
.tabulator .tabulator-header {
    background: linear-gradient(180deg, #f0fdfa 0%, #e6f7f7 100%) !important;
    border-bottom: 1px solid rgba(0, 128, 128, 0.2) !important;
    color: #005a5a !important;
    font-weight: 600;
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.tabulator .tabulator-header .tabulator-col {
    background: transparent !important;
    border-right: none !important;
    padding: 10px 8px !important;
}
.tabulator .tabulator-header .tabulator-col:first-child,
.tabulator .tabulator-row .tabulator-cell:first-child {
    padding-left: 24px !important;
}
.tabulator .tabulator-header .tabulator-col:last-child,
.tabulator .tabulator-row .tabulator-cell:last-child {
    padding-right: 24px !important;
}
.tabulator .tabulator-header .tabulator-col .tabulator-col-content {
    padding: 0 !important;
}
.tabulator .tabulator-header .tabulator-col .tabulator-col-content .tabulator-col-title-holder {
    display: inline-flex;
    align-items: center;
    max-width: 100%;
}
.tabulator .tabulator-header .tabulator-col.tabulator-sortable .tabulator-col-title {
    padding-right: 0 !important;
    white-space: normal !important;
    overflow: visible !important;
    text-overflow: clip !important;
}
.tabulator .tabulator-header .tabulator-col .tabulator-col-sorter {
    position: static !important;
    margin-left: 5px;
    display: inline-flex !important;
    align-items: center;
    flex-shrink: 0;
}
.tabulator .tabulator-row {
    border-bottom: 1px solid #f1f5f9 !important;
    min-height: 54px;
    height: auto !important;
    background: #ffffff !important;
    transition: background-color 0.15s ease;
}
.tabulator .tabulator-row:hover {
    background-color: #f8fafc !important;
}
.tabulator .tabulator-row .tabulator-cell {
    padding: 8px 8px !important;
    border-right: none !important;
    vertical-align: middle;
    font-size: 0.86rem;
    height: auto !important;
    white-space: normal !important;
    word-break: break-word;
    overflow-wrap: break-word;
    display: inline-flex;
    align-items: center;
    min-width: 0 !important;
}
.tabulator .tabulator-footer {
    background: #ffffff;
    border-top: 1px solid #e2e8f0;
    padding: 8px 16px;
}

@media (max-width: 767px) {
    .tabulator .tabulator-header {
        display: none !important;
    }
    .tabulator .tabulator-table {
        display: flex !important;
        flex-direction: column !important;
        gap: 12px;
        padding: 8px !important;
    }
    .tabulator .tabulator-row {
        display: flex !important;
        flex-direction: column !important;
        border: 1px solid #e2e8f0 !important;
        border-radius: 10px !important;
        box-shadow: 0 1px 4px rgba(0,0,0,0.05) !important;
        padding: 12px 16px !important;
        margin-bottom: 8px !important;
        height: auto !important;
        min-height: auto !important;
        background: #ffffff !important;
    }
    .tabulator .tabulator-row .tabulator-cell {
        display: flex !important;
        justify-content: space-between !important;
        align-items: center !important;
        width: 100% !important;
        max-width: 100% !important;
        padding: 6px 0 !important;
        border-bottom: 1px dashed #f1f5f9 !important;
    }
    .tabulator .tabulator-row .tabulator-cell:last-child {
        border-bottom: none !important;
    }
    .tabulator .tabulator-row .tabulator-cell[tabulator-field="row_num"]::before {
        content: "#";
        font-weight: 600;
        font-size: 0.75rem;
        color: #64748b;
        margin-right: 8px;
    }
    .tabulator .tabulator-row .tabulator-cell[tabulator-field="student"]::before {
        content: "Student";
        font-weight: 600;
        font-size: 0.75rem;
        text-transform: uppercase;
        color: #64748b;
        margin-right: 8px;
    }
    .tabulator .tabulator-row .tabulator-cell[tabulator-field="email"]::before {
        content: "Email";
        font-weight: 600;
        font-size: 0.75rem;
        text-transform: uppercase;
        color: #64748b;
        margin-right: 8px;
    }
    .tabulator .tabulator-row .tabulator-cell[tabulator-field="contact_number"]::before {
        content: "Contact";
        font-weight: 600;
        font-size: 0.75rem;
        text-transform: uppercase;
        color: #64748b;
        margin-right: 8px;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var classRosterTableEl = document.getElementById('classRosterTable');
    if (classRosterTableEl) {
        function stripHtml(html) {
            var tmp = document.createElement('div');
            tmp.innerHTML = html;
            return tmp.textContent || tmp.innerText || '';
        }

        var classRosterTable = new Tabulator("#classRosterTable", {
            layout: "fitColumns",
            pagination: "local",
            paginationSize: 25,
            paginationSizeSelector: [10, 25, 50, 100],
            placeholder: "<div class='text-center py-5 text-muted'><i class='bi bi-person-x fs-1 d-block mb-2 text-muted-light'></i>No students enrolled in this section yet.</div>",
            columns: [
                { title: "#", field: "row_num", width: 60, hozAlign: "center", headerHozAlign: "center", sorter: "number", formatter: "html" },
                { title: "Student Name", field: "student", widthGrow: 4.0, minWidth: 160, formatter: "html" },
                { title: "Email", field: "email", widthGrow: 3.0, minWidth: 140, formatter: "html" },
                { title: "Contact Number", field: "contact_number", widthGrow: 2.0, minWidth: 120, formatter: "html" }
            ]
        });

        var searchInput = document.getElementById('classRosterSearch');
        if (searchInput) {
            searchInput.addEventListener('input', function () {
                var term = this.value.trim().toLowerCase();
                if (!term) {
                    classRosterTable.clearFilter();
                } else {
                    classRosterTable.setFilter(function (data) {
                        return stripHtml(data.student).toLowerCase().includes(term) ||
                               stripHtml(data.email).toLowerCase().includes(term) ||
                               stripHtml(data.contact_number).toLowerCase().includes(term);
                    });
                }
            });
        }
    }
});
</script>

<?php
require_once '../includes/footer.php';
?>
