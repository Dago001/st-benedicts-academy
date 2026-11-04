<?php
// admin/expenses.php - School Operational Expense Management
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/SystemSchema.php';

Security::requireRole(['super_admin', 'admin']);
SystemSchema::ensureAll();

$db = db();
[$message, $messageType] = flash_get();

// Handle New Expense POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        flash_redirect('Invalid security token.', 'error');
    }

    $title = trim((string)($_POST['title'] ?? ''));
    $category = trim((string)($_POST['category'] ?? 'General'));
    $amount = (float)($_POST['amount'] ?? 0.0);
    $expDate = valid_date($_POST['expense_date'] ?? '') ?: date('Y-m-d');
    $method = trim((string)($_POST['payment_method'] ?? 'bank_transfer'));
    $recipient = trim((string)($_POST['recipient'] ?? ''));
    $desc = trim((string)($_POST['description'] ?? ''));

    if ($title === '' || $amount <= 0) {
        flash_redirect('Please provide a valid expense title and amount.', 'error');
    }

    $expId = $db->insert(
        "INSERT INTO expenses (title, category, amount, expense_date, payment_method, recipient, description, recorded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
        [$title, $category, $amount, $expDate, $method, $recipient, $desc, $_SESSION['user_id']]
    );

    Security::logAudit('RECORDED_EXPENSE', 'expenses', $expId, null, [
        'title'  => $title,
        'amount' => $amount
    ]);

    flash_redirect("Expense of ₦" . number_format($amount, 2) . " recorded successfully.", 'success');
}

$expenses = $db->getRows("SELECT * FROM expenses ORDER BY expense_date DESC LIMIT 200");
$totalExpenses = (float)($db->getRow("SELECT SUM(amount) as s FROM expenses")['s'] ?? 0.0);
$thisMonthExpenses = (float)($db->getRow("SELECT SUM(amount) as s FROM expenses WHERE DATE_FORMAT(expense_date, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')")['s'] ?? 0.0);

$pageTitle = 'Expenses Management';
$extraCSS = ['admin.css', 'dashboard.css'];
include __DIR__ . '/../includes/header.php';
dashboard_open('admin', 'Operational Expenses');
render_alert($message, $messageType);
?>

<div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); margin-bottom:24px;">
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(239,68,68,0.1);"><i class="fas fa-receipt" style="color:#ef4444;"></i></div>
        <div class="stat-content">
            <h3>₦<?php echo number_format($thisMonthExpenses, 2); ?></h3>
            <p>Spent This Month</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(0,33,71,0.1);"><i class="fas fa-wallet" style="color:#002147;"></i></div>
        <div class="stat-content">
            <h3>₦<?php echo number_format($totalExpenses, 2); ?></h3>
            <p>Total Cumulative Expenses</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(16,185,129,0.1);"><i class="fas fa-file-invoice" style="color:#10b981;"></i></div>
        <div class="stat-content">
            <h3><?php echo count($expenses); ?></h3>
            <p>Recorded Expense Vouchers</p>
        </div>
    </div>
</div>

<div class="card" style="margin-bottom:24px;">
    <div class="card-header">
        <h3><i class="fas fa-plus-circle"></i> Record Operational Expense Voucher</h3>
    </div>
    <div class="card-body">
        <form method="POST" action="">
            <?php echo csrf_field(); ?>
            <div class="expense-form-grid form-grid" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:16px;">
                <div class="form-group">
                    <label for="title"><strong>Expense Title / Purpose *</strong></label>
                    <input type="text" name="title" id="title" class="form-control" required placeholder="e.g. Science Laboratory Consumables">
                </div>
                <div class="form-group">
                    <label for="category"><strong>Category *</strong></label>
                    <select name="category" id="category" class="form-control">
                        <option value="Academic Supplies" selected>Academic Supplies</option>
                        <option value="Facility Maintenance">Facility &amp; Repairs</option>
                        <option value="Utilities">Utilities &amp; Power</option>
                        <option value="IT & Software">IT &amp; Digital Infrastructure</option>
                        <option value="Staff Welfare">Staff Welfare</option>
                        <option value="Events">School Events &amp; Sports</option>
                        <option value="General">General Administrative</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="amount"><strong>Amount (₦) *</strong></label>
                    <input type="number" step="0.01" min="1" name="amount" id="amount" class="form-control" required placeholder="0.00">
                </div>
                <div class="form-group">
                    <label for="expense_date"><strong>Date *</strong></label>
                    <input type="date" name="expense_date" id="expense_date" class="form-control" required value="<?php echo date('Y-m-d'); ?>">
                </div>
            </div>

            <div class="expense-form-grid form-grid" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:16px; margin-top:8px;">
                <div class="form-group">
                    <label for="payment_method"><strong>Payment Channel</strong></label>
                    <select name="payment_method" id="payment_method" class="form-control">
                        <option value="bank_transfer" selected>Bank Transfer (Zenith Bank)</option>
                        <option value="cash">Petty Cash</option>
                        <option value="card">Debit Card / POS</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="recipient"><strong>Vendor / Recipient</strong></label>
                    <input type="text" name="recipient" id="recipient" class="form-control" placeholder="e.g. Cambridge Books Vendor">
                </div>
                <div class="form-group" style="grid-column: span 2;">
                    <label for="description"><strong>Description / Voucher Notes</strong></label>
                    <input type="text" name="description" id="description" class="form-control" placeholder="Optional expense justification note">
                </div>
            </div>

            <div class="expense-form-actions" style="margin-top:16px; display:flex; justify-content:flex-end;">
                <button type="submit" class="btn btn-primary" style="font-weight:700;">
                    <i class="fas fa-check"></i> Post Expense Voucher
                </button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-list"></i> Expenditure Ledger (<?php echo count($expenses); ?>)</h3>
    </div>
    <div class="card-body">
        <?php if (empty($expenses)): ?>
            <p class="text-muted">No expenses recorded yet.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Voucher Title</th>
                            <th>Category</th>
                            <th>Amount (₦)</th>
                            <th>Date</th>
                            <th>Method</th>
                            <th>Recipient</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($expenses as $ex): ?>
                        <tr>
                            <td><strong><?php echo e($ex['title']); ?></strong></td>
                            <td><span class="badge badge-secondary"><?php echo e($ex['category']); ?></span></td>
                            <td><strong style="color:#ef4444;">₦<?php echo number_format((float)$ex['amount'], 2); ?></strong></td>
                            <td><?php echo date('d M Y', strtotime($ex['expense_date'])); ?></td>
                            <td><?php echo ucfirst(str_replace('_', ' ', $ex['payment_method'])); ?></td>
                            <td><?php echo e($ex['recipient'] ?: '-'); ?></td>
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
