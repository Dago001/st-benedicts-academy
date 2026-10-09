<?php
// parent/schedule.php - timetable of a child's class
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/timetable.php';
Security::requireRole('parent');

$db = db();
$children = $db->getRows(
    "SELECT s.id, s.class_id, u.first_name, u.last_name, c.class_name, c.section FROM students s JOIN users u ON s.user_id = u.id
     LEFT JOIN classes c ON s.class_id = c.id WHERE s.parent_id = ? AND u.is_active = 1 ORDER BY u.first_name", [Security::currentParentId() ?? 0]);
$selected = $children[0] ?? null;
foreach ($children as $c) { if (isset($_GET['child']) && (int)$_GET['child'] === (int)$c['id']) $selected = $c; }

$pageTitle = 'Timetable';
$extraCSS = ['dashboard.css', 'timetable.css'];
include __DIR__ . '/../includes/header.php';
dashboard_open('parent', 'Class Timetable');
if (count($children) > 1): ?>
<form method="GET" class="card"><div class="card-body form-inline"><label for="child">Child</label>
    <select id="child" name="child" class="form-control" onchange="this.form.submit()">
    <?php foreach ($children as $c): ?><option value="<?php echo (int)$c['id']; ?>" <?php echo $selected && $c['id'] == $selected['id'] ? 'selected' : ''; ?>><?php echo e($c['first_name'] . ' ' . $c['last_name']); ?></option><?php endforeach; ?>
    </select></div></form>
<?php endif;
if (!$selected): ?><div class="alert alert-info">No children are linked to your account yet.</div>
<?php else: ?>
<div class="card"><div class="card-header"><h3><?php echo e($selected['first_name']); ?> &middot; <?php echo e(trim($selected['class_name'] . ' ' . $selected['section'])); ?></h3></div>
<div class="card-body"><?php render_timetable($selected['class_id'] ? timetable_for_class($selected['class_id']) : []); ?></div></div>
<?php endif;
dashboard_close();
include __DIR__ . '/../includes/footer.php';
