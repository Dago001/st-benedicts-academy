<?php
// admin/quick-fee-collect.php - Rapid 10-Second Fee Collection Terminal
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/SystemSchema.php';

Security::requireRole(['super_admin', 'admin']);
SystemSchema::ensureAll();

$db = db();
[$message, $messageType] = flash_get();

$studentId = (int)($_GET['student_id'] ?? 0);
$selectedStudent = null;
if ($studentId > 0) {
    $selectedStudent = $db->getRow(
        "SELECT s.*, u.first_name, u.last_name, c.class_name, c.section,
                CONCAT(pu.first_name, ' ', pu.last_name) as parent_name, pu.phone as parent_phone
         FROM students s
         JOIN users u ON s.user_id = u.id
         LEFT JOIN classes c ON s.class_id = c.id
         LEFT JOIN parents p ON s.parent_id = p.id
         LEFT JOIN users pu ON p.user_id = pu.id
         WHERE s.id = ?",
        [$studentId]
    );
}

// Calculate outstanding fees for selected student
$totalFees = 0.0;
$totalPaid = 0.0;
$balance   = 0.0;
if ($selectedStudent) {
    $cId = (int)($selectedStudent['class_id'] ?? 0);
    $totalFees = (float)($db->getRow("SELECT SUM(amount) as s FROM fee_structure WHERE class_id = ?", [$cId])['s'] ?? 0.0);
    $totalPaid = (float)($db->getRow("SELECT SUM(amount) as s FROM payments WHERE student_id = ? AND status = 'completed'", [$studentId])['s'] ?? 0.0);
    $balance = max(0.0, $totalFees - $totalPaid);
}

// Handle Payment Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        flash_redirect('Invalid security token.', 'error');
    }

    $stId = (int)($_POST['student_id'] ?? 0);
    $amount = (float)($_POST['amount'] ?? 0.0);
    $method = trim((string)($_POST['payment_method'] ?? 'cash'));
    $ref = trim((string)($_POST['transaction_id'] ?? '')) ?: ('RCP-QCK-' . strtoupper(bin2hex(random_bytes(4))));
    $term = trim((string)($_POST['term'] ?? 'First Term'));
    $session = trim((string)($_POST['academic_year'] ?? '2026/2027'));
    $remarks = trim((string)($_POST['remarks'] ?? 'Rapid fee collection at bursary desk'));

    if (!$stId || $amount <= 0) {
        flash_redirect('Please select a student and enter a valid positive payment amount.', 'error');
    }

    try {
        $receiptNo = 'RCP-' . date('Ymd') . '-' . strtoupper(substr(md5($ref . time()), 0, 6));

        $pId = $db->insert(
            "INSERT INTO payments (
                student_id, receipt_number, amount, payment_date, payment_method,
                transaction_id, term, academic_year, status, recorded_by, remarks
            ) VALUES (?, ?, ?, CURDATE(), ?, ?, ?, ?, 'completed', ?, ?)",
            [$stId, $receiptNo, $amount, $method, $ref, $term, $session, $_SESSION['user_id'], $remarks]
        );

        Security::logAudit('QUICK_FEE_COLLECTION', 'payments', $pId, null, [
            'amount'     => $amount,
            'receipt'    => $receiptNo,
            'student_id' => $stId
        ]);

        flash_redirect("Payment of ₦" . number_format($amount, 2) . " recorded successfully! Receipt: {$receiptNo}", 'success', "print-receipt?id={$pId}");
    } catch (Throwable $e) {
        error_log("quick-fee error: " . $e->getMessage());
        flash_redirect('Error recording payment: ' . $e->getMessage(), 'error');
    }
}

// Student options for search dropdown
$allStudents = $db->getRows(
    "SELECT s.id, s.admission_number, u.first_name, u.last_name, c.class_name, c.section
     FROM students s
     JOIN users u ON s.user_id = u.id
     LEFT JOIN classes c ON s.class_id = c.id
     WHERE u.is_active = 1
     ORDER BY u.first_name, u.last_name"
);

$pageTitle = 'Quick Fee Collection';
$extraCSS = ['admin.css', 'dashboard.css'];
include __DIR__ . '/../includes/header.php';
dashboard_open('admin', 'Quick Fee Collection');
render_alert($message, $messageType);
?>

