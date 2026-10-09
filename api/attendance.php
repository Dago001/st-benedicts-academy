<?php
// api/attendance.php
require_once __DIR__ . '/../includes/api.php';

$input = api_init(['GET', 'POST']);
$db = db();
$role = $_SESSION['user_role'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    switch ($_GET['action'] ?? '') {
        case 'get_today':
        case 'get_report':
            $classId = api_int($_GET['class_id'] ?? null);
            if (!$classId) api_error('Class ID required');
            if (!in_array($role, ['admin', 'teacher'], true) || !Security::canAccessClass($classId)) {
                api_error('Permission denied', 403);
            }
            if ($_GET['action'] === 'get_today') {
                $rows = $db->getRows(
                    "SELECT s.id, u.first_name, u.last_name, a.status, a.remarks
                     FROM students s
                     JOIN users u ON s.user_id = u.id
                     LEFT JOIN attendance a ON a.student_id = s.id AND a.date = CURDATE()
                     WHERE s.class_id = ? AND u.is_active = 1 AND u.deleted_at IS NULL
                     ORDER BY u.first_name, u.last_name",
                    [$classId]
                );
                api_ok(['data' => $rows]);
            }
            $start = api_date($_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days')));
            $end = api_date($_GET['end_date'] ?? date('Y-m-d'));
            if (!$start || !$end || $start > $end) api_error('Invalid date range');
            $rows = $db->getRows(
                "SELECT a.date, COUNT(*) AS total,
                        SUM(a.status = 'present') AS present,
                        SUM(a.status = 'absent') AS absent,
                        SUM(a.status = 'late') AS late
                 FROM attendance a
                 WHERE a.class_id = ? AND a.date BETWEEN ? AND ?
                 GROUP BY a.date ORDER BY a.date",
                [$classId, $start, $end]
            );
            api_ok(['data' => $rows]);

        case 'get_student_attendance':
            $studentId = api_int($_GET['student_id'] ?? null);
            if (!$studentId) api_error('Student ID required');
            if (!Security::canAccessStudent($studentId)) api_error('Permission denied', 403);
            $rows = $db->getRows(
                "SELECT a.date, a.status, a.remarks, c.class_name
                 FROM attendance a JOIN classes c ON a.class_id = c.id
                 WHERE a.student_id = ? AND a.date >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
                 ORDER BY a.date DESC",
                [$studentId]
            );
            api_ok(['data' => $rows]);

        default:
            api_error('Invalid action');
    }
}

// POST
if (($input['action'] ?? '') !== 'mark_attendance') api_error('Invalid action');
if (!in_array($role, ['admin', 'teacher'], true)) api_error('Permission denied', 403);

$classId = api_int($input['class_id'] ?? null);
$date = api_date($input['date'] ?? date('Y-m-d'));
$attendance = $input['attendance'] ?? [];
if (!$classId || !$date || !is_array($attendance) || !$attendance) api_error('Class, date and attendance are required');
if ($date > date('Y-m-d')) api_error('Cannot mark attendance for a future date');
if (!Security::canAccessClass($classId, true)) api_error('Permission denied', 403);

try {
    $count = save_attendance($classId, $date, $attendance, $input['remarks'] ?? [], $_SESSION['user_id']);
    Security::logAudit('MARKED_ATTENDANCE_API', 'attendance', $classId);
    api_ok(['message' => 'Attendance marked successfully', 'updated' => $count]);
} catch (Throwable $e) {
    api_exception($e);
}
