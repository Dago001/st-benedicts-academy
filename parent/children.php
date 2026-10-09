<?php
// parent/children.php - overview of the parent's children
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
Security::requireRole('parent');

$db = db();
$pid = Security::currentParentId() ?? 0;
$year = currentAcademicYear();
$children = $db->getRows(
    "SELECT s.id, s.admission_number, s.date_of_birth, s.gender, u.first_name, u.last_name, c.class_name, c.section,
            CONCAT(tu.first_name, ' ', tu.last_name) AS class_teacher,
            (SELECT ROUND(100 * SUM(a.status IN ('present','late')) / NULLIF(COUNT(*), 0)) FROM attendance a WHERE a.student_id = s.id AND a.date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) AS attendance_rate,
            GREATEST(COALESCE((SELECT SUM(f.amount) FROM fee_structure f WHERE f.class_id = s.class_id AND f.academic_year = ?), 0)
                   - COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.student_id = s.id AND p.academic_year = ? AND p.status = 'completed'), 0), 0) AS balance
     FROM students s JOIN users u ON s.user_id = u.id
     LEFT JOIN classes c ON s.class_id = c.id
     LEFT JOIN teachers t ON c.teacher_id = t.id LEFT JOIN users tu ON t.user_id = tu.id
     WHERE s.parent_id = ? AND u.is_active = 1 ORDER BY u.first_name", [$year, $year, $pid]);

$pageTitle = 'My Children';
$extraCSS = ['dashboard.css'];
include __DIR__ . '/../includes/header.php';
dashboard_open('parent', 'My Children');
?>
<?php if (!$children): ?><div class="alert alert-info">No children are linked to your account yet. Please contact the school office.</div><?php endif; ?>
<div class="child-cards">
<?php foreach ($children as $c): ?>
    <div class="card child-card"><div class="card-body">
        <h3><i class="fas fa-child"></i> <?php echo e($c['first_name'] . ' ' . $c['last_name']); ?></h3>
        <p class="text-muted"><?php echo e($c['admission_number']); ?> &middot; <?php echo e(trim(($c['class_name'] ?? 'No class') . ' ' . ($c['section'] ?? ''))); ?></p>
        <ul class="simple-list">
            <li>Class teacher: <strong><?php echo e($c['class_teacher'] ?: 'Not assigned'); ?></strong></li>
            <li>Attendance (30 days): <strong><?php echo $c['attendance_rate'] === null ? 'N/A' : (int)$c['attendance_rate'] . '%'; ?></strong></li>
            <li>Fee balance (<?php echo e($year); ?>): <strong><?php echo e(formatCurrency($c['balance'])); ?></strong></li>
        </ul>
        <div class="card-actions">
            <a class="btn btn-sm btn-primary" href="child-performance.php?child=<?php echo (int)$c['id']; ?>">Performance</a>
            <a class="btn btn-sm btn-outline" href="fees.php?child=<?php echo (int)$c['id']; ?>">Fees</a>
            <a class="btn btn-sm btn-outline" href="schedule.php?child=<?php echo (int)$c['id']; ?>">Timetable</a>
        </div>
    </div></div>
<?php endforeach; ?>
</div>
<?php dashboard_close(); include __DIR__ . '/../includes/footer.php';
