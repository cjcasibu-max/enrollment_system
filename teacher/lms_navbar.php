<?php
/**
 * Shared Teacher LMS Top Navigation Bar Component
 *
 * Usage in parent views:
 *   $lmsActiveTab = 'assignments'; // 'dashboard' | 'subject_hub' | 'materials' | 'assignments' | 'quizzes' | 'submissions' | 'grades' | 'announcements' | 'progress'
 *   require_once __DIR__ . '/lms_navbar.php';
 *
 * Automatically inherits $sectionSubjectId and $subjectMode from parent scope or query params.
 */

if (!isset($lmsActiveTab)) {
    $lmsActiveTab = '';
}

// Auto-detect sectionSubjectId and subjectMode
if (!isset($sectionSubjectId)) {
    $sectionSubjectId = isset($_GET['section_subject_id']) ? (int)$_GET['section_subject_id'] : 0;
}
$isSubjectMode = isset($subjectMode) ? (bool)$subjectMode : ($sectionSubjectId > 0);

// Helper to render individual tab items cleanly
$renderLmsTab = function(string $key, string $href, string $icon, string $label, bool $isActive) {
    $activeClass = $isActive ? 'lms-nav-btn active' : 'lms-nav-btn';
    $ariaCurrent = $isActive ? ' aria-current="page"' : '';
    ?>
    <a href="<?php echo htmlspecialchars($href); ?>"
       class="<?php echo $activeClass; ?>"
       <?php echo $ariaCurrent; ?>
       title="<?php echo htmlspecialchars($label); ?>">
        <i class="bi <?php echo htmlspecialchars($icon); ?> lms-nav-icon"></i>
        <span class="lms-nav-label"><?php echo htmlspecialchars($label); ?></span>
    </a>
    <?php
};
?>
<!-- ── SHARED TOP LMS NAVIGATION BAR ─────────────────────────────────────────── -->
<nav class="card shadow-sm mb-4 border-0 lms-top-nav-card" style="background:var(--sidebar-bg,#0b4f5c); width: 100%; border-radius: 10px;" aria-label="Instructor LMS navigation">
    <div class="card-body p-2 p-md-2.5">
        <div class="lms-nav-bar-grid">
            <?php
            // 1. Dashboard
            $renderLmsTab('dashboard', 'lms', 'bi-grid-fill', 'Dashboard', $lmsActiveTab === 'dashboard');

            if ($isSubjectMode) {
                // 2. Subject Hub (visible when scoped to an active section subject)
                $renderLmsTab('subject_hub', 'lms_subject?section_subject_id=' . $sectionSubjectId, 'bi-layers', 'Subject Hub', $lmsActiveTab === 'subject_hub');
                // 3. Materials
                $renderLmsTab('materials', 'lms_materials?section_subject_id=' . $sectionSubjectId, 'bi-file-earmark-text', 'Materials', $lmsActiveTab === 'materials');
                // 4. Assignments
                $renderLmsTab('assignments', 'lms_assignments?section_subject_id=' . $sectionSubjectId, 'bi-pencil-square', 'Assignments', $lmsActiveTab === 'assignments');
                // 5. Quizzes
                $renderLmsTab('quizzes', 'lms_quizzes?section_subject_id=' . $sectionSubjectId, 'bi-patch-question', 'Quizzes', $lmsActiveTab === 'quizzes');
                // 6. Submissions
                $renderLmsTab('submissions', 'lms_submissions?section_subject_id=' . $sectionSubjectId, 'bi-inbox-fill', 'Submissions', $lmsActiveTab === 'submissions');
                // 7. Grades
                $renderLmsTab('grades', 'lms_grades?section_subject_id=' . $sectionSubjectId, 'bi-award', 'Grades', $lmsActiveTab === 'grades');
                // 8. Announcements
                $renderLmsTab('announcements', 'lms_announcements?section_subject_id=' . $sectionSubjectId, 'bi-megaphone', 'Announcements', $lmsActiveTab === 'announcements');
                // 9. Student Progress
                $renderLmsTab('progress', 'lms_progress?section_subject_id=' . $sectionSubjectId, 'bi-graph-up-arrow', 'Progress', $lmsActiveTab === 'progress');
            } else {
                // Overview Mode Links (no section_subject_id)
                // 3. Materials
                $renderLmsTab('materials', 'lms_materials', 'bi-file-earmark-text', 'Materials', $lmsActiveTab === 'materials');
                // 4. Assignments
                $renderLmsTab('assignments', 'lms_assignments', 'bi-pencil-square', 'Assignments', $lmsActiveTab === 'assignments');
                // 5. Quizzes
                $renderLmsTab('quizzes', 'lms_quizzes', 'bi-patch-question', 'Quizzes', $lmsActiveTab === 'quizzes');
                // 6. Submissions
                $renderLmsTab('submissions', 'lms_submissions', 'bi-inbox-fill', 'Submissions', $lmsActiveTab === 'submissions');
                // 7. Grades
                $renderLmsTab('grades', 'lms_grades', 'bi-award', 'Grades', $lmsActiveTab === 'grades');
                // 8. Announcements
                $renderLmsTab('announcements', 'lms_announcements', 'bi-megaphone', 'Announcements', $lmsActiveTab === 'announcements');
                // 9. Student Progress
                $renderLmsTab('progress', 'lms_progress', 'bi-graph-up-arrow', 'Progress', $lmsActiveTab === 'progress');
            }
            ?>
        </div>
    </div>
