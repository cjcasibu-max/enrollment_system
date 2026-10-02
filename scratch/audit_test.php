<?php
require_once __DIR__ . '/../config/database.php';
$db = $pdo;

echo "=== DEMO TEACHERS & SECTION SUBJECTS ===\n";
$stmt = $db->query('
    SELECT ss.id as ss_id, ss.section_id, ss.subject_id, sec.section_name, 
           subj.subject_code, u.id as teacher_id, u.first_name, u.last_name
    FROM section_subjects ss
    JOIN sections sec ON sec.id = ss.section_id
    JOIN subjects subj ON subj.id = ss.subject_id
    JOIN users u ON u.id = ss.instructor_id
    WHERE u.username LIKE "demo_%"
    ORDER BY ss.id
');
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "ss_id: {$r['ss_id']} | Sec: {$r['section_name']} (id: {$r['section_id']}) | Subj: {$r['subject_code']} (id: {$r['subject_id']}) | Teacher: {$r['first_name']} {$r['last_name']} (t_id: {$r['teacher_id']})\n";
}

echo "\n=== DEMO STUDENTS & ENROLLMENTS ===\n";
$stmt = $db->query('
    SELECT s.id as student_id, u.username, u.first_name, u.last_name, 
           e.section_id, sec.section_name, s.enrollment_status, e.status as enrollment_status_e
    FROM students s
    JOIN users u ON u.id = s.user_id
    JOIN enrollments e ON e.student_id = s.id
    JOIN sections sec ON sec.id = e.section_id
    WHERE u.username LIKE "demo_%"
');
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "student_id: {$r['student_id']} | User: {$r['username']} ({$r['first_name']} {$r['last_name']}) | Sec: {$r['section_name']} (id: {$r['section_id']}) | enroll_status: {$r['enrollment_status']} | e.status: {$r['enrollment_status_e']}\n";
}

echo "\n=== LMS CONTENT AUDIT PER SECTION_SUBJECT ===\n";
$stmt = $db->query('
    SELECT 
        ss.id as ss_id,
        sec.section_name,
        subj.id as subject_id,
        subj.subject_code,
        (SELECT COUNT(*) FROM lms_materials WHERE section_subject_id = ss.id) as materials_all,
        (SELECT COUNT(*) FROM lms_materials WHERE section_subject_id = ss.id AND is_available = 1) as materials_avail,
        (SELECT COUNT(*) FROM lms_assignments WHERE section_subject_id = ss.id) as assignments_all,
        (SELECT COUNT(*) FROM lms_assignments WHERE section_subject_id = ss.id AND is_published = 1) as assignments_pub,
        (SELECT COUNT(*) FROM lms_quizzes WHERE section_subject_id = ss.id) as quizzes_all,
        (SELECT COUNT(*) FROM lms_quizzes WHERE section_subject_id = ss.id AND is_published = 1) as quizzes_pub,
        (SELECT COUNT(*) FROM lms_announcements WHERE section_subject_id = ss.id) as announcements_all,
        (SELECT COUNT(*) FROM lms_announcements WHERE section_subject_id = ss.id AND is_published = 1 AND (published_at IS NULL OR published_at <= NOW())) as announcements_pub,
        (SELECT COUNT(*) FROM lms_modules WHERE section_subject_id = ss.id) as modules_all,
        (SELECT COUNT(*) FROM lms_modules WHERE section_subject_id = ss.id AND is_published = 1) as modules_pub
    FROM section_subjects ss
    JOIN sections sec ON sec.id = ss.section_id
    JOIN subjects subj ON subj.id = ss.subject_id
    ORDER BY ss.id
');
require_once __DIR__ . '/../includes/lms_access.php';

$teacherUserId = 91; // demo_teacher1 (Capt. Roberto Santos)
$studentId = 60;     // demo_student1 (Alexander Cruz, BSMT-1A)
$subjectId = 4;      // BSMT-MT101
$ssId = 47;          // BSMT-1A - BSMT-MT101

echo "\n=== TEACHER ROW COUNTS VS STUDENT QUERY RESULTS (BSMT-1A, BSMT-MT101) ===\n";

// 1. Materials
$t_mat = $db->query("SELECT id, title, file_path, is_available FROM lms_materials WHERE section_subject_id = $ssId")->fetchAll(PDO::FETCH_ASSOC);
$s_mat = fetchLmsMaterialsForStudentSubject($db, $studentId, $subjectId);
echo "Materials (ss_id $ssId):\n";
echo "  Teacher total rows: " . count($t_mat) . "\n";
foreach ($t_mat as $m) {
    echo "    ID: {$m['id']} | Title: {$m['title']} | is_available: {$m['is_available']} | file: {$m['file_path']}\n";
}
echo "  Student query returned: " . count($s_mat) . "\n";

// 2. Assignments
$t_asg = $db->query("SELECT id, title, is_published, due_at FROM lms_assignments WHERE section_subject_id = $ssId")->fetchAll(PDO::FETCH_ASSOC);
$s_asg = fetchLmsAssignmentsForStudentSubject($db, $studentId, $subjectId);
echo "Assignments (ss_id $ssId):\n";
echo "  Teacher total rows: " . count($t_asg) . "\n";
foreach ($t_asg as $a) {
    echo "    ID: {$a['id']} | Title: {$a['title']} | is_published: {$a['is_published']} | due_at: {$a['due_at']}\n";
}
echo "  Student query returned: " . count($s_asg) . "\n";

// 3. Quizzes
$t_q = $db->query("SELECT id, title, is_published, opens_at, closes_at FROM lms_quizzes WHERE section_subject_id = $ssId")->fetchAll(PDO::FETCH_ASSOC);
$s_q = fetchLmsQuizzesForStudentSubject($db, $studentId, $subjectId);
echo "Quizzes (ss_id $ssId):\n";
echo "  Teacher total rows: " . count($t_q) . "\n";
foreach ($t_q as $q) {
    echo "    ID: {$q['id']} | Title: {$q['title']} | is_published: {$q['is_published']} | opens_at: {$q['opens_at']} | closes_at: {$q['closes_at']}\n";
}
echo "  Student query returned: " . count($s_q) . "\n";

// 4. Announcements
$t_ann = $db->query("SELECT id, title, is_published, published_at FROM lms_announcements WHERE section_subject_id = $ssId")->fetchAll(PDO::FETCH_ASSOC);
$s_ann = fetchLmsAnnouncementsForStudentSubject($db, $studentId, $subjectId);
echo "Announcements (ss_id $ssId):\n";
echo "  Teacher total rows: " . count($t_ann) . "\n";
foreach ($t_ann as $an) {
    echo "    ID: {$an['id']} | Title: {$an['title']} | is_published: {$an['is_published']} | published_at: {$an['published_at']}\n";
}
echo "  Student query returned: " . count($s_ann) . "\n";

// 5. Modules
$t_mod = $db->query("SELECT id, title, is_published FROM lms_modules WHERE section_subject_id = $ssId")->fetchAll(PDO::FETCH_ASSOC);
$s_mod = fetchLmsModulesForStudentSubject($db, $studentId, $subjectId);
echo "Modules (ss_id $ssId):\n";
echo "  Teacher total rows: " . count($t_mod) . "\n";
echo "  Student query returned: " . count($s_mod) . "\n";

