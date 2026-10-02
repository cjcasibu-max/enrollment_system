<?php
/**
 * Official Number Generator
 * Generates unique Application Numbers and Student ID Numbers.
 */

function generateApplicationNumber(PDO $pdo): string {
    $year = date('Y');
    $count = (int)$pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
    return sprintf("%s-ADM-%05d", $year, $count + 1);
}

function generateStudentNumber(PDO $pdo): string {
    $year = date('Y');
    $count = (int)$pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
    return sprintf("%s-%05d", $year, $count + 1);
}
