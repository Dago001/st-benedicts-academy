<?php
// admin/applications.php - Review admission applications & verify all online/bank payments at the backend
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/ApplicationHelper.php';
require_once __DIR__ . '/../includes/paystack.php';
Security::requireRole('admin');

ApplicationHelper::ensureSchema();

$db = db();
$statuses = ['pending', 'reviewing', 'accepted', 'rejected'];

// Secure document download: ?file=app|enq&id=N&doc=birth|photo|...
if (isset($_GET['file'])) {
    $id = (int)($_GET['id'] ?? 0);
    $path = null;
    if ($_GET['file'] === 'app') {
        $row = $db->getRow('SELECT birth_certificate_path, passport_photo_path, documents_path FROM applications WHERE id = ?', [$id]);
        $docKey = $_GET['doc'] ?? '';
        $docs = ($row && !empty($row['documents_path'])) ? (json_decode($row['documents_path'], true) ?: []) : [];
        if (isset($docs[$docKey])) {
            $name = $docs[$docKey];
        } elseif ($docKey === 'photo' || $docKey === 'passport_photo') {
            $name = $row['passport_photo_path'] ?? null;
        } else {
            $name = $row['birth_certificate_path'] ?? null;
        }
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
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        flash_redirect('Invalid security token', 'error');
    }

    $postAction = $_POST['post_action'] ?? 'review_application';

    // Action 1: Live Verify Payment Reference on Paystack API
    if ($postAction === 'verify_paystack') {
        $reference = trim((string)($_POST['payment_reference'] ?? ''));
        if ($reference === '') {
            flash_redirect('Please provide a payment reference to verify.', 'error');
        }
        $verifyRes = paystack_verify_transaction($reference);
        ApplicationHelper::recordPaymentVerification($reference, $verifyRes, (int)($_SESSION['user_id'] ?? 0));
        Security::logAudit('VERIFIED_PAYSTACK_PAYMENT', 'application_payments', null, null, [
            'reference' => $reference,
            'success'   => $verifyRes['success'] ?? false,
            'status'    => $verifyRes['status'] ?? ''
        ]);

        if (!empty($verifyRes['success'])) {
            $amtFormatted = '₦' . number_format((float)($verifyRes['amount'] ?? ApplicationHelper::APPLICATION_FEE), 2);
            flash_redirect("Paystack Verification Successful for {$reference} ({$amtFormatted}) — Marked as VERIFIED.", 'success');
        } else {
            $errMsg = $verifyRes['message'] ?? 'Could not verify reference on Paystack.';
            flash_redirect("Paystack check for {$reference}: {$errMsg}", 'warning');
        }
    }

    // Action 2: Manual Admin Payment Status Update (e.g. Confirming Zenith Bank Transfer or Manual Approval)
    if ($postAction === 'update_payment_status') {
        $reference = trim((string)($_POST['payment_reference'] ?? ''));
        $newPayStatus = trim((string)($_POST['payment_status'] ?? 'verified'));
        if ($reference === '' || !ApplicationHelper::updatePaymentStatus($reference, $newPayStatus, (int)($_SESSION['user_id'] ?? 0))) {
            flash_redirect('Could not update payment status. Please check the reference.', 'error');
        }
        Security::logAudit('UPDATED_APPLICATION_PAYMENT_STATUS', 'application_payments', null, null, [
            'reference' => $reference,
            'payment_status' => $newPayStatus
        ]);
        flash_redirect("Payment {$reference} status updated to " . strtoupper(str_replace('_', ' ', $newPayStatus)) . '.', 'success');
    }

    // Action 3: Standard Application Status & Remarks Update
    $kind = $_POST['kind'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? '';
    $remarks = mb_substr(Security::sanitize($_POST['remarks'] ?? ''), 0, 1000);
    $table = ['app' => 'applications', 'enq' => 'admissions'][$kind] ?? null;
    if (!$table || !$id || !in_array($status, $statuses, true)) {
        flash_redirect('Invalid request', 'error');
    }
    $db->query("UPDATE $table SET status = ?, remarks = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?", [$status, $remarks, $_SESSION['user_id'], $id]);
    Security::logAudit('REVIEWED_APPLICATION', $table, $id, null, ['status' => $status]);
    flash_redirect('Application updated', 'success');
}

// Fetch unified payments ledger (also backfills any existing rows from `admissions` & `applications`)
$paymentsLedger = ApplicationHelper::getAllPaymentsLedger(200);

$filter = in_array($_GET['status'] ?? '', $statuses, true) ? $_GET['status'] : '';
$where = $filter ? 'WHERE status = ?' : '';
$params = $filter ? [$filter] : [];
$apps = $db->getRows("SELECT id, application_number, CONCAT(child_first_name, ' ', child_last_name) AS child, child_dob, child_gender, class_applying AS class_name,
        CONCAT(parent_first_name, ' ', parent_last_name) AS parent_name, parent_email, parent_phone, address, city, state, lga, country, previous_school,
        reason_applying, birth_certificate_path, passport_photo_path, documents_path,
        payment_method, receipt_number, payment_reference, amount_paid, payment_status, paid_at, status, remarks, created_at
        FROM applications $where ORDER BY created_at DESC LIMIT 200", $params);
$enquiries = $db->getRows("SELECT id, application_number, CONCAT(first_name, ' ', last_name) AS child, date_of_birth AS child_dob, gender AS child_gender,
        class_applying_for AS class_name, parent_name, parent_email, parent_phone, address, city, state, lga, country, previous_school, documents_path,
        payment_method, receipt_number, payment_reference, amount_paid, payment_status, paid_at, status, remarks, submitted_at AS created_at
        FROM admissions $where ORDER BY submitted_at DESC LIMIT 200", $params);

// Calculate payment KPI metrics
$totalLedgerCount = count($paymentsLedger);
$verifiedCount = 0;
$verifiedTotalAmount = 0.0;
$pendingVerifyCount = 0;
foreach ($paymentsLedger as $pRow) {
    $st = strtolower((string)($pRow['payment_status'] ?? ''));
    if (in_array($st, ['verified', 'paid'], true)) {
        $verifiedCount++;
        $verifiedTotalAmount += (float)($pRow['amount'] ?? ApplicationHelper::APPLICATION_FEE);
    } else {
        $pendingVerifyCount++;
    }
}

$pageTitle = 'Admission Applications & Payment Verification';
$extraCSS = ['admin.css', 'dashboard.css'];
include __DIR__ . '/../includes/header.php';
dashboard_open('admin', 'Admission Applications & Payments');
render_alert($message, $messageType);
?>

<style>
.payment-kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
    margin-bottom: 20px;
}
.payment-kpi-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 18px 20px;
    box-shadow: 0 2px 10px rgba(15, 23, 42, 0.04);
}
.payment-kpi-card .kpi-label {
    font-size: 0.78rem;
    font-weight: 700;
    text-transform: uppercase;
    color: #64748b;
    letter-spacing: 0.5px;
    margin-bottom: 6px;
}
.payment-kpi-card .kpi-val {
    font-size: 1.5rem;
    font-weight: 800;
    color: #002147;
}
.payment-kpi-card .kpi-sub {
    font-size: 0.8rem;
    color: #475569;
    margin-top: 4px;
}
.verify-bar-card {
    background: #002147;
    color: #ffffff;
    border-radius: 12px;
    padding: 18px 22px;
    margin-bottom: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
}
.verify-bar-form {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.verify-bar-form input[type="text"] {
    padding: 10px 14px;
    border-radius: 8px;
    border: 1px solid #cbd5e1;
    min-width: 260px;
    font-size: 0.9rem;
    color: #0f172a;
}
.ledger-table-wrap {
    overflow-x: auto;
}
.ledger-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.88rem;
}
.ledger-table th {
    background: #f8fafc;
    color: #002147;
    font-weight: 700;
    text-align: left;
    padding: 12px 14px;
    border-bottom: 2px solid #e2e8f0;
    white-space: nowrap;
}
.ledger-table td {
    padding: 12px 14px;
    border-bottom: 1px solid #e2e8f0;
    vertical-align: middle;
}
.ledger-actions {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}
.pay-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
}
.pay-pill.verified, .pay-pill.paid {
    background: #dcfce7;
    color: #15803d;
}
.pay-pill.pending_verification, .pay-pill.pending, .pay-pill.initialized {
    background: #fef3c7;
    color: #b45309;
}
.pay-pill.failed, .pay-pill.refunded {
    background: #fee2e2;
    color: #b91c1c;
}
</style>

