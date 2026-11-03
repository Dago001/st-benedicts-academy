<?php
// admin/fee-reminders.php - Outstanding Fee Defaulters & WhatsApp/Email Reminders
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/SystemSchema.php';

Security::requireRole(['super_admin', 'admin']);
SystemSchema::ensureAll();

$db = db();
[$message, $messageType] = flash_get();

// Send Email reminder action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_email_reminder'])) {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        flash_redirect('Invalid security token.', 'error');
    }
    $pEmail = trim((string)($_POST['parent_email'] ?? ''));
    $childName = trim((string)($_POST['child_name'] ?? ''));
    $amtDue = (float)($_POST['balance'] ?? 0.0);

    if ($pEmail !== '' && Security::validateEmail($pEmail)) {
        $subject = "Fee Reminder: {$childName} - " . SCHOOL_NAME;
        $body = "<div style='font-family:Arial,sans-serif; max-width:600px; margin:0 auto; padding:20px; border:1px solid #e2e8f0; border-radius:10px;'>"
              . "<h2 style='color:#002147; border-bottom:2px solid #FFD700; padding-bottom:8px;'>St. Benedict's Early Years British Academy</h2>"
              . "<p>Dear Parent / Guardian,</p>"
              . "<p>This is a gentle reminder regarding the outstanding school fee balance of <strong>₦" . number_format($amtDue, 2) . "</strong> for your ward, <strong>" . e($childName) . "</strong>.</p>"
              . "<div style='background:#f8fafc; padding:14px; border-radius:8px; margin:16px 0;'>"
              . "<strong>Official School Bank Account:</strong><br>"
              . "Bank: <strong>" . e(school_bank_name()) . "</strong><br>"
              . "Account Name: <strong>" . e(school_account_name()) . "</strong><br>"
              . "Account Number: <strong style='color:#c8102e; font-size:1.1rem;'>" . e(school_account_number()) . "</strong>"
              . "</div>"
              . "<p>Please send proof of transfer to our bursary or call <strong>" . e(school_phone()) . "</strong> for any assistance.</p>"
              . "<p>Warm regards,<br><strong>Bursary Office</strong><br>" . e(SCHOOL_NAME) . "</p>"
              . "</div>";

        sendEmail($pEmail, $subject, $body);
        Security::logAudit('SENT_FEE_REMINDER_EMAIL', 'students', null, null, ['email' => $pEmail, 'amount' => $amtDue]);
        flash_redirect("Email reminder sent to {$pEmail} successfully.", 'success');
    } else {
        flash_redirect('Invalid parent email address.', 'error');
    }
}

// Fetch students with fees and calculate balances
$students = $db->getRows(
    "SELECT s.id, s.admission_number, u.first_name, u.last_name, s.class_id,
            c.class_name, c.section,
            CONCAT(pu.first_name, ' ', pu.last_name) as parent_name,
            pu.email as parent_email, pu.phone as parent_phone
     FROM students s
     JOIN users u ON s.user_id = u.id
     LEFT JOIN classes c ON s.class_id = c.id
     LEFT JOIN parents p ON s.parent_id = p.id
     LEFT JOIN users pu ON p.user_id = pu.id
     WHERE u.is_active = 1
     ORDER BY c.class_name, u.first_name"
);

$defaulters = [];
$totalOutstandingDebt = 0.0;

foreach ($students as $st) {
    $cId = (int)($st['class_id'] ?? 0);
    $totalFee = (float)($db->getRow("SELECT SUM(amount) as s FROM fee_structure WHERE class_id = ?", [$cId])['s'] ?? 0.0);
    $paid = (float)($db->getRow("SELECT SUM(amount) as s FROM payments WHERE student_id = ? AND status = 'completed'", [$st['id']])['s'] ?? 0.0);
    $bal = max(0.0, $totalFee - $paid);

    if ($bal > 0) {
        $st['total_fees'] = $totalFee;
        $st['total_paid'] = $paid;
        $st['balance'] = $bal;
        $defaulters[] = $st;
        $totalOutstandingDebt += $bal;
    }
}

$pageTitle = 'Fee Reminders & WhatsApp Alerts';
$extraCSS = ['admin.css', 'dashboard.css'];
include __DIR__ . '/../includes/header.php';
dashboard_open('admin', 'Fee Reminders & Alerts');
render_alert($message, $messageType);
?>

