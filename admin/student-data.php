<?php
// admin/student-data.php - data-subject requests: export everything held about a pupil (JSON) or anonymise the record.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/helpers.php';
Security::requireRole('admin');

$db = db();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$st = $db->getRow('SELECT s.*, u.username, u.email, u.phone, u.first_name, u.last_name FROM students s JOIN users u ON s.user_id = u.id WHERE s.id = ?', [$id]);
if (!$st) { header('Location: students'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) { http_response_code(419); die('Invalid security token'); }
    if (($_POST['confirm'] ?? '') !== $st['admission_number']) {
        flash_redirect('Type the admission number exactly to confirm erasure', 'error', 'student-data?id=' . $id);
    }
    // Keep academic/financial rows for legal retention but remove identity
    $db->beginTransaction();
    $db->query("UPDATE users SET first_name = 'Erased', last_name = 'Pupil', email = CONCAT('erased-', id, '@invalid.local'), username = CONCAT('erased-', id),
                phone = NULL, profile_image = NULL, is_active = 0, password_hash = ?, totp_secret = NULL, totp_enabled = 0 WHERE id = ?",
               [Security::hashPassword(bin2hex(random_bytes(16))), $st['user_id']]);
    $db->query('UPDATE students SET date_of_birth = NULL, address = NULL, blood_group = NULL, medical_notes = NULL WHERE id = ?', [$id]);
    $db->commit();
    Security::logAudit('STUDENT_DATA_ERASED', 'students', $id);
    flash_redirect('Pupil record anonymised', 'success', 'students');
}

if (isset($_GET['download'])) {
    $out = [
        'exported_at' => date('c'),
        'student' => $st,
        'attendance' => $db->getRows('SELECT * FROM attendance WHERE student_id = ?', [$id]),
        'results' => $db->getRows('SELECT * FROM results WHERE student_id = ?', [$id]),
        'payments' => $db->getRows('SELECT * FROM payments WHERE student_id = ?', [$id]),
        'homework_submissions' => $db->getRows('SELECT * FROM homework_submissions WHERE student_id = ?', [$id]),
    ];
    unset($out['student']['password_hash']);
    Security::logAudit('STUDENT_DATA_EXPORTED', 'students', $id);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="student-' . $st['admission_number'] . '-data.json"');
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

$pageTitle = 'Pupil Data Request';
$extraCSS = ['admin.css', 'dashboard.css'];
include __DIR__ . '/../includes/header.php';
[$fm, $ft] = flash_get();
dashboard_open('admin', 'Data request: ' . $st['first_name'] . ' ' . $st['last_name']);
render_alert($fm, $ft);
?>
<div class="card"><div class="card-header"><h3>Export</h3></div><div class="card-body">
    <p>Download everything held about this pupil (profile, attendance, results, payments, homework) as a JSON file, e.g. to answer a parent's access request.</p>
    <a class="btn btn-primary" href="student-data?id=<?php echo $id; ?>&amp;download=1"><i class="fas fa-download"></i> Download data</a>
</div></div>
<div class="card"><div class="card-header"><h3>Erase</h3></div><div class="card-body">
    <p>Removes the pupil's name, contact details, date of birth, address and medical notes and disables the login. Results and payment rows are kept (anonymised) for legal retention. <strong>This cannot be undone.</strong></p>
    <form method="POST"><?php echo csrf_field(); ?><input type="hidden" name="id" value="<?php echo $id; ?>">
        <div class="form-group"><label for="confirm">Type the admission number (<code><?php echo e($st['admission_number']); ?></code>) to confirm</label>
        <input class="form-control" id="confirm" name="confirm" required autocomplete="off"></div>
        <button type="submit" class="btn btn-danger"><i class="fas fa-user-slash"></i> Erase personal data</button>
    </form>
</div></div>
<?php dashboard_close(); include __DIR__ . '/../includes/footer.php';
