<?php
// admin/audit-logs.php - Audit Logs Viewer
require_once '../config/config.php';
require_once '../config/security.php';

Security::requireRole('admin');

$pageTitle = 'Audit Logs';
$extraCSS = ['admin.css', 'dashboard.css'];
$extraJS = ['audit-logs.js'];

include __DIR__ . '/../includes/header.php';

$db = db();
[$message, $messageType] = flash_get();

// Clear logs older than 90 days (POST + CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_old') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        flash_redirect('Invalid security token', 'error');
    }
    $db->query("DELETE FROM audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
    $deleted = $db->rowCount();
    Security::logAudit('CLEARED_AUDIT_LOGS', 'audit_logs', null, null, ['deleted' => $deleted]);
    flash_redirect("$deleted log entries older than 90 days were deleted", 'success');
}

// Pagination
$page = page_param('p');
$limit = 50;
$offset = ($page - 1) * $limit;

// Filters
$userId = (int)($_GET['user_id'] ?? 0) ?: '';
$action = Security::sanitize($_GET['action'] ?? '');
$dateFrom = valid_date($_GET['date_from'] ?? '') ?? '';
$dateTo = valid_date($_GET['date_to'] ?? '') ?? '';

// Build query
$query = "SELECT a.*, u.username, u.first_name, u.last_name, u.role
          FROM audit_logs a
          LEFT JOIN users u ON a.user_id = u.id
          WHERE 1=1";
$countQuery = "SELECT COUNT(*) as count FROM audit_logs a WHERE 1=1";
$params = [];
$countParams = [];

if ($userId) {
    $query .= " AND a.user_id = ?";
    $countQuery .= " AND a.user_id = ?";
    $params[] = $userId;
    $countParams[] = $userId;
}

if ($action) {
    $query .= " AND a.action LIKE ?";
    $countQuery .= " AND a.action LIKE ?";
    $params[] = "%$action%";
    $countParams[] = "%$action%";
}

if ($dateFrom) {
    $query .= " AND DATE(a.created_at) >= ?";
    $countQuery .= " AND DATE(a.created_at) >= ?";
    $params[] = $dateFrom;
    $countParams[] = $dateFrom;
}

if ($dateTo) {
    $query .= " AND DATE(a.created_at) <= ?";
    $countQuery .= " AND DATE(a.created_at) <= ?";
    $params[] = $dateTo;
    $countParams[] = $dateTo;
}

$query .= " ORDER BY a.created_at DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

// Get total count for pagination
$totalLogs = $db->getRow($countQuery, $countParams)['count'];
$totalPages = max(1, (int)ceil($totalLogs / $limit));

// Get logs
$logs = $db->getRows($query, $params);

// Get distinct actions for filter
$actions = $db->getRows("SELECT DISTINCT action FROM audit_logs ORDER BY action");

// Get users for filter
$users = $db->getRows(
    "SELECT DISTINCT u.id, u.username, u.first_name, u.last_name
     FROM audit_logs a
     JOIN users u ON a.user_id = u.id
     ORDER BY u.first_name"
);
?>

