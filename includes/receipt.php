<?php
// includes/receipt.php - printable payment receipt shared by admin / student / parent

function render_receipt_page($paymentId) {
    $db = db();
    $paymentId = (int)$paymentId;
    $p = $paymentId ? $db->getRow(
        "SELECT p.*, CONCAT(u.first_name, ' ', u.last_name) AS student_name, s.admission_number, s.id AS sid,
                c.class_name, c.section, CONCAT(ru.first_name, ' ', ru.last_name) AS recorded_by_name
         FROM payments p JOIN students s ON p.student_id = s.id JOIN users u ON s.user_id = u.id
         LEFT JOIN classes c ON s.class_id = c.id LEFT JOIN users ru ON p.recorded_by = ru.id
         WHERE p.id = ?", [$paymentId]) : null;

    // Unknown and forbidden look the same
    if (!$p || !Security::canAccessStudent($p['sid']) || Security::hasRole('teacher')) {
        http_response_code(404);
        $p = null;
    }
    ?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Receipt <?php echo $p ? e($p['receipt_number']) : ''; ?> - <?php echo e(SITE_NAME); ?></title>
<meta name="robots" content="noindex">
<style>
body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f4f6f9;margin:0;padding:16px;color:#1b2a41}
.receipt{max-width:640px;margin:0 auto;background:#fff;border-radius:10px;padding:24px;box-shadow:0 4px 18px rgba(0,0,0,.08)}
.receipt h1{font-size:1.15rem;margin:0;color:#002855;text-align:center}.receipt .sub{text-align:center;color:#667;font-size:.85rem;margin:4px 0 16px}
table{width:100%;border-collapse:collapse}td{padding:9px 4px;border-bottom:1px solid #eee;vertical-align:top}td:first-child{color:#667;width:42%}
.amount{font-size:1.4rem;font-weight:700;color:#198754}.status{display:inline-block;padding:2px 10px;border-radius:12px;background:#e8f5e9;color:#1b5e20;font-size:.85rem}
.actions{max-width:640px;margin:0 auto 12px;display:flex;gap:8px;flex-wrap:wrap}.actions a,.actions button{padding:10px 16px;border-radius:8px;border:0;background:#002855;color:#fff;text-decoration:none;font-size:.95rem;cursor:pointer}
.actions .alt{background:#6c757d}.foot{text-align:center;color:#889;font-size:.8rem;margin-top:18px}
@media print{body{background:#fff;padding:0}.actions{display:none}.receipt{box-shadow:none}}
</style></head><body>
<?php if (!$p): ?>
<div class="receipt"><h1>Receipt not found</h1><p class="sub">This receipt does not exist or you do not have access to it.</p></div>
<?php else: ?>
<div class="actions"><button type="button" onclick="window.print()">Print / Save as PDF</button><a class="alt" href="#" onclick="history.back();return false">Back</a></div>
<div class="receipt">
    <h1><?php echo e(SCHOOL_NAME); ?></h1>
    <p class="sub"><?php echo e(school_address()); ?> &middot; <?php echo e(school_phone()); ?></p>
    <h1 style="margin-bottom:12px">PAYMENT RECEIPT</h1>
    <table>
        <tr><td>Receipt No.</td><td><strong><?php echo e($p['receipt_number']); ?></strong></td></tr>
        <tr><td>Date</td><td><?php echo e(formatDate($p['payment_date'], 'd M Y')); ?></td></tr>
        <tr><td>Student</td><td><?php echo e($p['student_name']); ?> (<?php echo e($p['admission_number']); ?>)</td></tr>
        <tr><td>Class</td><td><?php echo e(trim($p['class_name'] . ' ' . $p['section'])); ?></td></tr>
        <tr><td>Term / Year</td><td><?php echo e($p['term']); ?> / <?php echo e($p['academic_year']); ?></td></tr>
        <tr><td>Method</td><td><?php echo e(ucwords(str_replace('_', ' ', $p['payment_method']))); ?><?php echo $p['transaction_id'] ? ' &middot; Ref ' . e($p['transaction_id']) : ''; ?></td></tr>
        <tr><td>Amount</td><td class="amount"><?php echo e(formatCurrency($p['amount'])); ?></td></tr>
        <tr><td>Status</td><td><span class="status"><?php echo e(ucfirst($p['status'])); ?></span></td></tr>
        <?php if ($p['remarks']): ?><tr><td>Remarks</td><td><?php echo e($p['remarks']); ?></td></tr><?php endif; ?>
        <?php if ($p['recorded_by_name']): ?><tr><td>Received by</td><td><?php echo e($p['recorded_by_name']); ?></td></tr><?php endif; ?>
    </table>
    <p class="foot">Thank you. This receipt was generated electronically on <?php echo e(date('d M Y, h:i A')); ?>.</p>
</div>
<?php endif; ?>
</body></html>
<?php
}
