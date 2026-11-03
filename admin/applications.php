<?php
// admin/applications.php - review admission applications (full form + quick enquiries)
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/ApplicationHelper.php';
Security::requireRole('admin');

ApplicationHelper::ensureSchema();

$db = db();
$statuses = ['pending', 'reviewing', 'accepted', 'rejected'];

// Secure document download: ?file=app|enq&id=N&doc=birth|photo|...
if (isset($_GET['file'])) {
    $id = (int)($_GET['id'] ?? 0);
    $path = null;
    if ($_GET['file'] === 'app') {
        $row = $db->getRow('SELECT birth_certificate_path, passport_photo_path, documents_path FROM applications WHERE id = ?', [$id]);
        $docKey = $_GET['doc'] ?? '';
        $docs = ($row && !empty($row['documents_path'])) ? (json_decode($row['documents_path'], true) ?: []) : [];
        if (isset($docs[$docKey])) {
            $name = $docs[$docKey];
        } elseif ($docKey === 'photo' || $docKey === 'passport_photo') {
            $name = $row['passport_photo_path'] ?? null;
        } else {
            $name = $row['birth_certificate_path'] ?? null;
        }
        $path = $name ? PRIVATE_PATH . 'applications/' . basename($name) : null;
    } elseif ($_GET['file'] === 'enq') {
        $row = $db->getRow('SELECT documents_path FROM admissions WHERE id = ?', [$id]);
        $docs = $row && $row['documents_path'] ? (json_decode($row['documents_path'], true) ?: []) : [];
        $key = $_GET['doc'] ?? '';
        $path = isset($docs[$key]) ? PRIVATE_PATH . 'applications/' . basename($docs[$key]) : null;
    }
    if (!$path || !is_file($path)) {
        http_response_code(404);
        exit('File not found');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . basename($path) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize($path));
    Security::logAudit('VIEWED_APPLICATION_DOCUMENT', $_GET['file'] === 'app' ? 'applications' : 'admissions', $id);
    readfile($path);
    exit;
}

[$message, $messageType] = flash_get();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $kind = $_POST['kind'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? '';
    $remarks = mb_substr(Security::sanitize($_POST['remarks'] ?? ''), 0, 1000);
    $table = ['app' => 'applications', 'enq' => 'admissions'][$kind] ?? null;
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        flash_redirect('Invalid security token', 'error');
    }
    if (!$table || !$id || !in_array($status, $statuses, true)) {
        flash_redirect('Invalid request', 'error');
    }
    $db->query("UPDATE $table SET status = ?, remarks = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?", [$status, $remarks, $_SESSION['user_id'], $id]);
    Security::logAudit('REVIEWED_APPLICATION', $table, $id, null, ['status' => $status]);
    flash_redirect('Application updated', 'success');
}

