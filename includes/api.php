<?php
// includes/api.php - shared bootstrap for JSON API endpoints
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/**
 * Initialise an endpoint.
 *  $methods     allowed HTTP methods
 *  $roles       roles allowed (null = any logged-in user, false = public)
 * Returns the request input (JSON body merged over POST).
 */
function api_init(array $methods = ['GET', 'POST'], $roles = null) {
    $method = $_SERVER['REQUEST_METHOD'];
    if (!in_array($method, $methods, true)) {
        header('Allow: ' . implode(', ', $methods));
        json_response(['success' => false, 'message' => 'Method not allowed'], 405);
    }
    if ($roles !== false) {
        Security::requireLogin();
        if ($roles !== null && !Security::hasRole($roles)) {
            json_response(['success' => false, 'message' => 'Permission denied'], 403);
        }
    }

    $input = $_POST;
    if ($method === 'POST' && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
        $json = json_decode(file_get_contents('php://input'), true);
        if (is_array($json)) {
            $input = $json + $input;
        }
    }
    if ($method !== 'GET') {
        $token = $input[CSRF_TOKEN_NAME] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($roles !== false && !Security::verifyCSRFToken($token)) {
            json_response(['success' => false, 'message' => 'Invalid security token'], 419);
        }
    }
    return $input;
}

function api_error($message, $status = 400) {
    json_response(['success' => false, 'message' => $message], $status);
}

function api_ok(array $data = []) {
    json_response(['success' => true] + $data);
}

/** Positive integer from a value or null. */
function api_int($value) {
    return (is_scalar($value) && preg_match('/^\d{1,10}$/', (string)$value) && (int)$value > 0) ? (int)$value : null;
}

/** Validate a Y-m-d date; returns the string or null. */
function api_date($value) {
    if (!is_string($value)) return null;
    $d = DateTime::createFromFormat('Y-m-d', $value);
    return ($d && $d->format('Y-m-d') === $value) ? $value : null;
}

/** Log the exception, return a generic message. */
function api_exception(Throwable $e, $message = 'A server error occurred') {
    error_log('API error in ' . basename($_SERVER['SCRIPT_NAME']) . ': ' . $e->getMessage());
    api_error(DEBUG_MODE ? $e->getMessage() : $message, 500);
}
