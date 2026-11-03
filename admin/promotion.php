<?php
// admin/promotion.php - Batch Student Academic Promotion Module
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/SystemSchema.php';

Security::requireRole(['super_admin', 'admin', 'head_teacher']);
SystemSchema::ensureAll();

$db = db();
[$message, $messageType] = flash_get();

$allClasses = $db->getRows("SELECT id, class_name, section, academic_year FROM classes WHERE is_active = 1 ORDER BY class_name, section");

$fromClassId = (int)($_GET['from_class_id'] ?? 0);
$toClassId   = (int)($_GET['to_class_id'] ?? 0);
$nextSession = trim((string)($_GET['next_session'] ?? '2026/2027'));

// Handle Batch Promotion POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        flash_redirect('Invalid security token.', 'error');
    }

    $fromClass = (int)($_POST['from_class_id'] ?? 0);
    $toClass   = (int)($_POST['to_class_id'] ?? 0);
    $targetSession = trim((string)($_POST['next_session'] ?? '2026/2027'));
    $promotedStudents = $_POST['student_ids'] ?? [];

    if (!$fromClass || !$toClass) {
        flash_redirect('Please select both the Source Class and Target Class.', 'error');
    }
    if (empty($promotedStudents) || !is_array($promotedStudents)) {
        flash_redirect('Please select at least one student to promote.', 'warning');
    }

    try {
        $db->beginTransaction();
        $promotedCount = 0;

        foreach ($promotedStudents as $sId) {
            $sId = (int)$sId;
            if ($sId <= 0) continue;

            $curr = $db->getRow("SELECT class_id FROM students WHERE id = ?", [$sId]);
            $currClassId = $curr ? (int)$curr['class_id'] : $fromClass;

            // Update student class
            $db->query("UPDATE students SET class_id = ? WHERE id = ?", [$toClass, $sId]);

            // Record promotion log
            $db->insert(
                "INSERT INTO student_promotions (student_id, from_class_id, to_class_id, from_academic_year, to_academic_year, status, promoted_by)
                 VALUES (?, ?, ?, ?, ?, 'promoted', ?)",
                [$sId, $currClassId, $toClass, '2025/2026', $targetSession, $_SESSION['user_id']]
            );
            $promotedCount++;
        }

        $db->commit();
        Security::logAudit('BATCH_STUDENT_PROMOTION', 'students', null, null, [
            'count'      => $promotedCount,
            'from_class' => $fromClass,
            'to_class'   => $toClass,
            'session'    => $targetSession
        ]);

        flash_redirect("Successfully promoted {$promotedCount} student(s) to the selected class!", 'success', "promotion?from_class_id={$toClass}");
    } catch (Throwable $e) {
        $db->rollback();
        error_log("Promotion error: " . $e->getMessage());
        flash_redirect('Error executing promotion: ' . $e->getMessage(), 'error');
    }
}

// Get student list for the source class
$studentsToPromote = [];
if ($fromClassId > 0) {
    $studentsToPromote = $db->getRows(
        "SELECT s.id, s.admission_number, u.first_name, u.last_name, s.gender,
                (SELECT ROUND(AVG(score), 1) FROM results WHERE student_id = s.id) as avg_score
         FROM students s
         JOIN users u ON s.user_id = u.id
         WHERE s.class_id = ? AND u.is_active = 1
         ORDER BY u.first_name, u.last_name",
        [$fromClassId]
    );
}

$pageTitle = 'Student Academic Promotion';
$extraCSS = ['admin.css', 'dashboard.css'];
include __DIR__ . '/../includes/header.php';
dashboard_open('admin', 'Student Promotion');
render_alert($message, $messageType);
?>

