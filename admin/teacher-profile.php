<?php
// admin/teacher-profile.php - teacher profile with classes and subjects
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
Security::requireRole('admin');

$db = db();
$id = (int)($_GET['id'] ?? 0);
$t = $db->getRow("SELECT t.*, u.username, u.email, u.phone, u.first_name, u.last_name, u.is_active, u.last_login
                  FROM teachers t JOIN users u ON t.user_id = u.id WHERE t.id = ?", [$id]);
$pageTitle = 'Teacher Profile';
$extraCSS = ['admin.css', 'dashboard.css'];
include __DIR__ . '/../includes/header.php';

if (!$t) {
    dashboard_open('admin', 'Teacher not found');
    echo '<div class="alert alert-error">No teacher with that ID.</div><a class="btn btn-secondary" href="teachers">Back</a>';
    dashboard_close();
    include __DIR__ . '/../includes/footer.php';
    exit;
}
$classes = $db->getRows("SELECT c.id, c.class_name, c.section, c.academic_year, (SELECT COUNT(*) FROM students s WHERE s.class_id = c.id) AS students FROM classes c WHERE c.teacher_id = ? ORDER BY c.class_name", [$id]);
$subjects = $db->getRows("SELECT s.subject_name, s.subject_code, c.class_name, c.section FROM subjects s LEFT JOIN classes c ON s.class_id = c.id WHERE s.teacher_id = ? AND s.is_active = 1 ORDER BY c.class_name, s.subject_name", [$id]);

dashboard_open('admin', $t['first_name'] . ' ' . $t['last_name'],
    '<a href="teachers?action=edit&id=' . $id . '" class="btn btn-primary"><i class="fas fa-edit"></i> Edit</a>'
    . '<a href="assign-subjects?teacher_id=' . $id . '" class="btn btn-outline"><i class="fas fa-book"></i> Assign subjects</a>'
    . '<a href="teachers" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>');
?>
<div class="card"><div class="card-header"><h3>Details</h3></div><div class="card-body"><div class="detail-grid">
    <?php foreach (['Employee ID' => $t['employee_id'], 'Username' => $t['username'], 'Email' => $t['email'], 'Phone' => $t['phone'],
        'Qualification' => $t['qualification'], 'Specialization' => $t['specialization'], 'Date of hire' => formatDate($t['date_of_hire'], 'd M Y'),
        'Address' => $t['address'], 'Emergency contact' => $t['emergency_contact'], 'Status' => $t['is_active'] ? 'Active' : 'Inactive',
        'Last login' => $t['last_login'] ? formatDate($t['last_login'], 'd M Y, h:i A') : 'Never'] as $l => $v): ?>
    <div class="detail-row"><span class="detail-label"><?php echo e($l); ?></span><span class="detail-value"><?php echo e($v !== null && $v !== '' ? $v : '-'); ?></span></div>
    <?php endforeach; ?>
</div></div></div>
<div class="card"><div class="card-header"><h3>Classes led</h3></div><div class="card-body">
<?php if ($classes): ?><ul class="simple-list"><?php foreach ($classes as $c): ?>
    <li><?php echo e(trim($c['class_name'] . ' ' . $c['section'])); ?> <small class="text-muted">(<?php echo e($c['academic_year']); ?>, <?php echo (int)$c['students']; ?> students)</small></li>
<?php endforeach; ?></ul><?php else: ?><p class="text-muted">Not a class teacher.</p><?php endif; ?></div></div>
<div class="card"><div class="card-header"><h3>Subjects taught</h3></div><div class="card-body">
<?php if ($subjects): ?><div class="table-responsive"><table class="table"><thead><tr><th>Code</th><th>Subject</th><th>Class</th></tr></thead><tbody>
<?php foreach ($subjects as $s): ?><tr><td><?php echo e($s['subject_code']); ?></td><td><?php echo e($s['subject_name']); ?></td><td><?php echo e(trim($s['class_name'] . ' ' . $s['section'])); ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php else: ?><p class="text-muted">No subjects assigned.</p><?php endif; ?></div></div>
<?php dashboard_close(); include __DIR__ . '/../includes/footer.php';
