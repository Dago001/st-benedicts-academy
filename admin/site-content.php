<?php
// admin/site-content.php - edit the wording and images of the public pages without touching code
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
Security::requireRole('admin');

$db = db();
$registry = cms_registry();
$group = $_GET['group'] ?? $_POST['group'] ?? 'home';
if (!isset($registry[$group])) $group = 'home';
[$message, $messageType] = flash_get();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) flash_redirect('Invalid security token', 'error');
    $current = cms_all(true);
    $fields = $registry[$group][2];
    $action = $_POST['action'] ?? 'save';
    $changed = 0;
    foreach ($fields as $k => [$label, $type, $default]) {
        $full = "$group.$k";
        $old = $current[$full] ?? '';
        $new = $old;
        if ($action === 'reset') {
            $new = '';
        } elseif ($type === 'image') {
            if (!empty($_POST['clear_' . $k])) $new = '';
            [$name, $err] = cms_store_image($_FILES['img_' . $k] ?? null);
            if ($err) flash_redirect($label . ': ' . $err, 'error', "site-content?group=$group");
            if ($name) $new = $name;
        } else {
            $new = trim(str_replace("\0", '', (string)($_POST['f'][$k] ?? '')));
            if (mb_strlen($new) > 4000) flash_redirect($label . ' is too long', 'error', "site-content?group=$group");
            if ($type === 'text') $new = preg_replace('/\s+/', ' ', $new);
            if (in_array($k, ['facebook', 'instagram', 'twitter', 'youtube'], true) && $new !== '' && !preg_match('~^https?://~i', $new)) {
                flash_redirect($label . ' must start with https://', 'error', "site-content?group=$group");
            }
            if ($k === 'email' && $new !== '' && !Security::validateEmail($new)) flash_redirect('Please enter a valid email address', 'error', "site-content?group=$group");
            if ($new === $default) $new = '';   // identical to the built-in text: keep tracking the default
        }
        if ($new === $old) continue;
        if ($new === '') {
            $db->query('DELETE FROM site_content WHERE content_key = ?', [$full]);
        } else {
            $db->query('INSERT INTO site_content (content_key, content_value, updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE content_value = VALUES(content_value), updated_by = VALUES(updated_by)', [$full, $new, $_SESSION['user_id']]);
        }
        if ($type === 'image' && $old !== '' && $old !== $new) cms_remove_image($old);
        $changed++;
    }
    Security::logAudit($action === 'reset' ? 'RESET_SITE_CONTENT' : 'UPDATED_SITE_CONTENT', 'site_content', 0);
    flash_redirect($action === 'reset' ? 'Restored the original wording for this section' : ($changed ? "Saved $changed change(s)" : 'Nothing changed'), 'success', "site-content?group=$group");
}

$vals = cms_all(true);
$pageTitle = 'Site Content';
$extraCSS = ['admin.css', 'dashboard.css'];
include __DIR__ . '/../includes/header.php';
dashboard_open('admin', 'Website Content', '<a class="btn btn-outline" href="' . e(BASE_URL) . '/" target="_blank" rel="noopener"><i class="fas fa-external-link-alt"></i> View site</a>');
render_alert($message, $messageType);
?>
<p class="text-muted">Change the text and pictures on the public website. Leave a box unchanged to keep the current wording. Also manage: <a href="slides">Home slider</a> &middot; <a href="pages">Extra pages</a> &middot; <a href="news">News &amp; Events</a> &middot; <a href="gallery">Gallery</a>.</p>
<div class="tabs" style="display:flex;flex-wrap:wrap;gap:8px;margin:0 0 16px">
<?php foreach ($registry as $g => [$gLabel, $gIcon]): ?>
    <a class="btn <?php echo $g === $group ? 'btn-primary' : 'btn-outline'; ?>" href="site-content?group=<?php echo e($g); ?>"><i class="fas <?php echo e($gIcon); ?>"></i> <?php echo e($gLabel); ?></a>
<?php endforeach; ?>
</div>
<form method="POST" enctype="multipart/form-data" class="card"><div class="card-header"><h3><?php echo e($registry[$group][0]); ?></h3></div><div class="card-body">
    <?php echo csrf_field(); ?><input type="hidden" name="group" value="<?php echo e($group); ?>"><input type="hidden" name="action" value="save">
    <?php foreach ($registry[$group][2] as $k => [$label, $type, $default, $help]):
        $val = $vals["$group.$k"] ?? $default; $id = 'f_' . $k; ?>
    <div class="form-group">
        <label for="<?php echo e($id); ?>"><?php echo e($label); ?></label>
        <?php if ($type === 'textarea'): ?>
            <textarea class="form-control" id="<?php echo e($id); ?>" name="f[<?php echo e($k); ?>]" rows="<?php echo mb_strlen($val) > 160 || substr_count($val, "\n") > 1 ? 5 : 3; ?>"><?php echo e($val); ?></textarea>
        <?php elseif ($type === 'image'): $cur = cms_img("$group.$k", ''); ?>
            <?php if ($cur): ?><div style="margin-bottom:8px"><img src="<?php echo e($cur); ?>" alt="" style="max-width:220px;max-height:140px;border-radius:8px"></div>
            <label class="checkbox-label"><input type="checkbox" name="clear_<?php echo e($k); ?>" value="1"> Remove this image (go back to the original)</label><?php endif; ?>
            <input type="file" class="form-control" id="<?php echo e($id); ?>" name="img_<?php echo e($k); ?>" accept="image/*">
        <?php else: ?>
            <input class="form-control" id="<?php echo e($id); ?>" name="f[<?php echo e($k); ?>]" value="<?php echo e($val); ?>">
        <?php endif; ?>
        <?php if ($help): ?><small class="form-text text-muted"><?php echo e($help); ?></small><?php endif; ?>
    </div>
    <?php endforeach; ?>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> Save changes</button></div>
</div></form>
<form method="POST" onsubmit="return confirm('Restore the original wording and images for this whole section?')"><?php echo csrf_field(); ?><input type="hidden" name="group" value="<?php echo e($group); ?>"><input type="hidden" name="action" value="reset">
    <button class="btn btn-secondary" type="submit"><i class="fas fa-undo"></i> Restore original wording</button></form>
<?php dashboard_close(); include __DIR__ . '/../includes/footer.php';
