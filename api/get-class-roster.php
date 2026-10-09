<?php
// api/get-class-roster.php
require_once __DIR__ . '/../includes/api.php';

api_init(['GET'], ['admin', 'teacher']);
$classId = api_int($_GET['class_id'] ?? null);
if (!$classId) api_error('Class ID required');
if (!Security::canAccessClass($classId)) api_error('Permission denied', 403);

$roster = db()->getRows(
    "SELECT s.id, s.admission_number, u.first_name, u.last_name, s.gender,
            CONCAT(pu.first_name, ' ', pu.last_name) AS parent_name
     FROM students s
     JOIN users u ON s.user_id = u.id
     LEFT JOIN parents p ON s.parent_id = p.id
     LEFT JOIN users pu ON p.user_id = pu.id
     WHERE s.class_id = ? AND u.is_active = 1 AND u.deleted_at IS NULL
     ORDER BY u.first_name, u.last_name",
    [$classId]
);
api_ok(['roster' => $roster]);
