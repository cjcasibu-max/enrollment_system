<?php
/**
 * My Assigned Classes
 * Read-only list of sections assigned to the logged-in instructor.
 */

require_once '../includes/auth_check.php';
checkRole(['teacher', 'admin']);

require_once '../config/database.php';

$userId = (int)$_SESSION['user_id'];
$userRole = $_SESSION['role'];

try {
    if ($userRole === 'teacher') {
        $stmt = $pdo->prepare("
            SELECT s.id, s.schedule, s.room, s.capacity,
                   COALESCE(c.course_code, s.section_name, 'Section') AS course_code,
                   COALESCE(c.course_name, s.section_name, 'Block Section') AS course_name,
                   COALESCE(c.units, 0) AS units,
                   (s.teacher_id = :teacher_id_a) AS is_lead,
                   (SELECT COUNT(*) FROM enrollments e
                    WHERE e.section_id = s.id AND e.status = 'enrolled') AS enrolled_count
            FROM sections s
            LEFT JOIN courses c ON s.course_id = c.id
            WHERE s.teacher_id = :teacher_id_b
               OR EXISTS (
                   SELECT 1 FROM section_subjects ss
                   WHERE ss.section_id = s.id AND ss.instructor_id = :teacher_id_c
               )
            ORDER BY course_code ASC, s.schedule ASC
        ");
        $stmt->execute([
            'teacher_id_a' => $userId,
            'teacher_id_b' => $userId,
            'teacher_id_c' => $userId
        ]);
    } else {
        // Admin oversight: view all sections with assigned instructor
        $stmt = $pdo->query("
            SELECT s.id, s.schedule, s.room, s.capacity,
                   COALESCE(c.course_code, s.section_name, 'Section') AS course_code,
                   COALESCE(c.course_name, s.section_name, 'Block Section') AS course_name,
                   COALESCE(c.units, 0) AS units,
                   1 AS is_lead,
                   u.username AS teacher_username,
                   (SELECT COUNT(*) FROM enrollments e
                    WHERE e.section_id = s.id AND e.status = 'enrolled') AS enrolled_count
            FROM sections s
            LEFT JOIN courses c ON s.course_id = c.id
            LEFT JOIN users u ON s.teacher_id = u.id
            ORDER BY course_code ASC, s.schedule ASC
        ");
    }
    $sections = $stmt->fetchAll();
} catch (\PDOException $e) {
    error_log("Fetch teacher sections failed: " . $e->getMessage());
    $sections = [];
}

$page_title = "My Classes";
require_once '../includes/header.php';
?>

<div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h3 class="m-0 text-navy-alt">My Assigned Classes</h3>
        <p class="text-muted small m-0">
            <?php if ($userRole === 'teacher'): ?>
                View your scheduled sections, room assignments, and enrolled student counts.
            <?php else: ?>
                Overview of all class sections and their assigned instructors.
            <?php endif; ?>
        </p>
    </div>
    <span class="badge bg-secondary-subtle text-brand-primary border border-secondary px-2.5 py-1 text-uppercase fw-semibold" style="font-size: 0.7rem;">
        <?php echo count($sections); ?> Section<?php echo count($sections) !== 1 ? 's' : ''; ?>
    </span>
</div>

<div class="card card-premium shadow-sm border-0" style="border-radius: 12px; overflow: hidden;">
    <div class="card-header card-header-premium d-flex justify-content-between align-items-center gap-3 flex-wrap">
        <h5 class="card-title m-0 fw-semibold text-navy-alt">
            <i class="bi bi-door-open me-1"></i>
            <?php echo $userRole === 'teacher' ? 'Assigned Sections' : 'All Sections'; ?>
        </h5>
        <div class="d-flex align-items-center gap-2 ms-auto">
            <label class="visually-hidden" for="myClassesSearch">Search sections</label>
            <div class="input-group input-group-sm" style="min-width: 260px;">
                <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                <input type="search" id="myClassesSearch" class="form-control" placeholder="Search sections or courses..." aria-label="Search sections">
            </div>
        </div>
    </div>

    <div class="card-body card-body-premium p-0">
        <div class="w-100">
            <table class="table table-hover align-middle m-0" id="myClassesTable" style="width: 100%;">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4" tabulator-field="course" style="padding-left: 24px !important;">Course</th>
                        <th tabulator-field="schedule">Schedule</th>
                        <th tabulator-field="room">Room</th>
                        <?php if ($userRole === 'admin'): ?>
                            <th tabulator-field="instructor">Instructor</th>
                        <?php endif; ?>
                        <th tabulator-field="enrolled" class="text-center">Enrolled</th>
                        <th class="pe-4 text-end" tabulator-field="actions" style="padding-right: 24px !important;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($sections)): ?>
                        <?php foreach ($sections as $sec): ?>
                            <tr>
                                <td class="ps-4" style="padding-left: 24px !important;">
                                    <div class="d-flex flex-column gap-1 py-1 w-100" style="line-height: 1.35;">
                                        <div>
                                            <span class="fw-bold text-navy-alt fs-6 me-1"><?php echo htmlspecialchars($sec['course_code']); ?></span>
                                            <span class="text-darker fw-semibold"><?php echo htmlspecialchars($sec['course_name']); ?></span>
                                        </div>
                                        <div class="text-muted small d-flex flex-wrap align-items-center gap-1.5" style="font-size: 0.75rem;">
                                            <span><?php echo (int)$sec['units']; ?> units &middot; Section #<?php echo (int)$sec['id']; ?></span>
                                            <?php if ($userRole === 'teacher'): ?>
                                                <span class="d-inline-flex align-items-center gap-1">&middot; 
                                                    <span class="badge <?php echo !empty($sec['is_lead']) ? 'bg-primary-subtle text-primary border' : 'bg-info-subtle text-info border'; ?>" style="font-size: 0.65rem;">
                                                        <?php echo !empty($sec['is_lead']) ? 'Section Lead' : 'Subject Instructor'; ?>
                                                    </span>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center gap-1.5 text-navy-alt py-1">
                                        <i class="bi bi-clock text-brand-primary small flex-shrink-0"></i>
                                        <span class="fw-medium text-darker"><?php echo htmlspecialchars($sec['schedule'] ?: 'TBA'); ?></span>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-secondary-subtle text-navy border px-2.5 py-1" style="font-weight: 500;">
                                        <i class="bi bi-geo-alt text-brand-primary me-1"></i><?php echo htmlspecialchars($sec['room'] ?: 'TBA'); ?>
                                    </span>
                                </td>
                                <?php if ($userRole === 'admin'): ?>
                                    <td>
                                        <span class="text-muted small">
                                            <?php echo htmlspecialchars($sec['teacher_username'] ?? 'Unassigned'); ?>
                                        </span>
                                    </td>
                                <?php endif; ?>
                                <td>
                                    <?php
                                    $enrolled = (int)$sec['enrolled_count'];
                                    $capacity = (int)$sec['capacity'];
                                    $pct = ($capacity > 0) ? min(100, round(($enrolled / $capacity) * 100)) : 0;
                                    if ($pct >= 100) {
                                        $barBg = '#dc3545';
                                        $badgeTextClass = 'text-danger';
                                    } elseif ($pct >= 80) {
                                        $barBg = '#f59e0b';
                                        $badgeTextClass = 'text-warning-emphasis';
                                    } else {
                                        $barBg = '#008080';
                                        $badgeTextClass = 'text-navy-alt';
                                    }
                                    ?>
                                    <div class="d-flex flex-column gap-1 mx-auto" style="width: 100%; max-width: 100px;">
                                        <div class="d-flex justify-content-center align-items-center gap-1 small">
                                            <span class="fw-bold <?php echo $badgeTextClass; ?>"><?php echo $enrolled; ?></span>
                                            <span class="text-muted" style="font-size: 0.75rem;">/ <?php echo $capacity; ?></span>
                                        </div>
                                        <div class="progress" style="height: 4px; background: rgba(0,128,128,0.12); border-radius: 2px;">
                                            <div class="progress-bar" role="progressbar" style="width: <?php echo $pct; ?>%; background-color: <?php echo $barBg; ?>; border-radius: 2px;" aria-valuenow="<?php echo $pct; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="pe-4 text-end" style="padding-right: 24px !important;">
                                    <div class="d-flex align-items-center justify-content-end flex-wrap" style="gap: 8px;">
                                        <a href="class_list?section_id=<?php echo (int)$sec['id']; ?>"
                                           class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1" title="View class list">
                                            <i class="bi bi-people"></i> Class List
                                        </a>
                                        <?php if ($userRole === 'teacher'): ?>
                                            <?php if (!empty($sec['is_lead'])): ?>
                                                <a href="attendance?section_id=<?php echo (int)$sec['id']; ?>" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1">
                                                    <i class="bi bi-calendar-check"></i> Attendance
                                                </a>
                                            <?php endif; ?>
                                            <a href="lms_grades?section_id=<?php echo (int)$sec['id']; ?>" class="btn btn-sm btn-brand-primary d-inline-flex align-items-center gap-1 shadow-sm">
                                                <i class="bi bi-award"></i> Grades
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
.tabulator {
    font-family: inherit;
    border: none;
    background-color: transparent;
    width: 100% !important;
    min-width: 0 !important;
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
    justify-content: flex-end !important;
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
    min-height: 56px;
    height: auto !important;
    background: #ffffff !important;
    transition: background-color 0.15s ease;
}
.tabulator .tabulator-row:hover {
    background-color: #f8fafc !important;
}
.tabulator .tabulator-row .tabulator-cell {
    padding: 10px 8px !important;
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
        padding-top: 10px !important;
        justify-content: flex-end !important;
    }
    .tabulator .tabulator-row .tabulator-cell[tabulator-field="course"]::before {
        content: "Course";
        font-weight: 600;
        font-size: 0.75rem;
        text-transform: uppercase;
        color: #64748b;
        margin-right: 8px;
    }
    .tabulator .tabulator-row .tabulator-cell[tabulator-field="schedule"]::before {
        content: "Schedule";
        font-weight: 600;
        font-size: 0.75rem;
        text-transform: uppercase;
        color: #64748b;
        margin-right: 8px;
    }
    .tabulator .tabulator-row .tabulator-cell[tabulator-field="room"]::before {
        content: "Room";
        font-weight: 600;
        font-size: 0.75rem;
        text-transform: uppercase;
        color: #64748b;
        margin-right: 8px;
    }
    .tabulator .tabulator-row .tabulator-cell[tabulator-field="instructor"]::before {
        content: "Instructor";
        font-weight: 600;
        font-size: 0.75rem;
        text-transform: uppercase;
        color: #64748b;
        margin-right: 8px;
    }
    .tabulator .tabulator-row .tabulator-cell[tabulator-field="enrolled"]::before {
        content: "Enrolled";
        font-weight: 600;
        font-size: 0.75rem;
        text-transform: uppercase;
        color: #64748b;
        margin-right: 8px;
    }
    .tabulator .tabulator-row .tabulator-cell[tabulator-field="actions"]::before {
        display: none;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    function stripHtml(html) {
        var tmp = document.createElement('div');
        tmp.innerHTML = html;
        return tmp.textContent || tmp.innerText || '';
    }

    var myClassesTable = new Tabulator("#myClassesTable", {
        layout: "fitColumns",
        pagination: "local",
        paginationSize: 25,
        paginationSizeSelector: [10, 25, 50, 100],
        placeholder: "<div class='text-center py-5 text-muted'><i class='bi bi-door-closed fs-1 d-block mb-2 text-muted-light'></i>No class sections found.</div>",
        columns: [
            { title: "Course", field: "course", widthGrow: 3.8, minWidth: 200, formatter: "html" },
            { title: "Schedule", field: "schedule", widthGrow: 1.6, minWidth: 120, formatter: "html" },
            { title: "Room", field: "room", widthGrow: 1.0, minWidth: 80, formatter: "html" },
            <?php if ($userRole === 'admin'): ?>
                { title: "Instructor", field: "instructor", widthGrow: 1.4, minWidth: 100, formatter: "html" },
            <?php endif; ?>
            { 
                title: "Enrolled", 
                field: "enrolled", 
                widthGrow: 1.2, 
                minWidth: 95, 
                hozAlign: "center", 
                headerHozAlign: "center",
                formatter: "html",
                sorter: function(a, b) {
                    var aNum = parseInt(stripHtml(a).split('/')[0]) || 0;
                    var bNum = parseInt(stripHtml(b).split('/')[0]) || 0;
                    return aNum - bNum;
                }
            },
            { 
                title: "Actions", 
                field: "actions", 
                widthGrow: 3.0, 
                minWidth: 290, 
                hozAlign: "right", 
                headerHozAlign: "right",
                headerSort: false, 
                formatter: "html" 
            }
        ]
    });

    var searchInput = document.getElementById('myClassesSearch');
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            var term = this.value.trim().toLowerCase();
            if (!term) {
                myClassesTable.clearFilter();
            } else {
                myClassesTable.setFilter(function (data) {
                    return stripHtml(data.course).toLowerCase().includes(term) ||
                           stripHtml(data.schedule).toLowerCase().includes(term) ||
                           stripHtml(data.room).toLowerCase().includes(term)
                           <?php if ($userRole === 'admin'): ?>
                               || stripHtml(data.instructor || '').toLowerCase().includes(term)
                           <?php endif; ?>
                           ;
                });
            }
        });
    }
});
</script>

<?php
require_once '../includes/footer.php';
?>
