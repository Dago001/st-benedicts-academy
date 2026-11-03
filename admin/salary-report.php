<?php
// admin/salary-report.php - Annual & Monthly Staff Salary Reports
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/SystemSchema.php';

Security::requireRole(['super_admin', 'admin']);
SystemSchema::ensureAll();

$db = db();
[$message, $messageType] = flash_get();

$totalPayroll = (float)($db->getRow("SELECT SUM(net_salary) as s FROM salary_payments WHERE status = 'paid'")['s'] ?? 0.0);
$totalStaffCount = (int)($db->getRow("SELECT COUNT(*) as c FROM teachers t JOIN users u ON t.user_id = u.id WHERE u.is_active = 1")['c'] ?? 0);

$monthlyPayrolls = $db->getRows(
    "SELECT month_year,
            COUNT(*) as staff_paid,
            SUM(basic_salary) as total_basic,
            SUM(allowances) as total_allowances,
            SUM(deductions) as total_deductions,
            SUM(net_salary) as total_net
     FROM salary_payments
     WHERE status = 'paid'
     GROUP BY month_year
     ORDER BY id DESC LIMIT 24"
);

$pageTitle = 'Salary Report';
$extraCSS = ['admin.css', 'dashboard.css'];
include __DIR__ . '/../includes/header.php';
dashboard_open('admin', 'Salary Report');
render_alert($message, $messageType);
?>

<div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); margin-bottom:24px;">
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(0,33,71,0.1);"><i class="fas fa-users" style="color:#002147;"></i></div>
        <div class="stat-content">
            <h3><?php echo $totalStaffCount; ?></h3>
            <p>Active Staff Members</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(16,185,129,0.1);"><i class="fas fa-money-check" style="color:#10b981;"></i></div>
        <div class="stat-content">
            <h3>₦<?php echo number_format($totalPayroll, 2); ?></h3>
            <p>Total Cumulative Payroll</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(59,130,246,0.1);"><i class="fas fa-calendar-alt" style="color:#3b82f6;"></i></div>
        <div class="stat-content">
            <h3><?php echo count($monthlyPayrolls); ?></h3>
            <p>Payroll Cycles Processed</p>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <h3><i class="fas fa-chart-bar"></i> Monthly Payroll Summary Ledger</h3>
        <button type="button" onclick="window.print()" class="btn btn-outline"><i class="fas fa-print"></i> Print Payroll Report</button>
    </div>
    <div class="card-body">
        <?php if (empty($monthlyPayrolls)): ?>
            <p class="text-muted">No monthly payroll records available yet.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Payroll Period</th>
                            <th>Staff Disbursed</th>
                            <th style="text-align:right;">Basic Salary</th>
                            <th style="text-align:right;">Allowances</th>
                            <th style="text-align:right;">Deductions</th>
                            <th style="text-align:right;">Net Disbursement (₦)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($monthlyPayrolls as $mp): ?>
                        <tr>
                            <td><strong><?php echo e($mp['month_year']); ?></strong></td>
                            <td><span class="badge badge-info"><?php echo (int)$mp['staff_paid']; ?> Staff</span></td>
                            <td style="text-align:right;">₦<?php echo number_format((float)$mp['total_basic'], 2); ?></td>
                            <td style="text-align:right;">₦<?php echo number_format((float)$mp['total_allowances'], 2); ?></td>
                            <td style="text-align:right;">₦<?php echo number_format((float)$mp['total_deductions'], 2); ?></td>
                            <td style="text-align:right; font-weight:800; color:#15803d; font-size:1.05rem;">
                                ₦<?php echo number_format((float)$mp['total_net'], 2); ?>
                            </td>
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
