<?php
// admin/fee-deletion-log.php - Financial Audit Trail of Deleted/Reversed Fee Records
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/SystemSchema.php';

Security::requireRole(['super_admin', 'admin']);
SystemSchema::ensureAll();

$db = db();
[$message, $messageType] = flash_get();

$logs = $db->getRows("SELECT * FROM fee_deletion_logs ORDER BY deleted_at DESC LIMIT 200");

$pageTitle = 'Fee Deletion Log';
$extraCSS = ['admin.css', 'dashboard.css'];
include __DIR__ . '/../includes/header.php';
dashboard_open('admin', 'Fee Deletion Log');
render_alert($message, $messageType);
?>

<div class="card">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <div>
            <h3 style="margin:0;"><i class="fas fa-trash-alt" style="color:#ef4444;"></i> Financial Deletion &amp; Reversal Audit Log</h3>
            <span class="text-muted" style="font-size:0.85rem;">Immutable audit trail of reversed transactions or deleted fee structures</span>
        </div>
        <span class="badge badge-danger"><?php echo count($logs); ?> Deletion Log(s)</span>
    </div>
    <div class="card-body">
        <?php if (empty($logs)): ?>
            <div class="alert alert-info">
                <i class="fas fa-shield-alt"></i> No fee structures or payments have been deleted or reversed. All financial ledgers are intact.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Item Type</th>
                            <th>Student / Reference</th>
                            <th>Amount</th>
                            <th>Reason for Deletion</th>
                            <th>Deleted By</th>
                            <th>IP Address</th>
                            <th>Timestamp</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $l): ?>
                        <tr>
                            <td>
                                <span class="badge badge-<?php echo $l['item_type'] === 'payment' ? 'danger' : 'warning'; ?>">
                                    <?php echo strtoupper($l['item_type']); ?>
                                </span>
                            </td>
                            <td>
                                <strong><?php echo e($l['student_name'] ?: 'Fee Structure #' . $l['item_id']); ?></strong>
                                <?php if (!empty($l['admission_number']) || !empty($l['reference'])): ?>
                                    <br><small class="text-muted"><?php echo e(trim(($l['admission_number'] ?? '') . ' ' . ($l['reference'] ?? ''))); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><strong>₦<?php echo number_format((float)$l['amount'], 2); ?></strong></td>
                            <td><?php echo e($l['reason']); ?></td>
                            <td><strong><?php echo e($l['deleted_by_name']); ?></strong> (ID: <?php echo (int)$l['deleted_by']; ?>)</td>
                            <td><code><?php echo e($l['ip_address'] ?: '-'); ?></code></td>
                            <td><?php echo date('d M Y H:i:s', strtotime($l['deleted_at'])); ?></td>
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
