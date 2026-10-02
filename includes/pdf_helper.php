<?php
/**
 * Simple Pure-PHP PDF Generator for Academic Documents
 * Generates standard PDF 1.4 documents without requiring external dependencies or extensions.
 */

if (!function_exists('generateCorPdf')) {
    function generateCorPdf(array $student, ?array $term, array $enrollments): string {
        $title = "NCST MARITIME ACADEMY";
        $docTitle = "OFFICIAL CERTIFICATE OF REGISTRATION";
        $termStr = ($term['school_year'] ?? 'Current Term') . ' - ' . ucfirst($term['semester'] ?? '1st') . ' Semester';
        
        $fullName = trim(($student['first_name'] ?? '') . ' ' . ($student['middle_name'] ?? '') . ' ' . ($student['last_name'] ?? ''));
        if ($fullName === '') {
            $fullName = 'Cadet Student';
        }
        $studentId = '#' . str_pad((string)($student['id'] ?? 0), 5, '0', STR_PAD_LEFT);
        $program = !empty($student['program_applying_for']) ? $student['program_applying_for'] : (!empty($student['program_code']) ? $student['program_code'] : 'BSMT');
        $rawYear = trim((string)($student['year_level'] ?? ''));
        $yearLevel = ($rawYear !== '' && strtolower($rawYear) !== 'year level') ? $rawYear : 'Unassigned';
        
        $lines = [
            "STUDENT INFORMATION",
            "--------------------------------------------------------------------------------",
            sprintf("Student Name: %-34s Student ID: %s", $fullName, $studentId),
            sprintf("Program:      %-34s Year Level:  %s", $program, $yearLevel),
            sprintf("Term:         %-34s Status:      %s", $termStr, "Officially Enrolled"),
            "--------------------------------------------------------------------------------",
            "",
            "OFFICIALLY REGISTERED COURSES / SUBJECTS:",
            "================================================================================",
            sprintf("%-10s | %-30s | %-20s | %s", "CODE", "COURSE TITLE", "SCHEDULE / ROOM", "UNITS"),
            "--------------------------------------------------------------------------------"
        ];
        
        $totalUnits = 0.0;
        foreach ($enrollments as $row) {
            $units = (float)($row['units'] ?? 0.0);
            $totalUnits += $units;
            $code = substr((string)($row['course_code'] ?? 'SUBJ'), 0, 10);
            $name = substr((string)($row['course_name'] ?? 'Course Subject'), 0, 30);
            $sched = substr((string)($row['schedule'] ?? 'TBA') . ' ' . ($row['room'] ?? ''), 0, 20);
            $lines[] = sprintf("%-10s | %-30s | %-20s | %4.1f", $code, $name, $sched, $units);
        }
        
        $lines[] = "================================================================================";
        $lines[] = sprintf("%-64s TOTAL UNITS: %4.1f", "", $totalUnits);
        $lines[] = "";
        $lines[] = "--------------------------------------------------------------------------------";
        $lines[] = "Issued on: " . date('F j, Y g:i A') . " | NCST Maritime Academy Registrar's Office";
        $lines[] = "This document is valid for institutional registration and official academic use.";

        // Construct PDF stream
        $stream = "BT\n/F2 16 Tf\n50 740 Td\n(" . addcslashes($title, "()\\") . ") Tj\nET\n";
        $stream .= "BT\n/F2 12 Tf\n50 722 Td\n(" . addcslashes($docTitle, "()\\") . ") Tj\nET\n";
        $stream .= "BT\n/F1 10 Tf\n50 706 Td\n(" . addcslashes($termStr, "()\\") . ") Tj\nET\n";
        
        $y = 675;
        foreach ($lines as $line) {
            $isBold = (strpos($line, 'STUDENT INFORMATION') === 0 
                || strpos($line, 'OFFICIALLY REGISTERED') === 0 
                || strpos($line, 'TOTAL UNITS') !== false 
                || strpos($line, 'CODE') === 0);
            $font = $isBold ? '/F2 9' : '/F1 8.5';
            $stream .= "BT\n{$font} Tf\n50 {$y} Td\n(" . addcslashes($line, "()\\") . ") Tj\nET\n";
            $y -= 14;
        }
        
        $len = strlen($stream);
        $objs = [
            1 => "<< /Type /Catalog /Pages 2 0 R >>",
            2 => "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
            3 => "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>",
            4 => "<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>",
            5 => "<< /Type /Font /Subtype /Type1 /BaseFont /Courier-Bold >>",
            6 => "<< /Length {$len} >>\nstream\n{$stream}\nendstream"
        ];
        
        $out = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objs as $i => $body) {
            $offsets[$i] = strlen($out);
            $out .= "{$i} 0 obj\n{$body}\nendobj\n";
        }
        $xrefOffset = strlen($out);
        $out .= "xref\n0 7\n0000000000 65535 f \n";
        for ($i = 1; $i <= 6; $i++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $out .= "trailer\n<< /Size 7 /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF\n";
        return $out;
    }
}