<div class="dashboard-container">
    <?php render_sidebar('admin'); ?>

    <main class="dashboard-main">
        <div class="dashboard-header">
            <h1>Audit Logs</h1>
            <div class="header-actions">
                <button class="btn btn-outline" onclick="exportLogs()">
                    <i class="fas fa-download"></i> Export
                </button>
                <button class="btn btn-danger" onclick="clearLogs()" title="Clear old logs">
                    <i class="fas fa-trash"></i> Clear Old
                </button>
            </div>
        </div>

        <!-- Filter Form -->
        <div class="card">
            <div class="card-header">
                <h3>Filter Logs</h3>
            </div>
            <div class="card-body">
                <form method="GET" class="form-row">
                    <div class="form-group col-md-3">
                        <label for="user_id">User</label>
                        <select id="user_id" name="user_id" class="form-control">
                            <option value="">All Users</option>
                            <?php foreach ($users as $user): ?>
                            <option value="<?php echo e($user['id']); ?>" <?php echo $userId == $user['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name'] . ' (' . $user['username'] . ')'); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group col-md-3">
                        <label for="action">Action</label>
                        <select id="action" name="action" class="form-control">
                            <option value="">All Actions</option>
                            <?php foreach ($actions as $act): ?>
                            <option value="<?php echo e($act['action']); ?>" <?php echo $action == $act['action'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($act['action']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group col-md-2">
                        <label for="date_from">From</label>
                        <input type="date" id="date_from" name="date_from" class="form-control" value="<?php echo e($dateFrom); ?>">
                    </div>

                    <div class="form-group col-md-2">
                        <label for="date_to">To</label>
                        <input type="date" id="date_to" name="date_to" class="form-control" value="<?php echo e($dateTo); ?>">
                    </div>

                    <div class="form-group col-md-2">
                        <label>&nbsp;</label>
                        <button type="submit" class="btn btn-primary form-control">Filter</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Logs Table -->
        <div class="card">
            <div class="card-header">
                <h3>Audit Trail</h3>
                <span>Total Records: <?php echo e($totalLogs); ?></span>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Date/Time</th>
                                <th>User</th>
                                <th>Role</th>
                                <th>Action</th>
                                <th>Table</th>
                                <th>Record ID</th>
                                <th>IP Address</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><?php echo date('d/m/Y H:i:s', strtotime($log['created_at'])); ?></td>
                                <td>
                                    <?php if ($log['user_id']): ?>
                                        <?php echo htmlspecialchars($log['first_name'] . ' ' . $log['last_name']); ?>
                                        <br><small><?php echo htmlspecialchars($log['username']); ?></small>
                                    <?php else: ?>
                                        <em>System</em>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $log['role'] ? ucfirst($log['role']) : '-'; ?></td>
                                <td><?php echo htmlspecialchars($log['action']); ?></td>
                                <td><?php echo htmlspecialchars($log['table_affected'] ?? '-'); ?></td>
                                <td><?php echo $log['record_id'] ?? '-'; ?></td>
                                <td><?php echo $log['ip_address'] ?? '-'; ?></td>
                                <td>
                                    <button class="btn btn-sm btn-info" onclick="viewDetails(<?php echo e($log['id']); ?>)">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>

                            <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="8" class="text-center">No audit logs found</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                    <a href="?p=<?php echo $page - 1; ?>&user_id=<?php echo e($userId); ?>&action=<?php echo e($action); ?>&date_from=<?php echo e($dateFrom); ?>&date_to=<?php echo e($dateTo); ?>" class="page-link">
                        <i class="fas fa-chevron-left"></i> Previous
                    </a>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="?p=<?php echo e($i); ?>&user_id=<?php echo e($userId); ?>&action=<?php echo e($action); ?>&date_from=<?php echo e($dateFrom); ?>&date_to=<?php echo e($dateTo); ?>"
                       class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                        <?php echo e($i); ?>
                    </a>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                    <a href="?p=<?php echo $page + 1; ?>&user_id=<?php echo e($userId); ?>&action=<?php echo e($action); ?>&date_from=<?php echo e($dateFrom); ?>&date_to=<?php echo e($dateTo); ?>" class="page-link">
                        Next <i class="fas fa-chevron-right"></i>
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<!-- Details Modal -->
<div id="detailsModal" class="modal">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h3>Audit Log Details</h3>
            <button type="button" class="close" onclick="closeModal()">&times;</button>
        </div>
        <div class="modal-body" id="detailsContent">
            <!-- Content will be loaded via AJAX -->
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal()">Close</button>
        </div>
    </div>
</div>

<form method="POST" id="clearLogsForm" style="display:none">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="clear_old">
</form>
<script nonce="<?php echo CSP_NONCE; ?>">
function viewDetails(logId) {
    fetch(`${BASE_URL}/api/get-audit-log?id=${encodeURIComponent(logId)}`, {credentials: 'same-origin'})
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                let html = '<table class="table table-bordered">';
                html += '<tr><th>Field</th><th>Value</th></tr>';
                html += `<tr><td>ID</td><td>${escapeHtml(data.log.id)}</td></tr>`;
                html += `<tr><td>Date/Time</td><td>${escapeHtml(data.log.created_at)}</td></tr>`;
                html += `<tr><td>User</td><td>${escapeHtml(data.log.user_name || 'System')}</td></tr>`;
                html += `<tr><td>Action</td><td>${escapeHtml(data.log.action)}</td></tr>`;
                html += `<tr><td>Table</td><td>${escapeHtml(data.log.table_affected || '-')}</td></tr>`;
                html += `<tr><td>Record ID</td><td>${escapeHtml(data.log.record_id || '-')}</td></tr>`;
                html += `<tr><td>IP Address</td><td>${escapeHtml(data.log.ip_address || '-')}</td></tr>`;
                html += `<tr><td>User Agent</td><td>${escapeHtml(data.log.user_agent || '-')}</td></tr>`;

                if (data.log.old_values) {
                    html += `<tr><td colspan="2"><strong>Old Values:</strong><br><pre>${escapeHtml(JSON.stringify(JSON.parse(data.log.old_values), null, 2))}</pre></td></tr>`;
                }
                if (data.log.new_values) {
                    html += `<tr><td colspan="2"><strong>New Values:</strong><br><pre>${escapeHtml(JSON.stringify(JSON.parse(data.log.new_values), null, 2))}</pre></td></tr>`;
                }

                html += '</table>';
                document.getElementById('detailsContent').innerHTML = html;
                document.getElementById('detailsModal').style.display = 'block';
            }
        });
}

function closeModal() {
    document.getElementById('detailsModal').style.display = 'none';
}

function exportLogs() {
    const params = new URLSearchParams(window.location.search);
    window.location.href = BASE_URL + '/admin/export-logs?' + params.toString();
}

function clearLogs() {
    if (confirm('Are you sure you want to clear logs older than 90 days? This action cannot be undone.')) {
        document.getElementById('clearLogsForm').submit();
    }
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('detailsModal');
    if (event.target === modal) {
        modal.style.display = 'none';
    }
}
</script>

<?php
include '../includes/footer.php';
?>