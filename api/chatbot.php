<?php
// api/chatbot.php - public chat assistant endpoint (no login, CSRF + rate limited)
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/chatbot.php';

$input = api_init(['POST'], false);

// The session token issued with the page proves the request came from our own site
if (!Security::verifyCSRFToken($input[CSRF_TOKEN_NAME] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    api_error('Your session expired. Please reload the page.', 419);
}

$message = trim((string)($input['message'] ?? ''));
if ($message === '') api_error('Please type a message.');
if (mb_strlen($message) > 300) api_error('Please keep your message under 300 characters.');
$message = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $message);

// Rate limit per IP: 40 messages / 10 minutes (survives cookie clearing)
$db = db();
$ipHash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . (getenv('APP_KEY') ?: ROOT_PATH));
$recent = (int)$db->getRow('SELECT COUNT(*) c FROM chatbot_logs WHERE ip_hash = ? AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)', [$ipHash])['c'];
if ($recent >= 40) {
    header('Retry-After: 600');
    api_error('You are sending messages too quickly. Please wait a few minutes or call the school office.', 429);
}

try {
    $result = SchoolBot::answer($message, ['last_intent' => $_SESSION['chat_last_intent'] ?? null]);
    if (!empty($result['intent'])) $_SESSION['chat_last_intent'] = $result['intent'];
    $db->query('INSERT INTO chatbot_logs (ip_hash, question, intent, matched) VALUES (?, ?, ?, ?)',
        [$ipHash, SchoolBot::redact($message), $result['intent'] ?? null, !empty($result['matched']) ? 1 : 0]);
} catch (Throwable $e) {
    error_log('chatbot: ' . $e->getMessage());
    api_ok(['reply' => ['Sorry, I ran into a problem. Please try again, or contact the school office directly.'],
            'links' => [['label' => 'Call ' . school_phone(), 'url' => 'tel:' . school_phone()]], 'suggestions' => [], 'list' => [], 'note' => '']);
}

api_ok([
    'reply' => $result['reply'],
    'list' => $result['list'] ?? [],
    'note' => $result['note'] ?? '',
    'links' => $result['links'] ?? [],
    'suggestions' => array_slice($result['suggestions'] ?? [], 0, 4),
]);
