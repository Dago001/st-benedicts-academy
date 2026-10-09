<?php
// admin/view-student.php - full student profile
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
Security::requireRole('admin');

$db = db();
$id = (int)($_GET['id'] ?? 0);
$st = $db->getRow(
    "SELECT s.*, u.username, u.email, u.phone, u.first_name, u.last_name, u.is_active, u.last_login,
            c.class_name, c.section, CONCAT(pu.first_name, ' ', pu.last_name) AS parent_name, pu.email AS parent_email, pu.phone AS parent_phone, p.id AS pid
     FROM students s JOIN users u ON s.user_id = u.id
     LEFT JOIN classes c ON s.class_id = c.id
     LEFT JOIN parents p ON s.parent_id = p.id LEFT JOIN users pu ON p.user_id = pu.id
     WHERE s.id = ?", [$id]);

$pageTitle = 'Student Profile';
$extraCSS = ['admin.css', 'dashboard.css'];
include __DIR__ . '/../includes/header.php';

if (!$st) {
    dashboard_open('admin', 'Student not found');
    echo '<div class="alert alert-error">No student with that ID.</div><a class="btn btn-secondary" href="students">Back to students</a>';
    dashboard_close();
    include __DIR__ . '/../includes/footer.php';
    exit;
}

$att = $db->getRow(
    "SELECT COUNT(*) total, COALESCE(SUM(status='present'),0) present, COALESCE(SUM(status='absent'),0) absent, COALESCE(SUM(status='late'),0) late
     FROM attendance WHERE student_id = ? AND date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)", [$id]);
$attRate = $att['total'] > 0 ? round(($att['present'] + $att['late']) / $att['total'] * 100) : null;
$results = $db->getRows(
    "SELECT r.term, r.academic_year, sub.subject_name, r.assessment_type, r.score, r.max_score, r.grade, r.is_approved
     FROM results r JOIN subjects sub ON r.subject_id = sub.id WHERE r.student_id = ?
     ORDER BY r.academic_year DESC, r.term DESC, sub.subject_name LIMIT 30", [$id]);
$fees = $db->getRow(
    "SELECT COALESCE((SELECT SUM(amount) FROM fee_structure WHERE class_id = ? AND academic_year = ?),0) AS expected,
            COALESCE((SELECT SUM(amount) FROM payments WHERE student_id = ? AND academic_year = ? AND status='completed'),0) AS paid",
    [$st['class_id'] ?? 0, currentAcademicYear(), $id, currentAcademicYear()]);

dashboard_open('admin', $st['first_name'] . ' ' . $st['last_name'],
    '<a href="students?action=edit&id=' . $id . '" class="btn btn-primary"><i class="fas fa-edit"></i> Edit</a>'
    . '<a href="generate-login?id=' . $id . '" class="btn btn-outline"><i class="fas fa-key"></i> Reset Login</a>'
    . '<a href="students" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>');
?>
<div class="stats-grid">
    <div class="stat-card"><div class="stat-icon"><i class="fas fa-school"></i></div><div class="stat-content"><h3><?php echo e(trim(($st['class_name'] ?? '-') . ' ' . ($st['section'] ?? ''))); ?></h3><p>Class</p></div></div>
    <div class="stat-card"><div class="stat-icon"><i class="fas fa-calendar-check"></i></div><div class="stat-content"><h3><?php echo $attRate === null ? 'N/A' : $attRate . '%'; ?></h3><p>Attendance (90 days)</p></div></div>
    <div class="stat-card"><div class="stat-icon"><i class="fas fa-money-bill"></i></div><div class="stat-content"><h3><?php echo e(formatCurrency(max(0, $fees['expected'] - $fees['paid']))); ?></h3><p>Fees outstanding</p></div></div>
</div>

<div class="card"><div class="card-header"><h3>Personal details</h3></div><div class="card-body">
    <div class="detail-grid">
        <?php $rows = [
            'Admission No' => $st['admission_number'], 'Username' => $st['username'], 'Email' => $st['email'], 'Phone' => $st['phone'],
            'Gender' => ucfirst((string)$st['gender']), 'Date of Birth' => formatDate($st['date_of_birth'], 'd M Y'),
            'Admitted' => formatDate($st['admission_date'], 'd M Y'), 'Blood Group' => $st['blood_group'], 'Address' => $st['address'],
            'Medical Notes' => $st['medical_notes'], 'Status' => $st['is_active'] ? 'Active' : 'Inactive',
            'Last Login' => $st['last_login'] ? formatDate($st['last_login'], 'd M Y, h:i A') : 'Never',
        ];
        foreach ($rows as $label => $value): ?>
        <div class="detail-row"><span class="detail-label"><?php echo e($label); ?></span><span class="detail-value"><?php echo e($value !== null && $value !== '' ? $value : '-'); ?></span></div>
        <?php endforeach; ?>
    </div>
</div></div>

<div class="card"><div class="card-header"><h3>Parent / guardian</h3></div><div class="card-body">
    <?php if ($st['pid']): ?>
        <p><strong><a href="view-parent?id=<?php echo (int)$st['pid']; ?>"><?php echo e($st['parent_name']); ?></a></strong><br>
        <?php echo e($st['parent_email']); ?> &middot; <?php echo e($st['parent_phone'] ?: 'no phone'); ?></p>
    <?php else: ?><p class="text-muted">No parent linked.</p><?php endif; ?>
</div></div>

<div class="card"><div class="card-header"><h3>Recent results</h3>
    <a class="btn btn-sm btn-outline" href="student-fees?student_id=<?php echo $id; ?>">Fee statement</a></div>
<div class="card-body">
    <?php if ($results): ?><div class="table-responsive"><table class="table"><thead><tr><th>Year</th><th>Term</th><th>Subject</th><th>Type</th><th>Score</th><th>Grade</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($results as $r): ?><tr>
        <td><?php echo e($r['academic_year']); ?></td><td><?php echo e($r['term']); ?></td><td><?php echo e($r['subject_name']); ?></td>
        <td><?php echo e(ucfirst($r['assessment_type'])); ?></td><td><?php echo e($r['score'] + 0); ?>/<?php echo e($r['max_score'] + 0); ?></td>
        <td><span class="badge"><?php echo e($r['grade']); ?></span></td><td><?php echo $r['is_approved'] ? 'Approved' : 'Pending'; ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
    <?php else: ?><p class="text-muted">No results recorded yet.</p><?php endif; ?>
</div></div>
<?php dashboard_close(); include __DIR__ . '/../includes/footer.php';
