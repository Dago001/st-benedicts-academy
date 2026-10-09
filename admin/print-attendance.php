<?php
// admin/print-attendance.php - printable monthly attendance summary (use the browser's Save as PDF)
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
Security::requireRole('admin');

$db = db();
$month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m');
$rows = $db->getRows(
    "SELECT c.class_name, c.section,
            COALESCE(SUM(a.status='present'),0) present, COALESCE(SUM(a.status='absent'),0) absent,
            COALESCE(SUM(a.status='late'),0) late, COALESCE(SUM(a.status='excused'),0) excused, COUNT(a.id) total
     FROM classes c LEFT JOIN attendance a ON a.class_id = c.id AND DATE_FORMAT(a.date, '%Y-%m') = ?
     WHERE c.is_active = 1 GROUP BY c.id, c.class_name, c.section ORDER BY c.class_name, c.section", [$month]);
?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Attendance <?php echo e($month); ?></title><meta name="robots" content="noindex">
<style>body{font-family:system-ui,sans-serif;padding:16px;color:#1b2a41}h1{font-size:1.2rem;margin:0}table{width:100%;border-collapse:collapse;margin-top:14px}
th,td{border:1px solid #ccd;padding:8px;text-align:left}th{background:#f0f3f8}.r{text-align:right}.wrap{overflow-x:auto}button{padding:10px 16px;border:0;border-radius:8px;background:#002855;color:#fff;margin:10px 0}
@media print{button{display:none}}</style></head><body>
<h1><?php echo e(SCHOOL_NAME); ?></h1><p>Attendance summary for <strong><?php echo e(date('F Y', strtotime($month . '-01'))); ?></strong></p>
<button onclick="window.print()">Print / Save as PDF</button>
<div class="wrap"><table><thead><tr><th>Class</th><th class="r">Present</th><th class="r">Absent</th><th class="r">Late</th><th class="r">Excused</th><th class="r">Records</th><th class="r">Rate</th></tr></thead><tbody>
<?php foreach ($rows as $r): $rate = $r['total'] > 0 ? round(($r['present'] + $r['late']) / $r['total'] * 100) . '%' : '-'; ?>
<tr><td><?php echo e(trim($r['class_name'] . ' ' . $r['section'])); ?></td><td class="r"><?php echo (int)$r['present']; ?></td><td class="r"><?php echo (int)$r['absent']; ?></td>
<td class="r"><?php echo (int)$r['late']; ?></td><td class="r"><?php echo (int)$r['excused']; ?></td><td class="r"><?php echo (int)$r['total']; ?></td><td class="r"><?php echo e($rate); ?></td></tr>
<?php endforeach; ?></tbody></table></div></body></html>
