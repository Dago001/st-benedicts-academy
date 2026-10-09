<?php
// api/get-assignment.php - one assignment, for the teacher who owns it
require_once __DIR__ . '/../includes/api.php';

api_init(['GET'], 'teacher');
$id = api_int($_GET['id'] ?? null);
if (!$id) api_error('Assignment ID required');
$row = db()->getRow('SELECT * FROM homework WHERE id = ? AND teacher_id = ?', [$id, Security::currentTeacherId() ?? 0]);
if (!$row) api_error('Assignment not found', 404);
api_ok(['assignment' => $row]);
