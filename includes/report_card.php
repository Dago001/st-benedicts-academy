<?php
// includes/report_card.php - printable term report card (approved results only)

function render_report_card($studentId, $term, $year) {
    $db = db();
    $studentId = (int)$studentId;
    $st = Security::canAccessStudent($studentId) ? $db->getRow(
        "SELECT s.id, s.admission_number, s.date_of_birth, s.gender, s.class_id, u.first_name, u.last_name, c.class_name, c.section,
                CONCAT(tu.first_name, ' ', tu.last_name) AS class_teacher
         FROM students s JOIN users u ON s.user_id = u.id LEFT JOIN classes c ON s.class_id = c.id
         LEFT JOIN teachers t ON c.teacher_id = t.id LEFT JOIN users tu ON t.user_id = tu.id WHERE s.id = ?", [$studentId]) : null;
    if (!st_ok($st)) { http_response_code(404); }
    $rows = $st ? $db->getRows(
        "SELECT sub.subject_name,
                SUM(CASE WHEN r.assessment_type IN ('test','assignment','project') THEN r.score ELSE 0 END) AS ca,
                SUM(CASE WHEN r.assessment_type = 'exam' THEN r.score ELSE 0 END) AS exam,
                SUM(r.score) AS total, SUM(r.max_score) AS max_total
         FROM results r JOIN subjects sub ON r.subject_id = sub.id
         WHERE r.student_id = ? AND r.term = ? AND r.academic_year = ? AND r.is_approved = 1
         GROUP BY sub.id, sub.subject_name ORDER BY sub.subject_name", [$studentId, $term, $year]) : [];
    $sumScore = array_sum(array_column($rows, 'total'));
    $sumMax = array_sum(array_column($rows, 'max_total'));
    $avg = $sumMax > 0 ? round($sumScore / $sumMax * 100, 1) : null;
    $att = $st ? $db->getRow("SELECT COUNT(*) total, COALESCE(SUM(status IN ('present','late')),0) attended FROM attendance WHERE student_id = ?", [$studentId]) : null;
    ?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Report Card - <?php echo $st ? e($st['first_name'] . ' ' . $st['last_name']) : ''; ?></title><meta name="robots" content="noindex">
<style>
body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f4f6f9;margin:0;padding:16px;color:#1b2a41}
.card{max-width:760px;margin:0 auto;background:#fff;border-radius:10px;padding:22px;box-shadow:0 4px 18px rgba(0,0,0,.08)}
h1{font-size:1.2rem;text-align:center;margin:0;color:#002855}.sub{text-align:center;color:#667;font-size:.85rem;margin:4px 0 14px}
.meta{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:4px 16px;margin-bottom:14px;font-size:.92rem}.meta span{color:#667}
.wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;min-width:420px}th,td{border:1px solid #d5dbe6;padding:8px;text-align:left}th{background:#eef2f9}.r{text-align:right}
.actions{max-width:760px;margin:0 auto 12px;display:flex;gap:8px;flex-wrap:wrap}.actions a,.actions button{padding:10px 16px;border-radius:8px;border:0;background:#002855;color:#fff;text-decoration:none;cursor:pointer;font-size:.95rem}.actions .alt{background:#6c757d}
.sum{margin-top:14px;font-weight:600}.sig{display:flex;justify-content:space-between;gap:20px;margin-top:36px;font-size:.85rem;color:#667}.sig div{border-top:1px solid #99a;padding-top:4px;flex:1;text-align:center}
@media(max-width:480px){.meta{grid-template-columns:1fr}}@media print{body{background:#fff;padding:0}.actions{display:none}.card{box-shadow:none}}
</style></head><body>
<?php if (!$st): ?><div class="card"><h1>Report not available</h1><p class="sub">The student could not be found or you do not have access.</p></div>
<?php else: ?>
<div class="actions"><button type="button" onclick="window.print()">Print / Save as PDF</button><a class="alt" href="javascript:history.back()">Back</a></div>
<div class="card">
    <h1><?php echo e(SCHOOL_NAME); ?></h1>
    <p class="sub"><?php echo e(SCHOOL_ADDRESS); ?><br>REPORT CARD &mdash; <?php echo e($term); ?>, <?php echo e($year); ?></p>
    <div class="meta">
        <div><span>Name:</span> <strong><?php echo e($st['first_name'] . ' ' . $st['last_name']); ?></strong></div>
        <div><span>Admission No:</span> <?php echo e($st['admission_number']); ?></div>
        <div><span>Class:</span> <?php echo e(trim($st['class_name'] . ' ' . $st['section'])); ?></div>
        <div><span>Class teacher:</span> <?php echo e($st['class_teacher'] ?: '-'); ?></div>
    </div>
    <div class="wrap"><table>
        <thead><tr><th>Subject</th><th class="r">CA</th><th class="r">Exam</th><th class="r">Total</th><th class="r">%</th><th>Grade</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $pct = $r['max_total'] > 0 ? $r['total'] / $r['max_total'] * 100 : 0; ?>
            <tr><td><?php echo e($r['subject_name']); ?></td><td class="r"><?php echo e($r['ca'] + 0); ?></td><td class="r"><?php echo e($r['exam'] + 0); ?></td>
                <td class="r"><?php echo e($r['total'] + 0); ?>/<?php echo e($r['max_total'] + 0); ?></td><td class="r"><?php echo e(round($pct)); ?></td><td><strong><?php echo e(letterGrade($r['total'], $r['max_total'])); ?></strong></td></tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="6" style="text-align:center;color:#889">No approved results for this term yet.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
    <?php if ($avg !== null): ?><p class="sum">Overall average: <?php echo e($avg); ?>% (<?php echo e(letterGrade($sumScore, $sumMax)); ?>)</p><?php endif; ?>
    <p class="sum">Attendance: <?php echo $att && $att['total'] > 0 ? e(round($att['attended'] / $att['total'] * 100)) . '% (' . (int)$att['attended'] . ' of ' . (int)$att['total'] . ' days)' : 'no records'; ?></p>
    <div class="sig"><div>Class teacher</div><div>Head teacher</div><div>Parent / guardian</div></div>
</div>
<?php endif; ?>
</body></html>
<?php
}

function st_ok($st) { return (bool)$st; }
