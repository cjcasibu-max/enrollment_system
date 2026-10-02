<?php
$dirs = [
    'private_uploads/lms_materials',
    'private_uploads/lms_assignments',
    'private_uploads/lms_submissions',
    'private_uploads/lms_lessons',
];

foreach ($dirs as $rel) {
    $full = __DIR__ . '/../' . $rel;
    echo "=== $rel ===\n";
    echo "Exists: " . (is_dir($full) ? 'YES' : 'NO') . "\n";
    if (is_dir($full)) {
        $files = array_diff(scandir($full), ['.', '..', '.htaccess']);
        echo "Files count: " . count($files) . "\n";
        foreach (array_slice($files, 0, 10) as $f) {
            echo "  $f (" . filesize($full . '/' . $f) . " bytes)\n";
        }
    }
}
