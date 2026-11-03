<?php
// admin/salary.php - Staff Salary & Payroll Management
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/SystemSchema.php';

Security::requireRole(['super_admin', 'admin']);
SystemSchema::ensureAll();

$db = db();
[$message, $messageType] = flash_get();

// Handle Record Salary Payment POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        flash_redirect('Invalid security token.', 'error');
    }

    $staffId = (int)($_POST['staff_id'] ?? 0);
    $monthYear = trim((string)($_POST['month_year'] ?? date('F Y')));
    $basic = (float)($_POST['basic_salary'] ?? 0.0);
    $allowances = (float)($_POST['allowances'] ?? 0.0);
    $deductions = (float)($_POST['deductions'] ?? 0.0);
    $net = max(0.0, ($basic + $allowances) - $deductions);
    $payDate = valid_date($_POST['payment_date'] ?? '') ?: date('Y-m-d');
    $method = trim((string)($_POST['payment_method'] ?? 'bank_transfer'));
    $ref = trim((string)($_POST['reference'] ?? ('PAY-' . strtoupper(bin2hex(random_bytes(3))))));
    $remarks = trim((string)($_POST['remarks'] ?? ''));

    if (!$staffId || $basic <= 0) {
        flash_redirect('Please select a staff member and specify their basic salary.', 'error');
    }

    $salId = $db->insert(
        "INSERT INTO salary_payments (staff_id, month_year, basic_salary, allowances, deductions, net_salary, payment_date, payment_method, reference, status, remarks, recorded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'paid', ?, ?)",
        [$staffId, $monthYear, $basic, $allowances, $deductions, $net, $payDate, $method, $ref, $remarks, $_SESSION['user_id']]
    );

    Security::logAudit('RECORDED_SALARY_PAYMENT', 'salary_payments', $salId, null, [
        'staff_id'   => $staffId,
        'net_salary' => $net,
        'month'      => $monthYear
    ]);

    flash_redirect("Salary of ₦" . number_format($net, 2) . " recorded for {$monthYear}.", 'success');
}

$allStaff = $db->getRows(
    "SELECT t.id, t.employee_id, u.first_name, u.last_name, t.specialization
     FROM teachers t
     JOIN users u ON t.user_id = u.id
     WHERE u.is_active = 1
     ORDER BY u.first_name, u.last_name"
);

$recentSalaries = $db->getRows(
    "SELECT sp.*, u.first_name, u.last_name, t.employee_id
     FROM salary_payments sp
     JOIN teachers t ON sp.staff_id = t.id
     JOIN users u ON t.user_id = u.id
     ORDER BY sp.payment_date DESC LIMIT 50"
);

$pageTitle = 'Salary Management';
$extraCSS = ['admin.css', 'dashboard.css'];
include __DIR__ . '/../includes/header.php';
dashboard_open('admin', 'Salary &amp; Payroll');
render_alert($message, $messageType);
?>

<div class="card" style="margin-bottom:24px;">
    <div class="card-header">
        <h3><i class="fas fa-money-check-alt"></i> Process Staff Monthly Salary Payment</h3>
    </div>
    <div class="card-body">
        <form method="POST" action="">
            <?php echo csrf_field(); ?>
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:16px;">
                <div class="form-group">
                    <label for="staff_id"><strong>Staff Member *</strong></label>
                    <select name="staff_id" id="staff_id" class="form-control" required>
                        <option value="">-- Select Staff --</option>
                        <?php foreach ($allStaff as $st): ?>
                            <option value="<?php echo e($st['id']); ?>">
                                <?php echo htmlspecialchars($st['first_name'] . ' ' . $st['last_name']); ?> (<?php echo e($st['employee_id']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="month_year"><strong>Payroll Month *</strong></label>
                    <input type="text" name="month_year" id="month_year" class="form-control" required value="<?php echo date('F Y'); ?>" placeholder="e.g. November 2026">
                </div>
                <div class="form-group">
                    <label for="basic_salary"><strong>Basic Salary (₦) *</strong></label>
                    <input type="number" step="0.01" min="1" name="basic_salary" id="basic_salary" class="form-control" required placeholder="0.00">
                </div>
                <div class="form-group">
                    <label for="allowances"><strong>Allowances / Bonus (₦)</strong></label>
                    <input type="number" step="0.01" min="0" name="allowances" id="allowances" class="form-control" value="0.00">
                </div>
            </div>

            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:16px; margin-top:8px;">
                <div class="form-group">
                    <label for="deductions"><strong>Deductions / Taxes (₦)</strong></label>
                    <input type="number" step="0.01" min="0" name="deductions" id="deductions" class="form-control" value="0.00">
                </div>
                <div class="form-group">
                    <label for="payment_date"><strong>Payment Date *</strong></label>
                    <input type="date" name="payment_date" id="payment_date" class="form-control" required value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="form-group">
                    <label for="payment_method"><strong>Channel</strong></label>
                    <select name="payment_method" id="payment_method" class="form-control">
                        <option value="bank_transfer" selected>Zenith Bank Transfer</option>
                        <option value="cheque">Bank Cheque</option>
                        <option value="cash">Cash</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="reference"><strong>Transaction Ref / Slip</strong></label>
                    <input type="text" name="reference" id="reference" class="form-control" placeholder="e.g. SAL-REF-101">
                </div>
            </div>

            <div style="margin-top:12px; display:flex; justify-content:flex-end;">
                <button type="submit" class="btn btn-primary" style="font-weight:700;">
                    <i class="fas fa-check-circle"></i> Issue Salary Payment
                </button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-history"></i> Recent Staff Payroll Records (<?php echo count($recentSalaries); ?>)</h3>
    </div>
    <div class="card-body">
        <?php if (empty($recentSalaries)): ?>
            <p class="text-muted">No salary records generated yet.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Staff Member</th>
                            <th>Month / Period</th>
                            <th>Basic (₦)</th>
                            <th>Allowances</th>
                            <th>Deductions</th>
                            <th>Net Paid (₦)</th>
                            <th>Payment Date</th>
                            <th>Reference</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentSalaries as $sal): ?>
                        <tr>
                            <td>
                                <strong><?php echo e($sal['first_name'] . ' ' . $sal['last_name']); ?></strong><br>
                                <small class="text-muted"><?php echo e($sal['employee_id']); ?></small>
                            </td>
                            <td><span class="badge badge-info"><?php echo e($sal['month_year']); ?></span></td>
                            <td>₦<?php echo number_format((float)$sal['basic_salary'], 2); ?></td>
                            <td>₦<?php echo number_format((float)$sal['allowances'], 2); ?></td>
                            <td>₦<?php echo number_format((float)$sal['deductions'], 2); ?></td>
                            <td><strong style="color:#15803d; font-size:1.05rem;">₦<?php echo number_format((float)$sal['net_salary'], 2); ?></strong></td>
                            <td><?php echo date('d M Y', strtotime($sal['payment_date'])); ?></td>
                            <td><code><?php echo e($sal['reference'] ?: '-'); ?></code></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
dashboard_close();
include __DIR__ . '/../includes/footer.php';
