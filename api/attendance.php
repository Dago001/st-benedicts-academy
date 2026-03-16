<?php
// api/attendance.php
header('Content-Type: application/json');
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/security.php';

Security::requireLogin();

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    $db = db();
    
    switch ($action) {
        case 'get_today':
            $classId = Security::sanitize($_GET['class_id'] ?? '');
            
            if (!$classId) {
                $response['message'] = 'Class ID required';
                break;
            }
            
            $attendance = $db->getRows(
                "SELECT s.id, u.first_name, u.last_name, 
                        a.status, a.remarks
                 FROM students s
                 JOIN users u ON s.user_id = u.id
                 LEFT JOIN attendance a ON a.student_id = s.id 
                     AND a.date = CURDATE() AND a.class_id = ?
                 WHERE s.class_id = ? AND u.is_active = 1
                 ORDER BY u.first_name",
                [$classId, $classId]
            );
            
            $response['success'] = true;
            $response['data'] = $attendance;
            break;
            
        case 'get_report':
            $classId = Security::sanitize($_GET['class_id'] ?? '');
            $startDate = Security::sanitize($_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days')));
            $endDate = Security::sanitize($_GET['end_date'] ?? date('Y-m-d'));
            
            $report = $db->getRows(
                "SELECT a.date, 
                        COUNT(*) as total,
                        SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present,
                        SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent,
                        SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late
                 FROM attendance a
                 WHERE a.class_id = ? AND a.date BETWEEN ? AND ?
                 GROUP BY a.date
                 ORDER BY a.date",
                [$classId, $startDate, $endDate]
            );
            
            $response['success'] = true;
            $response['data'] = $report;
            break;
            
        case 'get_student_attendance':
            $studentId = Security::sanitize($_GET['student_id'] ?? '');
            $term = Security::sanitize($_GET['term'] ?? date('Y') . ' Term 1');
            
            $attendance = $db->getRows(
                "SELECT a.date, a.status, a.remarks, c.class_name
                 FROM attendance a
                 JOIN classes c ON a.class_id = c.id
                 WHERE a.student_id = ? AND a.date >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
                 ORDER BY a.date DESC",
                [$studentId]
            );
            
            $response['success'] = true;
            $response['data'] = $attendance;
            break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $response['message'] = 'Invalid security token';
        echo json_encode($response);
        exit;
    }
    
    $db = db();
    
    switch ($action) {
        case 'mark_attendance':
            $classId = Security::sanitize($_POST['class_id'] ?? '');
            $date = Security::sanitize($_POST['date'] ?? date('Y-m-d'));
            $attendance = $_POST['attendance'] ?? [];
            
            try {
                $db->beginTransaction();
                
                foreach ($attendance as $studentId => $status) {
                    $db->insert(
                        "INSERT INTO attendance (student_id, class_id, date, status, marked_by) 
                         VALUES (?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE status = VALUES(status)",
                        [$studentId, $classId, $date, $status, $_SESSION['user_id']]
                    );
                }
                
                $db->commit();
                
                Security::logAudit('MARKED_ATTENDANCE_API', 'attendance');
                
                $response['success'] = true;
                $response['message'] = 'Attendance marked successfully';
                
            } catch (Exception $e) {
                $db->rollback();
                $response['message'] = 'Error: ' . $e->getMessage();
            }
            break;
    }
}

echo json_encode($response);
?>