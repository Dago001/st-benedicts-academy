<?php
// admin/student-fees.php - fee statement for one student
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
Security::requireRole('admin');

$db = db();
$sid = (int)($_GET['student_id'] ?? $_GET['id'] ?? 0);
$year = preg_match('/^\d{4}-\d{4}$/', $_GET['year'] ?? '') ? $_GET['year'] : currentAcademicYear();
$st = $db->getRow("SELECT s.id, s.class_id, s.admission_number, u.first_name, u.last_name, c.class_name, c.section
                   FROM students s JOIN users u ON s.user_id = u.id LEFT JOIN classes c ON s.class_id = c.id WHERE s.id = ?", [$sid]);
$pageTitle = 'Fee Statement';
$extraCSS = ['admin.css', 'dashboard.css'];
include __DIR__ . '/../includes/header.php';
if (!$st) {
    dashboard_open('admin', 'Student not found');
    echo '<a class="btn btn-secondary" href="fees.php">Back to fees</a>';
    dashboard_close();
    include __DIR__ . '/../includes/footer.php';
    exit;
}
$fees = $db->getRows("SELECT * FROM fee_structure WHERE class_id = ? AND academic_year = ? ORDER BY term, fee_type", [$st['class_id'] ?? 0, $year]);
$payments = $db->getRows("SELECT * FROM payments WHERE student_id = ? AND academic_year = ? ORDER BY payment_date DESC, id DESC", [$sid, $year]);
$expected = array_sum(array_column($fees, 'amount'));
$paid = array_sum(array_map(function ($p) { return $p['status'] === 'completed' ? $p['amount'] : 0; }, $payments));
$years = academicYearList();

dashboard_open('admin', 'Fees: ' . $st['first_name'] . ' ' . $st['last_name'], '<a href="fees.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>');
?>
<form method="GET" class="card"><div class="card-body form-inline">
    <input type="hidden" name="student_id" value="<?php echo $sid; ?>">
    <label for="year">Academic year</label>
    <select id="year" name="year" class="form-control" onchange="this.form.submit()">
        <?php foreach ($years as $y): ?><option value="<?php echo e($y); ?>" <?php echo $y === $year ? 'selected' : ''; ?>><?php echo e($y); ?></option><?php endforeach; ?>
    </select>
</div></form>
<div class="stats-grid">
    <div class="stat-card"><div class="stat-content"><h3><?php echo e(formatCurrency($expected)); ?></h3><p>Total fees</p></div></div>
    <div class="stat-card"><div class="stat-content"><h3><?php echo e(formatCurrency($paid)); ?></h3><p>Paid</p></div></div>
    <div class="stat-card"><div class="stat-content"><h3><?php echo e(formatCurrency(max(0, $expected - $paid))); ?></h3><p>Balance</p></div></div>
</div>
<div class="card"><div class="card-header"><h3>Fee structure (<?php echo e(trim($st['class_name'] . ' ' . $st['section'])); ?>)</h3></div><div class="card-body">
<?php if ($fees): ?><div class="table-responsive"><table class="table"><thead><tr><th>Fee</th><th>Term</th><th>Due</th><th>Amount</th></tr></thead><tbody>
<?php foreach ($fees as $f): ?><tr><td><?php echo e($f['fee_type']); ?></td><td><?php echo e($f['term']); ?></td><td><?php echo e(formatDate($f['due_date'], 'd M Y')); ?></td><td><?php echo e(formatCurrency($f['amount'])); ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php else: ?><p class="text-muted">No fees defined for this year.</p><?php endif; ?></div></div>
<div class="card"><div class="card-header"><h3>Payments</h3></div><div class="card-body">
<?php if ($payments): ?><div class="table-responsive"><table class="table"><thead><tr><th>Date</th><th>Receipt</th><th>Term</th><th>Method</th><th>Amount</th><th>Status</th></tr></thead><tbody>
<?php foreach ($payments as $p): ?><tr><td><?php echo e(formatDate($p['payment_date'], 'd M Y')); ?></td>
    <td><a href="print-receipt.php?id=<?php echo (int)$p['id']; ?>" target="_blank" rel="noopener"><?php echo e($p['receipt_number']); ?></a></td>
    <td><?php echo e($p['term']); ?></td><td><?php echo e(ucwords(str_replace('_', ' ', $p['payment_method']))); ?></td>
    <td><?php echo e(formatCurrency($p['amount'])); ?></td><td><?php echo e(ucfirst($p['status'])); ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php else: ?><p class="text-muted">No payments recorded.</p><?php endif; ?></div></div>
<?php dashboard_close(); include __DIR__ . '/../includes/footer.php';
