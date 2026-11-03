<?php
// admin/admissions-enquiry.php - Admission Enquiries Management
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/SystemSchema.php';

Security::requireRole(['super_admin', 'admin', 'head_teacher']);
SystemSchema::ensureAll();

$db = db();
[$message, $messageType] = flash_get();

$filterStatus = $_GET['status'] ?? '';
$where = "WHERE 1=1";
$params = [];
if ($filterStatus !== '') {
    $where .= " AND status = ?";
    $params[] = $filterStatus;
}

$enquiries = $db->getRows(
    "SELECT * FROM admissions {$where} ORDER BY submitted_at DESC LIMIT 200",
    $params
);

$pageTitle = 'Admission Enquiries';
$extraCSS = ['admin.css', 'dashboard.css'];
include __DIR__ . '/../includes/header.php';
dashboard_open('admin', 'Admission Enquiries');
render_alert($message, $messageType);
?>

<div class="card" style="margin-bottom: 20px;">
    <div class="card-body form-inline" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <div style="display:flex; align-items:center; gap:8px;">
            <strong>Filter by Status:</strong>
            <a class="btn btn-sm <?php echo $filterStatus === '' ? 'btn-primary' : 'btn-outline'; ?>" href="admissions-enquiry">All</a>
            <a class="btn btn-sm <?php echo $filterStatus === 'pending' ? 'btn-primary' : 'btn-outline'; ?>" href="admissions-enquiry?status=pending">Pending</a>
            <a class="btn btn-sm <?php echo $filterStatus === 'reviewing' ? 'btn-primary' : 'btn-outline'; ?>" href="admissions-enquiry?status=reviewing">Reviewing</a>
            <a class="btn btn-sm <?php echo $filterStatus === 'accepted' ? 'btn-primary' : 'btn-outline'; ?>" href="admissions-enquiry?status=accepted">Admitted</a>
            <a class="btn btn-sm <?php echo $filterStatus === 'rejected' ? 'btn-primary' : 'btn-outline'; ?>" href="admissions-enquiry?status=rejected">Rejected</a>
        </div>
        <div>
            <a href="new-admission" class="btn btn-sm btn-primary">
                <i class="fas fa-user-plus"></i> Direct New Admission
            </a>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-clipboard-list"></i> Admission Enquiries &amp; Applications (<?php echo count($enquiries); ?>)</h3>
    </div>
    <div class="card-body">
        <?php if (empty($enquiries)): ?>
            <p class="text-muted">No admission enquiries found.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Enquiry No.</th>
                            <th>Candidate Name</th>
                            <th>Class Applying</th>
                            <th>Parent / Guardian</th>
                            <th>Contact Phone &amp; Email</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($enquiries as $enq):
                            $cName = trim($enq['first_name'] . ' ' . ($enq['middle_name'] ? $enq['middle_name'] . ' ' : '') . $enq['last_name']);
                            $stClass = ['pending' => 'warning', 'reviewing' => 'info', 'accepted' => 'success', 'rejected' => 'danger'][$enq['status']] ?? 'info';
                        ?>
                        <tr>
                            <td><strong><?php echo e($enq['application_number']); ?></strong></td>
                            <td>
                                <strong><?php echo e($cName); ?></strong><br>
                                <small class="text-muted">DOB: <?php echo date('d M Y', strtotime($enq['date_of_birth'])); ?> (<?php echo ucfirst($enq['gender']); ?>)</small>
                            </td>
                            <td><span class="badge badge-info"><?php echo e($enq['class_applying_for']); ?></span></td>
                            <td><?php echo e($enq['parent_name']); ?></td>
                            <td>
                                <a href="tel:<?php echo e($enq['parent_phone']); ?>"><?php echo e($enq['parent_phone']); ?></a><br>
                                <small><a href="mailto:<?php echo e($enq['parent_email']); ?>"><?php echo e($enq['parent_email']); ?></a></small>
                            </td>
                            <td><?php echo date('d M Y', strtotime($enq['submitted_at'])); ?></td>
                            <td><span class="badge badge-<?php echo $stClass; ?>"><?php echo ucfirst($enq['status']); ?></span></td>
                            <td>
                                <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                    <?php if ($enq['status'] !== 'accepted'): ?>
                                    <a href="new-admission?enquiry_id=<?php echo e($enq['id']); ?>" class="btn btn-sm btn-primary" title="Convert enquiry to full enrolled student">
                                        <i class="fas fa-check-circle"></i> Admit Student
                                    </a>
                                    <?php endif; ?>
                                    <a href="applications" class="btn btn-sm btn-outline" title="Full application details">
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
