<?php
// admin/gallery.php - Gallery Management
require_once '../config/config.php';
require_once '../config/security.php';

Security::requireRole('admin');

$pageTitle = 'Gallery Management';
$extraCSS = ['admin.css', 'dashboard.css'];
$extraJS = ['gallery.js', 'dropzone.js'];

include __DIR__ . '/../includes/header.php';

$db = db();
[$message, $messageType] = flash_get();

$action = $_GET['action'] ?? 'list';
$id = isset($_POST['id']) ? (int)$_POST['id'] : (isset($_GET['id']) ? (int)$_GET['id'] : null);
$galleryDir = UPLOAD_PATH . 'gallery/';
if (!is_dir($galleryDir)) {
    @mkdir($galleryDir, 0755, true);
}

/** Remove a gallery file by bare file name only (never a path). */
$removeFile = function ($name) use ($galleryDir) {
    if ($name && basename($name) === $name && is_file($galleryDir . $name)) {
        @unlink($galleryDir . $name);
    }
};

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid security token';
        $messageType = 'error';
    } else {
        $postAction = $_POST['action'] ?? '';
        $title = Security::sanitize($_POST['title'] ?? '');
        $description = Security::sanitize($_POST['description'] ?? '');
        $category = Security::sanitize($_POST['category'] ?? 'general') ?: 'general';
        $is_published = isset($_POST['is_published']) ? 1 : 0;

        if (in_array($postAction, ['upload', 'update'], true) && ($title === '' || mb_strlen($title) > 200 || mb_strlen($category) > 50)) {
            $message = 'Please enter a title (max 200 characters) and a short category';
            $messageType = 'error';
            $postAction = '';
        }

        switch ($postAction) {
            case 'upload':
                if (!isset($_FILES['image']) || $_FILES['image']['error'] === UPLOAD_ERR_NO_FILE) {
                    $message = 'No image uploaded';
                    $messageType = 'error';
                    break;
                }
                $upload = Security::validateFileUpload($_FILES['image'], ['jpg', 'jpeg', 'png', 'gif']);
                if (!$upload['valid']) {
                    $message = 'Invalid file: ' . $upload['message'];
                    $messageType = 'error';
                    break;
                }
                $filename = 'gallery_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $upload['extension'];
                $thumbnail = 'thumb_' . $filename;
                if (!move_uploaded_file($_FILES['image']['tmp_name'], $galleryDir . $filename)) {
                    $message = 'Failed to upload image';
                    $messageType = 'error';
                    break;
                }
                @chmod($galleryDir . $filename, 0644);
                Security::createThumbnail($galleryDir . $filename, $galleryDir . $thumbnail, 400);
                try {
                    $newId = $db->insert(
                        "INSERT INTO gallery (title, description, image_path, thumbnail_path, category, is_published, uploaded_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?)",
                        [$title, $description, $filename, $thumbnail, $category, $is_published, $_SESSION['user_id']]
                    );
                    Security::logAudit('UPLOADED_IMAGE', 'gallery', $newId);
                    $message = 'Image uploaded successfully';
                    $messageType = 'success';
                } catch (Exception $e) {
                    $removeFile($filename);
                    $removeFile($thumbnail);
                    $message = 'Error saving image';
                    $messageType = 'error';
                }
                break;

            case 'update':
                try {
                    if (!$id || !$db->getRow('SELECT id FROM gallery WHERE id = ?', [$id])) {
                        throw new Exception('Image not found');
                    }
                    $db->query(
                        "UPDATE gallery SET title = ?, description = ?, category = ?, is_published = ? WHERE id = ?",
                        [$title, $description, $category, $is_published, $id]
                    );
                    Security::logAudit('UPDATED_IMAGE', 'gallery', $id);
                    $message = 'Image updated successfully';
                    $messageType = 'success';
                } catch (Exception $e) {
                    $message = 'Error: ' . $e->getMessage();
                    $messageType = 'error';
                }
                break;

            case 'delete':
                try {
                    $image = $id ? $db->getRow("SELECT image_path, thumbnail_path FROM gallery WHERE id = ?", [$id]) : null;
                    if (!$image) {
                        throw new Exception('Image not found');
                    }
                    $db->query("DELETE FROM gallery WHERE id = ?", [$id]);
                    $removeFile($image['image_path']);
                    $removeFile($image['thumbnail_path']);
                    Security::logAudit('DELETED_IMAGE', 'gallery', $id);
                    $message = 'Image deleted successfully';
                    $messageType = 'success';
                } catch (Exception $e) {
                    $message = 'Error: ' . $e->getMessage();
                    $messageType = 'error';
                }
                break;

            case 'bulk_delete':
                $ids = $_POST['ids'] ?? [];
                if (!is_array($ids)) $ids = explode(',', (string)$ids);
                $ids = array_values(array_filter(array_map('intval', $ids)));
                if (!$ids) {
                    $message = 'No images selected';
                    $messageType = 'error';
                    break;
                }
                try {
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $images = $db->getRows("SELECT image_path, thumbnail_path FROM gallery WHERE id IN ($placeholders)", $ids);
                    $db->query("DELETE FROM gallery WHERE id IN ($placeholders)", $ids);
                    foreach ($images as $img) {
                        $removeFile($img['image_path']);
                        $removeFile($img['thumbnail_path']);
                    }
                    Security::logAudit('BULK_DELETED_IMAGES', 'gallery');
                    $message = 'Selected images deleted successfully';
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
    flash_redirect($message, 'success', BASE_URL . '/admin/gallery');
}

// Get gallery images with pagination
$page = page_param('p');
$limit = 20;
$offset = ($page - 1) * $limit;

$totalImages = $db->getRow("SELECT COUNT(*) as count FROM gallery")['count'];
$totalPages = max(1, (int)ceil($totalImages / $limit));

$images = $db->getRows(
    "SELECT g.*, u.first_name, u.last_name
     FROM gallery g
     JOIN users u ON g.uploaded_by = u.id
     ORDER BY g.uploaded_at DESC
     LIMIT ? OFFSET ?",
    [$limit, $offset]
);

// Get categories for filter
$categories = $db->getRows("SELECT DISTINCT category FROM gallery ORDER BY category");
?>

<div class="dashboard-container">
    <?php render_sidebar('admin'); ?>

    <main class="dashboard-main">
        <div class="dashboard-header">
            <h1>Gallery Management</h1>
            <div class="header-actions">
                <button class="btn btn-primary" onclick="showUploadModal()">
                    <i class="fas fa-upload"></i> Upload Images
                </button>
                <button class="btn btn-outline" onclick="toggleBulkDelete()">
                    <i class="fas fa-trash"></i> Bulk Delete
                </button>
            </div>
        </div>

        <?php if ($message): ?>
        <div class="alert alert-<?php echo e($messageType); ?> alert-dismissible">
            <i class="fas <?php echo $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
            <?php echo e($message); ?>
            <button type="button" class="close" onclick="this.parentElement.remove()">&times;</button>
        </div>
        <?php endif; ?>

        <!-- Upload Modal -->
        <div id="uploadModal" class="modal">
            <div class="modal-content modal-lg">
                <div class="modal-header">
                    <h3>Upload Images</h3>
                    <button type="button" class="close" onclick="closeModal('uploadModal')">&times;</button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                        <input type="hidden" name="action" value="upload">

                        <div class="form-group">
                            <label for="title">Title</label>
                            <input type="text" id="title" name="title" class="form-control" required>
                        </div>

                        <div class="form-group">
                            <label for="description">Description</label>
                            <textarea id="description" name="description" class="form-control" rows="3"></textarea>
                        </div>

                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label for="category">Category</label>
                                <select id="category" name="category" class="form-control">
                                    <option value="general">General</option>
                                    <option value="events">Events</option>
                                    <option value="sports">Sports</option>
                                    <option value="academic">Academic</option>
                                    <option value="graduation">Graduation</option>
                                    <option value="facilities">Facilities</option>
                                </select>
                            </div>

                            <div class="form-group col-md-6">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="is_published" value="1" checked>
                                    Publish immediately
                                </label>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="image">Select Image</label>
                            <input type="file" id="image" name="image" class="form-control-file"
                                   accept="image/*" required onchange="previewImage(this)">
                            <small class="form-text">Max file size: 5MB. Allowed: JPG, PNG, GIF</small>
                        </div>

                        <div id="imagePreview" class="image-preview" style="display: none;">
                            <img id="preview" src="#" alt="Preview">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('uploadModal')">Cancel</button>
                        <button type="submit" class="btn btn-primary">Upload</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Gallery Grid -->
        <div class="card">
            <div class="card-header">
                <h3>Gallery Images</h3>
                <div class="card-tools">
                    <select id="categoryFilter" class="form-control" style="width: 150px;" onchange="filterByCategory()">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo htmlspecialchars($cat['category']); ?>">
                            <?php echo ucfirst(htmlspecialchars($cat['category'])); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="card-body">
                <?php if (!empty($images)): ?>
                <div class="gallery-grid">
                    <?php foreach ($images as $image): ?>
                    <div class="gallery-item" data-category="<?php echo e($image['category']); ?>">
                        <div class="gallery-image">
                            <img src="<?php echo BASE_URL; ?>/uploads/gallery/<?php echo e($image['thumbnail_path']); ?>"
                                 alt="<?php echo htmlspecialchars($image['title']); ?>">
                            <div class="gallery-actions">
                                <input type="checkbox" class="gallery-select" value="<?php echo e($image['id']); ?>">
                                <button class="btn-icon" onclick="editImage(<?php echo e($image['id']); ?>)" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn-icon text-danger" onclick="deleteImage(<?php echo e($image['id']); ?>)" title="Delete">
                                    <i class="fas fa-trash"></i>
                                </button>
                                <a href="<?php echo BASE_URL; ?>/uploads/gallery/<?php echo e($image['image_path']); ?>"
                                   class="btn-icon" target="_blank" title="View Full Size">
                                    <i class="fas fa-external-link-alt"></i>
                                </a>
                            </div>
                        </div>
                        <div class="gallery-info">
                            <h4><?php echo htmlspecialchars($image['title']); ?></h4>
                            <p><?php echo htmlspecialchars($image['description']); ?></p>
                            <div class="gallery-meta">
                                <span class="badge badge-<?php echo $image['is_published'] ? 'success' : 'secondary'; ?>">
                                    <?php echo $image['is_published'] ? 'Published' : 'Draft'; ?>
                                </span>
                                <span class="badge badge-info"><?php echo e(ucfirst($image['category'])); ?></span>
                                <small>Uploaded by <?php echo htmlspecialchars($image['first_name']); ?></small>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Bulk Delete Bar -->
                <div id="bulkDeleteBar" class="bulk-delete-bar" style="display: none;">
                    <span><span id="selectedCount">0</span> image(s) selected</span>
                    <button class="btn btn-danger btn-sm" onclick="bulkDelete()">
                        <i class="fas fa-trash"></i> Delete Selected
                    </button>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="?p=<?php echo e($i); ?>" class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                        <?php echo e($i); ?>
                    </a>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>

                <?php else: ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    No images in gallery. Click "Upload Images" to add some.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<!-- Edit Modal -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Edit Image</h3>
            <button type="button" class="close" onclick="closeModal('editModal')">&times;</button>
        </div>
        <form method="POST" id="editForm">
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" id="edit_id">

                <div class="form-group">
                    <label for="edit_title">Title</label>
                    <input type="text" id="edit_title" name="title" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="edit_description">Description</label>
                    <textarea id="edit_description" name="description" class="form-control" rows="3"></textarea>
                </div>

                <div class="form-group">
                    <label for="edit_category">Category</label>
                    <select id="edit_category" name="category" class="form-control">
                        <option value="general">General</option>
                        <option value="events">Events</option>
                        <option value="sports">Sports</option>
                        <option value="academic">Academic</option>
                        <option value="graduation">Graduation</option>
                        <option value="facilities">Facilities</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="is_published" id="edit_published" value="1">
                        Published
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Update</button>
            </div>
        </form>
    </div>
</div>

<!-- Bulk Delete Form -->
<form method="POST" id="bulkDeleteForm" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
    <input type="hidden" name="action" value="bulk_delete">
    <input type="hidden" name="ids" id="bulkIds">
</form>

<style>
.gallery-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 20px;
}

.gallery-item {
    background: white;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    transition: transform 0.3s ease;
}

.gallery-item:hover {
    transform: translateY(-5px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.2);
}

.gallery-image {
    position: relative;
    height: 180px;
    overflow: hidden;
}

.gallery-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.gallery-actions {
    position: absolute;
    top: 10px;
    right: 10px;
    display: flex;
    gap: 5px;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.gallery-item:hover .gallery-actions {
    opacity: 1;
}

.gallery-actions .btn-icon {
    background: rgba(255,255,255,0.9);
    width: 30px;
    height: 30px;
    font-size: 14px;
}

.gallery-select {
    position: absolute;
    top: 10px;
    left: 10px;
    width: 20px;
    height: 20px;
    cursor: pointer;
}

.gallery-info {
    padding: 12px;
}

.gallery-info h4 {
    font-size: 14px;
    margin-bottom: 5px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.gallery-info p {
    font-size: 12px;
    color: #666;
    margin-bottom: 8px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.gallery-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
    align-items: center;
    font-size: 11px;
}

.gallery-meta small {
    color: #999;
    margin-left: auto;
}

.image-preview {
    margin-top: 15px;
    text-align: center;
}

.image-preview img {
    max-width: 100%;
    max-height: 300px;
    border-radius: 5px;
    border: 2px solid #ddd;
}

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
}

@media (max-width: 768px) {
    .gallery-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 480px) {
    .gallery-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
let selectedImages = [];

function showUploadModal() {
    document.getElementById('uploadModal').style.display = 'block';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

function previewImage(input) {
    const preview = document.getElementById('imagePreview');
    const previewImg = document.getElementById('preview');

    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            previewImg.src = e.target.result;
            preview.style.display = 'block';
        }
        reader.readAsDataURL(input.files[0]);
    }
}

function editImage(id) {
    // Fetch image details via AJAX
    fetch(`../api/get-gallery-image?id=${id}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('edit_id').value = data.image.id;
                document.getElementById('edit_title').value = data.image.title;
                document.getElementById('edit_description').value = data.image.description;
                document.getElementById('edit_category').value = data.image.category;
                document.getElementById('edit_published').checked = data.image.is_published == 1;
                document.getElementById('editModal').style.display = 'block';
            }
        });
}

function deleteImage(id) {
    if (confirm('Are you sure you want to delete this image?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="${id}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Gallery selection for bulk delete
document.querySelectorAll('.gallery-select').forEach(cb => {
    cb.addEventListener('change', updateBulkDeleteBar);
});

function updateBulkDeleteBar() {
    selectedImages = Array.from(document.querySelectorAll('.gallery-select:checked')).map(cb => cb.value);
    const bar = document.getElementById('bulkDeleteBar');
    const countSpan = document.getElementById('selectedCount');

    if (selectedImages.length > 0) {
        countSpan.textContent = selectedImages.length;
        bar.style.display = 'flex';
    } else {
        bar.style.display = 'none';
    }
}

function toggleBulkDelete() {
    document.querySelectorAll('.gallery-select').forEach(cb => {
        cb.checked = !cb.checked;
    });
    updateBulkDeleteBar();
}

function bulkDelete() {
    if (selectedImages.length === 0) return;

    if (confirm(`Delete ${selectedImages.length} selected images?`)) {
        document.getElementById('bulkIds').value = selectedImages.join(',');
        document.getElementById('bulkDeleteForm').submit();
    }
}

function filterByCategory() {
    const category = document.getElementById('categoryFilter').value.toLowerCase();
    const items = document.querySelectorAll('.gallery-item');

    items.forEach(item => {
        const itemCategory = item.dataset.category?.toLowerCase() || '';
        if (!category || itemCategory === category) {
            item.style.display = '';
        } else {
            item.style.display = 'none';
        }
    });
}

// Close modals when clicking outside
window.onclick = function(event) {
    const uploadModal = document.getElementById('uploadModal');
    const editModal = document.getElementById('editModal');

    if (event.target === uploadModal) {
        uploadModal.style.display = 'none';
    }
    if (event.target === editModal) {
        editModal.style.display = 'none';
    }
}
</script>

<?php
include '../includes/footer.php';
?>