<?php
// admin/news.php - publish news stories and events shown on the public site
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
Security::requireRole('admin');

$db = db();
$newsDir = UPLOAD_PATH . 'news/';
if (!is_dir($newsDir)) { @mkdir($newsDir, 0755, true); }
[$message, $messageType] = flash_get();
$removeImage = function ($name) use ($newsDir) {
    if ($name && basename($name) === $name && is_file($newsDir . $name)) { @unlink($newsDir . $name); }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        flash_redirect('Invalid security token', 'error');
    }
    if ($action === 'delete') {
        $row = $db->getRow('SELECT image_path FROM news_events WHERE id = ?', [$id]);
        if ($row) {
            $db->query('DELETE FROM news_events WHERE id = ?', [$id]);
            $removeImage($row['image_path']);
            Security::logAudit('DELETED_NEWS', 'news_events', $id);
            flash_redirect('Deleted', 'success');
        }
        flash_redirect('Item not found', 'error');
    }
    if ($action === 'save') {
        $title = Security::sanitize($_POST['title'] ?? '');
        $content = trim(str_replace("\0", '', (string)($_POST['content'] ?? '')));
        $type = $_POST['type'] ?? 'news';
        $eventDate = valid_date($_POST['event_date'] ?? '');
        $eventTime = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $_POST['event_time'] ?? '') ? $_POST['event_time'] . ':00' : null;
        $venue = mb_substr(Security::sanitize($_POST['venue'] ?? ''), 0, 255);
        $featured = isset($_POST['is_featured']) ? 1 : 0;
        $published = isset($_POST['is_published']) ? 1 : 0;
        if ($title === '' || mb_strlen($title) > 200 || $content === '' || !in_array($type, ['news', 'event'], true)) {
            flash_redirect('A title and content are required', 'error');
        }
        if ($type === 'event' && !$eventDate) {
            flash_redirect('Events need a valid date', 'error');
        }
        $image = null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $up = Security::validateFileUpload($_FILES['image'], ['jpg', 'jpeg', 'png', 'gif']);
            if (!$up['valid']) { flash_redirect('Image rejected: ' . $up['message'], 'error'); }
            $image = 'news_' . bin2hex(random_bytes(10)) . '.' . $up['extension'];
            if (!move_uploaded_file($_FILES['image']['tmp_name'], $newsDir . $image)) { flash_redirect('Could not store the image', 'error'); }
        }
        if ($id) {
            $old = $db->getRow('SELECT image_path FROM news_events WHERE id = ?', [$id]);
            if (!$old) { $removeImage($image); flash_redirect('Item not found', 'error'); }
            $sql = 'UPDATE news_events SET title=?, content=?, type=?, event_date=?, event_time=?, venue=?, is_featured=?, is_published=?';
            $params = [$title, $content, $type, $eventDate, $eventTime, $venue ?: null, $featured, $published];
            if ($image) { $sql .= ', image_path=?'; $params[] = $image; }
            $db->query($sql . ' WHERE id=?', array_merge($params, [$id]));
            if ($image) { $removeImage($old['image_path']); }
            Security::logAudit('UPDATED_NEWS', 'news_events', $id);
        } else {
            $id = $db->insert('INSERT INTO news_events (title, content, type, event_date, event_time, venue, image_path, is_featured, is_published, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$title, $content, $type, $eventDate, $eventTime, $venue ?: null, $image, $featured, $published, $_SESSION['user_id']]);
            Security::logAudit('ADDED_NEWS', 'news_events', $id);
        }
        flash_redirect('Saved', 'success');
    }
}

$edit = isset($_GET['edit']) ? $db->getRow('SELECT * FROM news_events WHERE id = ?', [(int)$_GET['edit']]) : null;
$items = $db->getRows('SELECT id, title, type, event_date, is_published, is_featured, created_at FROM news_events ORDER BY created_at DESC LIMIT 100');