<div class="card" style="margin-bottom:24px;">
    <div class="card-header">
        <h3><i class="fas fa-graduation-cap"></i> Select Classes &amp; Academic Session</h3>
    </div>
    <div class="card-body">
        <form method="GET" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:16px; align-items:end;">
            <div class="form-group" style="margin-bottom:0;">
                <label for="from_class_id"><strong>1. Promote From (Current Class) *</strong></label>
                <select name="from_class_id" id="from_class_id" class="form-control" required onchange="this.form.submit()">
                    <option value="">-- Select Source Class --</option>
                    <?php foreach ($allClasses as $c): ?>
                        <option value="<?php echo e($c['id']); ?>" <?php echo $fromClassId === (int)$c['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['class_name'] . ($c['section'] ? ' ' . $c['section'] : '')); ?> (<?php echo e($c['academic_year']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin-bottom:0;">
                <label for="to_class_id"><strong>2. Promote To (Target Class) *</strong></label>
                <select name="to_class_id" id="to_class_id" class="form-control" required>
                    <option value="">-- Select Target Class --</option>
                    <?php foreach ($allClasses as $c): ?>
                        <option value="<?php echo e($c['id']); ?>" <?php echo $toClassId === (int)$c['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['class_name'] . ($c['section'] ? ' ' . $c['section'] : '')); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin-bottom:0;">
                <label for="next_session"><strong>3. Target Session *</strong></label>
                <input type="text" name="next_session" id="next_session" class="form-control" value="<?php echo e($nextSession); ?>" placeholder="e.g. 2026/2027">
            </div>

            <div class="form-group" style="margin-bottom:0;">
                <button type="submit" class="btn btn-primary" style="width:100%;">
                    <i class="fas fa-users"></i> Load Students
                </button>
            </div>
        </form>
    </div>
</div>

<?php if ($fromClassId > 0): ?>
<form method="POST" action="">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="from_class_id" value="<?php echo e($fromClassId); ?>">
    <input type="hidden" name="to_class_id" value="<?php echo e($toClassId); ?>" id="post_to_class_id">
    <input type="hidden" name="next_session" value="<?php echo e($nextSession); ?>">

    <div class="card">
        <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <h3><i class="fas fa-user-check"></i> Eligible Students for Promotion (<?php echo count($studentsToPromote); ?>)</h3>
            <div>
                <button type="button" class="btn btn-sm btn-outline" onclick="toggleAllCheckboxes(true)">Select All</button>
                <button type="button" class="btn btn-sm btn-outline" onclick="toggleAllCheckboxes(false)">Deselect All</button>
            </div>
        </div>
        <div class="card-body">
            <?php if (empty($studentsToPromote)): ?>
                <p class="text-muted">No active students enrolled in this source class.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th style="width:40px;"><input type="checkbox" id="checkMaster" checked onclick="toggleAllCheckboxes(this.checked)"></th>
                                <th>Adm No.</th>
                                <th>Student Full Name</th>
                                <th>Gender</th>
                                <th>Average Score</th>
                                <th>Status Recommendation</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($studentsToPromote as $s):
                                $avg = (float)($s['avg_score'] ?? 0);
                                $isPass = ($avg >= 50.0 || $avg == 0);
                            ?>
                            <tr>
                                <td>
                                    <input type="checkbox" name="student_ids[]" value="<?php echo e($s['id']); ?>" class="promo-chk" checked>
                                </td>
                                <td><strong><?php echo e($s['admission_number']); ?></strong></td>
                                <td><strong><?php echo e($s['first_name'] . ' ' . $s['last_name']); ?></strong></td>
                                <td><?php echo ucfirst($s['gender']); ?></td>
                                <td><?php echo $avg > 0 ? "<strong>{$avg}%</strong>" : '<span class="text-muted">No exams yet</span>'; ?></td>
                                <td>
                                    <span class="badge badge-<?php echo $isPass ? 'success' : 'warning'; ?>">
                                        <?php echo $isPass ? 'Promote' : 'Review / Repeat'; ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div style="margin-top:20px; display:flex; justify-content:flex-end;">
                    <button type="submit" class="btn btn-primary" onclick="return syncTargetClass();" style="font-weight:700; padding:12px 24px;">
                        <i class="fas fa-check-double"></i> Batch Promote Selected Students
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>
</form>

<script nonce="<?php echo CSP_NONCE; ?>">
function toggleAllCheckboxes(val) {
    document.querySelectorAll('.promo-chk').forEach(c => c.checked = val);
    const m = document.getElementById('checkMaster');
    if (m) m.checked = val;
}
function syncTargetClass() {
    const toSel = document.getElementById('to_class_id');
    const hiddenTo = document.getElementById('post_to_class_id');
    if (!toSel || !toSel.value) {
        alert('Please select a Target Class before promoting!');
        return false;
    }
    hiddenTo.value = toSel.value;
    return confirm('Are you sure you want to promote the selected students to the target class?');
}
</script>
<?php endif; ?>

<?php
dashboard_close();
include __DIR__ . '/../includes/footer.php';