<div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); margin-bottom:20px;">
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(239,68,68,0.1);"><i class="fas fa-exclamation-triangle" style="color:#ef4444;"></i></div>
        <div class="stat-content">
            <h3><?php echo count($defaulters); ?></h3>
            <p>Students with Balance</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(220,38,38,0.1);"><i class="fas fa-money-bill-wave" style="color:#dc2626;"></i></div>
        <div class="stat-content">
            <h3>₦<?php echo number_format($totalOutstandingDebt, 2); ?></h3>
            <p>Total Outstanding Fees</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(16,185,129,0.1);"><i class="fab fa-whatsapp" style="color:#10b981;"></i></div>
        <div class="stat-content">
            <h3 style="font-size:1.15rem; color:#10b981;">1-Click WhatsApp</h3>
            <p>Direct Parent Chat Link</p>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <h3><i class="fas fa-paper-plane" style="color:#10b981;"></i> Fee Defaulters &amp; Notification Dispatch (<?php echo count($defaulters); ?>)</h3>
        <span class="text-muted">Direct messaging with official Zenith Bank account: <?php echo e(school_account_number()); ?></span>
    </div>
    <div class="card-body">
        <?php if (empty($defaulters)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> All active students are fully up to date with their fee payments!
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Student &amp; Class</th>
                            <th>Parent / Guardian</th>
                            <th>Total Billed</th>
                            <th>Paid</th>
                            <th>Balance Due</th>
                            <th>Direct Reminder Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($defaulters as $d):
                            $childName = $d['first_name'] . ' ' . $d['last_name'];
                            $pPhone = preg_replace('/\D+/', '', (string)($d['parent_phone'] ?? ''));
                            if (strlen($pPhone) === 11 && $pPhone[0] === '0') {
                                $pPhone = '234' . substr($pPhone, 1);
                            }
                            $msgText = "Dear Parent, this is a friendly reminder from " . SCHOOL_NAME . " regarding the outstanding school fee balance of ₦" . number_format($d['balance'], 2) . " for your ward, " . $childName . " (" . ($d['class_name'] ?? '') . "). Kindly make payment to our official Zenith Bank account:\nBank: " . school_bank_name() . "\nAccount Name: " . school_account_name() . "\nAccount Number: " . school_account_number() . "\nThank you.";
                            $waUrl = "https://wa.me/{$pPhone}?text=" . rawurlencode($msgText);
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo e($childName); ?></strong><br>
                                <small class="text-muted"><?php echo e($d['admission_number']); ?> &bull; <?php echo e($d['class_name'] . ($d['section'] ? ' ' . $d['section'] : '')); ?></small>
                            </td>
                            <td>
                                <strong><?php echo e($d['parent_name'] ?: 'Parent'); ?></strong><br>
                                <small class="text-muted"><?php echo e($d['parent_phone'] ?: 'No Phone'); ?></small>
                            </td>
                            <td>₦<?php echo number_format($d['total_fees'], 2); ?></td>
                            <td><span style="color:#15803d; font-weight:600;">₦<?php echo number_format($d['total_paid'], 2); ?></span></td>
                            <td><strong style="color:#b91c1c; font-size:1.05rem;">₦<?php echo number_format($d['balance'], 2); ?></strong></td>
                            <td>
                                <div style="display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
                                    <?php if ($pPhone !== ''): ?>
                                    <a href="<?php echo e($waUrl); ?>" target="_blank" rel="noopener" class="btn btn-sm btn-success" style="background:#25d366; border-color:#25d366; font-weight:700;">
                                        <i class="fab fa-whatsapp"></i> WhatsApp
                                    </a>
                                    <?php endif; ?>

                                    <?php if (!empty($d['parent_email'])): ?>
                                    <form method="POST" style="display:inline;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="send_email_reminder" value="1">
                                        <input type="hidden" name="parent_email" value="<?php echo e($d['parent_email']); ?>">
                                        <input type="hidden" name="child_name" value="<?php echo e($childName); ?>">
                                        <input type="hidden" name="balance" value="<?php echo e((string)$d['balance']); ?>">
                                        <button type="submit" class="btn btn-sm btn-outline" title="Send Official Email Reminder">
                                            <i class="fas fa-envelope"></i> Email
                                        </button>
                                    </form>
                                    <?php endif; ?>

                                    <a href="quick-fee-collect?student_id=<?php echo e($d['id']); ?>" class="btn btn-sm btn-primary" title="Record Payment">
                                        <i class="fas fa-money-bill"></i> Pay
                                    </a>
                                </div>
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