$pageTitle = 'News & Events';
$extraCSS = ['admin.css', 'dashboard.css'];
include __DIR__ . '/../includes/header.php';
dashboard_open('admin', 'News & Events', $edit ? '<a class="btn btn-secondary" href="news.php">Cancel edit</a>' : '');
render_alert($message, $messageType);
?>
<div class="card"><div class="card-header"><h3><?php echo $edit ? 'Edit item' : 'Add news or event'; ?></h3></div><div class="card-body">
<form method="POST" enctype="multipart/form-data">
    <?php echo csrf_field(); ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?php echo (int)($edit['id'] ?? 0); ?>">
    <div class="form-row">
        <div class="form-group" style="flex:2"><label for="title">Title *</label><input class="form-control" id="title" name="title" required maxlength="200" value="<?php echo e($edit['title'] ?? ''); ?>"></div>
        <div class="form-group"><label for="type">Type</label><select class="form-control" id="type" name="type"><?php foreach (['news' => 'News', 'event' => 'Event'] as $k => $l): ?><option value="<?php echo $k; ?>" <?php echo ($edit['type'] ?? 'news') === $k ? 'selected' : ''; ?>><?php echo $l; ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="form-row">
        <div class="form-group"><label for="event_date">Event date</label><input type="date" class="form-control" id="event_date" name="event_date" value="<?php echo e($edit['event_date'] ?? ''); ?>"></div>
        <div class="form-group"><label for="event_time">Time</label><input type="time" class="form-control" id="event_time" name="event_time" value="<?php echo e(isset($edit['event_time']) ? substr($edit['event_time'], 0, 5) : ''); ?>"></div>
        <div class="form-group"><label for="venue">Venue</label><input class="form-control" id="venue" name="venue" maxlength="255" value="<?php echo e($edit['venue'] ?? ''); ?>"></div>
    </div>
    <div class="form-group"><label for="content">Content *</label><textarea class="form-control" id="content" name="content" rows="6" required><?php echo e($edit['content'] ?? ''); ?></textarea></div>
    <div class="form-group"><label for="image">Image (JPG, PNG, GIF - max 5MB)</label><input type="file" class="form-control" id="image" name="image" accept="image/*"></div>
    <label class="checkbox-label"><input type="checkbox" name="is_published" <?php echo !$edit || $edit['is_published'] ? 'checked' : ''; ?>> Published</label>
    <label class="checkbox-label"><input type="checkbox" name="is_featured" <?php echo !empty($edit['is_featured']) ? 'checked' : ''; ?>> Featured</label>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> Save</button></div>
</form></div></div>

<div class="card"><div class="card-header"><h3>All items (<?php echo count($items); ?>)</h3></div><div class="card-body"><div class="table-responsive"><table class="table"><thead><tr><th>Title</th><th>Type</th><th>Date</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($items as $it): ?><tr>
    <td><?php echo e($it['title']); ?><?php echo $it['is_featured'] ? ' <i class="fas fa-star" title="Featured"></i>' : ''; ?></td><td><?php echo e(ucfirst($it['type'])); ?></td>
    <td><?php echo e(formatDate($it['type'] === 'event' ? $it['event_date'] : $it['created_at'], 'd M Y')); ?></td>
    <td><?php echo $it['is_published'] ? 'Published' : 'Draft'; ?></td>
    <td class="action-buttons"><a class="btn-icon" href="?edit=<?php echo (int)$it['id']; ?>" title="Edit"><i class="fas fa-edit"></i></a>
        <a class="btn-icon" href="<?php echo e(BASE_URL); ?>/public/news-detail.php?id=<?php echo (int)$it['id']; ?>" target="_blank" rel="noopener" title="View"><i class="fas fa-eye"></i></a>
        <form method="POST" style="display:inline" onsubmit="return confirm('Delete this item?')"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$it['id']; ?>">
        <button class="btn-icon text-danger" type="submit" title="Delete"><i class="fas fa-trash"></i></button></form></td></tr>
<?php endforeach; ?>
<?php if (!$items): ?><tr><td colspan="5" class="text-center text-muted">Nothing published yet.</td></tr><?php endif; ?>
</tbody></table></div></div></div>
<?php dashboard_close(); include __DIR__ . '/../includes/footer.php';
