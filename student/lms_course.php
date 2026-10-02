<?php
require_once '../includes/lms_access.php';

$lmsStudent = requireLmsAccess(['student']);
$subjectId = filter_input(INPUT_GET, 'subject_id', FILTER_VALIDATE_INT);

$course = $subjectId
    ? fetchLmsSubjectForStudent($pdo, (int)$lmsStudent['student_id'], $subjectId)
    : null;

if (!$course) {
    $_SESSION['flash_error'] = 'That subject is not part of your confirmed enrollment.';
    header('Location: lms');
    exit;
}

$courseSections = [
    'overview'      => 'Overview',
    'announcements' => 'Announcements',
    'lessons'       => 'Lessons / Modules',
    'materials'     => 'Learning Materials',
    'assignments'   => 'Assignments',
    'quizzes'       => 'Quizzes / Exams',
    'grades'        => 'Grades',
    'progress'      => 'Course Progress',
];
$activeSection = (string)($_GET['section'] ?? 'overview');
if (!isset($courseSections[$activeSection])) {
    $activeSection = 'overview';
}

$courseGrades = [];
if ($activeSection === 'grades') {
    $courseGrades = fetchLmsCourseGrades($pdo, (int)$lmsStudent['student_id'], (int)$course['subject_id']);
}
$courseProgress = null;
if ($activeSection === 'progress') {
    $courseProgress = fetchLmsCourseProgress($pdo, (int)$lmsStudent['student_id'], (int)$course['subject_id']);
}
$assignmentGrades = [];
$activityGrades = [];
$quizGrades = [];
if ($activeSection === 'grades') {
    $assignmentGrades = fetchLmsWorkGradesForStudentSubject($pdo,(int)$lmsStudent['student_id'],(int)$course['subject_id'],'assignment');
    $activityGrades   = fetchLmsWorkGradesForStudentSubject($pdo,(int)$lmsStudent['student_id'],(int)$course['subject_id'],'activity');
    $quizGrades       = fetchLmsQuizGradesForStudentSubject($pdo,(int)$lmsStudent['student_id'],(int)$course['subject_id']);
}
$lessonModules = [];
$selectedLesson = null;
if ($activeSection === 'lessons') {
    $lessonRows = fetchLmsModulesForStudentSubject($pdo, (int)$lmsStudent['student_id'], (int)$course['subject_id']);
    foreach ($lessonRows as $row) {
        $moduleId = (int)$row['module_id'];
        if (!isset($lessonModules[$moduleId])) {
            $lessonModules[$moduleId] = ['id'=>$moduleId,'title'=>$row['module_title'],'description'=>$row['module_description'],'lessons'=>[]];
        }
        if ($row['lesson_id'] !== null) {
            $lessonModules[$moduleId]['lessons'][] = ['id'=>(int)$row['lesson_id'],'title'=>$row['lesson_title'],'content_type'=>$row['content_type']];
        }
    }
    $lessonId = filter_input(INPUT_GET, 'lesson_id', FILTER_VALIDATE_INT);
    if (!$lessonId) {
        foreach ($lessonModules as $module) {
            if ($module['lessons']) { $lessonId = $module['lessons'][0]['id']; break; }
        }
    }
    if ($lessonId) {
        $selectedLesson = fetchLmsLessonForStudentSubject($pdo,(int)$lmsStudent['student_id'],(int)$course['subject_id'],(int)$lessonId);
        if (!$selectedLesson) { http_response_code(404); }
    }
}
$learningMaterials = [];
if ($activeSection === 'materials') {
    $learningMaterials = fetchLmsMaterialsForStudentSubject($pdo,(int)$lmsStudent['student_id'],(int)$course['subject_id']);
}
$announcements = [];
if ($activeSection === 'announcements') {
    $announcements = fetchLmsAnnouncementsForStudentSubject($pdo,(int)$lmsStudent['student_id'],(int)$course['subject_id']);
}
$learningAssignments = [];
$assignmentFilesById = [];
if ($activeSection === 'assignments') {
    $learningAssignments = fetchLmsAssignmentsForStudentSubject($pdo,(int)$lmsStudent['student_id'],(int)$course['subject_id']);
    foreach (fetchLmsAssignmentFilesForStudentSubject($pdo,(int)$lmsStudent['student_id'],(int)$course['subject_id']) as $af) {
        $assignmentFilesById[(int)$af['assignment_id']][] = $af;
    }
}
$learningQuizzes = [];
$quizAttemptsByQuiz = [];
if ($activeSection === 'quizzes') {
    $learningQuizzes = fetchLmsQuizzesForStudentSubject($pdo,(int)$lmsStudent['student_id'],(int)$course['subject_id']);
    foreach (fetchLmsQuizAttemptsForStudentSubject($pdo,(int)$lmsStudent['student_id'],(int)$course['subject_id']) as $qa) {
        $quizAttemptsByQuiz[(int)$qa['quiz_id']][] = $qa;
    }
}
$page_title = $course['subject_code'] . ' — ' . $courseSections[$activeSection];
require_once '../includes/header.php';
?>
<style>
/* ── LMS Course Page ─────────────────────────────────── */
.lms-course-banner{background:linear-gradient(135deg,var(--brand-dark,#064b55) 0%,var(--brand-primary,#0b9b98) 100%);border-radius:14px;padding:1.75rem 2rem;color:#fff;margin-bottom:1.5rem;box-shadow:0 4px 20px rgba(11,155,152,.25);position:relative;overflow:hidden;}
.lms-course-banner::before{content:'';position:absolute;inset:0;background:url("data:image/svg+xml,%3Csvg width='160' height='160' viewBox='0 0 160 160' xmlns='http://www.w3.org/2000/svg'%3E%3Ccircle cx='80' cy='80' r='72' fill='none' stroke='rgba(255,255,255,.06)' stroke-width='28'/%3E%3C/svg%3E") right -40px top -40px/200px no-repeat;pointer-events:none;}
.lms-back-btn{display:inline-flex;align-items:center;gap:.35rem;font-size:.78rem;font-weight:600;color:rgba(255,255,255,.78);text-decoration:none;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.22);border-radius:6px;padding:.28rem .7rem;transition:background .15s,color .15s;margin-bottom:.9rem;}
.lms-back-btn:hover{background:rgba(255,255,255,.22);color:#fff;}
.lms-code-pill{display:inline-flex;align-items:center;background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.3);border-radius:20px;padding:.18rem .7rem;font-size:.7rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:rgba(255,255,255,.95);margin-bottom:.55rem;}
.lms-course-title{font-size:1.5rem;font-weight:800;line-height:1.2;margin:0 0 .75rem;color:#fff !important;text-shadow:0 1px 3px rgba(0,0,0,.18);}
.lms-meta-chip{display:inline-flex;align-items:center;gap:.3rem;background:rgba(255,255,255,.13);border:1px solid rgba(255,255,255,.2);border-radius:20px;padding:.18rem .65rem;font-size:.75rem;font-weight:500;color:rgba(255,255,255,.9);}
/* ── Tab bar ─────────────────────────────────────────── */
.lms-tabs-wrap{background:#fff;border:1px solid var(--gray-200,#e2e8f0);border-radius:12px;padding:.3rem .35rem;margin-bottom:1.5rem;box-shadow:0 1px 4px rgba(0,0,0,.04);}
.lms-tabs{display:flex;flex-wrap:wrap;gap:.15rem;list-style:none;margin:0;padding:0;}
.lms-tabs li{flex:1 1 auto;min-width:0;}
.lms-tab-link{display:flex;align-items:center;justify-content:center;gap:.3rem;white-space:nowrap;padding:.48rem .4rem;border-radius:8px;border-bottom:2px solid transparent;font-size:.76rem;font-weight:600;color:var(--text-slate,#334155);text-decoration:none;transition:background .13s,color .13s;width:100%;}
.lms-tab-link i{font-size:.82rem;flex-shrink:0;}
.lms-tab-link:hover{background:var(--surface-soft-alt,#eff5f6);color:var(--brand-primary,#0b9b98);}
.lms-tab-link.is-active{background:var(--surface-tint,#eaf4f5);color:var(--brand-primary,#0b9b98);border-bottom-color:var(--brand-primary,#0b9b98);}
@media(max-width:767px){.lms-tab-link{font-size:.7rem;padding:.42rem .25rem;}.lms-tab-label{display:none;}}
@media(max-width:480px){.lms-tabs li{flex:0 0 calc(25% - .15rem);}}
/* ── Content panel ───────────────────────────────────── */
.lms-panel{background:#fff;border:1px solid var(--gray-200,#e2e8f0);border-radius:14px;box-shadow:0 2px 8px rgba(0,0,0,.04);overflow:hidden;}
.lms-panel-header{padding:1rem 1.6rem;border-bottom:2px solid var(--brand-primary-soft,#b5e0de);border-left:4px solid var(--brand-primary,#0b9b98);background:linear-gradient(to right,rgba(11,155,152,.08) 0%,rgba(11,155,152,.02) 60%,#fff 100%);display:flex;align-items:flex-start;gap:.85rem;flex-wrap:wrap;}
.lms-panel-icon{width:36px;height:36px;min-width:36px;border-radius:9px;background:var(--brand-primary-soft,#d9efee);color:var(--brand-primary,#0b9b98);display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0;margin-top:.05rem;}
.lms-panel-text{min-width:0;}
.lms-panel-title{font-size:.95rem;font-weight:700;color:var(--text-navy-alt,#0b6570);margin:0 0 .18rem;}
.lms-panel-sub{font-size:.76rem;color:var(--text-muted,#647b80);margin:0;}
.lms-panel-body{padding:1.4rem 1.6rem;}
/* ── Overview info grid ──────────────────────────────── */
.lms-info-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:.85rem;}
@media(max-width:767px){.lms-info-grid{grid-template-columns:repeat(2,1fr);}}
@media(max-width:479px){.lms-info-grid{grid-template-columns:1fr;}}
.lms-info-item{background:var(--surface-soft,#f7f9fa);border:1px solid var(--gray-200,#e2e8f0);border-radius:10px;padding:.8rem 1rem;}
.lms-info-icon{width:30px;height:30px;border-radius:7px;background:var(--brand-primary-soft,#d9efee);color:var(--brand-primary,#0b9b98);display:flex;align-items:center;justify-content:center;font-size:.85rem;margin-bottom:.45rem;}
.lms-info-label{font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted,#647b80);margin-bottom:.15rem;}
.lms-info-value{font-size:.88rem;font-weight:600;color:var(--text-darker,#10383f);}
/* ── Card list items ─────────────────────────────────── */
.lms-card-item{border:1px solid var(--gray-200,#e2e8f0);border-radius:10px;padding:1rem 1.2rem;background:#fff;transition:box-shadow .14s,border-color .14s;}
.lms-card-item:hover{box-shadow:0 2px 12px rgba(11,155,152,.1);border-color:var(--brand-accent,#55b9b5);}
.lms-card-item.is-important{background:#fffbeb;border-color:#fde68a;}
/* ── Empty state ─────────────────────────────────────── */
.lms-empty{display:flex;flex-direction:column;align-items:center;padding:3rem 1.5rem;color:var(--text-muted,#647b80);text-align:center;}
.lms-empty i{font-size:2.4rem;margin-bottom:.65rem;opacity:.4;color:var(--brand-primary,#0b9b98);}
.lms-empty strong{color:var(--text-navy-alt,#0b6570);font-size:.92rem;}
/* ── Progress bars ───────────────────────────────────── */
.lms-prog-bar-wrap{height:6px;background:var(--gray-200,#e2e8f0);border-radius:999px;overflow:hidden;}
.lms-prog-bar-fill{height:100%;border-radius:999px;transition:width .4s;}
/* ── Lesson sidebar ──────────────────────────────────── */
.lms-lesson-sidebar{border-right:1px solid var(--gray-200,#e2e8f0);}
.lms-lesson-item{display:block;padding:.55rem .9rem;text-decoration:none;border-radius:7px;color:var(--text-slate,#334155);font-size:.84rem;transition:background .12s;}
.lms-lesson-item:hover{background:var(--surface-soft-alt,#eff5f6);}
.lms-lesson-item.is-active{background:var(--surface-tint,#eaf4f5);font-weight:700;color:var(--brand-primary,#0b9b98);}
.lms-module-label{font-size:.65rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted,#647b80);padding:.7rem .9rem .2rem;}
</style>
<!-- ══ Course Header Banner ═════════════════════════ -->
<div class="lms-course-banner">
    <a href="lms" class="lms-back-btn"><i class="bi bi-arrow-left" aria-hidden="true"></i> My Courses</a>
    <div class="lms-code-pill"><i class="bi bi-book me-1" aria-hidden="true"></i><?php echo htmlspecialchars($course['subject_code']); ?></div>
    <h1 class="lms-course-title"><?php echo htmlspecialchars($course['subject_name']); ?></h1>
    <div class="d-flex flex-wrap gap-2">
        <span class="lms-meta-chip"><i class="bi bi-people-fill" aria-hidden="true"></i><?php echo htmlspecialchars($course['section_name'] ?: 'Confirmed section'); ?></span>
        <span class="lms-meta-chip"><i class="bi bi-calendar3" aria-hidden="true"></i><?php echo htmlspecialchars($course['school_year'] . ' / ' . $course['semester']); ?></span>
    </div>
</div>

<!-- ══ Tab Bar ══════════════════════════════════════ -->
<?php
$tabIcons = [
    'overview'      => 'bi-house-fill',
    'announcements' => 'bi-megaphone-fill',
    'lessons'       => 'bi-collection-fill',
    'materials'     => 'bi-file-earmark-text-fill',
    'assignments'   => 'bi-pencil-square',
    'quizzes'       => 'bi-patch-question-fill',
    'grades'        => 'bi-award-fill',
    'progress'      => 'bi-bar-chart-fill',
];
?>
<div class="lms-tabs-wrap">
    <ul class="lms-tabs" role="tablist">
        <?php foreach ($courseSections as $sectionKey => $sectionLabel): ?>
        <li role="presentation">
            <a class="lms-tab-link <?php echo $activeSection === $sectionKey ? 'is-active' : ''; ?>"
               href="lms_course?subject_id=<?php echo (int)$course['subject_id']; ?>&amp;section=<?php echo htmlspecialchars($sectionKey); ?>"
               <?php echo $activeSection === $sectionKey ? 'aria-current="page"' : ''; ?>>
                <i class="bi <?php echo $tabIcons[$sectionKey] ?? 'bi-circle'; ?>" aria-hidden="true"></i>
                <span class="lms-tab-label"><?php echo htmlspecialchars($sectionLabel); ?></span>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>
</div>

<!-- ══ Tab Content ══════════════════════════════════ -->
<div class="lms-panel" aria-labelledby="lms-section-heading">

<?php if ($activeSection === 'overview'): ?>
<div class="lms-panel-header">
    <div class="lms-panel-icon" aria-hidden="true"><i class="bi bi-house-fill"></i></div>
    <div class="lms-panel-text">
        <div class="lms-panel-title" id="lms-section-heading">Overview</div>
        <p class="lms-panel-sub">Course details and instructor information.</p>
    </div>
</div>
<div class="lms-panel-body">
    <div class="lms-info-grid">
        <div class="lms-info-item">
            <div class="lms-info-icon"><i class="bi bi-people-fill"></i></div>
            <div class="lms-info-label">Section</div>
            <div class="lms-info-value"><?php echo htmlspecialchars($course['section_name'] ?: 'Confirmed section'); ?></div>
        </div>
        <div class="lms-info-item">
            <div class="lms-info-icon"><i class="bi bi-calendar3"></i></div>
            <div class="lms-info-label">Academic Term</div>
            <div class="lms-info-value"><?php echo htmlspecialchars($course['school_year'] . ' / ' . $course['semester']); ?></div>
        </div>
        <div class="lms-info-item">
            <div class="lms-info-icon"><i class="bi bi-hash"></i></div>
            <div class="lms-info-label">Units</div>
            <div class="lms-info-value"><?php echo htmlspecialchars((string)$course['units']); ?></div>
        </div>
        <div class="lms-info-item">
            <div class="lms-info-icon"><i class="bi bi-person-fill"></i></div>
            <div class="lms-info-label">Instructor</div>
            <div class="lms-info-value"><?php echo htmlspecialchars($course['instructor_name'] ?: 'Not assigned'); ?></div>
            <?php if (!empty($course['instructor_email'])): ?>
            <div class="mt-1"><a href="mailto:<?php echo htmlspecialchars($course['instructor_email']); ?>" style="font-size:.78rem;color:var(--brand-primary);font-weight:500;"><?php echo htmlspecialchars($course['instructor_email']); ?></a></div>
            <?php endif; ?>
        </div>
        <div class="lms-info-item">
            <div class="lms-info-icon"><i class="bi bi-clock-fill"></i></div>
            <div class="lms-info-label">Schedule</div>
            <div class="lms-info-value"><?php echo htmlspecialchars(trim(($course['day_of_week'] ?? '') . ' ' . ($course['start_time'] ?? '') . ' - ' . ($course['end_time'] ?? '')) ?: 'Not scheduled'); ?></div>
        </div>
        <div class="lms-info-item">
            <div class="lms-info-icon"><i class="bi bi-door-open-fill"></i></div>
            <div class="lms-info-label">Room</div>
            <div class="lms-info-value"><?php echo htmlspecialchars($course['room'] ?: 'Not assigned'); ?></div>
        </div>
    </div>
</div>

<?php elseif ($activeSection === 'announcements'): ?>
<div class="lms-panel-header">
    <div class="lms-panel-icon" aria-hidden="true"><i class="bi bi-megaphone-fill"></i></div>
    <div class="lms-panel-text">
        <div class="lms-panel-title" id="lms-section-heading">Announcements</div>
        <p class="lms-panel-sub">Updates and notices from your instructor.</p>
    </div>
</div>
<div class="lms-panel-body">
    <?php if ($announcements): ?>
        <div class="vstack gap-3">
            <?php foreach ($announcements as $announcement): ?>
                <article class="lms-card-item <?php echo !empty($announcement['is_important']) ? 'is-important' : ''; ?>">
                    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-2">
                        <div>
                            <h3 class="h6 fw-bold mb-1">
                                <?php if (!empty($announcement['is_important'])): ?><i class="bi bi-exclamation-circle-fill text-warning me-1" aria-hidden="true"></i><?php endif; ?>
                                <?php echo htmlspecialchars($announcement['title']); ?>
                                <?php if (!empty($announcement['is_important'])): ?><span class="badge text-bg-warning ms-1">Important</span><?php endif; ?>
                            </h3>
                            <div class="small text-muted">
                                <i class="bi bi-person-circle me-1" aria-hidden="true"></i><?php echo htmlspecialchars($announcement['author_name']); ?>
                                <span aria-hidden="true" class="mx-1">·</span>
                                <i class="bi bi-clock me-1" aria-hidden="true"></i><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($announcement['published_at']))); ?>
                            </div>
                        </div>
                    </div>
                    <div class="text-dark" style="font-size:.9rem;line-height:1.65;"><?php echo nl2br(htmlspecialchars($announcement['message'])); ?></div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="lms-empty"><i class="bi bi-megaphone"></i><strong>No announcements yet</strong><p class="small mt-1 mb-0">Your instructor hasn't posted any announcements for this subject.</p></div>
    <?php endif; ?>
</div>

<?php elseif ($activeSection === 'lessons'): ?>
<div class="lms-panel-header">
    <div class="lms-panel-icon" aria-hidden="true"><i class="bi bi-collection-fill"></i></div>
    <div class="lms-panel-text">
        <div class="lms-panel-title" id="lms-section-heading">Lessons / Modules</div>
        <p class="lms-panel-sub">Browse modules and view lesson content.</p>
    </div>
</div>
<?php if (!$lessonModules): ?>
    <div class="lms-panel-body"><div class="lms-empty"><i class="bi bi-collection"></i><strong>No lessons available yet</strong><p class="small mt-1 mb-0">No published lessons are available for this subject yet.</p></div></div>
<?php else: ?>
    <div class="row g-0">
        <aside class="col-12 col-lg-3 lms-lesson-sidebar" aria-label="Modules and lessons">
            <div style="max-height:640px;overflow-y:auto;padding:.5rem .25rem;">
                <?php foreach ($lessonModules as $module): ?>
                    <div class="lms-module-label"><?php echo htmlspecialchars($module['title']); ?></div>
                    <?php if (!empty($module['description'])): ?><p class="small text-muted mb-1 px-3"><?php echo nl2br(htmlspecialchars($module['description'])); ?></p><?php endif; ?>
                    <?php if ($module['lessons']): ?>
                        <?php foreach ($module['lessons'] as $lesson): ?>
                            <a class="lms-lesson-item <?php echo (int)($selectedLesson['id'] ?? 0) === $lesson['id'] ? 'is-active' : ''; ?>"
                               href="lms_course?subject_id=<?php echo (int)$course['subject_id']; ?>&amp;section=lessons&amp;lesson_id=<?php echo $lesson['id']; ?>">
                                <i class="bi bi-play-circle-fill me-1 text-muted" style="font-size:.75rem;" aria-hidden="true"></i>
                                <?php echo htmlspecialchars($lesson['title']); ?>
                                <span class="d-block small text-muted ms-3" style="font-weight:400;"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $lesson['content_type']))); ?></span>
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?><p class="small text-muted px-3 mb-0">No published lessons in this module.</p><?php endif; ?>
                <?php endforeach; ?>
            </div>
        </aside>
        <div class="col-12 col-lg-9 p-4">
            <?php if (!$selectedLesson): ?>
                <div class="lms-empty"><i class="bi bi-hand-index-thumb"></i><strong>Select a lesson</strong><p class="small mt-1 mb-0">Choose a lesson from the left panel to view its content.</p></div>
            <?php elseif (!$selectedLesson['id']): ?>
                <div class="alert alert-warning mb-0">That lesson is not available in your enrolled subject.</div>
            <?php else: ?>
                <article>
                    <div class="small text-muted mb-1"><i class="bi bi-collection me-1"></i><?php echo htmlspecialchars($selectedLesson['module_title']); ?></div>
                    <h3 class="h5 fw-bold text-navy-alt"><?php echo htmlspecialchars($selectedLesson['title']); ?></h3>
                    <?php if (!empty($selectedLesson['description'])): ?><p class="text-muted small"><?php echo nl2br(htmlspecialchars($selectedLesson['description'])); ?></p><?php endif; ?>
                    <hr class="my-3">
                    <?php if ($selectedLesson['content_type'] === 'text'): ?>
                        <?php if (!empty($selectedLesson['content_body'])): ?>
                            <div style="line-height:1.75;"><?php echo nl2br(htmlspecialchars($selectedLesson['content_body'])); ?></div>
                        <?php else: ?><p class="text-muted mb-0">Lesson content has not been added yet.</p><?php endif; ?>
                    <?php elseif ($selectedLesson['content_type'] === 'external_link'): ?>
                        <?php
                            $externalUrl = trim((string)$selectedLesson['content_path']);
                            $externalScheme = strtolower((string)parse_url($externalUrl, PHP_URL_SCHEME));
                            $externalUrlIsAllowed = filter_var($externalUrl, FILTER_VALIDATE_URL) && in_array($externalScheme, ['http', 'https'], true);
                        ?>
                        <?php if ($externalUrlIsAllowed): ?>
                            <a class="btn btn-brand-primary" href="<?php echo htmlspecialchars($externalUrl); ?>" target="_blank" rel="noopener noreferrer">Open learning link <i class="bi bi-box-arrow-up-right ms-1" aria-hidden="true"></i></a>
                        <?php else: ?><p class="text-muted mb-0">This learning link is unavailable.</p><?php endif; ?>
                    <?php elseif (in_array($selectedLesson['content_type'], ['image', 'presentation', 'pdf', 'video'], true) && !empty($selectedLesson['content_path'])): ?>
                        <?php $lessonFileUrl = 'lms_lesson_file.php?subject_id=' . (int)$course['subject_id'] . '&amp;lesson_id=' . (int)$selectedLesson['id']; ?>
                        <?php if ($selectedLesson['content_type'] === 'image'): ?>
                            <img class="img-fluid rounded" src="<?php echo $lessonFileUrl; ?>" alt="<?php echo htmlspecialchars($selectedLesson['title']); ?>">
                        <?php elseif ($selectedLesson['content_type'] === 'pdf'): ?>
                            <div class="ratio" style="--bs-aspect-ratio:75%;"><iframe src="<?php echo $lessonFileUrl; ?>" title="<?php echo htmlspecialchars($selectedLesson['title']); ?>" loading="lazy"></iframe></div>
                            <a class="d-inline-block mt-2 small" href="<?php echo $lessonFileUrl; ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-box-arrow-up-right me-1"></i>Open PDF in a new tab</a>
                        <?php elseif ($selectedLesson['content_type'] === 'video'): ?>
                            <video class="w-100 rounded" controls preload="metadata"><source src="<?php echo $lessonFileUrl; ?>">Your browser does not support this video format.</video>
                        <?php else: ?>
                            <a class="btn btn-outline-secondary" href="<?php echo $lessonFileUrl; ?>" target="_blank" rel="noopener noreferrer">Open presentation <i class="bi bi-box-arrow-up-right ms-1" aria-hidden="true"></i></a>
                        <?php endif; ?>
                    <?php else: ?><p class="text-muted mb-0">Lesson content has not been added yet.</p><?php endif; ?>
                </article>
                <script>
                    fetch('../actions/lms_progress_actions',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({csrf_token:<?php echo json_encode(ensureCsrfToken()); ?>,subject_id:<?php echo (int)$course['subject_id']; ?>,lesson_id:<?php echo (int)$selectedLesson['id']; ?>})}).catch(()=>{});
                </script>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php elseif ($activeSection === 'materials'): ?>
<div class="lms-panel-header">
    <div class="lms-panel-icon" aria-hidden="true"><i class="bi bi-file-earmark-text-fill"></i></div>
    <div class="lms-panel-text">
        <div class="lms-panel-title" id="lms-section-heading">Learning Materials</div>
        <p class="lms-panel-sub">Downloadable resources shared by your instructor.</p>
    </div>
</div>
<div class="lms-panel-body">
    <?php if ($learningMaterials): ?>
        <div class="vstack gap-3">
            <?php foreach ($learningMaterials as $material): ?>
                <div class="lms-card-item d-flex align-items-center gap-3 flex-wrap">
                    <div class="flex-shrink-0 rounded" style="width:40px;height:40px;background:var(--brand-primary-soft);color:var(--brand-primary);display:flex;align-items:center;justify-content:center;font-size:1.15rem;"><i class="bi bi-file-earmark-fill" aria-hidden="true"></i></div>
                    <div class="flex-fill" style="min-width:0;">
                        <div class="fw-semibold text-darker"><?php echo htmlspecialchars($material['title']); ?></div>
                        <?php if (!empty($material['description'])): ?><div class="small text-muted"><?php echo nl2br(htmlspecialchars($material['description'])); ?></div><?php endif; ?>
                        <div class="small text-muted mt-1"><i class="bi bi-paperclip me-1"></i><?php echo htmlspecialchars($material['file_name']); ?> <span class="ms-1 badge bg-light text-muted border" style="font-size:.66rem;"><?php echo htmlspecialchars(ucfirst($material['material_type'])); ?></span></div>
                    </div>
                    <a class="btn btn-sm btn-outline-secondary text-nowrap flex-shrink-0" href="lms_material_file.php?subject_id=<?php echo (int)$course['subject_id']; ?>&amp;material_id=<?php echo (int)$material['id']; ?>" aria-label="Download <?php echo htmlspecialchars($material['title']); ?>"><i class="bi bi-download me-1" aria-hidden="true"></i>Download</a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="lms-empty"><i class="bi bi-file-earmark"></i><strong>No materials available</strong><p class="small mt-1 mb-0">No learning materials are currently available for this subject.</p></div>
    <?php endif; ?>
</div>
<?php elseif ($activeSection === 'assignments'): ?>
<div class="lms-panel-header">
    <div class="lms-panel-icon" aria-hidden="true"><i class="bi bi-pencil-square"></i></div>
    <div class="lms-panel-text">
        <div class="lms-panel-title" id="lms-section-heading">Assignments</div>
        <p class="lms-panel-sub">Submit your work and track your graded assignments.</p>
    </div>
</div>
<div class="lms-panel-body">
    <?php if ($learningAssignments): ?>
        <div class="vstack gap-4">
            <?php foreach ($learningAssignments as $assignment): ?>
                <?php
                    $assignmentStatus = (string)$assignment['submission_status'];
                    $statusLabels = [
                        'not_submitted' => ['Not submitted','text-bg-secondary'],
                        'submitted'     => ['Submitted',    'text-bg-success'],
                        'late'          => ['Submitted late','text-bg-warning'],
                        'past_due'      => ['Past due',     'text-bg-danger'],
                    ];
                    [$statusLabel, $statusClass] = $statusLabels[$assignmentStatus] ?? $statusLabels['not_submitted'];
                    $canSubmitAssignment = empty($assignment['is_graded'])
                        && (empty($assignment['is_past_due']) || !empty($assignment['allow_late_submissions']));
                ?>
                <article class="lms-card-item">
                    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
                        <div>
                            <h3 class="h6 fw-bold mb-1">
                                <?php echo htmlspecialchars($assignment['title']); ?>
                                <?php if (($assignment['assignment_type'] ?? 'assignment') === 'activity'): ?><span class="badge text-bg-info ms-1">Activity</span><?php endif; ?>
                            </h3>
                            <div class="small text-muted"><i class="bi bi-clock me-1"></i>Due <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($assignment['due_at']))); ?></div>
                        </div>
                        <span class="badge <?php echo $statusClass; ?>"><?php echo htmlspecialchars($statusLabel); ?></span>
                    </div>
                    <?php if (!empty($assignment['is_past_due'])): ?>
                        <div class="small text-danger mb-2"><i class="bi bi-exclamation-triangle-fill me-1"></i>Past due<?php echo !empty($assignment['allow_late_submissions']) ? ' · late submissions are accepted' : ' · submissions are closed'; ?>.</div>
                    <?php endif; ?>
                    <div class="mb-3 text-dark" style="font-size:.9rem;line-height:1.65;"><?php echo nl2br(htmlspecialchars($assignment['instructions'])); ?></div>
                    <?php if (!empty($assignmentFilesById[(int)$assignment['id']])): ?>
                        <div class="mb-3">
                            <div class="small fw-semibold mb-1"><i class="bi bi-paperclip me-1"></i>Reference materials</div>
                            <ul class="list-unstyled mb-0 vstack gap-1">
                                <?php foreach ($assignmentFilesById[(int)$assignment['id']] as $assignmentFile): ?>
                                    <li><a href="lms_assignment_file.php?subject_id=<?php echo (int)$course['subject_id']; ?>&amp;file_id=<?php echo (int)$assignmentFile['id']; ?>" class="small"><i class="bi bi-download me-1" aria-hidden="true"></i><?php echo htmlspecialchars($assignmentFile['file_name']); ?></a></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($assignment['submission_id'])): ?>
                        <div class="small p-2 rounded mb-3" style="background:var(--surface-soft);border:1px solid var(--gray-200);">
                            <i class="bi bi-check-circle-fill text-success me-1"></i>Current submission: <span class="fw-semibold"><?php echo htmlspecialchars($assignment['submission_file_name']); ?></span>
                            <span class="text-muted">· <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($assignment['submitted_at']))); ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($assignment['is_graded'])): ?>
                        <div class="p-3 rounded" style="background:var(--surface-tint);border:1px solid var(--gray-200);">
                            <div class="small fw-bold mb-1"><i class="bi bi-chat-quote me-1"></i>Instructor feedback</div>
                            <?php if ($assignment['score'] !== null): ?><div class="mb-1 fw-semibold small">Score: <?php echo htmlspecialchars((string)$assignment['score']); ?><?php if ($assignment['max_score'] !== null): ?> / <?php echo htmlspecialchars((string)$assignment['max_score']); ?><?php endif; ?></div><?php endif; ?>
                            <?php if (!empty($assignment['feedback'])): ?>
                                <div class="small text-dark"><?php echo nl2br(htmlspecialchars($assignment['feedback'])); ?></div>
                            <?php else: ?><div class="small text-muted">No written feedback was provided.</div><?php endif; ?>
                        </div>
                    <?php elseif ($canSubmitAssignment): ?>
                        <form action="../actions/lms_assignment_actions" method="post" enctype="multipart/form-data" class="mt-2">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                            <input type="hidden" name="subject_id" value="<?php echo (int)$course['subject_id']; ?>">
                            <input type="hidden" name="assignment_id" value="<?php echo (int)$assignment['id']; ?>">
                            <label class="form-label fw-semibold small" for="assignment-file-<?php echo (int)$assignment['id']; ?>"><?php echo !empty($assignment['submission_id']) ? 'Replace submission' : 'Upload submission'; ?></label>
                            <div class="d-flex flex-column flex-sm-row gap-2 align-items-sm-end">
                                <input class="form-control" type="file" name="submission_file" id="assignment-file-<?php echo (int)$assignment['id']; ?>" accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.rtf,.txt,.csv,.jpg,.jpeg,.png,.gif,.webp,.mp4,.webm,.zip" required>
                                <button class="btn btn-brand-primary text-nowrap" type="submit"><i class="bi bi-upload me-1" aria-hidden="true"></i>Submit</button>
                            </div>
                            <div class="form-text">Accepted: PDF, Office documents, RTF, text/CSV, images, video, and ZIP. Maximum file size: 20 MB.</div>
                        </form>
                    <?php elseif (!empty($assignment['is_past_due'])): ?>
                        <p class="small text-muted mb-0">The submission deadline has passed.</p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="lms-empty"><i class="bi bi-pencil-square"></i><strong>No assignments yet</strong><p class="small mt-1 mb-0">No published assignments are available for this subject.</p></div>
    <?php endif; ?>
</div>

<?php elseif ($activeSection === 'quizzes'): ?>
<div class="lms-panel-header">
    <div class="lms-panel-icon" aria-hidden="true"><i class="bi bi-patch-question-fill"></i></div>
    <div class="lms-panel-text">
        <div class="lms-panel-title" id="lms-section-heading">Quizzes / Exams</div>
        <p class="lms-panel-sub">Take quizzes and review your attempt results.</p>
    </div>
</div>
<div class="lms-panel-body">
    <?php if ($learningQuizzes): ?>
        <div class="vstack gap-4">
            <?php foreach ($learningQuizzes as $quiz): ?>
                <?php
                    $quizAttempts = $quizAttemptsByQuiz[(int)$quiz['id']] ?? [];
                    $attemptsUsed = count($quizAttempts);
                    $activeAttemptId = null;
                    $hasUnfinishedAttempt = false;
                    foreach ($quizAttempts as $quizAttempt) {
                        $candidateAttemptId = (int)$quizAttempt['id'];
                        if ($quizAttempt['status'] === 'in_progress') {
                            $hasUnfinishedAttempt = true;
                            if (!empty($_SESSION['lms_quiz_active_attempts'][$candidateAttemptId])) {
                                $activeAttemptId = $candidateAttemptId;
                            }
                        }
                    }
                    $availabilityLabels = [
                        'available' => ['Available',   'text-bg-success'],
                        'upcoming'  => ['Not open yet','text-bg-secondary'],
                        'closed'    => ['Closed',      'text-bg-danger'],
                    ];
                    [$availabilityLabel, $availabilityClass] = $availabilityLabels[$quiz['availability_status']] ?? $availabilityLabels['closed'];
                ?>
                <article class="lms-card-item">
                    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
                        <div>
                            <h3 class="h6 fw-bold mb-1"><?php echo htmlspecialchars($quiz['title']); ?></h3>
                            <div class="d-flex flex-wrap gap-2 small text-muted">
                                <span><i class="bi bi-clock me-1"></i><?php echo (int)$quiz['time_limit_minutes']; ?> min</span>
                                <span><i class="bi bi-arrow-repeat me-1"></i><?php echo (int)$quiz['allowed_attempts']; ?> attempt<?php echo (int)$quiz['allowed_attempts'] === 1 ? '' : 's'; ?></span>
                                <?php if (!empty($quiz['passing_score']) || (isset($quiz['passing_score']) && is_numeric($quiz['passing_score']))): ?>
                                    <span><i class="bi bi-trophy me-1"></i>Pass: <?php echo htmlspecialchars((string)$quiz['passing_score']); ?> pts</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <span class="badge <?php echo $availabilityClass; ?>"><?php echo htmlspecialchars($availabilityLabel); ?></span>
                    </div>
                    <?php if (!empty($quiz['instructions'])): ?><div class="small text-dark mb-3"><?php echo nl2br(htmlspecialchars($quiz['instructions'])); ?></div><?php endif; ?>
                    <div class="small text-muted mb-2 d-flex flex-wrap gap-3">
                        <?php if (!empty($quiz['opens_at'])): ?><span><i class="bi bi-calendar-check me-1"></i>Opens <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($quiz['opens_at']))); ?></span><?php endif; ?>
                        <?php if (!empty($quiz['closes_at'])): ?><span><i class="bi bi-calendar-x me-1"></i>Closes <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($quiz['closes_at']))); ?></span><?php endif; ?>
                    </div>
                    <div class="small mb-3"><span class="fw-semibold">Attempts used:</span> <?php echo $attemptsUsed; ?> / <?php echo (int)$quiz['allowed_attempts']; ?></div>
                    <?php if ($quizAttempts): ?>
                        <div class="vstack gap-2 mb-3">
                            <?php foreach ($quizAttempts as $quizAttempt): ?>
                                <div class="d-flex flex-wrap gap-2 align-items-center p-2 rounded" style="background:var(--surface-soft);font-size:.82rem;">
                                    <span class="fw-semibold">Attempt <?php echo (int)$quizAttempt['attempt_number']; ?>:</span>
                                    <span class="badge bg-light text-dark border"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $quizAttempt['status']))); ?></span>
                                    <?php if ($quizAttempt['score'] !== null): ?>
                                        <span class="fw-semibold"><?php echo htmlspecialchars((string)$quizAttempt['score']); ?> / <?php echo htmlspecialchars((string)$quizAttempt['total_points']); ?></span>
                                        <?php if (!empty($quiz['passing_score']) || (isset($quiz['passing_score']) && is_numeric($quiz['passing_score']))): ?>
                                            <?php $isAttPassed = (float)$quizAttempt['score'] >= (float)$quiz['passing_score']; ?>
                                            <span class="badge <?php echo $isAttPassed ? 'text-bg-success' : 'text-bg-danger'; ?>"><?php echo $isAttPassed ? 'Passed' : 'Failed'; ?></span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if ($quizAttempt['status'] !== 'in_progress'): ?>
                                        <a href="lms_quiz?subject_id=<?php echo (int)$course['subject_id']; ?>&amp;attempt_id=<?php echo (int)$quizAttempt['id']; ?>" class="small">View result</a>
                                    <?php elseif ($activeAttemptId === (int)$quizAttempt['id']): ?>
                                        <span class="text-muted small">Active in the open quiz tab.</span>
                                    <?php else: ?>
                                        <span class="text-muted small">This attempt cannot be resumed.</span>
                                    <?php endif; ?>
                                    <?php if ($quizAttempt['status'] === 'in_progress'): ?>
                                        <form action="../actions/lms_quiz_actions" method="post" class="ms-auto">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                                            <input type="hidden" name="action" value="interrupt">
                                            <input type="hidden" name="subject_id" value="<?php echo (int)$course['subject_id']; ?>">
                                            <input type="hidden" name="quiz_id" value="<?php echo (int)$quiz['id']; ?>">
                                            <input type="hidden" name="attempt_id" value="<?php echo (int)$quizAttempt['id']; ?>">
                                            <button class="btn btn-sm btn-outline-secondary" type="submit">Submit saved answers and consume attempt</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($hasUnfinishedAttempt): ?>
                        <p class="small text-warning mb-0"><i class="bi bi-exclamation-triangle-fill me-1"></i>Complete the active quiz tab or submit saved answers to consume the unfinished attempt before starting another.</p>
                    <?php elseif ($quiz['availability_status'] === 'available' && $attemptsUsed < (int)$quiz['allowed_attempts']): ?>
                        <form action="../actions/lms_quiz_actions" method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                            <input type="hidden" name="action" value="start">
                            <input type="hidden" name="subject_id" value="<?php echo (int)$course['subject_id']; ?>">
                            <input type="hidden" name="quiz_id" value="<?php echo (int)$quiz['id']; ?>">
                            <button class="btn btn-brand-primary" type="submit"><i class="bi bi-play-fill me-1" aria-hidden="true"></i>Start quiz</button>
                        </form>
                    <?php elseif ($attemptsUsed >= (int)$quiz['allowed_attempts']): ?>
                        <p class="small text-muted mb-0">No attempts remain.</p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="lms-empty"><i class="bi bi-patch-question"></i><strong>No quizzes available</strong><p class="small mt-1 mb-0">No published quizzes are available for this subject.</p></div>
    <?php endif; ?>
</div>
<?php elseif ($activeSection === 'grades'): ?>
<div class="lms-panel-header">
    <div class="lms-panel-icon" aria-hidden="true"><i class="bi bi-award-fill"></i></div>
    <div class="lms-panel-text">
        <div class="lms-panel-title" id="lms-section-heading">Grades</div>
        <p class="lms-panel-sub">Your periodic grades, final standing, and coursework performance.</p>
    </div>
</div>
<div class="lms-panel-body">
    <?php if (!empty($courseGrades)): ?>
        <?php $latestGrade = $courseGrades[0];
            $statBadges = [
                'approved'  => ['Approved by Registrar (Official)',          'text-bg-success'],
                'submitted' => ['Submitted to Registrar (Pending Approval)', 'text-bg-info'],
                'draft'     => ['Instructor Evaluation (Draft)',              'text-bg-secondary'],
            ];
            [$statLabel, $statClass] = $statBadges[$latestGrade['grade_status'] ?? 'draft'] ?? ['Evaluation Recorded','text-bg-secondary'];
        ?>
        <div class="mb-4 p-4 rounded-3" style="border:1px solid var(--gray-200,#e2e8f0);background:var(--surface-soft,#f7f9fa);">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <span class="fw-bold text-navy-alt"><i class="bi bi-clipboard-data me-2"></i>Term Evaluation Summary</span>
                <span class="badge <?php echo $statClass; ?>"><?php echo htmlspecialchars($statLabel); ?></span>
            </div>
            <div class="row g-3 text-center mb-3">
                <div class="col-6 col-md-3">
                    <div class="p-3 bg-white rounded border">
                        <div class="text-muted small fw-semibold">Prelim</div>
                        <div class="fs-4 fw-bold text-navy-alt mt-1"><?php echo $latestGrade['prelim_grade'] !== null ? htmlspecialchars((string)$latestGrade['prelim_grade']) : '<span class="text-muted fs-6">—</span>'; ?></div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="p-3 bg-white rounded border">
                        <div class="text-muted small fw-semibold">Midterm</div>
                        <div class="fs-4 fw-bold text-navy-alt mt-1"><?php echo $latestGrade['midterm_grade'] !== null ? htmlspecialchars((string)$latestGrade['midterm_grade']) : '<span class="text-muted fs-6">—</span>'; ?></div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="p-3 bg-white rounded border">
                        <div class="text-muted small fw-semibold">Final Exam</div>
                        <div class="fs-4 fw-bold text-navy-alt mt-1"><?php echo $latestGrade['final_exam_grade'] !== null ? htmlspecialchars((string)$latestGrade['final_exam_grade']) : '<span class="text-muted fs-6">—</span>'; ?></div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="p-3 rounded border <?php echo $latestGrade['final_grade'] !== null ? 'bg-success-subtle border-success' : 'bg-white'; ?>">
                        <div class="small fw-semibold <?php echo $latestGrade['final_grade'] !== null ? 'text-success-emphasis' : 'text-muted'; ?>">Final Grade</div>
                        <div class="fs-4 fw-bold mt-1"><?php echo $latestGrade['final_grade'] !== null ? htmlspecialchars((string)$latestGrade['final_grade']) : '<span class="text-muted fs-6">—</span>'; ?></div>
                    </div>
                </div>
            </div>
            <?php if (!empty($latestGrade['remarks'])): ?>
                <div class="p-3 rounded bg-white border">
                    <div class="small fw-semibold text-muted mb-1"><i class="bi bi-chat-quote me-1"></i>Instructor Feedback / Remarks:</div>
                    <div class="text-dark small"><?php echo nl2br(htmlspecialchars($latestGrade['remarks'])); ?></div>
                </div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="lms-card-item text-center py-4 mb-4">
            <i class="bi bi-hourglass-split text-muted" style="font-size:2rem;"></i>
            <div class="fw-semibold text-navy-alt mt-2">Term Evaluation Pending</div>
            <p class="text-muted small mb-0">Periodic grades have not been posted yet by your instructor. Check back soon.</p>
        </div>
    <?php endif; ?>

    <h4 class="h6 fw-bold text-navy-alt mb-3"><i class="bi bi-journal-check me-2 text-brand-primary"></i>LMS Coursework Breakdown</h4>
    <div class="row g-4">
        <div class="col-12 col-lg-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white py-3"><span class="fw-bold text-navy-alt"><i class="bi bi-pencil-square me-1 text-primary"></i>Assignments &amp; Activities</span></div>
                <div class="card-body p-0">
                    <?php $allWork = array_merge($assignmentGrades, $activityGrades); ?>
                    <?php if (empty($allWork)): ?>
                        <div class="lms-empty py-4"><i class="bi bi-inbox"></i><span class="small">No graded assignments or activities recorded yet.</span></div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0" style="font-size:.85rem;">
                                <thead class="table-light"><tr><th class="ps-3">Item</th><th>Score</th><th class="pe-3">Feedback</th></tr></thead>
                                <tbody>
                                    <?php foreach ($allWork as $work): ?>
                                        <tr>
                                            <td class="ps-3"><div class="fw-semibold"><?php echo htmlspecialchars($work['title']); ?></div><span class="badge bg-light text-muted border" style="font-size:.66rem;"><?php echo htmlspecialchars(ucfirst($work['assignment_type'])); ?></span></td>
                                            <td><strong><?php echo $work['score'] !== null ? htmlspecialchars((string)$work['score']) : '—'; ?></strong><span class="text-muted"> / <?php echo htmlspecialchars((string)($work['max_score'] ?? '—')); ?></span></td>
                                            <td class="pe-3 small text-muted"><?php echo !empty($work['feedback']) ? htmlspecialchars($work['feedback']) : '—'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white py-3"><span class="fw-bold text-navy-alt"><i class="bi bi-patch-question me-1 text-info"></i>Quizzes &amp; Tests</span></div>
                <div class="card-body p-0">
                    <?php if (empty($quizGrades)): ?>
                        <div class="lms-empty py-4"><i class="bi bi-inbox"></i><span class="small">No completed quiz scores recorded yet.</span></div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0" style="font-size:.85rem;">
                                <thead class="table-light"><tr><th class="ps-3">Quiz</th><th>Attempt</th><th>Score</th><th class="pe-3">Submitted</th></tr></thead>
                                <tbody>
                                    <?php foreach ($quizGrades as $qg): ?>
                                        <tr>
                                            <td class="ps-3 fw-semibold"><?php echo htmlspecialchars($qg['title']); ?></td>
                                            <td><span class="badge bg-light text-dark border">#<?php echo (int)$qg['attempt_number']; ?></span></td>
                                            <td><strong class="text-success"><?php echo htmlspecialchars((string)$qg['score']); ?></strong><span class="text-muted"> / <?php echo htmlspecialchars((string)$qg['total_points']); ?></span></td>
                                            <td class="pe-3 small text-muted"><?php echo !empty($qg['submitted_at']) ? htmlspecialchars(date('M j, Y', strtotime($qg['submitted_at']))) : '—'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php elseif ($activeSection === 'progress'): ?>
<div class="lms-panel-header">
    <div class="lms-panel-icon" aria-hidden="true"><i class="bi bi-bar-chart-fill"></i></div>
    <div class="lms-panel-text">
        <div class="lms-panel-title" id="lms-section-heading">Course Progress</div>
        <p class="lms-panel-sub">Track how far along you are in each learning activity.</p>
    </div>
</div>
<div class="lms-panel-body">
    <div class="p-4 rounded-3 mb-4 text-center" style="background:linear-gradient(135deg,var(--brand-dark,#064b55),var(--brand-primary,#0b9b98));color:#fff;">
        <div class="small fw-semibold mb-1" style="opacity:.8;">Overall Course Progress</div>
        <div class="display-6 fw-bold mb-1"><?php echo (int)$courseProgress['percent']; ?>%</div>
        <div class="small mb-3" style="opacity:.8;"><?php echo (int)$courseProgress['completed']; ?> of <?php echo (int)$courseProgress['total']; ?> activities completed</div>
        <div style="height:10px;background:rgba(255,255,255,.25);border-radius:999px;overflow:hidden;max-width:340px;margin:0 auto;">
            <div style="height:100%;width:<?php echo (int)$courseProgress['percent']; ?>%;background:#fff;border-radius:999px;transition:width .5s;"></div>
        </div>
    </div>
    <div class="vstack gap-3">
        <?php foreach ($courseProgress['items'] as $progressItem): ?>
            <?php $itemPercent = $progressItem['total'] > 0 ? (int)round(($progressItem['completed'] / $progressItem['total']) * 100) : 0; ?>
            <div class="lms-card-item">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="fw-semibold" style="font-size:.9rem;"><?php echo htmlspecialchars($progressItem['label']); ?></span>
                    <span class="small text-muted"><?php echo (int)$progressItem['completed']; ?> / <?php echo (int)$progressItem['total']; ?> &middot; <strong><?php echo $itemPercent; ?>%</strong></span>
                </div>
                <div class="lms-prog-bar-wrap">
                    <div class="lms-prog-bar-fill" style="width:<?php echo $itemPercent; ?>%;background:<?php echo $itemPercent >= 100 ? 'var(--color-success,#22c55e)' : 'var(--brand-primary,#0b9b98)'; ?>;"></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <p class="small text-muted mt-3 mb-0"><i class="bi bi-info-circle me-1"></i>Progress is based on your lesson views, material downloads, assignment submissions, and completed quiz attempts.</p>
</div>

<?php else: ?>
<div class="lms-panel-body">
    <div class="lms-empty"><i class="bi bi-question-circle"></i><strong>Not available</strong><p class="small mt-1 mb-0">This course area is not available yet.</p></div>
</div>
<?php endif; ?>

</div>

<?php require_once '../includes/footer.php'; ?>