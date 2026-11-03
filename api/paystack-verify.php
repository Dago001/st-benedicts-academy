<?php
/**
 * api/paystack-verify.php
 * Verifies a Paystack transaction reference and returns JSON status.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/paystack.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    $input = $_POST;
}

$csrf = Security::requestCSRFToken($input);
if (!Security::verifyCSRFToken($csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token. Please reload the page.']);
    exit;
}

$reference = trim((string)($input['reference'] ?? ''));
$expectedAmount = isset($input['amount']) ? (float)$input['amount'] : null;

$result = paystack_verify_transaction($reference, $expectedAmount);
echo json_encode($result);
