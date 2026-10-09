<?php
// admin/announcements.php - Manage Announcements
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';

// Define UPLOAD_PATH if not already defined (fallback)
if (!defined('UPLOAD_PATH')) {
    define('UPLOAD_PATH', __DIR__ . '/../uploads/');
}

Security::requireRole('admin');

$pageTitle = 'Manage Announcements';
$extraCSS = ['admin.css', 'dashboard.css'];
$extraJS = ['announcements.js'];

include __DIR__ . '/../includes/header.php';

// Get database instance
try {
    $db = Database::getInstance();
} catch (Exception $e) {
    die("Database connection error: " . $e->getMessage());
}

// Upload directory (web-served, but PHP execution is disabled in uploads/.htaccess)
$uploadDir = UPLOAD_PATH . 'announcements/';
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
    error_log("Failed to create upload directory: " . $uploadDir);
}

[$message, $messageType] = flash_get();

$action = isset($_GET['action']) ? $_GET['action'] : 'list';
if (!in_array($action, ['list', 'add', 'edit'], true)) $action = 'list';
$id = isset($_POST['id']) ? (int)$_POST['id'] : (isset($_GET['id']) ? (int)$_GET['id'] : null);

/** Delete an attachment file that belongs to the announcements folder. */
$removeAttachment = function ($name) use ($uploadDir) {
    if ($name && basename($name) === $name && is_file($uploadDir . $name)) {
        @unlink($uploadDir . $name);
    }
};

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid security token. Please refresh the page and try again.';
        $messageType = 'error';
    } else {
        $postAction = $_POST['action'] ?? '';

        switch ($postAction) {
            case 'add':
            case 'edit':
                // Content is plain text: it is escaped (and line breaks kept) on output
                $title = Security::sanitize($_POST['title'] ?? '');
                $content = trim(str_replace("\0", '', (string)($_POST['content'] ?? '')));
                $audience = $_POST['audience'] ?? 'all';
                $priority = $_POST['priority'] ?? 'normal';
                $expiresAt = valid_datetime($_POST['expires_at'] ?? '');
                $isPublished = isset($_POST['is_published']) ? 1 : 0;

                if ($title === '' || mb_strlen($title) > 200) {
                    $message = 'Please enter a title (max 200 characters)';
                    $messageType = 'error';
                    break;
                }
                if ($content === '' || mb_strlen($content) > 20000) {
                    $message = 'Please enter content (max 20,000 characters)';
                    $messageType = 'error';
                    break;
                }
                if (!in_array($audience, ['all', 'students', 'teachers', 'parents', 'admins'], true)
                    || !in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
                    $message = 'Invalid audience or priority';
                    $messageType = 'error';
                    break;
                }
                if (!empty($_POST['expires_at']) && !$expiresAt) {
                    $message = 'Invalid expiry date';
                    $messageType = 'error';
                    break;
                }

                // Handle file attachment
                $attachmentPath = null;
                if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $upload = Security::validateFileUpload($_FILES['attachment']);
                    if (!$upload['valid']) {
                        $message = 'Invalid file: ' . $upload['message'];
                        $messageType = 'error';
                        break;
                    }
                    $fileName = Security::generateSecureFilename($_FILES['attachment']['name']);
                    if (!move_uploaded_file($_FILES['attachment']['tmp_name'], $uploadDir . $fileName)) {
                        $message = 'Failed to upload file. Please check directory permissions.';
                        $messageType = 'error';
                        break;
                    }
                    @chmod($uploadDir . $fileName, 0644);
                    $attachmentPath = $fileName;
                }

                try {
                    if ($postAction === 'add') {
                        $newId = $db->insert(
                            "INSERT INTO announcements (title, content, audience, priority, attachment, expires_at, is_published, created_by)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                            [$title, $content, $audience, $priority, $attachmentPath, $expiresAt, $isPublished, $_SESSION['user_id']]
                        );
                        Security::logAudit('ADDED_ANNOUNCEMENT', 'announcements', $newId);
                        $message = 'Announcement added successfully';
                    } else {
                        $current = $id ? $db->getRow("SELECT attachment FROM announcements WHERE id = ?", [$id]) : null;
                        if (!$current) {
                            throw new Exception("Announcement not found");
                        }
                        $sql = "UPDATE announcements SET title = ?, content = ?, audience = ?, priority = ?, expires_at = ?, is_published = ?";
                        $params = [$title, $content, $audience, $priority, $expiresAt, $isPublished];
                        if ($attachmentPath) {
                            $sql .= ", attachment = ?";
                            $params[] = $attachmentPath;
                        }
                        $db->query($sql . " WHERE id = ?", array_merge($params, [$id]));
                        if ($attachmentPath) {
                            $removeAttachment($current['attachment']);
                        }
                        Security::logAudit('UPDATED_ANNOUNCEMENT', 'announcements', $id);
                        $message = 'Announcement updated successfully';
                    }
                    $messageType = 'success';
                } catch (Exception $e) {
                    $removeAttachment($attachmentPath);
                    $message = 'Error: ' . $e->getMessage();
                    $messageType = 'error';
                }
                break;

            case 'delete':
                try {
                    $announcement = $id ? $db->getRow("SELECT attachment FROM announcements WHERE id = ?", [$id]) : null;
                    if (!$announcement) {
                        throw new Exception("Announcement not found");
                    }
                    $db->query("DELETE FROM announcements WHERE id = ?", [$id]);
                    $removeAttachment($announcement['attachment']);
                    Security::logAudit('DELETED_ANNOUNCEMENT', 'announcements', $id);
                    $message = 'Announcement deleted successfully';
                    $messageType = 'success';
                } catch (Exception $e) {
                    $message = 'Error: ' . $e->getMessage();
                    $messageType = 'error';
                }
                break;

            case 'bulk_delete':
                $ids = array_values(array_filter(array_map('intval', explode(',', (string)($_POST['ids'] ?? '')))));
                if (!$ids) {
                    $message = 'No announcements selected';
                    $messageType = 'error';
                    break;
                }
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                try {
                    $rows = $db->getRows("SELECT attachment FROM announcements WHERE id IN ($placeholders)", $ids);
                    $db->query("DELETE FROM announcements WHERE id IN ($placeholders)", $ids);
                    foreach ($rows as $row) {
                        $removeAttachment($row['attachment']);
                    }
                    Security::logAudit('BULK_DELETED_ANNOUNCEMENTS', 'announcements');
                    $message = count($ids) . ' announcement(s) deleted';
                    $messageType = 'success';
                } catch (Exception $e) {
                    $message = 'Error: ' . $e->getMessage();
                    $messageType = 'error';
                }
                break;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $messageType === 'success') {
    flash_redirect($message, 'success', BASE_URL . '/admin/announcements');
}

