<?php
// admin/admissions-enquiry.php - Admission Enquiries Management
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/SystemSchema.php';
require_once __DIR__ . '/../includes/ApplicationHelper.php';

Security::requireRole(['super_admin', 'admin', 'head_teacher']);
SystemSchema::ensureAll();
ApplicationHelper::ensureSchema();
ApplicationHelper::syncAdmissionsTables();

$db = db();
[$message, $messageType] = flash_get();

// Handle quick status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        flash_redirect('Invalid security token.', 'error');
    }
    $id = (int)($_POST['id'] ?? 0);
    $newStatus = trim($_POST['status'] ?? '');
    if ($id > 0 && in_array($newStatus, ['pending', 'reviewing', 'accepted', 'rejected'], true)) {
        $db->query("UPDATE admissions SET status = ? WHERE id = ?", [$newStatus, $id]);
        $row = $db->getRow("SELECT application_number FROM admissions WHERE id = ?", [$id]);
        if ($row && !empty($row['application_number'])) {
            $db->query("UPDATE applications SET status = ? WHERE application_number = ?", [$newStatus, $row['application_number']]);
        }
        Security::logAudit('UPDATED_ADMISSION_STATUS', 'admissions', $id, null, ['status' => $newStatus]);
        flash_redirect("Admission enquiry status updated to " . ucfirst($newStatus) . ".", 'success');
    }
}

$filterStatus = $_GET['status'] ?? '';
$where = "WHERE 1=1";
$params = [];
if ($filterStatus !== '') {
    $where .= " AND status = ?";
    $params[] = $filterStatus;
}

$enquiries = [];
try {
    $enquiries = $db->getRows(
        "SELECT * FROM admissions {$where} ORDER BY submitted_at DESC LIMIT 200",
        $params
    );
} catch (Throwable $e) {
    try {
        $enquiries = $db->getRows(
            "SELECT * FROM admissions {$where} ORDER BY id DESC LIMIT 200",
            $params
        );
    } catch (Throwable $e2) {
        error_log("admissions-enquiry query error: " . $e2->getMessage());
        $enquiries = [];
    }
}

$statusCounts = ['pending' => 0, 'reviewing' => 0, 'accepted' => 0, 'rejected' => 0];
$totalCount = 0;
try {
    $countsQuery = $db->getRows("SELECT status, COUNT(*) as cnt FROM admissions GROUP BY status");
    foreach ($countsQuery as $cq) {
        $st = strtolower((string)($cq['status'] ?? ''));
        $cnt = (int)($cq['cnt'] ?? 0);
        if (isset($statusCounts[$st])) {
            $statusCounts[$st] = $cnt;
        }
        $totalCount += $cnt;
    }
} catch (Throwable $e) {
    // Graceful fallback
}

$pageTitle = 'Admission Enquiries';
$extraCSS = ['admin.css', 'dashboard.css'];
include __DIR__ . '/../includes/header.php';

$headerActions = '<a href="applications" class="btn btn-outline" style="white-space:nowrap;"><i class="fas fa-file-invoice-dollar"></i> Review Applications &amp; Payments</a> '
               . '<a href="new-admission" class="btn btn-primary" style="white-space:nowrap;"><i class="fas fa-user-plus"></i> Direct New Admission</a>';

dashboard_open('admin', 'Admission Enquiries', $headerActions);
render_alert($message, $messageType);
?>

<style>
.enquiry-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 14px;
    padding: 16px 20px;
    background: #ffffff;
    border-bottom: 1px solid #e2e8f0;
}
.enquiry-card-header h3 {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 700;
    color: #002147;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
.enquiry-filter-pills {
    display: inline-flex;
    align-items: center;
    background: #f1f5f9;
    padding: 3px;
    border-radius: 10px;
    gap: 2px;
    flex-wrap: wrap;
}
.enquiry-filter-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    font-size: 0.83rem;
    font-weight: 600;
    color: #475569;
    text-decoration: none;
    border-radius: 8px;
    transition: all 0.18s ease;
    white-space: nowrap;
}
.enquiry-filter-pill:hover {
    color: #002147;
    background: rgba(255, 255, 255, 0.7);
}
.enquiry-filter-pill.active {
    background: #c8102e;
    color: #ffffff;
    box-shadow: 0 1px 3px rgba(200, 16, 46, 0.25);
}
.enquiry-filter-pill .pill-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 18px;
    height: 18px;
    padding: 0 6px;
    font-size: 0.72rem;
    font-weight: 700;
    border-radius: 999px;
    background: rgba(0, 0, 0, 0.08);
}
.enquiry-filter-pill.active .pill-count {
    background: rgba(255, 255, 255, 0.28);
    color: #ffffff;
}
@media (max-width: 900px) {
    .enquiry-card-header {
        flex-direction: column;
        align-items: flex-start;
    }
    .enquiry-filter-pills {
        width: 100%;
        overflow-x: auto;
        flex-wrap: nowrap;
        -webkit-overflow-scrolling: touch;
        padding: 4px;
    }
}
</style>

