<?php
require_once '../includes/auth_check.php';
checkRole(['student', 'enrollee']);
require_once '../config/database.php';

$redirectUrl = (($_SESSION['role'] ?? '') === 'enrollee') ? '../enrollee/profile_edit' : '../student/profile_edit';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'update_profile') {
	$_SESSION['flash_error'] = 'Invalid profile action.';
	header("Location: {$redirectUrl}");
	exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
	$_SESSION['flash_error'] = 'Security validation failed. Please try again.';
	header("Location: {$redirectUrl}");
	exit;
}

$userId = (int)$_SESSION['user_id'];
$contactNumber = trim($_POST['contact_number'] ?? '');
$addressStreet = trim($_POST['address_street'] ?? '');
$addressBarangay = trim($_POST['address_barangay'] ?? '');
$addressCity = trim($_POST['address_city'] ?? '');
$addressProvince = trim($_POST['address_province'] ?? '');
$addressZipCode = trim($_POST['address_zip_code'] ?? '');
$guardianName = trim($_POST['guardian_name'] ?? '');
$guardianRelationship = trim($_POST['guardian_relationship'] ?? '');
$guardianContact = trim($_POST['guardian_contact_number'] ?? '');
$guardianAddress = trim($_POST['guardian_address'] ?? '');
$religion = trim($_POST['religion'] ?? '');

try {
	$validationErrors = [];
	if ($contactNumber === '') {
		$validationErrors[] = 'Contact Number is required.';
	} elseif (!preg_match('/^09\d{9}$/', $contactNumber)) {
		$validationErrors[] = 'Contact Number must be a valid 11-digit Philippine mobile number beginning with 09.';
	}
	if ($addressCity === '' || $addressProvince === '') {
		$validationErrors[] = 'City and Province are required.';
	}
	if ($guardianName === '') {
		$validationErrors[] = 'Guardian Name is required.';
	}
	if ($guardianRelationship === '') {
		$validationErrors[] = 'Guardian Relationship is required.';
	}
	if ($guardianAddress === '') {
		$validationErrors[] = 'Guardian Address is required.';
	}
	if ($guardianContact !== '' && !preg_match('/^09\d{9}$/', $guardianContact)) {
		$validationErrors[] = 'Guardian Contact must be a valid 11-digit Philippine mobile number beginning with 09.';
	}
	if ($validationErrors) {
		throw new RuntimeException(implode(' ', $validationErrors));
	}
	$stmt = $pdo->prepare(
		'UPDATE students SET contact_number = :contact_number,
		 address_street = :address_street, address_barangay = :address_barangay,
		 address_city = :address_city, address_province = :address_province, address_zip_code = :address_zip_code,
		 guardian_name = :guardian_name, guardian_relationship = :guardian_relationship,
		 guardian_contact_number = :guardian_contact_number, guardian_address = :guardian_address, religion = :religion
		 WHERE user_id = :user_id'
	);
	$stmt->execute([
		'contact_number' => $contactNumber,
		'address_street' => $addressStreet ?: null,
		'address_barangay' => $addressBarangay ?: null,
		'address_city' => $addressCity,
		'address_province' => $addressProvince,
		'address_zip_code' => $addressZipCode ?: null,
		'guardian_name' => $guardianName,
		'guardian_relationship' => $guardianRelationship,
		'guardian_contact_number' => $guardianContact,
		'guardian_address' => $guardianAddress,
		'religion' => $religion ?: null,
		'user_id' => $userId,
	]);
	$_SESSION['flash_success'] = 'Profile information updated.';
} catch (\RuntimeException $e) {
	error_log('Student profile update failed: ' . $e->getMessage());
	$_SESSION['flash_error'] = $e->getMessage();
} catch (\Throwable $e) {
	error_log('Student profile update failed (unexpected): ' . $e->getMessage());
	$_SESSION['flash_error'] = 'An unexpected error occurred. Please try again.';
}

header("Location: {$redirectUrl}");
exit;
