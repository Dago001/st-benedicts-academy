<?php
// api/get-teacher-subjects.php
require_once __DIR__ . '/../includes/api.php';

api_init(['GET'], ['admin', 'teacher']);
$teacherId = api_int($_GET['teacher_id'] ?? null);
if (!$teacherId) api_error('Teacher ID required');
if (Security::hasRole('teacher') && Security::currentTeacherId() !== $teacherId) {
    api_error('Permission denied', 403);
}

$subjects = db()->getRows(
    "SELECT s.*, c.class_name
     FROM subjects s JOIN classes c ON s.class_id = c.id
     WHERE s.teacher_id = ? AND s.is_active = 1
     ORDER BY c.class_name, s.subject_name",
    [$teacherId]
);
api_ok(['subjects' => $subjects]);
