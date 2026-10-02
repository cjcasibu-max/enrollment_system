<?php
/**
 * Sync 108 Subjects into Curriculums and Curriculum Subjects
 */
require_once __DIR__ . '/config/database.php';

try {
    $pdo->beginTransaction();

    echo "--- 1. ENSURING CURRICULUMS EXIST ---\n";
    $bsmtProgId = (int)$pdo->query("SELECT id FROM programs WHERE program_code = 'BSMT'")->fetchColumn();
    $bsmareProgId = (int)$pdo->query("SELECT id FROM programs WHERE program_code = 'BSMarE'")->fetchColumn();

    if (!$bsmtProgId || !$bsmareProgId) {
        throw new RuntimeException("Programs BSMT or BSMarE not found.");
    }

    // Insert or fetch BSMT Curriculum
    $currCheck = $pdo->prepare("SELECT id FROM curriculums WHERE program_id = ? AND is_active = 1 LIMIT 1");
    $currCheck->execute([$bsmtProgId]);
    $bsmtCurrId = $currCheck->fetchColumn();
    if (!$bsmtCurrId) {
        $insCurr = $pdo->prepare("
            INSERT INTO curriculums (program_id, curriculum_name, effective_year, is_active, description)
            VALUES (?, 'BSMT Curriculum 2026-2027', '2026-2027', 1, 'Standard CHED/MARINA Aligned Curriculum for Bachelor of Science in Marine Transportation')
        ");
        $insCurr->execute([$bsmtProgId]);
        $bsmtCurrId = (int)$pdo->lastInsertId();
    }

    // Insert or fetch BSMarE Curriculum
    $currCheck->execute([$bsmareProgId]);
    $bsmareCurrId = $currCheck->fetchColumn();
    if (!$bsmareCurrId) {
        $insCurr = $pdo->prepare("
            INSERT INTO curriculums (program_id, curriculum_name, effective_year, is_active, description)
            VALUES (?, 'BSMarE Curriculum 2026-2027', '2026-2027', 1, 'Standard CHED/MARINA Aligned Curriculum for Bachelor of Science in Marine Engineering')
        ");
        $insCurr->execute([$bsmareProgId]);
        $bsmareCurrId = (int)$pdo->lastInsertId();
    }

    echo "BSMT Curriculum ID: {$bsmtCurrId}\n";
    echo "BSMarE Curriculum ID: {$bsmareCurrId}\n";

    echo "--- 2. SYNCING SUBJECTS TO CURRICULUM_SUBJECTS ---\n";
    $subjectsStmt = $pdo->query("SELECT * FROM subjects ORDER BY id ASC");
    $subjects = $subjectsStmt->fetchAll(PDO::FETCH_ASSOC);

    $csCheck = $pdo->prepare("SELECT id FROM curriculum_subjects WHERE curriculum_id = ? AND subject_id = ? LIMIT 1");
    $csInsert = $pdo->prepare("
        INSERT INTO curriculum_subjects (curriculum_id, subject_id, year_level, semester, units, is_required, display_order, subject_type)
        VALUES (?, ?, ?, ?, ?, 1, ?, ?)
    ");
    $subUpdate = $pdo->prepare("UPDATE subjects SET curriculum_id = ? WHERE id = ?");

    $insertedCs = 0;
    $orderCounters = [];

    foreach ($subjects as $s) {
        $progId = (int)$s['program_id'];
        $currId = ($progId === $bsmtProgId) ? $bsmtCurrId : $bsmareCurrId;

        // Update subjects.curriculum_id
        $subUpdate->execute([$currId, $s['id']]);

        // Key for display order
        $termKey = "{$currId}_{$s['year_level']}_{$s['semester_name']}";
        if (!isset($orderCounters[$termKey])) {
            $orderCounters[$termKey] = 1;
        } else {
            $orderCounters[$termKey]++;
        }
        $displayOrder = $orderCounters[$termKey];

        // Check if exists in curriculum_subjects
        $csCheck->execute([$currId, $s['id']]);
        $existingCsId = $csCheck->fetchColumn();

        if (!$existingCsId) {
            $yearLevel = $s['year_level'] ?: '1st Year';
            $semester = $s['semester_name'] ?: '1st Semester';
            $units = (float)($s['units'] ?: 3.0);
            $type = $s['subject_type'] ?: 'General Education';

            $csInsert->execute([$currId, $s['id'], $yearLevel, $semester, $units, $displayOrder, $type]);
            $insertedCs++;
        }
    }

    echo "Total subjects synced to curriculum_subjects: {$insertedCs}\n";

    // Set sample prerequisites if none exist
    $prereqCount = (int)$pdo->query("SELECT count(*) FROM subject_prerequisites")->fetchColumn();
    echo "Current prerequisites count: {$prereqCount}\n";

    if ($prereqCount === 0) {
        echo "Adding sample prerequisites between sequential subjects...\n";
        // Map prerequisites: e.g. BSMT-MT104 requires BSMT-MT103, BSMT-GE104 requires BSMT-GE101, etc.
        $pairs = [
            // BSMT sequential
            'BSMT-GE104' => 'BSMT-GE101',
            'BSMT-MT104' => 'BSMT-MT103',
            'BSMT-MT201' => 'BSMT-MT105',
            'BSMT-MT202' => 'BSMT-MT106',
            'BSMT-MT206' => 'BSMT-MT201',
            'BSMT-MT207' => 'BSMT-MT203',
            'BSMT-MT301' => 'BSMT-MT206',
            'BSMT-PE102'  => 'BSMT-PE101',
            'BSMT-NSTP102'=> 'BSMT-NSTP101',
            // BSMarE sequential
            'BSMarE-GE104' => 'BSMarE-GE101',
            'BSMarE-ME104' => 'BSMarE-ME103',
            'BSMarE-ME201' => 'BSMarE-ME105',
            'BSMarE-ME202' => 'BSMarE-ME106',
            'BSMarE-ME206' => 'BSMarE-ME201',
            'BSMarE-ME207' => 'BSMarE-ME203',
            'BSMarE-ME301' => 'BSMarE-ME206',
            'BSMarE-PE102'  => 'BSMarE-PE101',
            'BSMarE-NSTP102'=> 'BSMarE-NSTP101',
        ];

        $codeMap = [];
        foreach ($subjects as $s) {
            $codeMap[$s['subject_code']] = (int)$s['id'];
        }

        $insPrereq = $pdo->prepare("INSERT IGNORE INTO subject_prerequisites (subject_id, prerequisite_subject_id) VALUES (?, ?)");
        $pInserted = 0;
        foreach ($pairs as $subjCode => $preCode) {
            if (isset($codeMap[$subjCode], $codeMap[$preCode])) {
                $insPrereq->execute([$codeMap[$subjCode], $codeMap[$preCode]]);
                $pInserted++;
            }
        }
        echo "Sample prerequisites created: {$pInserted}\n";
    }

    $pdo->commit();
    echo "=== SYNC COMPLETED SUCCESSFULLY ===\n";

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "ERROR: " . $e->getMessage() . "\n";
}