<div class="card" style="margin-bottom:20px;">
    <div class="card-header">
        <h3><i class="fas fa-bolt" style="color:#10b981;"></i> Step 1: Search &amp; Select Student</h3>
    </div>
    <div class="card-body">
        <form method="GET" class="form-inline" style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
            <div style="flex:1; min-width:280px;">
                <select name="student_id" id="student_id" class="form-control" onchange="this.form.submit()" style="width:100%;">
                    <option value="">-- Choose a Student by Name or Admission No. --</option>
                    <?php foreach ($allStudents as $st): ?>
                        <option value="<?php echo e($st['id']); ?>" <?php echo $studentId === (int)$st['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($st['first_name'] . ' ' . $st['last_name']); ?>
                            (<?php echo htmlspecialchars($st['admission_number']); ?>) &mdash; <?php echo htmlspecialchars(($st['class_name'] ?? 'No Class') . ($st['section'] ? ' ' . $st['section'] : '')); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Select Student</button>
        </form>
    </div>
</div>

<?php if ($selectedStudent): ?>
<div class="card" style="margin-bottom:20px; border-color:#86efac; background:#f0fdf4;">
    <div class="card-body" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
        <div>
            <h4 style="margin:0 0 4px; color:#14532d; font-size:1.15rem;">
                <i class="fas fa-user-check"></i> <?php echo htmlspecialchars($selectedStudent['first_name'] . ' ' . $selectedStudent['last_name']); ?>
                <span class="badge badge-info" style="font-size:0.8rem;"><?php echo htmlspecialchars($selectedStudent['admission_number']); ?></span>
            </h4>
            <div style="font-size:0.88rem; color:#166534;">
                <span>Class: <strong><?php echo htmlspecialchars($selectedStudent['class_name'] . ' ' . ($selectedStudent['section'] ?? '')); ?></strong></span> &bull;
                <span>Parent: <strong><?php echo htmlspecialchars($selectedStudent['parent_name'] ?: 'Not recorded'); ?></strong> (<?php echo htmlspecialchars($selectedStudent['parent_phone'] ?: 'N/A'); ?>)</span>
            </div>
        </div>
        <div style="display:flex; gap:20px; text-align:right;">
            <div>
                <small class="text-muted" style="display:block; text-transform:uppercase; font-size:0.7rem; font-weight:700;">Total Fee Bill</small>
                <strong style="font-size:1.1rem; color:#0f172a;">₦<?php echo number_format($totalFees, 2); ?></strong>
            </div>
            <div>
                <small class="text-muted" style="display:block; text-transform:uppercase; font-size:0.7rem; font-weight:700;">Already Paid</small>
                <strong style="font-size:1.1rem; color:#15803d;">₦<?php echo number_format($totalPaid, 2); ?></strong>
            </div>
            <div>
                <small class="text-muted" style="display:block; text-transform:uppercase; font-size:0.7rem; font-weight:700;">Current Balance Due</small>
                <strong style="font-size:1.3rem; color:#b91c1c;">₦<?php echo number_format($balance, 2); ?></strong>
            </div>
        </div>
    </div>
</div>

<!-- Step 2: Instant Payment Form -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-money-bill-wave"></i> Step 2: Record Payment &amp; Issue Receipt</h3>
    </div>
    <div class="card-body">
        <form method="POST" action="">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="student_id" value="<?php echo e($studentId); ?>">

            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap:16px;">
                <div class="form-group">
                    <label for="amount"><strong>Amount Received (₦) *</strong></label>
                    <input type="number" step="0.01" min="1" id="amount" name="amount" class="form-control"
                           value="<?php echo $balance > 0 ? e((string)$balance) : '15000.00'; ?>" required>
                    <small class="text-muted">Enter exact amount received</small>
                </div>

                <div class="form-group">
                    <label for="payment_method"><strong>Payment Channel *</strong></label>
                    <select name="payment_method" id="payment_method" class="form-control" required>
                        <option value="bank_transfer" selected>Direct Bank Transfer (Zenith Bank - 1310057496)</option>
                        <option value="card">Paystack / POS Card</option>
                        <option value="cash">Cash Payment</option>
                        <option value="cheque">Bank Draft / Cheque</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="transaction_id"><strong>Bank Reference / Teller No.</strong></label>
                    <input type="text" id="transaction_id" name="transaction_id" class="form-control"
                           placeholder="e.g. ZEN-TRF-..." value="TRF-<?php echo strtoupper(bin2hex(random_bytes(3))); ?>">
                </div>

                <div class="form-group">
                    <label for="term"><strong>Academic Term</strong></label>
                    <select name="term" id="term" class="form-control">
                        <option value="Term 1" selected>Term 1 (First Term)</option>
                        <option value="Term 2">Term 2 (Second Term)</option>
                        <option value="Term 3">Term 3 (Third Term)</option>
                    </select>
                </div>
            </div>

            <div class="form-group" style="margin-top:8px;">
                <label for="remarks">Payment Remarks</label>
                <input type="text" id="remarks" name="remarks" class="form-control" placeholder="Optional cashier note">
            </div>

            <div style="margin-top:20px; display:flex; justify-content:flex-end;">
                <button type="submit" class="btn btn-primary" style="font-weight:700; padding:12px 28px; background:#10b981; border-color:#10b981;">
                    <i class="fas fa-check-circle"></i> Confirm Payment &amp; Generate Receipt
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php
dashboard_close();
include __DIR__ . '/../includes/footer.php';