<!-- Backend Payment KPI Summary -->
<div class="payment-kpi-grid">
    <div class="payment-kpi-card">
        <div class="kpi-label"><i class="fas fa-receipt"></i> Total Payment Records</div>
        <div class="kpi-val"><?php echo (int)$totalLedgerCount; ?></div>
        <div class="kpi-sub">All Paystack &amp; Bank Transfer transactions</div>
    </div>
    <div class="payment-kpi-card">
        <div class="kpi-label"><i class="fas fa-check-circle" style="color:#16a34a;"></i> Verified / Paid Payments</div>
        <div class="kpi-val" style="color:#15803d;">₦<?php echo number_format($verifiedTotalAmount, 2); ?></div>
        <div class="kpi-sub"><?php echo (int)$verifiedCount; ?> confirmed payment(s)</div>
    </div>
    <div class="payment-kpi-card">
        <div class="kpi-label"><i class="fas fa-clock" style="color:#d97706;"></i> Pending Verification</div>
        <div class="kpi-val" style="color:#b45309;"><?php echo (int)$pendingVerifyCount; ?></div>
        <div class="kpi-sub">Awaiting Paystack or Bank confirmation</div>
    </div>
    <div class="payment-kpi-card">
        <div class="kpi-label"><i class="fas fa-university"></i> School Bank &amp; Gateway</div>
        <div class="kpi-val" style="font-size:1.05rem;"><?php echo e(school_bank_name()); ?> &bull; <?php echo e(school_account_number()); ?></div>
        <div class="kpi-sub"><?php echo e(school_account_name()); ?></div>
    </div>
