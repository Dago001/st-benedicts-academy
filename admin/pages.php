<?php
// admin/pages.php - create extra website pages (e.g. Fees, Calendar, Careers, PTA) without code
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
Security::requireRole('admin');

$db = db();
[$message, $messageType] = flash_get();
$reserved = ['index', 'about', 'admissions', 'admission', 'academics', 'news', 'gallery', 'contact', 'apply', 'privacy', 'page', 'login'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) flash_redirect('Invalid security token', 'error');
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if ($action === 'delete') {
        $row = $db->getRow('SELECT image FROM site_pages WHERE id = ?', [$id]);
        if ($row) { $db->query('DELETE FROM site_pages WHERE id = ?', [$id]); cms_remove_image($row['image']); Security::logAudit('DELETED_PAGE', 'site_pages', $id); }
        flash_redirect('Page deleted', 'success');
    }
    if ($action === 'save') {
        $title = trim(preg_replace('/\s+/', ' ', (string)($_POST['title'] ?? '')));
        $content = trim(str_replace("\0", '', (string)($_POST['content'] ?? '')));
        $slug = cms_slugify($_POST['slug'] !== '' ? $_POST['slug'] : $title);
        if ($title === '' || mb_strlen($title) > 150 || $content === '') flash_redirect('A title and content are required', 'error');
        if (in_array($slug, $reserved, true)) flash_redirect('That address is reserved - choose another title or address', 'error');
        $dup = $db->getRow('SELECT id FROM site_pages WHERE slug = ? AND id <> ?', [$slug, $id]);
        if ($dup) flash_redirect('Another page already uses that address', 'error');
        [$img, $err] = cms_store_image($_FILES['image'] ?? null);
        if ($err) flash_redirect($err, 'error');
        $f = [$slug, $title, mb_substr(trim($_POST['summary'] ?? ''), 0, 255), $content, isset($_POST['show_in_menu']) ? 1 : 0, (int)($_POST['menu_order'] ?? 100), isset($_POST['is_published']) ? 1 : 0];
        if ($id) {
            $old = $db->getRow('SELECT image FROM site_pages WHERE id = ?', [$id]);
            if (!$old) { cms_remove_image($img); flash_redirect('Page not found', 'error'); }
            $sql = 'UPDATE site_pages SET slug=?,title=?,summary=?,content=?,show_in_menu=?,menu_order=?,is_published=?';
            $p = $f;
            if ($img) { $sql .= ',image=?'; $p[] = $img; }
            elseif (!empty($_POST['clear_image'])) { $sql .= ',image=NULL'; cms_remove_image($old['image']); }
            $db->query($sql . ' WHERE id=?', array_merge($p, [$id]));
            if ($img) cms_remove_image($old['image']);
        } else {
            $id = $db->insert('INSERT INTO site_pages (slug,title,summary,content,show_in_menu,menu_order,is_published,image,created_by) VALUES (?,?,?,?,?,?,?,?,?)', array_merge($f, [$img, $_SESSION['user_id']]));
        }
        Security::logAudit('SAVED_PAGE', 'site_pages', $id);
        flash_redirect('Page saved', 'success', 'pages');
    }
}

