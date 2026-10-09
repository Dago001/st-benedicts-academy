<?php
// admin/slides.php - manage the home-page hero slider
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
Security::requireRole('admin');

$db = db();
[$message, $messageType] = flash_get();

$cleanUrl = function ($u) {
    $u = trim((string)$u);
    if ($u === '') return '';
    return (preg_match('~^(https?://|mailto:|tel:)~i', $u) || ($u[0] === '/' && strpos($u, '//') !== 0)) ? mb_substr($u, 0, 255) : false;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) flash_redirect('Invalid security token', 'error');
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'import') {
        if ((int)$db->getRow('SELECT COUNT(*) c FROM hero_slides')['c'] === 0) {
            foreach (cms_default_slides() as $i => $s) {
                $db->query('INSERT INTO hero_slides (subtitle,title,text,image,btn1_label,btn1_url,btn2_label,btn2_url,use_motto,grad,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                    [$s['subtitle'], $s['title'], $s['text'], null, $s['btn1_label'], $s['btn1_url'], $s['btn2_label'], $s['btn2_url'], $s['use_motto'], $s['grad'], $i + 1]);
            }
        }
        flash_redirect('Current slides copied - you can now edit them', 'success');
    }
    if ($action === 'delete') {
        $row = $db->getRow('SELECT image FROM hero_slides WHERE id = ?', [$id]);
        if ($row) { $db->query('DELETE FROM hero_slides WHERE id = ?', [$id]); cms_remove_image($row['image']); Security::logAudit('DELETED_SLIDE', 'hero_slides', $id); }
        flash_redirect('Slide deleted', 'success');
    }
    if ($action === 'save') {
        $title = trim(preg_replace('/\s+/', ' ', (string)($_POST['title'] ?? '')));
        $sub = mb_substr(trim((string)($_POST['subtitle'] ?? '')), 0, 120);
        $text = mb_substr(trim((string)($_POST['text'] ?? '')), 0, 300);
        $b1u = $cleanUrl($_POST['btn1_url'] ?? ''); $b2u = $cleanUrl($_POST['btn2_url'] ?? '');
        if ($title === '' || mb_strlen($title) > 200) flash_redirect('A slide title is required', 'error');
        if ($b1u === false || $b2u === false) flash_redirect('Button links must start with / or https://', 'error');
        [$img, $err] = cms_store_image($_FILES['image'] ?? null);
        if ($err) flash_redirect($err, 'error');
        $vals = [$sub, $title, $text, mb_substr(trim($_POST['btn1_label'] ?? ''), 0, 40), $b1u, mb_substr(trim($_POST['btn2_label'] ?? ''), 0, 40), $b2u,
                 isset($_POST['use_motto']) ? 1 : 0, max(0, min(2, (int)($_POST['grad'] ?? 0))), (int)($_POST['sort_order'] ?? 0), isset($_POST['is_active']) ? 1 : 0];
        if ($id) {
            $old = $db->getRow('SELECT image FROM hero_slides WHERE id = ?', [$id]);
            if (!$old) { cms_remove_image($img); flash_redirect('Slide not found', 'error'); }
            $sql = 'UPDATE hero_slides SET subtitle=?,title=?,text=?,btn1_label=?,btn1_url=?,btn2_label=?,btn2_url=?,use_motto=?,grad=?,sort_order=?,is_active=?';
            $p = $vals;
            if ($img) { $sql .= ',image=?'; $p[] = $img; }
            elseif (!empty($_POST['clear_image'])) { $sql .= ',image=NULL'; cms_remove_image($old['image']); }
            $db->query($sql . ' WHERE id=?', array_merge($p, [$id]));
            if ($img) cms_remove_image($old['image']);
        } else {
            $db->query('INSERT INTO hero_slides (subtitle,title,text,btn1_label,btn1_url,btn2_label,btn2_url,use_motto,grad,sort_order,is_active,image) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', array_merge($vals, [$img]));
        }
        Security::logAudit('SAVED_SLIDE', 'hero_slides', $id);
        flash_redirect('Slide saved', 'success', 'slides');
    }
}