// Get announcement for editing
$announcement = null;
if ($action === 'edit' && $id) {
    $announcement = $db->getRow("SELECT * FROM announcements WHERE id = ?", [$id]);
}

// Handle duplicate action
if ($action === 'add' && isset($_GET['duplicate'])) {
    $duplicateId = (int)$_GET['duplicate'];
    $announcement = $db->getRow("SELECT * FROM announcements WHERE id = ?", [$duplicateId]);
    if ($announcement) {
        // Clear the ID to create new
        unset($announcement['id']);
        $announcement['title'] = $announcement['title'] . ' (Copy)';
    }
}

// Get announcements list with pagination
$page = page_param('p');
$limit = 20;
$offset = ($page - 1) * $limit;

$totalAnnouncements = $db->getRow("SELECT COUNT(*) as count FROM announcements")['count'] ?? 0;
$totalPages = $totalAnnouncements > 0 ? ceil($totalAnnouncements / $limit) : 1;

$announcements = $db->getRows(
    "SELECT a.*, u.first_name, u.last_name
     FROM announcements a
     JOIN users u ON a.created_by = u.id
     ORDER BY a.created_at DESC
     LIMIT ? OFFSET ?",
    [$limit, $offset]
);
?>


<style>
/* Modal positioning fix */
.modal {
    display: none;
    position: fixed;
    z-index: 9999;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    overflow: auto;
    background-color: rgba(0,0,0,0.5);
}

.modal-content {
    background-color: #fefefe;
    margin: 5% auto;
    padding: 0;
    border: 1px solid #888;
    width: 90%;
    max-width: 800px;
    border-radius: 10px;
    box-shadow: 0 5px 30px rgba(0,0,0,0.3);
    position: relative;
    z-index: 10000;
}

.modal-content.modal-lg {
    max-width: 900px;
}

.modal-header {
    padding: 15px 20px;
    border-bottom: 1px solid #dee2e6;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: linear-gradient(135deg, var(--navy), var(--navy-dark));
    color: white;
    border-radius: 10px 10px 0 0;
}

