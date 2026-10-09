<?php
// teacher/export.php?class_id=N - CSV of a class roster the teacher is responsible for
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/export.php';
Security::requireRole('teacher');

$classId = (int)($_GET['class_id'] ?? 0);
$db = db();
if ($classId && !Security::canAccessClass($classId)) {
    http_response_code(403);
    exit('Access denied');
}
if ($classId) {
    $where = 's.class_id = ?'; $params = [$classId];
} else {
    $tid = Security::currentTeacherId() ?? 0;
    $where = 's.class_id IN (SELECT id FROM classes WHERE teacher_id = ? UNION SELECT class_id FROM subjects WHERE teacher_id = ?)';
    $params = [$tid, $tid];
}
$rows = $db->getRows(
    "SELECT s.admission_number, u.first_name, u.last_name, s.gender, s.date_of_birth, c.class_name,
            CONCAT(pu.first_name, ' ', pu.last_name) AS parent_name, pu.phone AS parent_phone
     FROM students s JOIN users u ON s.user_id = u.id LEFT JOIN classes c ON s.class_id = c.id
     LEFT JOIN parents p ON s.parent_id = p.id LEFT JOIN users pu ON p.user_id = pu.id
     WHERE u.is_active = 1 AND $where ORDER BY c.class_name, u.first_name", $params);
csv_download('students-' . date('Ymd') . '.csv', ['Admission No', 'First Name', 'Last Name', 'Gender', 'Date of Birth', 'Class', 'Parent', 'Parent Phone'], $rows);