$edit = isset($_GET['edit']) ? $db->getRow('SELECT * FROM site_pages WHERE id = ?', [(int)$_GET['edit']]) : null;
$new = isset($_GET['new']);
$pages = $db->getRows('SELECT id, slug, title, show_in_menu, is_published, updated_at FROM site_pages ORDER BY menu_order, title');
$pageTitle = 'Extra Pages';
$extraCSS = ['admin.css', 'dashboard.css'];
include __DIR__ . '/../includes/header.php';
dashboard_open('admin', 'Extra Website Pages', ($edit || $new) ? '<a class="btn btn-secondary" href="pages">Back</a>' : '<a class="btn btn-primary" href="pages?new=1"><i class="fas fa-plus"></i> New page</a>');
render_alert($message, $messageType);
if ($edit || $new): $p = $edit ?: ['id' => 0, 'title' => '', 'slug' => '', 'summary' => '', 'content' => '', 'show_in_menu' => 1, 'menu_order' => 100, 'is_published' => 1, 'image' => '']; ?>
<div class="card"><div class="card-header"><h3><?php echo $edit ? 'Edit page' : 'New page'; ?></h3></div><div class="card-body">
<form method="POST" enctype="multipart/form-data"><?php echo csrf_field(); ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
    <div class="form-row">
        <div class="form-group" style="flex:2"><label for="title">Page title *</label><input class="form-control" id="title" name="title" required maxlength="150" value="<?php echo e($p['title']); ?>"></div>
        <div class="form-group"><label for="slug">Web address</label><input class="form-control" id="slug" name="slug" maxlength="80" placeholder="auto from title" value="<?php echo e($p['slug']); ?>"><small class="text-muted">/public/page?slug=<strong>this</strong></small></div>
    </div>
    <div class="form-group"><label for="summary">Short description (search engines &amp; link previews)</label><input class="form-control" id="summary" name="summary" maxlength="255" value="<?php echo e($p['summary']); ?>"></div>
    <div class="form-group"><label for="content">Content *</label>
        <textarea class="form-control" id="content" name="content" rows="14" required><?php echo e($p['content']); ?></textarea>
        <small class="text-muted">Leave a blank line between paragraphs. <code>## Heading</code> for a heading, lines starting with <code>- </code> make a list, <code>**bold**</code>, and <code>[link text](https://example.com)</code> or <code>[Apply](/public/apply)</code> for links.</small></div>
    <div class="form-group"><label for="image">Banner picture (optional)</label>
        <?php if (!empty($p['image'])): ?><div><img src="<?php echo e(BASE_URL . '/uploads/site/' . rawurlencode($p['image'])); ?>" alt="" style="max-width:240px;border-radius:8px"></div><label class="checkbox-label"><input type="checkbox" name="clear_image" value="1"> Remove picture</label><?php endif; ?>
        <input type="file" class="form-control" id="image" name="image" accept="image/*"></div>
    <div class="form-row"><div class="form-group"><label for="menu_order">Menu position (lower = earlier)</label><input type="number" class="form-control" id="menu_order" name="menu_order" value="<?php echo (int)$p['menu_order']; ?>"></div></div>
    <label class="checkbox-label"><input type="checkbox" name="show_in_menu" <?php echo $p['show_in_menu'] ? 'checked' : ''; ?>> Show in the website menu</label>
    <label class="checkbox-label"><input type="checkbox" name="is_published" <?php echo $p['is_published'] ? 'checked' : ''; ?>> Published (untick to keep as a draft)</label>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> Save page</button></div>
</form></div></div>
<?php else: ?>
<div class="card"><div class="card-body"><div class="table-responsive"><table class="table"><thead><tr><th>Title</th><th>Address</th><th>Menu</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($pages as $pg): ?><tr>
    <td><?php echo e($pg['title']); ?></td><td><code>page?slug=<?php echo e($pg['slug']); ?></code></td><td><?php echo $pg['show_in_menu'] ? 'Yes' : 'No'; ?></td><td><?php echo $pg['is_published'] ? 'Published' : 'Draft'; ?></td>
    <td class="action-buttons"><a class="btn-icon" href="pages?edit=<?php echo (int)$pg['id']; ?>" title="Edit"><i class="fas fa-edit"></i></a>
    <a class="btn-icon" target="_blank" rel="noopener" href="<?php echo e(BASE_URL); ?>/public/page?slug=<?php echo e(rawurlencode($pg['slug'])); ?>" title="View"><i class="fas fa-eye"></i></a>
    <form method="POST" style="display:inline" onsubmit="return confirm('Delete this page?')"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$pg['id']; ?>"><button class="btn-icon text-danger" type="submit" title="Delete"><i class="fas fa-trash"></i></button></form></td></tr>
<?php endforeach; if (!$pages): ?><tr><td colspan="5" class="text-center text-muted">No extra pages yet. Use "New page" to add things like Fees, School Calendar or Careers.</td></tr><?php endif; ?>
</tbody></table></div></div></div>
<?php endif; dashboard_close(); include __DIR__ . '/../includes/footer.php';
