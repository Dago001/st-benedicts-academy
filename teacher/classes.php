<?php
// teacher/classes.php - classes the teacher leads or teaches, with rosters
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/timetable.php';
Security::requireRole('teacher');

$db = db();
$tid = Security::currentTeacherId() ?? 0;
$classes = $db->getRows(
    "SELECT c.id, c.class_name, c.section, c.academic_year, c.capacity, c.teacher_id = ? AS is_form_teacher,
            (SELECT COUNT(*) FROM students s WHERE s.class_id = c.id) AS students,
            (SELECT GROUP_CONCAT(sub.subject_name ORDER BY sub.subject_name SEPARATOR ', ') FROM subjects sub WHERE sub.class_id = c.id AND sub.teacher_id = ? AND sub.is_active = 1) AS my_subjects
     FROM classes c
     WHERE c.is_active = 1 AND c.id IN (SELECT id FROM classes WHERE teacher_id = ? UNION SELECT class_id FROM subjects WHERE teacher_id = ?)
     ORDER BY c.class_name, c.section", [$tid, $tid, $tid, $tid]);

$selected = (int)($_GET['class_id'] ?? 0);
$roster = [];
$selectedClass = null;
foreach ($classes as $c) { if ((int)$c['id'] === $selected) $selectedClass = $c; }
if ($selectedClass) {
    $roster = $db->getRows(
        "SELECT s.id, s.admission_number, s.gender, u.first_name, u.last_name,
                CONCAT(pu.first_name, ' ', pu.last_name) AS parent_name, pu.phone AS parent_phone
         FROM students s JOIN users u ON s.user_id = u.id
         LEFT JOIN parents p ON s.parent_id = p.id LEFT JOIN users pu ON p.user_id = pu.id
         WHERE s.class_id = ? AND u.is_active = 1 ORDER BY u.first_name, u.last_name", [$selected]);
}

$pageTitle = 'My Classes';
$extraCSS = ['dashboard.css', 'timetable.css'];
include __DIR__ . '/../includes/header.php';
dashboard_open('teacher', 'My Classes');
?>
<div class="stats-grid">
<?php foreach ($classes as $c): ?>
    <a class="stat-card" href="?class_id=<?php echo (int)$c['id']; ?>" style="text-decoration:none;color:inherit;<?php echo (int)$c['id'] === $selected ? 'outline:2px solid #ffd700' : ''; ?>">
        <div class="stat-icon"><i class="fas fa-school"></i></div>
        <div class="stat-content">
            <h3><?php echo e(trim($c['class_name'] . ' ' . $c['section'])); ?></h3>
            <p><?php echo (int)$c['students']; ?> students &middot; <?php echo e($c['academic_year']); ?>
            <?php if ($c['is_form_teacher']): ?><br><span class="badge badge-info">Class teacher</span><?php endif; ?>
            <?php if ($c['my_subjects']): ?><br><small><?php echo e($c['my_subjects']); ?></small><?php endif; ?></p>
        </div>
    </a>
<?php endforeach; ?>
<?php if (!$classes): ?><div class="alert alert-info">You have not been assigned to any class yet. Please contact the school administrator.</div><?php endif; ?>
</div>

<?php if ($selectedClass): ?>
<div class="card"><div class="card-header"><h3><?php echo e(trim($selectedClass['class_name'] . ' ' . $selectedClass['section'])); ?> roster</h3>
    <div><a class="btn btn-sm btn-primary" href="attendance.php?class_id=<?php echo $selected; ?>">Take attendance</a>
    <a class="btn btn-sm btn-outline" href="export.php?class_id=<?php echo $selected; ?>">Export CSV</a></div></div>
<div class="card-body"><div class="table-responsive"><table class="table"><thead><tr><th>#</th><th>Admission No</th><th>Name</th><th>Gender</th><th>Parent</th><th>Parent phone</th></tr></thead><tbody>
<?php foreach ($roster as $i => $s): ?><tr><td><?php echo $i + 1; ?></td><td><?php echo e($s['admission_number']); ?></td><td><?php echo e($s['first_name'] . ' ' . $s['last_name']); ?></td>
    <td><?php echo e(ucfirst((string)$s['gender'])); ?></td><td><?php echo e($s['parent_name'] ?: '-'); ?></td>
    <td><?php if ($s['parent_phone']): ?><a href="tel:<?php echo e($s['parent_phone']); ?>"><?php echo e($s['parent_phone']); ?></a><?php else: ?>-<?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$roster): ?><tr><td colspan="6" class="text-center text-muted">No students in this class.</td></tr><?php endif; ?>
</tbody></table></div></div></div>
<div class="card"><div class="card-header"><h3>Timetable</h3></div><div class="card-body"><?php render_timetable(timetable_for_class($selected)); ?></div></div>
<?php endif;
dashboard_close();
include __DIR__ . '/../includes/footer.php';
