<?php
// api/update-subject-assignment.php
require_once __DIR__ . '/../includes/api.php';

$input = api_init(['POST'], 'admin');
$subjectId = api_int($input['subject_id'] ?? null);
$teacherId = api_int($input['teacher_id'] ?? null);
$action = $input['action'] ?? '';
if (!$subjectId || !$teacherId || !in_array($action, ['assign', 'unassign'], true)) api_error('Invalid parameters');

$db = db();
if (!$db->getRow('SELECT id FROM subjects WHERE id = ?', [$subjectId])) api_error('Subject not found', 404);
if (!$db->getRow('SELECT id FROM teachers WHERE id = ?', [$teacherId])) api_error('Teacher not found', 404);

try {
    if ($action === 'assign') {
        $db->query('UPDATE subjects SET teacher_id = ? WHERE id = ?', [$teacherId, $subjectId]);
    } else {
        $db->query('UPDATE subjects SET teacher_id = NULL WHERE id = ? AND teacher_id = ?', [$subjectId, $teacherId]);
    }
    Security::logAudit('UPDATED_SUBJECT_ASSIGNMENT', 'subjects', $subjectId);
    api_ok();
} catch (Throwable $e) {
    api_exception($e);
}
