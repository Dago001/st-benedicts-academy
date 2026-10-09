<?php
// student/timetable.php - the student's class timetable
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/timetable.php';
Security::requireRole('student');

$st = db()->getRow("SELECT s.class_id, c.class_name, c.section FROM students s LEFT JOIN classes c ON s.class_id = c.id WHERE s.user_id = ?", [$_SESSION['user_id']]);
$pageTitle = 'Timetable';
$extraCSS = ['dashboard.css', 'timetable.css'];
include __DIR__ . '/../includes/header.php';
dashboard_open('student', 'My Timetable');
?>
<div class="card"><div class="card-header"><h3><?php echo e(trim(($st['class_name'] ?? '') . ' ' . ($st['section'] ?? ''))); ?></h3></div>
<div class="card-body"><?php render_timetable(!empty($st['class_id']) ? timetable_for_class($st['class_id']) : []); ?></div></div>
<?php dashboard_close(); include __DIR__ . '/../includes/footer.php';
