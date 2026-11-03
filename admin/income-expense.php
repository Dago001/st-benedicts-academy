<?php
// admin/income-expense.php - Cashflow & Net Operating Income vs Expense
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/SystemSchema.php';

Security::requireRole(['super_admin', 'admin']);
SystemSchema::ensureAll();

$db = db();
[$message, $messageType] = flash_get();

// Total Income (from payments table & online application ledger)
$totalIncome = (float)($db->getRow("SELECT SUM(amount) as s FROM payments WHERE status = 'completed'")['s'] ?? 0.0);
$totalExpenses = (float)($db->getRow("SELECT SUM(amount) as s FROM expenses")['s'] ?? 0.0);
$netBalance = $totalIncome - $totalExpenses;

// Monthly Breakdown (Last 6 Months)
$monthlyData = [];
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-{$i} month"));
    $mLabel = date('M Y', strtotime("-{$i} month"));

    $inc = (float)($db->getRow("SELECT SUM(amount) as s FROM payments WHERE status = 'completed' AND DATE_FORMAT(payment_date, '%Y-%m') = ?", [$m])['s'] ?? 0.0);
    $exp = (float)($db->getRow("SELECT SUM(amount) as s FROM expenses WHERE DATE_FORMAT(expense_date, '%Y-%m') = ?", [$m])['s'] ?? 0.0);

    $monthlyData[] = [
        'month'   => $mLabel,
        'income'  => $inc,
        'expense' => $exp,
        'net'     => $inc - $exp
    ];
}

$pageTitle = 'Income vs Expense';
$extraCSS = ['admin.css', 'dashboard.css'];
include __DIR__ . '/../includes/header.php';
dashboard_open('admin', 'Income vs Expense');
render_alert($message, $messageType);
?>

<div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); margin-bottom:24px;">
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(16,185,129,0.1);"><i class="fas fa-arrow-down" style="color:#10b981;"></i></div>
        <div class="stat-content">
            <h3 style="color:#15803d;">₦<?php echo number_format($totalIncome, 2); ?></h3>
            <p>Total Revenue (Income)</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(239,68,68,0.1);"><i class="fas fa-arrow-up" style="color:#ef4444;"></i></div>
        <div class="stat-content">
            <h3 style="color:#b91c1c;">₦<?php echo number_format($totalExpenses, 2); ?></h3>
            <p>Total Operational Expenses</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(192,132,252,0.1);"><i class="fas fa-balance-scale" style="color:#a855f7;"></i></div>
        <div class="stat-content">
            <h3 style="color:<?php echo $netBalance >= 0 ? '#15803d' : '#b91c1c'; ?>;">₦<?php echo number_format($netBalance, 2); ?></h3>
            <p>Net Operating <?php echo $netBalance >= 0 ? 'Surplus' : 'Deficit'; ?></p>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <h3><i class="fas fa-chart-pie" style="color:#c084fc;"></i> Monthly Financial Comparison</h3>
        <button type="button" onclick="window.print()" class="btn btn-outline"><i class="fas fa-print"></i> Print Statement</button>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Calendar Month</th>
                        <th style="text-align:right;">Income / Fees Received (₦)</th>
                        <th style="text-align:right;">Operating Expenses (₦)</th>
                        <th style="text-align:right;">Net Margin (₦)</th>
                        <th style="text-align:center;">Health Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($monthlyData as $md):
                        $isPos = $md['net'] >= 0;
                    ?>
                    <tr>
                        <td><strong><?php echo e($md['month']); ?></strong></td>
                        <td style="text-align:right; font-weight:700; color:#15803d;">₦<?php echo number_format($md['income'], 2); ?></td>
                        <td style="text-align:right; font-weight:700; color:#b91c1c;">₦<?php echo number_format($md['expense'], 2); ?></td>
                        <td style="text-align:right; font-weight:800; color:<?php echo $isPos ? '#15803d' : '#b91c1c'; ?>;">
                            ₦<?php echo number_format($md['net'], 2); ?>
                        </td>
                        <td style="text-align:center;">
                            <span class="badge badge-<?php echo $isPos ? 'success' : 'danger'; ?>">
                                <?php echo $isPos ? 'Surplus' : 'Deficit'; ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
dashboard_close();
include __DIR__ . '/../includes/footer.php';