$edit = isset($_GET['edit']) ? $db->getRow('SELECT * FROM hero_slides WHERE id = ?', [(int)$_GET['edit']]) : null;
$new = isset($_GET['new']);
$slides = $db->getRows('SELECT * FROM hero_slides ORDER BY sort_order, id');
$pageTitle = 'Home Slider';
$extraCSS = ['admin.css', 'dashboard.css'];
include __DIR__ . '/../includes/header.php';
dashboard_open('admin', 'Home Page Slider', ($edit || $new) ? '<a class="btn btn-secondary" href="slides">Back</a>' : '<a class="btn btn-primary" href="slides?new=1"><i class="fas fa-plus"></i> Add slide</a>');
render_alert($message, $messageType);
if ($edit || $new): $s = $edit ?: ['title' => '', 'subtitle' => '', 'text' => '', 'btn1_label' => 'Apply Now', 'btn1_url' => '/public/apply', 'btn2_label' => '', 'btn2_url' => '', 'use_motto' => 0, 'grad' => 0, 'sort_order' => count($slides) + 1, 'is_active' => 1, 'image' => '', 'id' => 0]; ?>
<div class="card"><div class="card-header"><h3><?php echo $edit ? 'Edit slide' : 'New slide'; ?></h3></div><div class="card-body">
<form method="POST" enctype="multipart/form-data"><?php echo csrf_field(); ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>">
    <div class="form-row">
        <div class="form-group"><label for="subtitle">Small text above the title</label><input class="form-control" id="subtitle" name="subtitle" maxlength="120" value="<?php echo e($s['subtitle']); ?>"></div>
        <div class="form-group" style="flex:2"><label for="title">Title *</label><input class="form-control" id="title" name="title" required maxlength="200" value="<?php echo e($s['title']); ?>"><small class="text-muted">Put *asterisks* around words to highlight them.</small></div>
    </div>
    <div class="form-group"><label for="text">Short text under the title</label><input class="form-control" id="text" name="text" maxlength="300" value="<?php echo e($s['text']); ?>"></div>
    <label class="checkbox-label"><input type="checkbox" name="use_motto" <?php echo $s['use_motto'] ? 'checked' : ''; ?>> Show the school motto under the title</label>
    <div class="form-row">
        <div class="form-group"><label for="btn1_label">Button 1 text</label><input class="form-control" id="btn1_label" name="btn1_label" maxlength="40" value="<?php echo e($s['btn1_label']); ?>"></div>
        <div class="form-group"><label for="btn1_url">Button 1 link</label><input class="form-control" id="btn1_url" name="btn1_url" placeholder="/public/apply or https://..." value="<?php echo e($s['btn1_url']); ?>"></div>
    </div>
    <div class="form-row">
        <div class="form-group"><label for="btn2_label">Button 2 text</label><input class="form-control" id="btn2_label" name="btn2_label" maxlength="40" value="<?php echo e($s['btn2_label']); ?>"></div>
        <div class="form-group"><label for="btn2_url">Button 2 link</label><input class="form-control" id="btn2_url" name="btn2_url" placeholder="/public/contact" value="<?php echo e($s['btn2_url']); ?>"></div>
    </div>
    <div class="form-row">
        <div class="form-group"><label for="grad">Colour (when there is no picture)</label><select class="form-control" id="grad" name="grad"><?php foreach (['Navy &rarr; Red', 'Red &rarr; Gold', 'Gold &rarr; Navy'] as $i => $l): ?><option value="<?php echo $i; ?>" <?php echo (int)$s['grad'] === $i ? 'selected' : ''; ?>><?php echo $l; ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label for="sort_order">Order</label><input type="number" class="form-control" id="sort_order" name="sort_order" value="<?php echo (int)$s['sort_order']; ?>"></div>
    </div>
    <div class="form-group"><label for="image">Background picture (optional, wide photo works best)</label>
        <?php if (!empty($s['image'])): ?><div><img src="<?php echo e(BASE_URL . '/uploads/site/' . rawurlencode($s['image'])); ?>" alt="" style="max-width:240px;border-radius:8px"></div><label class="checkbox-label"><input type="checkbox" name="clear_image" value="1"> Remove picture</label><?php endif; ?>
        <input type="file" class="form-control" id="image" name="image" accept="image/*"></div>
    <label class="checkbox-label"><input type="checkbox" name="is_active" <?php echo $s['is_active'] ? 'checked' : ''; ?>> Show this slide</label>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> Save slide</button></div>
</form></div></div>
<?php else: ?>
<?php if (!$slides): ?>
<div class="card"><div class="card-body"><p>The home page is currently showing the three built-in slides. To change them, copy them here first, or add your own.</p>
<form method="POST"><?php echo csrf_field(); ?><input type="hidden" name="action" value="import"><button class="btn btn-primary" type="submit"><i class="fas fa-copy"></i> Copy current slides so I can edit them</button></form></div></div>
<?php else: ?>
<div class="card"><div class="card-body"><div class="table-responsive"><table class="table"><thead><tr><th>#</th><th>Title</th><th>Buttons</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($slides as $sl): ?><tr>
    <td><?php echo (int)$sl['sort_order']; ?></td><td><?php echo e(str_replace('*', '', $sl['title'])); ?></td>
    <td><?php echo e(trim($sl['btn1_label'] . ' / ' . $sl['btn2_label'], ' /')); ?></td><td><?php echo $sl['is_active'] ? 'Shown' : 'Hidden'; ?></td>
    <td class="action-buttons"><a class="btn-icon" href="slides?edit=<?php echo (int)$sl['id']; ?>" title="Edit"><i class="fas fa-edit"></i></a>
    <form method="POST" style="display:inline" onsubmit="return confirm('Delete this slide?')"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$sl['id']; ?>"><button class="btn-icon text-danger" type="submit" title="Delete"><i class="fas fa-trash"></i></button></form></td></tr>
<?php endforeach; ?></tbody></table></div></div></div>
<?php endif; endif; dashboard_close(); include __DIR__ . '/../includes/footer.php';
