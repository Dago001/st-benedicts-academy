<?php
// student/report-card.php - printable report card
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/report_card.php';
Security::requireRole('student');

$term = in_array($_GET['term'] ?? '', ['Term 1', 'Term 2', 'Term 3'], true) ? $_GET['term'] : 'Term 1';
$year = preg_match('/^\d{4}-\d{4}$/', $_GET['year'] ?? '') ? $_GET['year'] : currentAcademicYear();
$id = (int)($_GET['child'] ?? $_GET['student'] ?? 0);
if ('student' === 'student') { $id = Security::currentStudentId() ?? 0; }
render_report_card($id, $term, $year);
