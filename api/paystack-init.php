<?php
/**
 * api/paystack-init.php
 * Initializes a Paystack Hosted Checkout session and returns the Paystack authorization_url.
 * When the user completes payment on Paystack, Paystack redirects them back to callback_url (?trxref=...&reference=...).
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/ApplicationHelper.php';
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
    echo json_encode(['success' => false, 'message' => 'Invalid security token. Please reload the page and try again.']);
    exit;
}

$email = trim((string)($input['email'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $email = 'admissions@stbenedicts.edu.ng';
}

$amount = ApplicationHelper::APPLICATION_FEE;
$reference = ApplicationHelper::generatePaymentReference('PSTK-STB');

// Validate return_url so it always stays on our domain
$returnPath = trim((string)($input['return_path'] ?? '/public/admissions'));
if ($returnPath === '' || $returnPath[0] !== '/' || strpos($returnPath, '//') === 0) {
    $returnPath = '/public/admissions';
}
$callbackUrl = rtrim(BASE_URL, '/') . $returnPath;

$metadata = [
    'child_name'  => Security::sanitize($input['child_name'] ?? ''),
    'parent_name' => Security::sanitize($input['parent_name'] ?? ''),
    'phone'       => Security::sanitize($input['phone'] ?? ''),
    'purpose'     => 'Admission Application & Assessment Fee'
];

$result = paystack_initialize_transaction($email, $amount, $reference, $callbackUrl, $metadata);
if (!empty($result['success'])) {
    $_SESSION['pending_paystack_ref'] = $result['reference'];
    ApplicationHelper::recordPaymentInit(
        $result['reference'],
        $email,
        $amount,
        (string)($metadata['child_name'] ?? ''),
        (string)($metadata['parent_name'] ?? ''),
        (string)($metadata['phone'] ?? '')
    );
}

echo json_encode($result);
