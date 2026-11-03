<?php
/**
 * Official Admission Application & Fee Payment Receipt Generator
 * Supports direct receipt link (?app=APP-...&token=...), Admin view, and Parent self-service lookup.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/ApplicationHelper.php';

ApplicationHelper::ensureSchema();

$appQuery = trim($_GET['app'] ?? $_POST['app_query'] ?? '');
$token    = trim($_GET['token'] ?? '');
$verifyContact = trim($_POST['verify_contact'] ?? '');
$lookupError = '';
$application = null;

if ($appQuery !== '') {
    $found = ApplicationHelper::findApplication($appQuery);
    if ($found) {
        $isAdmin = Security::isLoggedIn() && Security::hasRole('admin');
        $expectedToken = $found['receipt_token'];
        $sessionAllowed = isset($_SESSION['recent_application_receipts']) && in_array($found['application_number'], $_SESSION['recent_application_receipts'], true);

        // Contact match for self-service lookup
        $contactMatch = false;
        if ($verifyContact !== '') {
            $cleanVerify = strtolower(preg_replace('/\s+/', '', $verifyContact));
            $cleanEmail  = strtolower(trim($found['parent_email']));
            $cleanPhone  = preg_replace('/\D+/', '', $found['parent_phone']);
            $cleanVerifyDigits = preg_replace('/\D+/', '', $verifyContact);

            if ($cleanVerify === $cleanEmail || ($cleanVerifyDigits !== '' && strlen($cleanVerifyDigits) >= 7 && strpos($cleanPhone, substr($cleanVerifyDigits, -7)) !== false)) {
                $contactMatch = true;
            }
        }

        if ($isAdmin || $sessionAllowed || ($token !== '' && hash_equals($expectedToken, $token)) || $contactMatch) {
            $application = $found;
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $lookupError = 'The Parent Email or Phone Number entered does not match this Application / Receipt Number.';
        } else {
            // If accessed via GET with ?app= without token, prompt to verify email/phone or show if token omitted in local preview
            $lookupError = 'Please enter the Parent Email or Phone Number used during application to view and print your official receipt.';
        }
    } else {
        $lookupError = 'No application or receipt was found matching "' . htmlspecialchars($appQuery) . '". Please check your Application or Receipt Number.';
    }
}

$docCatalog = ApplicationHelper::getDocumentCatalog();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $application ? 'Official Receipt #' . htmlspecialchars($application['receipt_number']) : 'Print Admission Receipt'; ?> - <?php echo SITE_NAME; ?></title>
    <link rel="icon" type="image/png" href="<?php echo BASE_URL; ?>/assets/images/logo.png">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/fonts.css?v=27">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/vendor/fontawesome/all.min.css?v=1">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #f1f5f9;
            color: #1e293b;
            padding: 28px 16px 60px;
            line-height: 1.5;
        }
        .receipt-toolbar {
            max-width: 860px;
            margin: 0 auto 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            background: #002147;
            color: #fff;
            padding: 14px 22px;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0, 33, 71, 0.15);
        }
        .receipt-toolbar a, .receipt-toolbar button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.9rem;
            text-decoration: none;
            cursor: pointer;
            border: none;
            transition: all 0.2s ease;
        }
        .btn-back {
            background: rgba(255, 255, 255, 0.12);
            color: #fff;
        }
        .btn-back:hover { background: rgba(255, 255, 255, 0.22); }
        .btn-print {
            background: #FFD700;
            color: #002147;
            font-weight: 700;
        }
        .btn-print:hover { background: #facc15; transform: translateY(-1px); }

        .receipt-sheet {
            max-width: 860px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 20px 50px rgba(15, 23, 42, 0.08);
            overflow: hidden;
            position: relative;
        }
        .receipt-top-stripe {
            height: 8px;
            background: linear-gradient(90deg, #002147 0%, #c8102e 50%, #FFD700 100%);
        }
        .receipt-header {
            padding: 30px 36px 24px;
            border-bottom: 2px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 20px;
            flex-wrap: wrap;
        }
        .school-brand {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .school-brand img {
            width: 72px;
            height: 72px;
            object-fit: contain;
        }
        .school-brand h1 {
            font-family: 'Poppins', sans-serif;
            font-size: 1.25rem;
            color: #002147;
            line-height: 1.25;
            letter-spacing: -0.3px;
        }
        .school-brand .motto {
            font-size: 0.8rem;
            color: #c8102e;
            font-style: italic;
            font-weight: 600;
            margin-top: 2px;
        }
        .school-brand .contact-line {
            font-size: 0.78rem;
            color: #64748b;
            margin-top: 4px;
        }
        .receipt-badge-box {
            text-align: right;
        }
        .receipt-title-pill {
            display: inline-block;
            background: #002147;
            color: #FFD700;
            padding: 6px 14px;
            border-radius: 6px;
            font-size: 0.78rem;
            font-weight: 800;
            letter-spacing: 1px;
            text-transform: uppercase;
        }
        .receipt-no {
            font-family: 'Poppins', sans-serif;
            font-size: 1.1rem;
            font-weight: 700;
            color: #0f172a;
            margin-top: 8px;
        }
        .receipt-date {
            font-size: 0.82rem;
            color: #64748b;
            margin-top: 2px;
        }
        .status-stamp {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 8px;
            padding: 4px 12px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.6px;
        }
        .status-stamp.paid {
            background: #dcfce7;
            color: #15803d;
            border: 1px solid #86efac;
        }
        .status-stamp.pending {
            background: #fef9c3;
            color: #a16207;
            border: 1px solid #fde047;
        }

        .receipt-body {
            padding: 28px 36px 36px;
        }
        .meta-summary-bar {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 26px;
        }
        .meta-item .label {
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: #64748b;
            font-weight: 700;
        }
        .meta-item .val {
            font-size: 0.95rem;
            font-weight: 700;
            color: #002147;
            margin-top: 3px;
            word-break: break-word;
        }

        .section-heading {
            font-size: 0.85rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #002147;
            padding-bottom: 8px;
            border-bottom: 2px solid #e2e8f0;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .section-heading i {
            color: #c8102e;
        }

        .info-grid-2 {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 24px;
            margin-bottom: 26px;
        }
        .info-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 18px 20px;
        }
        .kv-row {
            display: flex;
            justify-content: space-between;
            padding: 7px 0;
            border-bottom: 1px dashed #e2e8f0;
            font-size: 0.88rem;
            gap: 12px;
        }
        .kv-row:last-child { border-bottom: none; }
        .kv-row .k { color: #64748b; font-weight: 500; }
        .kv-row .v { color: #0f172a; font-weight: 600; text-align: right; }

        .fee-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 26px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            overflow: hidden;
        }
        .fee-table th {
            background: #002147;
            color: #ffffff;
            text-align: left;
            padding: 12px 16px;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .fee-table td {
            padding: 14px 16px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.92rem;
        }
        .fee-table tr.total-row td {
            background: #f8fafc;
            font-weight: 800;
            font-size: 1.05rem;
            color: #002147;
        }

        .docs-chips {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
            gap: 10px;
            margin-bottom: 26px;
        }
        .doc-chip {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            font-size: 0.84rem;
            font-weight: 600;
        }
        .doc-chip.uploaded {
            background: #f0fdf4;
            border-color: #bbf7d0;
            color: #166534;
        }
        .doc-chip.missing {
            color: #64748b;
        }

        .receipt-footer {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 20px;
            padding-top: 22px;
            border-top: 2px solid #e2e8f0;
            flex-wrap: wrap;
        }
        .receipt-note {
            max-width: 480px;
            font-size: 0.8rem;
            color: #64748b;
        }
        .signature-block {
            text-align: center;
            min-width: 200px;
        }
        .signature-line {
            font-family: 'Georgia', serif;
            font-style: italic;
            font-size: 1.05rem;
            color: #002147;
            border-bottom: 1px solid #94a3b8;
            padding-bottom: 4px;
            margin-bottom: 4px;
        }
        .signature-label {
            font-size: 0.75rem;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
        }

        /* Lookup Card */
        .lookup-card {
            max-width: 540px;
            margin: 40px auto;
            background: #fff;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            padding: 32px;
            box-shadow: 0 15px 35px rgba(15, 23, 42, 0.08);
        }
        .lookup-card h2 {
            color: #002147;
            font-family: 'Poppins', sans-serif;
            margin-bottom: 8px;
        }
        .lookup-card p {
            color: #64748b;
            font-size: 0.92rem;
            margin-bottom: 20px;
        }
        .form-group { margin-bottom: 16px; }
        .form-group label {
            display: block;
            font-weight: 600;
            font-size: 0.88rem;
            margin-bottom: 6px;
            color: #1e293b;
        }
        .form-control {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 0.95rem;
        }
        .btn-lookup {
            width: 100%;
            padding: 13px;
            background: #002147;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-weight: 700;
            font-size: 0.95rem;
            cursor: pointer;
        }
        .btn-lookup:hover { background: #003366; }
        .error-box {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #b91c1c;
            padding: 12px 14px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 0.88rem;
        }

        @media (max-width: 768px) {
            .meta-summary-bar { grid-template-columns: repeat(2, 1fr); }
            .info-grid-2 { grid-template-columns: 1fr; }
            .receipt-header, .receipt-body { padding: 20px; }
            .receipt-badge-box { text-align: left; }
        }
        @media print {
            body { background: #fff; padding: 0; }
            .receipt-toolbar { display: none !important; }
            .receipt-sheet { box-shadow: none; border: 1px solid #cbd5e1; border-radius: 0; max-width: 100%; }
        }
    </style>
</head>
<body>

<div class="receipt-toolbar">
    <div style="display:flex; align-items:center; gap:10px;">
        <a href="<?php echo BASE_URL; ?>/public/admissions" class="btn-back">
            <i class="fas fa-arrow-left"></i> Back to Admissions
        </a>
        <?php if (Security::isLoggedIn() && Security::hasRole('admin')): ?>
            <a href="<?php echo BASE_URL; ?>/admin/applications" class="btn-back">
                <i class="fas fa-th-list"></i> Admin Applications
            </a>
        <?php endif; ?>
    </div>
    <?php if ($application): ?>
    <div style="display:flex; align-items:center; gap:10px;">
        <button type="button" onclick="window.print()" class="btn-print">
            <i class="fas fa-print"></i> Print / Save Receipt as PDF
        </button>
    </div>
    <?php endif; ?>
</div>

<?php if ($application): ?>
    <div class="receipt-sheet">
        <div class="receipt-top-stripe"></div>

        <div class="receipt-header">
            <div class="school-brand">
                <img src="<?php echo BASE_URL; ?>/assets/images/logo.png" alt="Crest">
                <div>
                    <h1>ST. BENEDICT'S EARLY YEARS<br>BRITISH ACADEMY</h1>
                    <div class="motto"><?php echo SCHOOL_MOTTO; ?></div>
                    <div class="contact-line">
                        <i class="fas fa-map-marker-alt"></i> <?php echo SCHOOL_ADDRESS; ?> &bull;
                        <i class="fas fa-phone"></i> <?php echo SCHOOL_PHONE; ?>
                    </div>
                </div>
            </div>

            <div class="receipt-badge-box">
                <span class="receipt-title-pill">Official Application &amp; Fee Receipt</span>
                <div class="receipt-no"><?php echo htmlspecialchars($application['receipt_number']); ?></div>
                <div class="receipt-date">Issued: <?php echo date('d M Y, h:i A', strtotime($application['paid_at'])); ?></div>
                <?php $isPaid = strtolower($application['payment_status']) === 'paid'; ?>
                <div class="status-stamp <?php echo $isPaid ? 'paid' : 'pending'; ?>">
                    <i class="fas <?php echo $isPaid ? 'fa-check-circle' : 'fa-clock'; ?>"></i>
                    <?php echo $isPaid ? 'PAYMENT VERIFIED - PAID' : 'VERIFICATION PENDING'; ?>
                </div>
            </div>
        </div>

        <div class="receipt-body">
            <div class="meta-summary-bar">
                <div class="meta-item">
                    <div class="label">Application Number</div>
                    <div class="val"><?php echo htmlspecialchars($application['application_number']); ?></div>
                </div>
                <div class="meta-item">
                    <div class="label">Transaction Reference</div>
                    <div class="val"><?php echo htmlspecialchars($application['payment_reference']); ?></div>
                </div>
                <div class="meta-item">
                    <div class="label">Academic Session</div>
                    <div class="val"><?php echo htmlspecialchars($application['academic_session'] . ' (' . $application['entry_term'] . ')'); ?></div>
                </div>
                <div class="meta-item">
                    <div class="label">Application Status</div>
                    <div class="val"><?php echo ucwords(str_replace('_', ' ', htmlspecialchars($application['status']))); ?></div>
                </div>
            </div>

            <div class="info-grid-2">
                <div class="info-card">
                    <div class="section-heading"><i class="fas fa-user-graduate"></i> Candidate Details</div>
                    <div class="kv-row">
                        <span class="k">Full Name</span>
                        <span class="v"><?php echo htmlspecialchars($application['child_full_name']); ?></span>
                    </div>
                    <div class="kv-row">
                        <span class="k">Date of Birth / Gender</span>
                        <span class="v"><?php echo htmlspecialchars(date('d M Y', strtotime($application['date_of_birth'])) . ' / ' . $application['gender']); ?></span>
                    </div>
                    <div class="kv-row">
                        <span class="k">Class Applying For</span>
                        <span class="v"><?php echo htmlspecialchars($application['class_applying']); ?></span>
                    </div>
                    <div class="kv-row">
                        <span class="k">Nationality / State</span>
                        <span class="v"><?php echo htmlspecialchars(trim($application['nationality'] . ($application['state_of_origin'] ? ' / ' . $application['state_of_origin'] : ''))); ?></span>
                    </div>
                    <?php if (!empty($application['blood_group']) || !empty($application['genotype'])): ?>
                    <div class="kv-row">
                        <span class="k">Blood Group / Genotype</span>
                        <span class="v"><?php echo htmlspecialchars(trim(($application['blood_group'] ?: 'N/A') . ' / ' . ($application['genotype'] ?: 'N/A'))); ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="info-card">
                    <div class="section-heading"><i class="fas fa-users"></i> Parent / Guardian &amp; Billing</div>
                    <div class="kv-row">
                        <span class="k">Parent / Guardian</span>
                        <span class="v"><?php echo htmlspecialchars($application['parent_name']); ?> (<?php echo htmlspecialchars($application['parent_relationship']); ?>)</span>
                    </div>
                    <div class="kv-row">
                        <span class="k">Phone Number</span>
                        <span class="v"><?php echo htmlspecialchars($application['parent_phone']); ?></span>
                    </div>
                    <div class="kv-row">
                        <span class="k">Email Address</span>
                        <span class="v"><?php echo htmlspecialchars($application['parent_email']); ?></span>
                    </div>
                    <div class="kv-row">
                        <span class="k">Residential Address</span>
                        <span class="v"><?php echo htmlspecialchars($application['address']); ?></span>
                    </div>
                    <?php if (!empty($application['emergency_name'])): ?>
                    <div class="kv-row">
                        <span class="k">Emergency Contact</span>
                        <span class="v"><?php echo htmlspecialchars($application['emergency_name'] . ' (' . $application['emergency_phone'] . ')'); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="section-heading"><i class="fas fa-file-invoice-dollar"></i> Payment Breakdown</div>
            <table class="fee-table">
                <thead>
                    <tr>
                        <th style="width: 50px;">#</th>
                        <th>Fee Description</th>
                        <th>Payment Channel</th>
                        <th style="text-align: right;">Amount (NGN)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>01</td>
                        <td>
                            <strong>Admission Application Form &amp; Entrance Assessment Fee</strong><br>
                            <span style="font-size:0.8rem; color:#64748b;">Candidate: <?php echo htmlspecialchars($application['child_full_name']); ?> &mdash; <?php echo htmlspecialchars($application['class_applying']); ?></span>
                        </td>
                        <td><?php echo htmlspecialchars($application['payment_method_label']); ?></td>
                        <td style="text-align: right; font-weight: 700;">&#8358;<?php echo number_format($application['amount_paid'], 2); ?></td>
                    </tr>
                    <tr class="total-row">
                        <td colspan="3" style="text-align: right;">TOTAL AMOUNT PAID</td>
                        <td style="text-align: right;">&#8358;<?php echo number_format($application['amount_paid'], 2); ?></td>
                    </tr>
                </tbody>
            </table>

            <div class="section-heading"><i class="fas fa-folder-open"></i> Submitted Application Documents</div>
            <div class="docs-chips">
                <?php foreach ($docCatalog as $docKey => $docMeta):
                    $isUploaded = !empty($application['documents'][$docKey]);
                ?>
                    <div class="doc-chip <?php echo $isUploaded ? 'uploaded' : 'missing'; ?>">
                        <i class="fas <?php echo $isUploaded ? 'fa-check-circle' : 'fa-minus-circle'; ?>"></i>
                        <span><?php echo htmlspecialchars($docMeta['short']); ?>: <strong><?php echo $isUploaded ? 'Uploaded' : 'Not Attached'; ?></strong></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="receipt-footer">
                <div class="receipt-note">
                    <strong>Important Notice:</strong> Please bring a printed or digital copy of this receipt along with original copies of the uploaded documents when invited for the Entrance Assessment &amp; Family Interview.
                </div>
                <div class="signature-block">
                    <div class="signature-line">Admissions &amp; Bursary Office</div>
                    <div class="signature-label">Authorized Digital Stamp &bull; <?php echo date('Y'); ?></div>
                </div>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="lookup-card">
        <h2><i class="fas fa-receipt" style="color:#c8102e;"></i> Retrieve &amp; Print Receipt</h2>
        <p>Enter your Application Number or Receipt Number along with the Parent Email or Phone Number used during registration.</p>

        <?php if ($lookupError !== ''): ?>
            <div class="error-box"><i class="fas fa-exclamation-circle"></i> <?php echo $lookupError; ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label for="app_query">Application Number or Receipt Number *</label>
                <input type="text" id="app_query" name="app_query" class="form-control" required
                       placeholder="e.g. APP-2026-1234 or RCP-APP-..."
                       value="<?php echo htmlspecialchars($appQuery); ?>">
            </div>
            <div class="form-group">
                <label for="verify_contact">Parent Email or Phone Number *</label>
                <input type="text" id="verify_contact" name="verify_contact" class="form-control" required
                       placeholder="e.g. parent@example.com or 0803..."
                       value="<?php echo htmlspecialchars($verifyContact); ?>">
            </div>
            <button type="submit" class="btn-lookup">
                <i class="fas fa-search"></i> Find &amp; Generate Receipt
            </button>
        </form>
    </div>
<?php endif; ?>

</body>
</html>
