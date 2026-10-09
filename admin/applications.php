<?php
// admin/applications.php - review admission applications (full form + quick enquiries)
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
Security::requireRole('admin');

$db = db();
$statuses = ['pending', 'reviewing', 'accepted', 'rejected'];

// Secure document download: ?file=app|enq&id=N&doc=birth|photo|...
if (isset($_GET['file'])) {
    $id = (int)($_GET['id'] ?? 0);
    $path = null;
    if ($_GET['file'] === 'app') {
        $row = $db->getRow('SELECT birth_certificate_path, passport_photo_path FROM applications WHERE id = ?', [$id]);
        $name = $row ? (($_GET['doc'] ?? '') === 'photo' ? $row['passport_photo_path'] : $row['birth_certificate_path']) : null;
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
        CONCAT(parent_first_name, ' ', parent_last_name) AS parent_name, parent_email, parent_phone, address, city, state, previous_school,
        reason_applying, birth_certificate_path, passport_photo_path, status, remarks, created_at
        FROM applications $where ORDER BY created_at DESC LIMIT 200", $params);
$enquiries = $db->getRows("SELECT id, application_number, CONCAT(first_name, ' ', last_name) AS child, date_of_birth AS child_dob, gender AS child_gender,
        class_applying_for AS class_name, parent_name, parent_email, parent_phone, address, previous_school, documents_path, status, remarks, submitted_at AS created_at
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
$sections = [['Online applications', 'app', $apps], ['Admission enquiries', 'enq', $enquiries]];
foreach ($sections as [$title, $kind, $rows]): ?>
<div class="card"><div class="card-header"><h3><?php echo e($title); ?> (<?php echo count($rows); ?>)</h3></div><div class="card-body">
<?php if (!$rows): ?><p class="text-muted">Nothing here.</p><?php endif; ?>
<?php foreach ($rows as $a): ?>
    <details class="app-item">
        <summary>
            <strong><?php echo e($a['child']); ?></strong> &middot; <?php echo e($a['class_name']); ?>
            <span class="badge badge-<?php echo ['pending' => 'warning', 'reviewing' => 'info', 'accepted' => 'success', 'rejected' => 'danger'][$a['status']]; ?>"><?php echo e(ucfirst($a['status'])); ?></span>
            <small class="text-muted"><?php echo e($a['application_number']); ?> &middot; <?php echo e(formatDate($a['created_at'], 'd M Y')); ?></small>
        </summary>
        <div class="detail-grid">
            <?php foreach (['Date of birth' => formatDate($a['child_dob'], 'd M Y'), 'Gender' => ucfirst((string)$a['child_gender']), 'Parent' => $a['parent_name'],
                'Email' => $a['parent_email'], 'Phone' => $a['parent_phone'], 'Address' => trim($a['address'] . ' ' . ($a['city'] ?? '') . ' ' . ($a['state'] ?? '')),
                'Previous school' => $a['previous_school'], 'Reason' => $a['reason_applying'] ?? ''] as $l => $v): ?>
            <div class="detail-row"><span class="detail-label"><?php echo e($l); ?></span><span class="detail-value"><?php echo e($v !== '' && $v !== null ? $v : '-'); ?></span></div>
            <?php endforeach; ?>
        </div>
        <p>
            <?php if ($kind === 'app'): ?>
                <?php if ($a['birth_certificate_path']): ?><a class="btn btn-sm btn-outline" target="_blank" rel="noopener" href="?file=app&id=<?php echo (int)$a['id']; ?>&doc=birth"><i class="fas fa-file"></i> Birth certificate</a><?php endif; ?>
                <?php if ($a['passport_photo_path']): ?><a class="btn btn-sm btn-outline" target="_blank" rel="noopener" href="?file=app&id=<?php echo (int)$a['id']; ?>&doc=photo"><i class="fas fa-image"></i> Passport photo</a><?php endif; ?>
            <?php else:
                $docs = $a['documents_path'] ? (json_decode($a['documents_path'], true) ?: []) : [];
                foreach ($docs as $key => $file): ?><a class="btn btn-sm btn-outline" target="_blank" rel="noopener" href="?file=enq&id=<?php echo (int)$a['id']; ?>&doc=<?php echo urlencode($key); ?>"><i class="fas fa-file"></i> <?php echo e(ucwords(str_replace('_', ' ', $key))); ?></a><?php endforeach;
            endif; ?>
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
