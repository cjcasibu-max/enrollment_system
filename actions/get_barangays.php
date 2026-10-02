<?php
/**
 * Barangay Lookup Endpoint
 * Returns a JSON array of barangay names for the given PSGC city/municipality code.
 * Used by enrollee/apply.php to populate the Barangay dropdown dynamically.
 *
 * Accepts: GET ?code=<9-digit PSGC code>
 * Returns: JSON array of strings, or {"error":"..."} on failure.
 */

// No session needed — this is a public read-only data endpoint.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=86400');   // cache for 1 day (data is static)

$rawCode = isset($_GET['code']) ? trim($_GET['code']) : '';

// Validate: must be exactly 9 digits.
if (!preg_match('/^\d{9}$/', $rawCode)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid PSGC code.']);
    exit;
}

// Build the file path — resolve to prevent directory traversal.
$dataDir  = realpath(__DIR__ . '/../assets/data/ph/barangays');
$filePath = realpath($dataDir . '/' . $rawCode . '.json');

if ($filePath === false || strpos($filePath, $dataDir) !== 0 || !is_file($filePath)) {
    // Code not found — return an empty array rather than a 404 so the UI degrades gracefully.
    echo json_encode([]);
    exit;
}

$contents = file_get_contents($filePath);
if ($contents === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to read barangay data.']);
    exit;
}

// Pass through raw JSON (already a valid JSON array from the PSGC dataset).
echo $contents;
