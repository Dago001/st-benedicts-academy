<?php
// api/generate-login.php - admin resets a student's password and gets the new one once
require_once __DIR__ . '/../includes/api.php';

$input = api_init(['POST'], 'admin');
$studentId = api_int($input['student_id'] ?? null);
if (!$studentId) api_error('Student ID required');

$db = db();
$student = $db->getRow('SELECT s.user_id, u.username FROM students s JOIN users u ON s.user_id = u.id WHERE s.id = ?', [$studentId]);
if (!$student) api_error('Student not found', 404);

// Letters and digits, with at least one of each
do {
    $password = generateRandomString(10);
} while (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password));

$db->query('UPDATE users SET password_hash = ?, login_attempts = 0, locked_until = NULL WHERE id = ?', [Security::hashPassword($password), $student['user_id']]);
Security::logAudit('GENERATED_LOGIN', 'users', $student['user_id']);

header('Cache-Control: no-store');
api_ok(['username' => $student['username'], 'password' => $password]);