$filter = in_array($_GET['status'] ?? '', $statuses, true) ? $_GET['status'] : '';
$where = $filter ? 'WHERE status = ?' : '';
$params = $filter ? [$filter] : [];
$apps = $db->getRows("SELECT id, application_number, CONCAT(child_first_name, ' ', child_last_name) AS child, child_dob, child_gender, class_applying AS class_name,
        CONCAT(parent_first_name, ' ', parent_last_name) AS parent_name, parent_email, parent_phone, address, city, state, lga, country, previous_school,
        reason_applying, birth_certificate_path, passport_photo_path, documents_path,
        receipt_number, payment_reference, amount_paid, payment_status, status, remarks, created_at
        FROM applications $where ORDER BY created_at DESC LIMIT 200", $params);
$enquiries = $db->getRows("SELECT id, application_number, CONCAT(first_name, ' ', last_name) AS child, date_of_birth AS child_dob, gender AS child_gender,
        class_applying_for AS class_name, parent_name, parent_email, parent_phone, address, city, state, lga, country, previous_school, documents_path,
        receipt_number, payment_reference, amount_paid, payment_status, status, remarks, submitted_at AS created_at
        FROM admissions $where ORDER BY submitted_at DESC LIMIT 200", $params);

$pageTitle = 'Admission Applications';
$extraCSS = ['admin.css', 'dashboard.css'];
include __DIR__ . '/../includes/header.php';
dashboard_open('admin', 'Admission Applications');
render_alert($message, $messageType);
?>
<div class="card"><div class="card-body form-inline">
    <strong>Show:</strong>
    <a class="btn btn-sm <?php echo $filter === '' ? 'btn-primary' : 'btn-outline'; ?>" href="applications">All</a>
    <?php foreach ($statuses as $s): ?><a class="btn btn-sm <?php echo $filter === $s ? 'btn-primary' : 'btn-outline'; ?>" href="applications?status=<?php echo e($s); ?>"><?php echo e(ucfirst($s)); ?></a><?php endforeach; ?>
</div></div>
<?php
$sections = [['Online applications', 'app', $apps], ['Admission enquiries & portal applications', 'enq', $enquiries]];
foreach ($sections as [$title, $kind, $rows]): ?>
<div class="card"><div class="card-header"><h3><?php echo e($title); ?> (<?php echo count($rows); ?>)</h3></div><div class="card-body">
<?php if (!$rows): ?><p class="text-muted">Nothing here.</p><?php endif; ?>
<?php foreach ($rows as $a):
    $payStatus = strtolower($a['payment_status'] ?? 'paid');
    $amtPaid = (float)($a['amount_paid'] ?? ApplicationHelper::APPLICATION_FEE);
?>
    <details class="app-item">
        <summary>
            <strong><?php echo e($a['child']); ?></strong> &middot; <?php echo e($a['class_name']); ?>
            <span class="badge badge-<?php echo ['pending' => 'warning', 'reviewing' => 'info', 'accepted' => 'success', 'rejected' => 'danger'][$a['status']] ?? 'info'; ?>"><?php echo e(ucfirst($a['status'])); ?></span>
            <span class="badge badge-<?php echo $payStatus === 'paid' ? 'success' : 'warning'; ?>"><?php echo $payStatus === 'paid' ? 'Fee Paid (₦' . number_format($amtPaid) . ')' : 'Fee Pending'; ?></span>
            <small class="text-muted"><?php echo e($a['application_number']); ?> &middot; <?php echo e(formatDate($a['created_at'], 'd M Y')); ?></small>
        </summary>
        <div class="detail-grid">
            <?php foreach ([
                'Date of birth'     => formatDate($a['child_dob'], 'd M Y'),
                'Gender'            => ucfirst((string)$a['child_gender']),
                'Parent'            => $a['parent_name'],
                'Email'             => $a['parent_email'],
                'Phone'             => $a['parent_phone'],
                'Address'           => trim($a['address'] . ' ' . ($a['city'] ?? '')),
                'Location'          => location_label($a['country'] ?? '', $a['state'] ?? '', $a['lga'] ?? ''),
                'Previous school'   => $a['previous_school'],
                'Receipt Number'    => $a['receipt_number'] ?? '-',
                'Payment Reference' => $a['payment_reference'] ?? '-',
                'Amount Paid'       => '₦' . number_format($amtPaid, 2),
                'Reason'            => $a['reason_applying'] ?? ''
            ] as $l => $v): ?>
            <div class="detail-row"><span class="detail-label"><?php echo e($l); ?></span><span class="detail-value"><?php echo e($v !== '' && $v !== null ? $v : '-'); ?></span></div>
            <?php endforeach; ?>
        </div>
        <p style="display:flex; flex-wrap:wrap; gap:8px; margin-top:12px;">
            <?php
            $docs = !empty($a['documents_path']) ? (json_decode($a['documents_path'], true) ?: []) : [];
            if ($kind === 'app') {
                if (empty($docs['birth_certificate']) && !empty($a['birth_certificate_path'])) {
                    $docs['birth_certificate'] = $a['birth_certificate_path'];
                }
                if (empty($docs['passport_photo']) && !empty($a['passport_photo_path'])) {
                    $docs['passport_photo'] = $a['passport_photo_path'];
                }
            }
            foreach ($docs as $key => $file):
                if (!$file) continue;
            ?>
                <a class="btn btn-sm btn-outline" target="_blank" rel="noopener" href="?file=<?php echo e($kind); ?>&id=<?php echo (int)$a['id']; ?>&doc=<?php echo urlencode($key); ?>">
                    <i class="fas fa-file-alt"></i> <?php echo e(ucwords(str_replace('_', ' ', $key))); ?>
                </a>
            <?php endforeach; ?>
            <a class="btn btn-sm btn-primary" target="_blank" rel="noopener" href="<?php echo BASE_URL; ?>/public/application-receipt?app=<?php echo urlencode($a['application_number']); ?>">
                <i class="fas fa-print"></i> View / Print Receipt
            </a>
            <a class="btn btn-sm btn-outline" href="mailto:<?php echo e($a['parent_email']); ?>"><i class="fas fa-envelope"></i> Email parent</a>
        </p>
        <form method="POST" class="form-row">
            <?php echo csrf_field(); ?><input type="hidden" name="kind" value="<?php echo e($kind); ?>"><input type="hidden" name="id" value="<?php echo (int)$a['id']; ?>">
            <div class="form-group"><label>Status</label><select name="status" class="form-control"><?php foreach ($statuses as $s): ?><option value="<?php echo e($s); ?>" <?php echo $a['status'] === $s ? 'selected' : ''; ?>><?php echo e(ucfirst($s)); ?></option><?php endforeach; ?></select></div>
            <div class="form-group" style="flex:2"><label>Remarks</label><input type="text" name="remarks" class="form-control" maxlength="1000" value="<?php echo e($a['remarks']); ?>"></div>
            <div class="form-group form-actions"><button type="submit" class="btn btn-primary">Save</button></div>
        </form>
    </details>
<?php endforeach; ?>
</div></div>
<?php endforeach;
dashboard_close();
include __DIR__ . '/../includes/footer.php';
