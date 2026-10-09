<?php
// admin/view-parent.php - parent profile with linked children
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
Security::requireRole('admin');

$db = db();
$id = (int)($_GET['id'] ?? 0);
$pa = $db->getRow("SELECT p.*, u.username, u.email, u.phone, u.first_name, u.last_name, u.is_active, u.last_login
                   FROM parents p JOIN users u ON p.user_id = u.id WHERE p.id = ?", [$id]);
$pageTitle = 'Parent Profile';
$extraCSS = ['admin.css', 'dashboard.css'];
include __DIR__ . '/../includes/header.php';

if (!$pa) {
    dashboard_open('admin', 'Parent not found');
    echo '<div class="alert alert-error">No parent with that ID.</div><a class="btn btn-secondary" href="parents.php">Back</a>';
    dashboard_close();
    include __DIR__ . '/../includes/footer.php';
    exit;
}
$children = $db->getRows(
    "SELECT s.id, s.admission_number, u.first_name, u.last_name, c.class_name, c.section,
            COALESCE((SELECT SUM(amount) FROM fee_structure f WHERE f.class_id = s.class_id AND f.academic_year = ?),0)
          - COALESCE((SELECT SUM(amount) FROM payments p WHERE p.student_id = s.id AND p.academic_year = ? AND p.status='completed'),0) AS balance
     FROM students s JOIN users u ON s.user_id = u.id LEFT JOIN classes c ON s.class_id = c.id
     WHERE s.parent_id = ? ORDER BY u.first_name", [currentAcademicYear(), currentAcademicYear(), $id]);

dashboard_open('admin', $pa['first_name'] . ' ' . $pa['last_name'],
    '<a href="parents.php?action=edit&id=' . $id . '" class="btn btn-primary"><i class="fas fa-edit"></i> Edit</a><a href="parents.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>');
?>
<div class="card"><div class="card-header"><h3>Contact details</h3></div><div class="card-body"><div class="detail-grid">
    <?php foreach (['Username' => $pa['username'], 'Email' => $pa['email'], 'Phone' => $pa['phone'], 'Occupation' => $pa['occupation'],
        'Relationship' => $pa['relationship'], 'Address' => $pa['address'], 'Emergency contact' => $pa['emergency_contact'],
        'Status' => $pa['is_active'] ? 'Active' : 'Inactive', 'Last login' => $pa['last_login'] ? formatDate($pa['last_login'], 'd M Y, h:i A') : 'Never'] as $l => $v): ?>
    <div class="detail-row"><span class="detail-label"><?php echo e($l); ?></span><span class="detail-value"><?php echo e($v !== null && $v !== '' ? $v : '-'); ?></span></div>
    <?php endforeach; ?>
</div></div></div>
<div class="card"><div class="card-header"><h3>Children (<?php echo count($children); ?>)</h3></div><div class="card-body">
<?php if ($children): ?><div class="table-responsive"><table class="table"><thead><tr><th>Name</th><th>Admission No</th><th>Class</th><th>Fee balance</th><th></th></tr></thead><tbody>
    <?php foreach ($children as $c): ?><tr>
        <td><?php echo e($c['first_name'] . ' ' . $c['last_name']); ?></td><td><?php echo e($c['admission_number']); ?></td>
        <td><?php echo e(trim($c['class_name'] . ' ' . $c['section'])); ?></td><td><?php echo e(formatCurrency(max(0, $c['balance']))); ?></td>
        <td><a class="btn btn-sm btn-outline" href="view-student.php?id=<?php echo (int)$c['id']; ?>">View</a></td></tr>
    <?php endforeach; ?></tbody></table></div>
<?php else: ?><p class="text-muted">No children linked to this parent yet.</p><?php endif; ?>
</div></div>
<?php dashboard_close(); include __DIR__ . '/../includes/footer.php';