<div class="card">
    <div class="enquiry-card-header">
        <h3>
            <i class="fas fa-clipboard-list"></i>
            Admission Enquiries &amp; Applications (<?php echo count($enquiries); ?>)
        </h3>
        <div class="enquiry-filter-pills" role="tablist">
            <a class="enquiry-filter-pill <?php echo $filterStatus === '' ? 'active' : ''; ?>" href="admissions-enquiry">
                All <span class="pill-count"><?php echo $totalCount; ?></span>
            </a>
            <a class="enquiry-filter-pill <?php echo $filterStatus === 'pending' ? 'active' : ''; ?>" href="admissions-enquiry?status=pending">
                Pending <span class="pill-count"><?php echo $statusCounts['pending']; ?></span>
            </a>
            <a class="enquiry-filter-pill <?php echo $filterStatus === 'reviewing' ? 'active' : ''; ?>" href="admissions-enquiry?status=reviewing">
                Reviewing <span class="pill-count"><?php echo $statusCounts['reviewing']; ?></span>
            </a>
            <a class="enquiry-filter-pill <?php echo $filterStatus === 'accepted' ? 'active' : ''; ?>" href="admissions-enquiry?status=accepted">
                Admitted <span class="pill-count"><?php echo $statusCounts['accepted']; ?></span>
            </a>
            <a class="enquiry-filter-pill <?php echo $filterStatus === 'rejected' ? 'active' : ''; ?>" href="admissions-enquiry?status=rejected">
                Rejected <span class="pill-count"><?php echo $statusCounts['rejected']; ?></span>
            </a>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($enquiries)): ?>
            <p class="text-muted" style="padding: 20px 0; text-align: center;">No admission enquiries found.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>App / Enquiry No.</th>
                            <th>Candidate Name</th>
                            <th>Class Applying</th>
                            <th>Parent / Guardian</th>
                            <th>Contact Phone &amp; Email</th>
                            <th>Payment</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($enquiries as $enq):
                            $cName = trim(($enq['first_name'] ?? '') . ' ' . (!empty($enq['middle_name']) ? $enq['middle_name'] . ' ' : '') . ($enq['last_name'] ?? ''));
                            if (!$cName) {
                                $cName = 'Candidate';
                            }
                            $stClass = ['pending' => 'warning', 'reviewing' => 'info', 'accepted' => 'success', 'rejected' => 'danger'][$enq['status']] ?? 'info';
                            $pStatus = strtolower($enq['payment_status'] ?? 'pending');
                            $pBadge = $pStatus === 'paid' ? 'success' : ($pStatus === 'pay_offline' ? 'info' : 'warning');
                            $pLabel = $pStatus === 'paid' ? 'Paid (₦' . number_format((float)($enq['amount_paid'] ?? 15000)) . ')' : ($pStatus === 'pay_offline' ? 'Pay at School' : 'Pending');
                            $dobText = !empty($enq['date_of_birth']) ? date('d M Y', strtotime($enq['date_of_birth'])) : '';
                            $genderText = !empty($enq['gender']) ? ucfirst($enq['gender']) : '';
                            $submittedDate = !empty($enq['submitted_at']) ? date('d M Y', strtotime($enq['submitted_at'])) : 'Recent';
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo e($enq['application_number']); ?></strong>
                                <?php if (!empty($enq['receipt_number'])): ?>
                                    <br><small class="text-muted"><?php echo e($enq['receipt_number']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?php echo e($cName); ?></strong>
                                <?php if ($dobText): ?>
                                    <br><small class="text-muted">DOB: <?php echo e($dobText); ?><?php echo $genderText ? ' (' . e($genderText) . ')' : ''; ?></small>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge badge-info"><?php echo e($enq['class_applying_for'] ?? 'N/A'); ?></span></td>
                            <td>
                                <?php echo e($enq['parent_name'] ?? 'Parent'); ?>
                                <?php if (!empty($enq['parent_relationship'])): ?>
                                    <br><small class="text-muted">(<?php echo e($enq['parent_relationship']); ?>)</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($enq['parent_phone'])): ?>
                                    <a href="tel:<?php echo e($enq['parent_phone']); ?>"><?php echo e($enq['parent_phone']); ?></a><br>
                                <?php endif; ?>
                                <?php if (!empty($enq['parent_email'])): ?>
                                    <small><a href="mailto:<?php echo e($enq['parent_email']); ?>"><?php echo e($enq['parent_email']); ?></a></small>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge badge-<?php echo $pBadge; ?>"><?php echo e($pLabel); ?></span></td>
                            <td><?php echo e($submittedDate); ?></td>
                            <td>
                                <span class="badge badge-<?php echo $stClass; ?>"><?php echo ucfirst($enq['status']); ?></span>
                            </td>
                            <td>
                                <div style="display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
                                    <?php if ($enq['status'] !== 'accepted'): ?>
                                    <a href="new-admission?enquiry_id=<?php echo e($enq['id']); ?>" class="btn btn-sm btn-primary" title="Convert enquiry to full enrolled student">
                                        <i class="fas fa-user-check"></i> Admit
                                    </a>
                                    <?php endif; ?>
                                    <?php if (!empty($enq['receipt_token'])): ?>
                                    <a href="../public/application-receipt?app=<?php echo urlencode($enq['application_number']); ?>&token=<?php echo urlencode($enq['receipt_token']); ?>" target="_blank" class="btn btn-sm btn-outline" title="Print/View official application slip">
                                        <i class="fas fa-print"></i> Slip
                                    </a>
                                    <?php endif; ?>
                                    <a href="applications" class="btn btn-sm btn-outline" title="Full application details &amp; review">
                                        <i class="fas fa-eye"></i> Details
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
