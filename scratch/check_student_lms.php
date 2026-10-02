<?php
require_once __DIR__ . '/../config/database.php';
print_r($pdo->query('SHOW CREATE TABLE assessment_items')->fetch(PDO::FETCH_ASSOC));
print_r($pdo->query('SHOW CREATE TABLE payment_allocations')->fetch(PDO::FETCH_ASSOC));
