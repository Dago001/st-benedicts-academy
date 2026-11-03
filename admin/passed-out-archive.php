<?php
// admin/passed-out-archive.php - Passed Out / Graduated Students Archive
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/SystemSchema.php';

Security::requireRole(['super_admin', 'admin', 'head_teacher']);
SystemSchema::ensureAll();

$db = db();
[$message, $messageType] = flash_get();

// Handle student archiving or unarchiving
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        flash_redirect('Invalid security token.', 'error');
    }
    $action = $_POST['post_action'] ?? '';
    $studentId = (int)($_POST['student_id'] ?? 0);

    if ($action === 'unarchive' && $studentId > 0) {
        $db->query("UPDATE students SET status = 'active' WHERE id = ?", [$studentId]);
        $st = $db->getRow("SELECT user_id FROM students WHERE id = ?", [$studentId]);
        if ($st) {
            $db->query("UPDATE users SET is_active = 1, deleted_at = NULL WHERE id = ?", [$st['user_id']]);
        }
        Security::logAudit('UNARCHIVED_STUDENT', 'students', $studentId);
        flash_redirect('Student restored to active status successfully.', 'success');
    }

    if ($action === 'archive' && $studentId > 0) {
        $gradYear = trim((string)($_POST['grad_year'] ?? date('Y')));
        $db->query("UPDATE students SET status = 'graduated', archive_year = ? WHERE id = ?", [$gradYear, $studentId]);
        Security::logAudit('ARCHIVED_STUDENT', 'students', $studentId, null, ['archive_year' => $gradYear]);
        flash_redirect('Student moved to Passed Out Archive.', 'success');
    }
}

$search = trim($_GET['search'] ?? '');
$yearFilter = trim($_GET['year'] ?? '');

$sql = "SELECT s.*, u.first_name, u.last_name, u.email, u.phone, u.profile_image,
               c.class_name, c.section,
               CONCAT(pu.first_name, ' ', pu.last_name) as parent_name, pu.phone as parent_phone
        FROM students s
        JOIN users u ON s.user_id = u.id
        LEFT JOIN classes c ON s.class_id = c.id
        LEFT JOIN parents p ON s.parent_id = p.id
        LEFT JOIN users pu ON p.user_id = pu.id
        WHERE (s.status = 'graduated' OR u.is_active = 0 OR u.deleted_at IS NOT NULL)";
$params = [];

if ($search !== '') {
    $sql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR s.admission_number LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
if ($yearFilter !== '') {
    $sql .= " AND (s.archive_year = ?)";
    $params[] = $yearFilter;
}

$sql .= " ORDER BY s.id DESC LIMIT 200";
$archivedStudents = $db->getRows($sql, $params);

$pageTitle = 'Passed Out Archive';
$extraCSS = ['admin.css', 'dashboard.css'];
include __DIR__ . '/../includes/header.php';
dashboard_open('admin', 'Passed Out &amp; Alumni Archive');
render_alert($message, $messageType);
?>

<div class="card" style="margin-bottom:20px;">
    <div class="card-body">
        <form method="GET" class="form-inline" style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
            <input type="text" name="search" class="form-control" placeholder="Search by name or Adm No..." value="<?php echo e($search); ?>">
            <input type="text" name="year" class="form-control" placeholder="Archive / Graduation Year (e.g. 2025)" value="<?php echo e($yearFilter); ?>" style="max-width:200px;">
            <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search Archive</button>
            <?php if ($search !== '' || $yearFilter !== ''): ?>
                <a href="passed-out-archive" class="btn btn-outline">Clear</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <h3><i class="fas fa-archive"></i> Passed Out / Alumni Records (<?php echo count($archivedStudents); ?>)</h3>
        <span class="text-muted">Permanent historical records of graduated learners</span>
    </div>
    <div class="card-body">
        <?php if (empty($archivedStudents)): ?>
            <p class="text-muted">No archived students found.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Adm No.</th>
                            <th>Student Full Name</th>
                            <th>Last Class</th>
                            <th>Parent / Guardian</th>
                            <th>Contact</th>
                            <th>Archive / Grad Year</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($archivedStudents as $st): ?>
                        <tr>
                            <td><strong><?php echo e($st['admission_number']); ?></strong></td>
                            <td>
                                <strong><?php echo e($st['first_name'] . ' ' . $st['last_name']); ?></strong><br>
                                <small class="text-muted">Gender: <?php echo ucfirst($st['gender']); ?></small>
                            </td>
                            <td><?php echo e($st['class_name'] . ($st['section'] ? ' ' . $st['section'] : '')); ?></td>
                            <td><?php echo e($st['parent_name'] ?: 'N/A'); ?></td>
                            <td><?php echo e($st['parent_phone'] ?: $st['phone']); ?></td>
                            <td><span class="badge badge-secondary"><?php echo e($st['archive_year'] ?: 'Alumni'); ?></span></td>
                            <td>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Restore this student to active status?');">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="post_action" value="unarchive">
                                    <input type="hidden" name="student_id" value="<?php echo e($st['id']); ?>">
                                    <button type="submit" class="btn btn-sm btn-outline" title="Restore to active students">
                                        <i class="fas fa-undo"></i> Restore
                                    </button>
                                </form>
                                <a href="student-data?id=<?php echo e($st['id']); ?>" class="btn btn-sm btn-primary">
                                    <i class="fas fa-eye"></i> Dossier
                                </a>
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
