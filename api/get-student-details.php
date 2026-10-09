<?php
// api/get-student-details.php - student profile for staff who may see the student
require_once __DIR__ . '/../includes/api.php';

api_init(['GET'], ['admin', 'teacher']);
$id = api_int($_GET['id'] ?? null);
if (!$id) api_error('Student ID required');
if (!Security::canAccessStudent($id)) api_error('Permission denied', 403);

$student = db()->getRow(
    "SELECT s.id, s.admission_number, s.date_of_birth, s.gender, s.address, s.blood_group, s.medical_notes,
            u.first_name, u.last_name, u.email, u.phone,
            CONCAT(c.class_name, ' ', COALESCE(c.section, '')) AS class_name,
            CONCAT(pu.first_name, ' ', pu.last_name) AS parent_name, pu.phone AS parent_phone
     FROM students s JOIN users u ON s.user_id = u.id
     LEFT JOIN classes c ON s.class_id = c.id
     LEFT JOIN parents p ON s.parent_id = p.id LEFT JOIN users pu ON p.user_id = pu.id
     WHERE s.id = ?", [$id]);
if (!$student) api_error('Student not found', 404);
api_ok(['student' => $student]);