.modal-header h3 {
    margin: 0;
    color: white;
}

.modal-header .close {
    background: none;
    border: none;
    color: white;
    font-size: 24px;
    cursor: pointer;
    opacity: 0.8;
}

.modal-header .close:hover {
    opacity: 1;
}

.modal-body {
    padding: 20px;
    max-height: 70vh;
    overflow-y: auto;
}

.modal-footer {
    padding: 15px 20px;
    border-top: 1px solid #dee2e6;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

/* Action buttons */
.action-buttons {
    display: flex;
    gap: 5px;
    flex-wrap: wrap;
}

.btn-icon {
    width: 32px;
    height: 32px;
    border-radius: 4px;
    border: none;
    background: #f0f0f0;
    color: #333;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
    text-decoration: none;
}

.btn-icon:hover {
    background: var(--gold);
    color: var(--navy);
}

.btn-icon.text-danger:hover {
    background: #dc3545;
    color: white;
}

/* Badge styles */
.badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
}

.badge.audience-students {
    background: rgba(0, 40, 85, 0.1);
    color: #002855;
}

.badge.audience-teachers {
    background: rgba(196, 30, 58, 0.1);
    color: #c41e3a;
}

.badge.audience-parents {
    background: rgba(40, 167, 69, 0.1);
    color: #28a745;
}

.badge.audience-admins {
    background: rgba(255, 193, 7, 0.1);
    color: #856404;
}

.badge.priority-low {
    background: rgba(108, 117, 125, 0.1);
    color: #6c757d;
}

.badge.priority-normal {
    background: rgba(0, 40, 85, 0.1);
    color: #002855;
}

.badge.priority-high {
    background: rgba(255, 193, 7, 0.1);
    color: #856404;
}

.badge.priority-urgent {
    background: rgba(220, 53, 69, 0.1);
    color: #dc3545;
}

.badge.success {
    background: rgba(40, 167, 69, 0.1);
    color: #28a745;
}

.badge.warning {
    background: rgba(255, 193, 7, 0.1);
    color: #856404;
}

.badge.danger {
    background: rgba(220, 53, 69, 0.1);
    color: #dc3545;
}

.badge.light {
    background: rgba(108, 117, 125, 0.1);
    color: #6c757d;
}

/* Table row colors */
.priority-high td {
    background-color: rgba(255, 193, 7, 0.05);
}

.priority-urgent td {
    background-color: rgba(220, 53, 69, 0.05);
    font-weight: 500;
}

/* Bulk delete bar */
.bulk-delete-bar {
    position: fixed;
    bottom: 20px;
    left: 50%;
    transform: translateX(-50%);
    background: white;
    padding: 15px 30px;
    border-radius: 50px;
    box-shadow: 0 5px 20px rgba(0,0,0,0.2);
    display: flex;
    align-items: center;
    gap: 20px;
    z-index: 1000;
    border: 2px solid #dc3545;
    animation: slideUp 0.3s ease;
}

@keyframes slideUp {
    from {
        transform: translate(-50%, 100%);
        opacity: 0;
    }
    to {
        transform: translate(-50%, 0);
        opacity: 1;
    }
}

/* Current attachment */
.current-attachment {
    margin-top: 10px;
    padding: 10px;
    background: #f8f9fa;
    border-radius: 4px;
    border-left: 3px solid var(--gold);
}

.current-attachment i {
    color: var(--gold);
    margin-right: 5px;
}

.current-attachment a {
    color: var(--navy);
    text-decoration: none;
}

.current-attachment a:hover {
    text-decoration: underline;
}

/* Pagination */
.pagination {
    display: flex;
    justify-content: center;
    gap: 5px;
    margin-top: 20px;
}

.page-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 35px;
    height: 35px;
    padding: 0 5px;
    background: white;
    border: 1px solid #ddd;
    border-radius: 4px;
    color: #333;
    text-decoration: none;
    transition: all 0.3s ease;
}

.page-link:hover,
.page-link.active {
    background: var(--navy);
    color: white;
    border-color: var(--navy);
}

/* Card tools */
.card-tools {
    display: flex;
    gap: 10px;
}

.btn-sm {
    padding: 5px 10px;
    font-size: 12px;
    border-radius: 4px;
}

/* Form styles */
.form-row {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 15px;
}

.form-group {
    flex: 1 1 200px;
}

.form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 500;
    color: var(--navy);
}

