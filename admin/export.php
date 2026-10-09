<?php
// admin/export.php?type=students|teachers|parents|classes|subjects|fees|results|announcements|attendance
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/export.php';
Security::requireRole('admin');

$db = db();
$type = $_GET['type'] ?? '';
$stamp = date('Ymd');
$classId = (int)($_GET['class_id'] ?? 0);

switch ($type) {
    case 'students':
        $rows = $db->getRows(
            "SELECT s.admission_number, u.first_name, u.last_name, u.email, u.phone, s.gender, s.date_of_birth,
                    CONCAT(c.class_name, ' ', COALESCE(c.section, '')) AS class_name,
                    CONCAT(pu.first_name, ' ', pu.last_name) AS parent_name, IF(u.is_active, 'Active', 'Inactive') AS status
             FROM students s JOIN users u ON s.user_id = u.id
             LEFT JOIN classes c ON s.class_id = c.id
             LEFT JOIN parents p ON s.parent_id = p.id LEFT JOIN users pu ON p.user_id = pu.id
             ORDER BY u.first_name, u.last_name");
        csv_download("students-$stamp.csv", ['Admission No', 'First Name', 'Last Name', 'Email', 'Phone', 'Gender', 'Date of Birth', 'Class', 'Parent', 'Status'], $rows);

    case 'teachers':
        $rows = $db->getRows(
            "SELECT t.employee_id, u.first_name, u.last_name, u.email, u.phone, t.qualification, t.specialization, t.date_of_hire,
                    IF(u.is_active, 'Active', 'Inactive') AS status
             FROM teachers t JOIN users u ON t.user_id = u.id ORDER BY u.first_name, u.last_name");
        csv_download("teachers-$stamp.csv", ['Employee ID', 'First Name', 'Last Name', 'Email', 'Phone', 'Qualification', 'Specialization', 'Date of Hire', 'Status'], $rows);

    case 'parents':
        $rows = $db->getRows(
            "SELECT u.first_name, u.last_name, u.email, u.phone, p.occupation, p.relationship,
                    (SELECT COUNT(*) FROM students s WHERE s.parent_id = p.id) AS children, IF(u.is_active, 'Active', 'Inactive') AS status
             FROM parents p JOIN users u ON p.user_id = u.id ORDER BY u.first_name, u.last_name");
        csv_download("parents-$stamp.csv", ['First Name', 'Last Name', 'Email', 'Phone', 'Occupation', 'Relationship', 'Children', 'Status'], $rows);

    case 'classes':
        $rows = $db->getRows(
            "SELECT c.class_name, c.section, c.academic_year, CONCAT(u.first_name, ' ', u.last_name) AS teacher, c.capacity,
                    (SELECT COUNT(*) FROM students s WHERE s.class_id = c.id) AS students, IF(c.is_active, 'Active', 'Inactive') AS status
             FROM classes c LEFT JOIN teachers t ON c.teacher_id = t.id LEFT JOIN users u ON t.user_id = u.id
             ORDER BY c.class_name, c.section");
        csv_download("classes-$stamp.csv", ['Class', 'Section', 'Academic Year', 'Class Teacher', 'Capacity', 'Students', 'Status'], $rows);

    case 'subjects':
        $rows = $db->getRows(
            "SELECT s.subject_code, s.subject_name, c.class_name, CONCAT(u.first_name, ' ', u.last_name) AS teacher, IF(s.is_active, 'Active', 'Inactive') AS status
             FROM subjects s LEFT JOIN classes c ON s.class_id = c.id LEFT JOIN teachers t ON s.teacher_id = t.id LEFT JOIN users u ON t.user_id = u.id
             ORDER BY c.class_name, s.subject_name");
        csv_download("subjects-$stamp.csv", ['Code', 'Subject', 'Class', 'Teacher', 'Status'], $rows);

    case 'fees':
        $rows = $db->getRows(
            "SELECT p.receipt_number, p.payment_date, CONCAT(u.first_name, ' ', u.last_name) AS student, s.admission_number,
                    p.amount, p.payment_method, p.term, p.academic_year, p.status
             FROM payments p JOIN students s ON p.student_id = s.id JOIN users u ON s.user_id = u.id
             ORDER BY p.payment_date DESC, p.id DESC");
        csv_download("payments-$stamp.csv", ['Receipt', 'Date', 'Student', 'Admission No', 'Amount', 'Method', 'Term', 'Academic Year', 'Status'], $rows);

    case 'results':
        $sql = "SELECT s.admission_number, CONCAT(u.first_name, ' ', u.last_name) AS student, c.class_name, sub.subject_name,
                       r.term, r.academic_year, r.assessment_type, r.score, r.max_score, r.grade, IF(r.is_approved, 'Approved', 'Pending') AS status
                FROM results r JOIN students s ON r.student_id = s.id JOIN users u ON s.user_id = u.id
                JOIN classes c ON r.class_id = c.id JOIN subjects sub ON r.subject_id = sub.id";
        $params = [];
        if ($classId) { $sql .= ' WHERE r.class_id = ?'; $params[] = $classId; }
        $rows = $db->getRows($sql . ' ORDER BY r.academic_year DESC, r.term, c.class_name, u.first_name', $params);
        csv_download("results-$stamp.csv", ['Admission No', 'Student', 'Class', 'Subject', 'Term', 'Year', 'Assessment', 'Score', 'Max', 'Grade', 'Status'], $rows);

    case 'announcements':
        $rows = $db->getRows(
            "SELECT a.created_at, a.title, a.audience, a.priority, IF(a.is_published, 'Published', 'Draft') AS status, a.expires_at,
                    CONCAT(u.first_name, ' ', u.last_name) AS author
             FROM announcements a JOIN users u ON a.created_by = u.id ORDER BY a.created_at DESC");
        csv_download("announcements-$stamp.csv", ['Created', 'Title', 'Audience', 'Priority', 'Status', 'Expires', 'Author'], $rows);

    case 'attendance':
        $from = valid_date($_GET['from'] ?? '') ?? date('Y-m-01');
        $to = valid_date($_GET['to'] ?? '') ?? date('Y-m-d');
        $month = $_GET['month'] ?? '';
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            $from = $month . '-01';
            $to = date('Y-m-t', strtotime($from));
        } elseif ($date = valid_date($_GET['date'] ?? '')) {
            $from = $to = $date;
        }
        $sql = "SELECT a.date, s.admission_number, CONCAT(u.first_name, ' ', u.last_name) AS student, c.class_name, a.status, a.remarks
                FROM attendance a JOIN students s ON a.student_id = s.id JOIN users u ON s.user_id = u.id JOIN classes c ON a.class_id = c.id
                WHERE a.date BETWEEN ? AND ?";
        $params = [$from, $to];
        if ($classId) { $sql .= ' AND a.class_id = ?'; $params[] = $classId; }
        $rows = $db->getRows($sql . ' ORDER BY a.date DESC, c.class_name, u.first_name', $params);
        csv_download("attendance-$from-to-$to.csv", ['Date', 'Admission No', 'Student', 'Class', 'Status', 'Remarks'], $rows);

    default:
        http_response_code(400);
        echo 'Unknown export type';
}