</div>

<!-- Instant Paystack Reference Verification Bar -->
<div class="verify-bar-card">
    <div>
        <h3 style="color:#FFD700; margin:0 0 4px; font-size:1.05rem;"><i class="fas fa-shield-alt"></i> Verify Any Payment Reference on Paystack</h3>
        <p style="margin:0; font-size:0.84rem; color:rgba(255,255,255,0.85);">Enter any Paystack Transaction Reference (`PSTK-STB-...`) to query Paystack live and update its backend status automatically.</p>
    </div>
    <form method="POST" class="verify-bar-form">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="post_action" value="verify_paystack">
        <input type="text" name="payment_reference" placeholder="Enter Reference (e.g. PSTK-STB-2026...)" required>
        <button type="submit" class="btn btn-primary" style="background:#FFD700; color:#002147; border:none; font-weight:700;">
            <i class="fas fa-sync-alt"></i> Verify Live on Paystack
        </button>
    </form>
</div>

<!-- Unified Online & Bank Application Payments Ledger -->
<div class="card" id="payments-ledger">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <h3><i class="fas fa-credit-card"></i> All Application &amp; Online Payments Ledger (<?php echo count($paymentsLedger); ?>)</h3>
        <span class="text-muted" style="font-size:0.84rem;">Shows all Paystack checkouts &amp; Zenith Bank transfers (`<?php echo e(school_account_number()); ?>`)</span>
    </div>
    <div class="card-body">
        <?php if (empty($paymentsLedger)): ?>
            <p class="text-muted">No application payment transactions recorded yet.</p>
        <?php else: ?>
            <div class="ledger-table-wrap">
                <table class="ledger-table">
                    <thead>
                        <tr>
                            <th>Reference / Receipt</th>
                            <th>Application No.</th>
                            <th>Candidate &amp; Parent</th>
                            <th>Channel / Method</th>
                            <th>Amount</th>
                            <th>Payment Status</th>
                            <th>Date</th>
                            <th>Backend Verification Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($paymentsLedger as $p):
                            $pRef = (string)($p['payment_reference'] ?? '');
                            $pAppNo = (string)($p['application_number'] ?? '');
                            $pRcpNo = (string)($p['receipt_number'] ?? '');
                            $pStatus = strtolower((string)($p['payment_status'] ?? 'pending'));
                            $isVerified = in_array($pStatus, ['verified', 'paid'], true);
                            $pAmt = (float)($p['amount'] ?? ApplicationHelper::APPLICATION_FEE);
                            $pDate = $p['paid_at'] ?: $p['created_at'];
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo e($pRef); ?></strong>
                                <?php if ($pRcpNo !== ''): ?>
                                    <br><small class="text-muted">Receipt: <?php echo e($pRcpNo); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($pAppNo !== ''): ?>
                                    <strong><?php echo e($pAppNo); ?></strong>
                                <?php else: ?>
                                    <span class="text-muted">Checkout Initialized</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?php echo e($p['child_name'] ?: 'Pending Submission'); ?></strong>
                                <?php if (!empty($p['parent_name']) || !empty($p['parent_email'])): ?>
                                    <br><small class="text-muted"><?php echo e(trim(($p['parent_name'] ?? '') . ' (' . ($p['parent_email'] ?? '') . ')')); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo e(ApplicationHelper::formatPaymentMethod($p['payment_channel'] ?: $p['payment_method'])); ?>
                                <?php if (!empty($p['gateway_response'])): ?>
                                    <br><small class="text-muted"><?php echo e($p['gateway_response']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><strong>₦<?php echo number_format($pAmt, 2); ?></strong></td>
                            <td>
                                <span class="pay-pill <?php echo e($pStatus); ?>">
                                    <i class="fas <?php echo $isVerified ? 'fa-check-circle' : 'fa-clock'; ?>"></i>
                                    <?php echo e(strtoupper(str_replace('_', ' ', $pStatus))); ?>
                                </span>
                            </td>
                            <td><?php echo $pDate ? e(formatDate($pDate, 'd M Y H:i')) : '-'; ?></td>
                            <td>
                                <div class="ledger-actions">
                                    <form method="POST" style="display:inline;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="post_action" value="verify_paystack">
                                        <input type="hidden" name="payment_reference" value="<?php echo e($pRef); ?>">
                                        <button type="submit" class="btn btn-sm btn-outline" title="Query Paystack API Live">
                                            <i class="fas fa-sync-alt"></i> Verify on Paystack
                                        </button>
                                    </form>

                                    <?php if (!$isVerified): ?>
                                    <form method="POST" style="display:inline;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="post_action" value="update_payment_status">
                                        <input type="hidden" name="payment_reference" value="<?php echo e($pRef); ?>">
                                        <input type="hidden" name="payment_status" value="verified">
                                        <button type="submit" class="btn btn-sm btn-primary" title="Confirm Bank Transfer / Mark Verified">
                                            <i class="fas fa-check"></i> Mark Verified
                                        </button>
                                    </form>
                                    <?php else: ?>
                                    <form method="POST" style="display:inline;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="post_action" value="update_payment_status">
                                        <input type="hidden" name="payment_reference" value="<?php echo e($pRef); ?>">
                                        <input type="hidden" name="payment_status" value="pending_verification">
                                        <button type="submit" class="btn btn-sm btn-outline" title="Set back to Pending Verification">
                                            <i class="fas fa-undo"></i> Mark Pending
                                        </button>
                                    </form>
                                    <?php endif; ?>

                                    <?php if ($pAppNo !== ''): ?>
                                    <a class="btn btn-sm btn-outline" target="_blank" rel="noopener" href="<?php echo BASE_URL; ?>/public/application-receipt?app=<?php echo urlencode($pAppNo); ?>" title="View Official Receipt">
                                        <i class="fas fa-print"></i> Receipt
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card"><div class="card-body form-inline">
    <strong>Filter Applications by Status:</strong>
    <a class="btn btn-sm <?php echo $filter === '' ? 'btn-primary' : 'btn-outline'; ?>" href="applications">All</a>
    <?php foreach ($statuses as $s): ?><a class="btn btn-sm <?php echo $filter === $s ? 'btn-primary' : 'btn-outline'; ?>" href="applications?status=<?php echo e($s); ?>"><?php echo e(ucfirst($s)); ?></a><?php endforeach; ?>
</div></div>

<?php
$sections = [['Online applications', 'app', $apps], ['Admission enquiries & portal applications', 'enq', $enquiries]];
foreach ($sections as [$title, $kind, $rows]): ?>
<div class="card"><div class="card-header"><h3><?php echo e($title); ?> (<?php echo count($rows); ?>)</h3></div><div class="card-body">
<?php if (!$rows): ?><p class="text-muted">Nothing here.</p><?php endif; ?>
<?php foreach ($rows as $a):
    $payStatus = strtolower($a['payment_status'] ?? 'paid');
    $isPayVerified = in_array($payStatus, ['paid', 'verified'], true);
    $amtPaid = (float)($a['amount_paid'] ?? ApplicationHelper::APPLICATION_FEE);
    $payRef = $a['payment_reference'] ?? '';
?>
    <details class="app-item">
        <summary>
            <strong><?php echo e($a['child']); ?></strong> &middot; <?php echo e($a['class_name']); ?>
            <span class="badge badge-<?php echo ['pending' => 'warning', 'reviewing' => 'info', 'accepted' => 'success', 'rejected' => 'danger'][$a['status']] ?? 'info'; ?>"><?php echo e(ucfirst($a['status'])); ?></span>
            <span class="badge badge-<?php echo $isPayVerified ? 'success' : 'warning'; ?>">
                <?php echo $isPayVerified ? 'Payment Verified (₦' . number_format($amtPaid) . ')' : 'Pending Verification (₦' . number_format($amtPaid) . ')'; ?>
            </span>
            <small class="text-muted"><?php echo e($a['application_number']); ?> &middot; Ref: <?php echo e($payRef ?: '-'); ?> &middot; <?php echo e(formatDate($a['created_at'], 'd M Y')); ?></small>
        </summary>
        <div class="detail-grid">
            <?php foreach ([
                'Date of birth'     => formatDate($a['child_dob'], 'd M Y'),
                'Gender'            => ucfirst((string)$a['child_gender']),
                'Parent'            => $a['parent_name'],
                'Email'             => $a['parent_email'],
                'Phone'             => $a['parent_phone'],
                'Address'           => trim($a['address'] . ' ' . ($a['city'] ?? '')),
                'Location'          => location_label($a['country'] ?? '', $a['state'] ?? '', $a['lga'] ?? ''),
                'Previous school'   => $a['previous_school'],
                'Payment Method'    => ApplicationHelper::formatPaymentMethod($a['payment_method'] ?? 'paystack_card'),
                'Receipt Number'    => $a['receipt_number'] ?? '-',
                'Payment Reference' => $payRef ?: '-',
                'Payment Status'    => strtoupper(str_replace('_', ' ', $payStatus)),
                'Amount Paid'       => '₦' . number_format($amtPaid, 2),
                'Reason'            => $a['reason_applying'] ?? ''
            ] as $l => $v): ?>
            <div class="detail-row"><span class="detail-label"><?php echo e($l); ?></span><span class="detail-value"><?php echo e($v !== '' && $v !== null ? $v : '-'); ?></span></div>
            <?php endforeach; ?>
        </div>
        <div style="display:flex; flex-wrap:wrap; gap:8px; margin-top:12px; margin-bottom:12px; align-items:center;">
            <?php
            $docs = !empty($a['documents_path']) ? (json_decode($a['documents_path'], true) ?: []) : [];
            if ($kind === 'app') {
                if (empty($docs['birth_certificate']) && !empty($a['birth_certificate_path'])) {
                    $docs['birth_certificate'] = $a['birth_certificate_path'];
                }
                if (empty($docs['passport_photo']) && !empty($a['passport_photo_path'])) {
                    $docs['passport_photo'] = $a['passport_photo_path'];
                }
            }
            foreach ($docs as $key => $file):
                if (!$file) continue;
            ?>
                <a class="btn btn-sm btn-outline" target="_blank" rel="noopener" href="?file=<?php echo e($kind); ?>&id=<?php echo (int)$a['id']; ?>&doc=<?php echo urlencode($key); ?>">
                    <i class="fas fa-file-alt"></i> <?php echo e(ucwords(str_replace('_', ' ', $key))); ?>
                </a>
            <?php endforeach; ?>
            <a class="btn btn-sm btn-primary" target="_blank" rel="noopener" href="<?php echo BASE_URL; ?>/public/application-receipt?app=<?php echo urlencode($a['application_number']); ?>">
                <i class="fas fa-print"></i> View / Print Receipt
            </a>
            <?php if ($payRef !== ''): ?>
                <form method="POST" style="display:inline;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="post_action" value="verify_paystack">
                    <input type="hidden" name="payment_reference" value="<?php echo e($payRef); ?>">
                    <button type="submit" class="btn btn-sm btn-outline">
                        <i class="fas fa-sync-alt"></i> Verify Reference on Paystack
                    </button>
                </form>
                <?php if (!$isPayVerified): ?>
                <form method="POST" style="display:inline;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="post_action" value="update_payment_status">
                    <input type="hidden" name="payment_reference" value="<?php echo e($payRef); ?>">
                    <input type="hidden" name="payment_status" value="verified">
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="fas fa-check-circle"></i> Approve / Mark Payment Verified
                    </button>
                </form>
                <?php endif; ?>
            <?php endif; ?>
            <a class="btn btn-sm btn-outline" href="mailto:<?php echo e($a['parent_email']); ?>"><i class="fas fa-envelope"></i> Email parent</a>
        </div>
        <form method="POST" class="form-row">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="post_action" value="review_application">
            <input type="hidden" name="kind" value="<?php echo e($kind); ?>">
            <input type="hidden" name="id" value="<?php echo (int)$a['id']; ?>">
            <div class="form-group"><label>Application Status</label><select name="status" class="form-control"><?php foreach ($statuses as $s): ?><option value="<?php echo e($s); ?>" <?php echo $a['status'] === $s ? 'selected' : ''; ?>><?php echo e(ucfirst($s)); ?></option><?php endforeach; ?></select></div>
            <div class="form-group" style="flex:2"><label>Remarks</label><input type="text" name="remarks" class="form-control" maxlength="1000" value="<?php echo e($a['remarks']); ?>"></div>
            <div class="form-group form-actions"><button type="submit" class="btn btn-primary">Save</button></div>
        </form>
    </details>
<?php endforeach; ?>
</div></div>
<?php endforeach;
dashboard_close();
include __DIR__ . '/../includes/footer.php';