.form-control {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
}

.form-control:focus {
    outline: none;
    border-color: var(--gold);
    box-shadow: 0 0 0 2px rgba(255, 215, 0, 0.2);
}

.checkbox-label {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
}

.checkbox-label input[type="checkbox"] {
    width: 16px;
    height: 16px;
    cursor: pointer;
}

.form-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid #dee2e6;
}

/* Rich editor */
.rich-editor {
    min-height: 200px;
}

/* Alert styles */
.alert {
    padding: 15px 20px;
    margin-bottom: 20px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    gap: 10px;
    animation: slideDown 0.3s ease;
}

.alert-success {
    background-color: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.alert-error {
    background-color: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

.alert-warning {
    background-color: #fff3cd;
    color: #856404;
    border: 1px solid #ffeeba;
}

.alert-info {
    background-color: #d1ecf1;
    color: #0c5460;
    border: 1px solid #bee5eb;
}

.alert .close {
    margin-left: auto;
    background: none;
    border: none;
    font-size: 20px;
    cursor: pointer;
    color: inherit;
    opacity: 0.7;
}

.alert .close:hover {
    opacity: 1;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Responsive */
@media (max-width: 768px) {
    .form-row {
        flex-direction: column;
        gap: 10px;
    }

    .action-buttons {
        justify-content: center;
    }

    .bulk-delete-bar {
        width: 90%;
        flex-direction: column;
        text-align: center;
        padding: 15px;
        border-radius: 10px;
    }
}
</style>

<div class="dashboard-container">
    <?php render_sidebar('admin'); ?>

    <main class="dashboard-main">
        <div class="dashboard-header">
            <h1>Manage Announcements</h1>
            <div class="header-actions">
                <?php if ($action === 'add' || $action === 'edit'): ?>
                <a href="announcements" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>
                <?php else: ?>
                <a href="?action=add" class="btn btn-primary">
                    <i class="fas fa-plus"></i> New Announcement
                </a>
                <button class="btn btn-outline" onclick="exportAnnouncements()">
                    <i class="fas fa-download"></i> Export
                </button>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($message): ?>
        <div class="alert alert-<?php echo e($messageType); ?> alert-dismissible">
            <i class="fas <?php echo $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="close" onclick="this.parentElement.remove()">&times;</button>
        </div>
        <?php endif; ?>

        <?php if ($action === 'add' || $action === 'edit'): ?>
        <!-- Announcement Form -->
        <div class="card">
            <div class="card-header">
                <h3><?php echo $action === 'add' ? 'Create New Announcement' : 'Edit Announcement'; ?></h3>
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data" class="announcement-form" id="announcementForm">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="<?php echo e($action); ?>">
                    <?php if ($action === 'edit'): ?>
                    <input type="hidden" name="id" value="<?php echo e($id); ?>">
                    <?php endif; ?>

                    <div class="form-group">
                        <label for="title">Title *</label>
                        <input type="text" id="title" name="title" class="form-control"
                               value="<?php echo htmlspecialchars($announcement['title'] ?? ''); ?>"
                               placeholder="Enter announcement title" required>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="audience">Audience *</label>
                            <select id="audience" name="audience" class="form-control" required>
                                <option value="all" <?php echo (isset($announcement['audience']) && $announcement['audience'] === 'all') ? 'selected' : ''; ?>>Everyone</option>
                                <option value="students" <?php echo (isset($announcement['audience']) && $announcement['audience'] === 'students') ? 'selected' : ''; ?>>Students Only</option>
                                <option value="teachers" <?php echo (isset($announcement['audience']) && $announcement['audience'] === 'teachers') ? 'selected' : ''; ?>>Teachers Only</option>
                                <option value="parents" <?php echo (isset($announcement['audience']) && $announcement['audience'] === 'parents') ? 'selected' : ''; ?>>Parents Only</option>
                                <option value="admins" <?php echo (isset($announcement['audience']) && $announcement['audience'] === 'admins') ? 'selected' : ''; ?>>Admins Only</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="priority">Priority</label>
                            <select id="priority" name="priority" class="form-control">
                                <option value="low" <?php echo (isset($announcement['priority']) && $announcement['priority'] === 'low') ? 'selected' : ''; ?>>Low</option>
                                <option value="normal" <?php echo (!isset($announcement['priority']) || $announcement['priority'] === 'normal') ? 'selected' : ''; ?>>Normal</option>
                                <option value="high" <?php echo (isset($announcement['priority']) && $announcement['priority'] === 'high') ? 'selected' : ''; ?>>High</option>
                                <option value="urgent" <?php echo (isset($announcement['priority']) && $announcement['priority'] === 'urgent') ? 'selected' : ''; ?>>Urgent</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="expires_at">Expiry Date</label>
                            <input type="date" id="expires_at" name="expires_at" class="form-control"
                                   value="<?php echo isset($announcement['expires_at']) ? htmlspecialchars($announcement['expires_at']) : ''; ?>"
                                   min="<?php echo date('Y-m-d'); ?>">
                            <small class="form-text text-muted">Leave blank for no expiry</small>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="content">Content *</label>
                        <textarea id="content" name="content" class="form-control rich-editor" rows="10" required><?php echo htmlspecialchars($announcement['content'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="attachment">Attachment (Optional)</label>
                        <input type="file" id="attachment" name="attachment" class="form-control">
                        <small class="form-text text-muted">Allowed: PDF, DOC, DOCX, JPG, PNG (Max: 5MB)</small>
                        <?php if ($action === 'edit' && !empty($announcement['attachment'])): ?>
                        <div class="current-attachment">
                            <i class="fas fa-paperclip"></i>
                            <a href="<?php echo BASE_URL; ?>/uploads/announcements/<?php echo urlencode($announcement['attachment']); ?>" target="_blank">
                                View Current Attachment
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="is_published" value="1"
                                   <?php echo (!isset($announcement['is_published']) || $announcement['is_published']) ? 'checked' : ''; ?>>
                            Publish immediately
                        </label>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> <?php echo $action === 'add' ? 'Publish Announcement' : 'Update Announcement'; ?>
                        </button>
                        <a href="announcements" class="btn btn-outline">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <script>
        // Initialize CKEditor
        CKEDITOR.replace('content', {
            height: 300,
            toolbar: [
                { name: 'document', items: ['Source', '-', 'Save', 'NewPage', 'Preview', 'Print', '-', 'Templates'] },
                { name: 'clipboard', items: ['Cut', 'Copy', 'Paste', 'PasteText', 'PasteFromWord', '-', 'Undo', 'Redo'] },
                { name: 'editing', items: ['Find', 'Replace', '-', 'SelectAll', '-', 'Scayt'] },
                { name: 'forms', items: ['Form', 'Checkbox', 'Radio', 'TextField', 'Textarea', 'Select', 'Button', 'ImageButton', 'HiddenField'] },
                '/',
                { name: 'basicstyles', items: ['Bold', 'Italic', 'Underline', 'Strike', 'Subscript', 'Superscript', '-', 'CopyFormatting', 'RemoveFormat'] },
                { name: 'paragraph', items: ['NumberedList', 'BulletedList', '-', 'Outdent', 'Indent', '-', 'Blockquote', 'CreateDiv', '-', 'JustifyLeft', 'JustifyCenter', 'JustifyRight', 'JustifyBlock', '-', 'BidiLtr', 'BidiRtl', 'Language'] },
                { name: 'links', items: ['Link', 'Unlink', 'Anchor'] },
                { name: 'insert', items: ['Image', 'Flash', 'Table', 'HorizontalRule', 'Smiley', 'SpecialChar', 'PageBreak', 'Iframe'] },
                '/',
                { name: 'styles', items: ['Styles', 'Format', 'Font', 'FontSize'] },
                { name: 'colors', items: ['TextColor', 'BGColor'] },
                { name: 'tools', items: ['Maximize', 'ShowBlocks'] },
                { name: 'about', items: ['About'] }
            ]
        });

        // Form validation
        document.getElementById('announcementForm')?.addEventListener('submit', function(e) {
            const title = document.getElementById('title').value.trim();
            const content = CKEDITOR.instances.content.getData().trim();

            if (!title) {
                e.preventDefault();
                alert('Please enter a title');
                return false;
            }

            if (!content) {
                e.preventDefault();
                alert('Please enter content');
                return false;
            }

            return true;
        });
        </script>

        <?php else: ?>
        <!-- Announcements List -->
        <div class="card">
            <div class="card-header">
                <h3>All Announcements</h3>
                <div class="card-tools">
                    <input type="text" id="searchInput" class="form-control" placeholder="Search announcements..." style="width: 250px;">
                </div>
            </div>
            <div class="card-body">
                <?php if (!empty($announcements)): ?>
                <div class="table-responsive">
                    <table class="data-table" id="announcementsTable">
                        <thead>
                            <tr>
                                <th width="30"><input type="checkbox" id="selectAll"></th>
                                <th>Title</th>
                                <th>Audience</th>
                                <th>Priority</th>
                                <th>Created By</th>
                                <th>Date</th>
                                <th>Expires</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($announcements as $item): ?>
                            <tr class="priority-<?php echo e($item['priority']); ?>">
                                <td><input type="checkbox" class="select-item" value="<?php echo e($item['id']); ?>"></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($item['title']); ?></strong>
                                    <?php if (!empty($item['attachment'])): ?>
                                    <i class="fas fa-paperclip" title="Has attachment"></i>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge audience-<?php echo e($item['audience']); ?>">
                                        <?php echo e(ucfirst($item['audience'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge priority-<?php echo e($item['priority']); ?>">
                                        <?php echo e(ucfirst($item['priority'])); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($item['first_name'] . ' ' . $item['last_name']); ?></td>
                                <td><?php echo date('M d, Y', strtotime($item['created_at'])); ?></td>
                                <td>
                                    <?php if (!empty($item['expires_at'])): ?>
                                        <?php if (strtotime($item['expires_at']) < time()): ?>
                                            <span class="badge danger">Expired</span>
                                        <?php else: ?>
                                            <?php echo date('M d, Y', strtotime($item['expires_at'])); ?>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge light">Never</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($item['is_published']): ?>
                                    <span class="badge success">Published</span>
                                    <?php else: ?>
                                    <span class="badge warning">Draft</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="?action=edit&id=<?php echo e($item['id']); ?>" class="btn-icon" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="#" onclick="previewAnnouncement(<?php echo e($item['id']); ?>)" class="btn-icon" title="Preview">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="?action=add&duplicate=<?php echo e($item['id']); ?>" class="btn-icon" title="Duplicate">
                                            <i class="fas fa-copy"></i>
                                        </a>
                                        <button type="button" class="btn-icon text-danger"
                                                onclick="confirmDelete(<?php echo e($item['id']); ?>, '<?php echo htmlspecialchars(addslashes($item['title'])); ?>')"
                                                title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Bulk Delete Bar -->
                <div id="bulkDeleteBar" class="bulk-delete-bar" style="display: none;">
                    <span><span id="selectedCount">0</span> item(s) selected</span>
                    <button class="btn btn-danger btn-sm" onclick="bulkDelete()">
                        <i class="fas fa-trash"></i> Delete Selected
                    </button>
                    <button class="btn btn-outline btn-sm" onclick="clearSelection()">
                        <i class="fas fa-times"></i> Clear
                    </button>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                    <a href="?p=<?php echo $page - 1; ?>" class="page-link">
                        <i class="fas fa-chevron-left"></i> Previous
                    </a>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="?p=<?php echo e($i); ?>" class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                        <?php echo e($i); ?>
                    </a>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                    <a href="?p=<?php echo $page + 1; ?>" class="page-link">
                        Next <i class="fas fa-chevron-right"></i>
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php else: ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    No announcements found. Click "New Announcement" to create one.
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </main>
</div>

<!-- Preview Modal -->
<div id="previewModal" class="modal">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h3>Announcement Preview</h3>
            <button type="button" class="close" onclick="closePreview()">&times;</button>
        </div>
        <div class="modal-body" id="previewContent">
            <!-- Preview content will be loaded here -->
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closePreview()">Close</button>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Confirm Delete</h3>
            <button type="button" class="close" onclick="closeModal()">&times;</button>
        </div>
        <div class="modal-body">
            <p>Are you sure you want to delete "<span id="announcementTitle"></span>"?</p>
            <p class="text-danger">This action cannot be undone.</p>
        </div>
        <div class="modal-footer">
            <form method="POST" id="deleteForm">
                <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="deleteId">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-danger">Delete</button>
            </form>
        </div>
    </div>
</div>

<!-- Bulk Delete Form -->
<form method="POST" id="bulkDeleteForm" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
    <input type="hidden" name="action" value="bulk_delete">
    <input type="hidden" name="ids" id="bulkIds">
</form>

<script>
let selectedItems = [];

// Search functionality
document.getElementById('searchInput')?.addEventListener('keyup', function() {
    const searchTerm = this.value.toLowerCase();
    const table = document.getElementById('announcementsTable');
    if (!table) return;

    const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');

    for (let row of rows) {
        const title = row.cells[1]?.textContent.toLowerCase() || '';
        const audience = row.cells[2]?.textContent.toLowerCase() || '';

        if (title.includes(searchTerm) || audience.includes(searchTerm)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    }
});

// Select All functionality
document.getElementById('selectAll')?.addEventListener('change', function(e) {
    const checkboxes = document.querySelectorAll('.select-item');
    checkboxes.forEach(cb => {
        cb.checked = e.target.checked;
        if (e.target.checked) {
            if (!selectedItems.includes(cb.value)) {
                selectedItems.push(cb.value);
            }
        } else {
            selectedItems = [];
        }
    });
    updateBulkDeleteBar();
});

// Individual checkbox change
document.querySelectorAll('.select-item').forEach(cb => {
    cb.addEventListener('change', function() {
        if (this.checked) {
            if (!selectedItems.includes(this.value)) {
                selectedItems.push(this.value);
            }
        } else {
            selectedItems = selectedItems.filter(id => id !== this.value);
        }
        updateBulkDeleteBar();

        // Update select all checkbox
        const allCheckboxes = document.querySelectorAll('.select-item');
        const allChecked = Array.from(allCheckboxes).every(cb => cb.checked);
        document.getElementById('selectAll').checked = allChecked;
    });
});

function updateBulkDeleteBar() {
    const bar = document.getElementById('bulkDeleteBar');
    const countSpan = document.getElementById('selectedCount');

    if (selectedItems.length > 0) {
        countSpan.textContent = selectedItems.length;
        bar.style.display = 'flex';
    } else {
        bar.style.display = 'none';
    }
}

function clearSelection() {
    document.querySelectorAll('.select-item').forEach(cb => {
        cb.checked = false;
    });
    document.getElementById('selectAll').checked = false;
    selectedItems = [];
    updateBulkDeleteBar();
}

function toggleBulkDelete() {
    const checkboxes = document.querySelectorAll('.select-item');
    const allChecked = Array.from(checkboxes).every(cb => cb.checked);

    checkboxes.forEach(cb => {
        cb.checked = !allChecked;
        if (cb.checked) {
            if (!selectedItems.includes(cb.value)) {
                selectedItems.push(cb.value);
            }
        } else {
            selectedItems = [];
        }
    });
    document.getElementById('selectAll').checked = !allChecked;
    updateBulkDeleteBar();
}

function bulkDelete() {
    if (selectedItems.length === 0) return;

    if (confirm(`Are you sure you want to delete ${selectedItems.length} announcement(s)?`)) {
        document.getElementById('bulkIds').value = selectedItems.join(',');
        document.getElementById('bulkDeleteForm').submit();
    }
}

function previewAnnouncement(id) {
    // In a real implementation, you would fetch the content via AJAX
    // For now, show a simple preview
    const row = event.target.closest('tr');
    const title = row?.cells[1]?.textContent || 'Announcement';

    document.getElementById('previewContent').innerHTML = `
        <h2>${escapeHtml(title)}</h2>
        <hr>
        <p class="text-muted"><i class="fas fa-info-circle"></i> Preview functionality would load the full announcement content here.</p>
        <p>In production, this would fetch the content via AJAX from the server.</p>
    `;
    document.getElementById('previewModal').style.display = 'block';
}

function closePreview() {
    document.getElementById('previewModal').style.display = 'none';
}

function confirmDelete(id, title) {
    document.getElementById('deleteId').value = id;
    document.getElementById('announcementTitle').textContent = title;
    document.getElementById('deleteModal').style.display = 'block';
}

function closeModal() {
    document.getElementById('deleteModal').style.display = 'none';
}

function exportAnnouncements() {
    window.location.href = 'export?type=announcements';
}

// Close modals when clicking outside
window.onclick = function(event) {
    const previewModal = document.getElementById('previewModal');
    const deleteModal = document.getElementById('deleteModal');

    if (event.target === previewModal) {
        previewModal.style.display = 'none';
    }
    if (event.target === deleteModal) {
        deleteModal.style.display = 'none';
    }
}

// Auto-hide alert after 5 seconds
setTimeout(function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        alert.style.transition = 'opacity 0.5s ease';
        alert.style.opacity = '0';
        setTimeout(function() {
            if (alert.parentNode) {
                alert.remove();
            }
        }, 500);
    });
}, 5000);
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