</nav>

<style>
.lms-top-nav-card {
    width: 100% !important;
    box-sizing: border-box;
}
.lms-top-nav-card .card-body {
    width: 100%;
    padding: 8px 12px;
}
.lms-nav-bar-grid {
    display: flex;
    flex-direction: row;
    align-items: stretch;
    width: 100%;
    gap: 8px; /* Even 8px gap between buttons */
}
.lms-nav-btn {
    flex: 1 1 0; /* Equal width distribution across the entire bar */
    min-width: 0;
    height: 46px; /* Uniform height between 44px and 48px */
    padding: 0 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    border-radius: 8px;
    font-size: 0.82rem;
    font-weight: 500;
    color: #ffffff;
    background: rgba(255, 255, 255, 0.08);
    opacity: 0.88;
    text-decoration: none;
    border: 1px solid rgba(255, 255, 255, 0.12);
    transition: all 0.18s ease-in-out;
    box-sizing: border-box;
}
.lms-nav-btn:hover {
    opacity: 1;
    color: #ffffff;
    background: rgba(255, 255, 255, 0.18);
    border-color: rgba(255, 255, 255, 0.25);
}
.lms-nav-btn:focus-visible {
    outline: 2px solid #20b2aa;
    outline-offset: 2px;
}
.lms-nav-btn.active {
    background-color: #008080 !important; /* Solid teal */
    color: #ffffff !important;
    opacity: 1 !important;
    font-weight: 600;
    border-color: rgba(255, 255, 255, 0.3) !important;
    box-shadow: 0 2px 6px rgba(0, 128, 128, 0.35);
}
.lms-nav-btn .lms-nav-icon {
    font-size: 1rem;
    margin-right: 8px; /* ~8px gap between icon and label */
    flex-shrink: 0;
    display: inline-flex;
    align-items: center;
}
.lms-nav-btn .lms-nav-label {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    display: inline-block;
}

/* Responsive: tablet and mobile */
@media (max-width: 991.98px) {
    .lms-nav-bar-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
        gap: 8px;
    }
    .lms-nav-btn {
        flex: initial;
        width: 100%;
        height: 44px;
    }
}
@media (max-width: 575.98px) {
    .lms-nav-bar-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr); /* 2-column grid on mobile */
        gap: 6px;
    }
    .lms-nav-btn {
        flex: initial;
        width: 100%;
        height: 44px;
        padding: 0 6px;
        font-size: 0.78rem;
    }
    .lms-nav-btn .lms-nav-icon {
        margin-right: 6px;
        font-size: 0.95rem;
    }
}
</style>
