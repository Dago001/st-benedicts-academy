<?php
/**
 * Shared UI Components for Online Application:
 * 1. Sequential "Required Documents" Dropdown Uploader
 * 2. Paystack Hosted Checkout Redirect + Official Zenith Bank Account (1310057496) & Instant Receipt Generator
 */
require_once __DIR__ . '/ApplicationHelper.php';
require_once __DIR__ . '/paystack.php';

function renderSequentialDocumentsUploader(): void
{
    $catalog = ApplicationHelper::getDocumentCatalog();
    ?>
    <div class="seq-doc-uploader-card" id="sequentialDocUploader">
        <div class="seq-doc-header">
            <div>
                <h4 class="seq-doc-title"><i class="fas fa-folder-plus"></i> Select &amp; Upload Documents One After Another</h4>
                <p class="seq-doc-subtitle">Choose a document from the <strong>Required Documents</strong> dropdown below, click <strong>Pick &amp; Upload File</strong>, and repeat for each document.</p>
            </div>
            <div class="seq-doc-counter" id="seqDocCounterBadge">
                <i class="fas fa-check-double"></i> <span id="seqDocUploadedCount">0</span> / <?php echo count($catalog); ?> Uploaded
            </div>
        </div>

        <div class="seq-doc-controls">
            <div class="seq-doc-select-wrap">
                <label for="requiredDocDropdown">Required Documents Dropdown *</label>
                <select id="requiredDocDropdown" class="form-control seq-doc-select">
                    <?php foreach ($catalog as $key => $meta): ?>
                        <option value="<?php echo htmlspecialchars($key); ?>"
                                data-label="<?php echo htmlspecialchars($meta['label']); ?>"
                                data-short="<?php echo htmlspecialchars($meta['short']); ?>"
                                data-hint="<?php echo htmlspecialchars($meta['hint']); ?>"
                                data-required="<?php echo $meta['required'] ? '1' : '0'; ?>">
                            <?php echo htmlspecialchars($meta['label']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="seq-doc-trigger-wrap">
                <button type="button" id="seqTriggerUploadBtn" class="btn-seq-upload">
                    <i class="fas fa-cloud-upload-alt"></i> Pick &amp; Upload Selected Document
                </button>
            </div>
        </div>



        <!-- Underlying native file inputs preserved for multipart/form-data and E2E tests -->
        <div class="seq-hidden-inputs" aria-hidden="true">
            <?php foreach ($catalog as $key => $meta): ?>
                <input type="file"
                       id="doc_input_<?php echo htmlspecialchars($key); ?>"
                       name="<?php echo htmlspecialchars($key); ?>"
                       accept="<?php echo htmlspecialchars($meta['accept']); ?>"
                       data-doc-key="<?php echo htmlspecialchars($key); ?>"
                       data-doc-short="<?php echo htmlspecialchars($meta['short']); ?>"
                       data-doc-required="<?php echo $meta['required'] ? '1' : '0'; ?>"
                       class="seq-native-file-input">
            <?php endforeach; ?>
        </div>

        <!-- Sequential Uploaded Documents Queue -->
        <div class="seq-uploaded-queue-wrap">
            <div class="seq-queue-heading">
                <span><i class="fas fa-list-check"></i> Uploaded Documents Queue</span>
                <span class="seq-req-note">Required: <strong>Birth Certificate</strong></span>
            </div>
            <div id="seqUploadedList" class="seq-uploaded-list">
                <div class="seq-empty-state" id="seqEmptyState">
                    <i class="fas fa-file-upload"></i>
                    <span>No document uploaded yet. Pick a document from the dropdown above and click <strong>Pick &amp; Upload Selected Document</strong>.</span>
                </div>
            </div>
        </div>
    </div>
    <?php
}

function renderOnlinePaymentSection(): void
{
    $fee        = ApplicationHelper::APPLICATION_FEE;
    $defaultRef = ApplicationHelper::generatePaymentReference('PSTK-STB');
    $bankName   = school_bank_name();
    $acctName   = school_account_name();
    $acctNum    = school_account_number();

    // Check if user was just re-routed back from Paystack Checkout (?reference=... or ?trxref=...)
    $returnedRef = trim((string)($_GET['reference'] ?? $_GET['trxref'] ?? ''));
    $isPreVerified = false;
    $verifiedChannel = 'Paystack Online Checkout';
    $verifiedMethodCode = 'paystack_card';
    $verifyErrorMsg = '';

    if ($returnedRef !== '') {
        $vResult = paystack_verify_transaction($returnedRef, $fee);
        ApplicationHelper::recordPaymentVerification($returnedRef, $vResult);
        if (!empty($vResult['success'])) {
            $isPreVerified = true;
            $defaultRef = $vResult['reference'];
            $verifiedMethodCode = $vResult['channel'] ?: 'paystack_card';
            $verifiedChannel = ApplicationHelper::formatPaymentMethod($verifiedMethodCode);
            $_SESSION['verified_paystack_ref'] = $defaultRef;
        } else {
            $verifyErrorMsg = $vResult['message'] ?? 'Payment could not be verified.';
        }
    }
    ?>
    <div class="online-payment-card" id="onlinePaymentSection" data-paystack-returned="<?php echo $isPreVerified ? '1' : '0'; ?>">
        <div class="payment-banner-top">
            <div>
                <span class="payment-pill-tag"><i class="fas fa-shield-alt"></i> Paystack &amp; Official School Bank Checkout</span>
                <h4>Application &amp; Entrance Assessment Fee</h4>
                <p>Pay online via <strong>Paystack</strong> or via direct transfer to the School's <strong><?php echo htmlspecialchars($bankName); ?></strong> account.</p>
            </div>
            <div class="payment-amount-box">
                <span class="amt-label">Payable Amount</span>
                <span class="amt-value">&#8358;<?php echo number_format($fee, 2); ?></span>
            </div>
        </div>

        <?php if ($verifyErrorMsg !== ''): ?>
            <div style="background:#fef2f2; border:1px solid #fecaca; color:#b91c1c; padding:12px 16px; border-radius:10px; margin-bottom:14px; font-size:0.88rem;">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($verifyErrorMsg); ?>
            </div>
        <?php endif; ?>

        <!-- Official School Bank Account Summary Bar -->
        <div class="official-school-account-bar">
            <div class="osa-left">
                <div class="osa-badge"><i class="fas fa-university"></i> Official School Bank Account</div>
                <div class="osa-details">
                    <span>Bank Name: <strong><?php echo htmlspecialchars($bankName); ?></strong></span>
                    <span class="osa-sep">&bull;</span>
                    <span>Account Name: <strong><?php echo htmlspecialchars($acctName); ?></strong></span>
                    <span class="osa-sep">&bull;</span>
                    <span>Account Number: <strong class="osa-acct-num" id="schoolAcctNumberText"><?php echo htmlspecialchars($acctNum); ?></strong></span>
                </div>
            </div>
            <button type="button" class="btn-copy-acct" id="copySchoolAcctBtn" data-acct="<?php echo htmlspecialchars($acctNum); ?>">
                <i class="fas fa-copy"></i> Copy Account No.
            </button>
        </div>

        <input type="hidden" name="amount_paid" id="input_amount_paid" value="<?php echo htmlspecialchars((string)$fee); ?>">
        <input type="hidden" name="payment_status" id="input_payment_status" value="paid">
        <input type="hidden" name="payment_verified_ui" id="input_payment_verified_ui" value="<?php echo $isPreVerified ? '1' : '0'; ?>">

        <div class="payment-method-selector">
            <label class="pay-method-option active" data-method="paystack_card">
                <input type="radio" name="payment_method" value="<?php echo htmlspecialchars($verifiedMethodCode); ?>" checked>
                <div class="pm-icon"><i class="fas fa-bolt"></i></div>
                <div class="pm-text">
                    <strong>Pay Online with Paystack</strong>
                    <span>Debit/Credit card, instant transfer, USSD &mdash; instant receipt</span>
                </div>
            </label>

            <label class="pay-method-option" data-method="pay_at_school">
                <input type="radio" name="payment_method" value="pay_at_school">
                <div class="pm-icon" style="background:rgba(217,119,6,0.15); color:#d97706;"><i class="fas fa-file-invoice"></i></div>
                <div class="pm-text">
                    <strong>Pay at School &amp; Print Application Slip</strong>
                    <span>Submit now, print official slip, pay at admissions office or Zenith Bank</span>
                </div>
            </label>

            <label class="pay-method-option" data-method="bank_transfer">
                <input type="radio" name="payment_method" value="bank_transfer">
                <div class="pm-icon"><i class="fas fa-university"></i></div>
                <div class="pm-text">
                    <strong>Direct Transfer to Zenith Bank (<?php echo htmlspecialchars($acctNum); ?>)</strong>
                    <span>Enter bank transfer session ID / teller reference</span>
                </div>
            </label>
        </div>

        <!-- Mode C: Pay at School & Print Application Slip Panel -->
        <div id="payAtSchoolPanel" class="pay-at-school-panel" style="display:none; margin-bottom:16px;">
            <div style="background:#fffbeb; border:1px solid #fde68a; border-radius:12px; padding:18px 20px;">
                <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
                    <span style="background:#d97706; color:#fff; width:34px; height:34px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-size:1rem;"><i class="fas fa-print"></i></span>
                    <strong style="color:#92400e; font-size:1.02rem;">Official Application Slip &amp; School Admissions Office Payment</strong>
                </div>
                <p style="color:#78350f; font-size:0.88rem; line-height:1.55; margin-bottom:12px;">
                    You can submit your application now without paying online. Immediately after clicking <strong>Submit Application</strong>, you will receive your <strong>Official Admission Application Slip</strong> with your child's photograph and application reference number. Take this slip to the school admissions office to complete payment and validate your child's application.
                </p>
                <div style="background:#ffffff; border:1px solid #fcd34d; border-radius:10px; padding:14px 16px; margin-bottom:10px;">
                    <div style="font-weight:700; color:#002147; font-size:0.9rem; margin-bottom:6px;">
                        <i class="fas fa-school" style="color:#c8102e;"></i> Steps to Complete Your Admission at the School:
                    </div>
                    <ol style="margin:0; padding-left:20px; font-size:0.86rem; color:#334155; line-height:1.6;">
                        <li><strong>Submit &amp; Print:</strong> Submit this form to generate your printable <strong>Official Admission Application Slip</strong>.</li>
                        <li><strong>Present at School:</strong> Bring the slip to St. Benedict's Academy Admissions Office (No. 560 A.A. New G.R.A Trans-Ekulu, Enugu).</li>
                        <li><strong>Complete Payment:</strong> Pay the <strong>&#8358;15,000</strong> application fee in person via POS / Cash, or transfer to the official Zenith Bank Account: <strong style="color:#c8102e;">1310057496</strong> (St. Benedict's Early Years British Academy).</li>
                        <li><strong>Validation &amp; Interview:</strong> The Admissions Office validates the payment on the portal, issues your official payment receipt, and confirms your child's teacher assessment schedule.</li>
                    </ol>
                </div>
            </div>
        </div>

        <!-- Mode A: Paystack Online Gateway Checkout -->
        <div id="onlineGatewayPanel" class="payment-gateway-panel">
            <div class="gateway-action-row" id="gatewayUnpaidState" style="<?php echo $isPreVerified ? 'display:none;' : ''; ?>">
                <div class="gateway-info">
                    <i class="fas fa-lock" style="color:#002147;"></i>
                    <span>Click <strong>Pay &#8358;<?php echo number_format($fee, 2); ?> on Paystack</strong> to complete your application fee payment securely.</span>
                </div>
                <button type="button" id="openPaymentModalBtn" class="btn-pay-online">
                    <i class="fas fa-external-link-alt"></i> Pay &#8358;<?php echo number_format($fee, 2); ?> on Paystack
                </button>
            </div>

            <div class="gateway-verified-box" id="gatewayPaidState" style="<?php echo $isPreVerified ? 'display:flex;' : 'display:none;'; ?>">
                <div class="gv-left">
                    <div class="gv-check"><i class="fas fa-check-circle"></i></div>
                    <div>
                        <div class="gv-title">Paystack Payment Verified</div>
                        <div class="gv-meta">
                            <span>Ref: <strong id="displayPaidRef"><?php echo htmlspecialchars($defaultRef); ?></strong></span> &bull;
                            <span>Channel: <strong id="displayPaidChannel"><?php echo htmlspecialchars($verifiedChannel); ?></strong></span> &bull;
                            <span>Amount: <strong>&#8358;<?php echo number_format($fee, 2); ?></strong></span>
                        </div>
                    </div>
                </div>
                <div class="gv-right">
                    <span class="receipt-ready-badge"><i class="fas fa-receipt"></i> Official Receipt Ready</span>
                </div>
            </div>
        </div>

        <!-- Mode B: Direct Zenith Bank Transfer Details -->
        <div id="bankTransferPanel" class="bank-transfer-panel" style="display:none;">
            <div class="school-bank-details">
                <div><span>Bank Name:</span> <strong><?php echo htmlspecialchars($bankName); ?></strong></div>
                <div><span>Account Name:</span> <strong><?php echo htmlspecialchars($acctName); ?></strong></div>
                <div><span>Account Number:</span> <strong style="color:#c8102e; font-size:1.05rem;"><?php echo htmlspecialchars($acctNum); ?></strong></div>
                <div><span>Amount:</span> <strong>&#8358;<?php echo number_format($fee, 2); ?></strong></div>
            </div>
            <p style="font-size:0.84rem; color:#475569; margin-top:10px; margin-bottom:0;">
                <i class="fas fa-info-circle"></i> After transferring &#8358;<?php echo number_format($fee, 2); ?> to <strong><?php echo htmlspecialchars($bankName); ?> (<?php echo htmlspecialchars($acctNum); ?>)</strong>, enter your Transaction Reference / Session ID below and optionally upload your transfer receipt in the <strong>Required Documents</strong> dropdown above.
            </p>
        </div>

        <div class="payment-ref-row">
            <div class="form-group" style="margin-bottom:0;">
                <label for="input_payment_reference">Payment Transaction Reference *</label>
                <input type="text" id="input_payment_reference" name="payment_reference" class="form-control"
                       value="<?php echo htmlspecialchars($defaultRef); ?>"
                       placeholder="e.g. PSTK-STB-2026..." readonly required>
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label>Instant Official Receipt Generation</label>
                <div class="receipt-guarantee-box">
                    <i class="fas fa-file-invoice"></i>
                    <span>Printable Official Admission &amp; Fee Receipt generated immediately on submission</span>
                </div>
            </div>
        </div>
    </div>
    <?php
}

function renderApplicationComponentsAssets(): void
{
    $pubKey = paystack_public_key();
    $fee    = ApplicationHelper::APPLICATION_FEE;
    ?>
    <style>
        /* Sequential Document Dropdown Uploader */
        .seq-doc-uploader-card {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 14px;
            padding: 22px;
            margin-top: 10px;
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.04);
        }
        .seq-doc-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 18px;
            padding-bottom: 14px;
            border-bottom: 1px solid #e2e8f0;
        }
        .seq-doc-title {
            font-size: 1.05rem;
            color: #002147;
            margin: 0 0 4px;
            font-weight: 700;
        }
        .seq-doc-subtitle {
            font-size: 0.86rem;
            color: #475569;
            margin: 0;
        }
        .seq-doc-counter {
            background: #002147;
            color: #FFD700;
            padding: 7px 14px;
            border-radius: 999px;
            font-size: 0.82rem;
            font-weight: 700;
            white-space: nowrap;
        }
        .seq-doc-controls {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 14px;
            align-items: end;
            margin-bottom: 16px;
        }
        .seq-doc-select-wrap label {
            display: block;
            font-weight: 700;
            font-size: 0.88rem;
            color: #0f172a;
            margin-bottom: 6px;
        }
        .seq-doc-select {
            width: 100%;
            padding: 12px 14px;
            border: 2px solid #002147;
            border-radius: 10px;
            font-size: 0.95rem;
            font-weight: 600;
            color: #002147;
            background: #f8fafc;
            cursor: pointer;
        }
        .btn-seq-upload {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            padding: 13px 22px;
            background: #c8102e;
            color: #ffffff;
            border: none;
            border-radius: 10px;
            font-weight: 700;
            font-size: 0.92rem;
            cursor: pointer;
            transition: all 0.2s ease;
            white-space: nowrap;
        }
        .btn-seq-upload:hover {
            background: #a00d25;
            transform: translateY(-1px);
        }
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 700;
        }
        .status-pill.pending { background: #e0f2fe; color: #0369a1; }
        .status-pill.done { background: #dcfce7; color: #15803d; }

        .seq-hidden-inputs {
            position: absolute;
            width: 1px;
            height: 1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            opacity: 0;
            pointer-events: none;
        }
        .seq-queue-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            font-size: 0.85rem;
            font-weight: 700;
            color: #002147;
            margin-bottom: 10px;
        }
        .seq-req-note {
            font-size: 0.78rem;
            color: #64748b;
            font-weight: 500;
        }
        .seq-uploaded-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .seq-empty-state {
            text-align: center;
            padding: 18px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            color: #64748b;
            font-size: 0.86rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        .seq-uploaded-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            padding: 12px 16px;
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 10px;
        }
        .seq-item-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .seq-item-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            background: #16a34a;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.95rem;
        }
        .seq-item-docname {
            font-weight: 700;
            font-size: 0.9rem;
            color: #0f172a;
        }
        .seq-item-filename {
            font-size: 0.8rem;
            color: #15803d;
        }
        .seq-item-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .btn-seq-small {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.78rem;
            font-weight: 600;
            cursor: pointer;
            border: 1px solid #cbd5e1;
            background: #fff;
            color: #334155;
        }
        .btn-seq-small.remove {
            border-color: #fecaca;
            color: #b91c1c;
            background: #fef2f2;
        }

        /* Online Payment Section */
        .online-payment-card {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 14px;
            padding: 22px;
            margin-top: 10px;
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.04);
        }
        .payment-banner-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            background: linear-gradient(135deg, #002147 0%, #003366 100%);
            color: #fff;
            padding: 18px 22px;
            border-radius: 12px;
            margin-bottom: 14px;
        }
        .payment-pill-tag {
            display: inline-block;
            background: rgba(255, 215, 0, 0.2);
            color: #FFD700;
            font-size: 0.74rem;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 999px;
            text-transform: uppercase;
            margin-bottom: 4px;
        }
        .payment-banner-top h4 {
            color: #fff;
            margin: 0 0 3px;
            font-size: 1.1rem;
        }
        .payment-banner-top p {
            color: rgba(255, 255, 255, 0.86);
            font-size: 0.85rem;
            margin: 0;
        }
        .payment-amount-box {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 215, 0, 0.4);
            padding: 10px 18px;
            border-radius: 10px;
            text-align: right;
        }
        .payment-amount-box .amt-label {
            display: block;
            font-size: 0.72rem;
            text-transform: uppercase;
            color: #cbd5e1;
        }
        .payment-amount-box .amt-value {
            font-size: 1.35rem;
            font-weight: 800;
            color: #FFD700;
        }

        /* Official School Bank Account Bar */
        .official-school-account-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 12px 16px;
            margin-bottom: 16px;
        }
        .osa-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.74rem;
            font-weight: 800;
            text-transform: uppercase;
            color: #002147;
            letter-spacing: 0.5px;
            margin-bottom: 3px;
        }
        .osa-details {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
            font-size: 0.88rem;
            color: #334155;
        }
        .osa-acct-num {
            color: #c8102e;
            font-size: 1rem;
            letter-spacing: 0.5px;
        }
        .osa-sep { color: #94a3b8; }
        .btn-copy-acct {
            padding: 7px 14px;
            border-radius: 8px;
            border: 1px solid #002147;
            background: #ffffff;
            color: #002147;
            font-size: 0.8rem;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s ease;
        }
        .btn-copy-acct:hover {
            background: #002147;
            color: #FFD700;
        }

        .payment-method-selector {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }
        .pay-method-option {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.2s ease;
            background: #f8fafc;
        }
        .pay-method-option.active {
            border-color: #002147;
            background: #eff6ff;
        }
        .pay-method-option input[type="radio"] {
            accent-color: #002147;
            width: 18px;
            height: 18px;
        }
        .pm-icon {
            width: 38px;
            height: 38px;
            border-radius: 8px;
            background: #002147;
            color: #FFD700;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
        }
        .pm-text strong {
            display: block;
            font-size: 0.9rem;
            color: #0f172a;
        }
        .pm-text span {
            font-size: 0.78rem;
            color: #64748b;
        }
        .gateway-action-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 16px 18px;
            margin-bottom: 16px;
        }
        .gateway-info {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.88rem;
            color: #334155;
            max-width: 560px;
        }
        .btn-pay-online {
            padding: 12px 22px;
            background: #09a5db;
            color: #ffffff;
            border: none;
            border-radius: 10px;
            font-weight: 800;
            font-size: 0.92rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 6px 16px rgba(9, 165, 219, 0.28);
            transition: all 0.2s ease;
        }
        .btn-pay-online:hover { background: #0787b5; transform: translateY(-1px); }
        .btn-pay-online:disabled { opacity: 0.75; cursor: wait; }
        .gateway-verified-box {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
            background: #f0fdf4;
            border: 2px solid #86efac;
            border-radius: 10px;
            padding: 16px 18px;
            margin-bottom: 16px;
        }
        .gv-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .gv-check {
            font-size: 1.6rem;
            color: #16a34a;
        }
        .gv-title {
            font-weight: 800;
            color: #14532d;
            font-size: 0.95rem;
        }
        .gv-meta {
            font-size: 0.82rem;
            color: #166534;
        }
        .receipt-ready-badge {
            background: #002147;
            color: #FFD700;
            padding: 6px 14px;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 700;
        }
        .bank-transfer-panel {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 16px;
        }
        .school-bank-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
            font-size: 0.88rem;
        }
        .school-bank-details span { color: #64748b; }
        .payment-ref-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
            align-items: end;
        }
        .receipt-guarantee-box {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 11px 14px;
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 0.83rem;
            color: #002147;
            font-weight: 600;
        }

        @media (max-width: 768px) {
            .seq-doc-controls,
            .payment-method-selector,
            .payment-ref-row {
                grid-template-columns: 1fr;
            }
            .btn-seq-upload { width: 100%; }
        }
    </style>

    <script nonce="<?php echo CSP_NONCE; ?>">
    window.PAYSTACK_PUBLIC_KEY = <?php echo json_encode($pubKey); ?>;
    window.APPLICATION_FEE_NAIRA = <?php echo json_encode((float)$fee); ?>;

    document.addEventListener('DOMContentLoaded', function() {
        const DRAFT_KEY = 'stb_application_form_draft_v1';
        const appForm = document.getElementById('admissionForm') || document.getElementById('applicationForm');

        // Save form fields to sessionStorage before routing to Paystack so user input is preserved on return
        function saveFormDraft() {
            if (!appForm) return;
            const draft = {};
            const fields = appForm.querySelectorAll('input:not([type="file"]):not([type="hidden"]):not([type="checkbox"]):not([type="radio"]), select, textarea');
            fields.forEach(function(el) {
                if (el.name && el.id !== 'requiredDocDropdown' && el.name !== 'payment_reference') {
                    draft[el.name] = el.value;
                }
            });
            try {
                sessionStorage.setItem(DRAFT_KEY, JSON.stringify(draft));
            } catch (e) {}
        }

        function restoreFormDraft() {
            if (!appForm) return;
            try {
                const raw = sessionStorage.getItem(DRAFT_KEY);
                if (!raw) return;
                const draft = JSON.parse(raw);
                if (!draft || typeof draft !== 'object') return;
                Object.keys(draft).forEach(function(name) {
                    const el = appForm.querySelector('[name="' + name + '"]');
                    if (el && draft[name] !== undefined && draft[name] !== '') {
                        el.value = draft[name];
                        el.dispatchEvent(new Event('change'));
                    }
                });
            } catch (e) {}
        }

        // Copy Official School Account Number
        const copyAcctBtn = document.getElementById('copySchoolAcctBtn');
        if (copyAcctBtn) {
            copyAcctBtn.addEventListener('click', function() {
                const acct = this.getAttribute('data-acct') || '1310057496';
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(acct);
                }
                const orig = this.innerHTML;
                this.innerHTML = '<i class="fas fa-check"></i> Copied: ' + acct;
                const self = this;
                setTimeout(function() { self.innerHTML = orig; }, 2000);
            });
        }

        // --- 1. Sequential Required Documents Dropdown Uploader ---
        const dropdown = document.getElementById('requiredDocDropdown');
        const triggerBtn = document.getElementById('seqTriggerUploadBtn');
        const activeBadge = document.getElementById('seqActiveBadge');
        const activeTitle = document.getElementById('seqActiveTitle');
        const activeHint = document.getElementById('seqActiveHint');
        const activeStatus = document.getElementById('seqActiveStatus');
        const uploadedList = document.getElementById('seqUploadedList');
        const uploadedCountEl = document.getElementById('seqDocUploadedCount');
        const nativeInputs = document.querySelectorAll('.seq-native-file-input');

        if (dropdown && triggerBtn) {
            function updateActiveBanner() {
                if (!activeBadge || !activeTitle) return;
                const opt = dropdown.options[dropdown.selectedIndex];
                if (!opt) return;
                const key = opt.value;
                const isReq = opt.getAttribute('data-required') === '1';
                const shortName = opt.getAttribute('data-short') || opt.text;
                const hint = opt.getAttribute('data-hint') || '';
                const inputEl = document.getElementById('doc_input_' + key);
                const hasFile = inputEl && inputEl.files && inputEl.files.length > 0;

                activeBadge.textContent = isReq ? 'Required Document' : 'Optional Document';
                activeBadge.className = 'seq-active-badge' + (isReq ? '' : ' optional');
                activeTitle.textContent = shortName;
                if (activeHint) activeHint.textContent = hint;

                if (activeStatus) {
                    if (hasFile) {
                        activeStatus.innerHTML = '<span class="status-pill done"><i class="fas fa-check-circle"></i> Uploaded (' + inputEl.files[0].name + ')</span>';
                    } else {
                        activeStatus.innerHTML = '<span class="status-pill pending"><i class="fas fa-arrow-up"></i> Ready to pick file</span>';
                    }
                }
            }

            function renderQueue() {
                let count = 0;
                let html = '';

                nativeInputs.forEach(function(inp) {
                    const key = inp.getAttribute('data-doc-key');
                    const shortName = inp.getAttribute('data-doc-short');
                    const opt = dropdown.querySelector('option[value="' + key + '"]');
                    const baseLabel = opt ? opt.getAttribute('data-label') : shortName;

                    if (inp.files && inp.files.length > 0) {
                        count++;
                        const f = inp.files[0];
                        const sizeKB = Math.max(1, Math.round(f.size / 1024));
                        const sizeLabel = sizeKB > 1024 ? (sizeKB / 1024).toFixed(1) + ' MB' : sizeKB + ' KB';

                        if (opt) {
                            opt.textContent = '✓ ' + baseLabel + ' [Uploaded]';
                        }

                        html += '<div class="seq-uploaded-item" data-key="' + key + '">' +
                            '<div class="seq-item-left">' +
                                '<div class="seq-item-icon"><i class="fas fa-check"></i></div>' +
                                '<div>' +
                                    '<div class="seq-item-docname">' + shortName + '</div>' +
                                    '<div class="seq-item-filename"><i class="fas fa-paperclip"></i> ' + f.name + ' (' + sizeLabel + ')</div>' +
                                '</div>' +
                            '</div>' +
                            '<div class="seq-item-actions">' +
                                '<button type="button" class="btn-seq-small replace" data-replace-key="' + key + '"><i class="fas fa-sync-alt"></i> Change</button>' +
                                '<button type="button" class="btn-seq-small remove" data-remove-key="' + key + '"><i class="fas fa-trash-alt"></i> Remove</button>' +
                            '</div>' +
                        '</div>';
                    } else if (opt) {
                        opt.textContent = baseLabel;
                    }
                });

                if (uploadedCountEl) uploadedCountEl.textContent = String(count);

                if (count === 0) {
                    uploadedList.innerHTML = '<div class="seq-empty-state"><i class="fas fa-file-upload"></i><span>No document uploaded yet. Pick a document from the dropdown above and click <strong>Pick &amp; Upload Selected Document</strong>.</span></div>';
                } else {
                    uploadedList.innerHTML = html;
                }

                updateActiveBanner();
            }

            function advanceToNextUnuploaded() {
                for (let i = 0; i < dropdown.options.length; i++) {
                    const key = dropdown.options[i].value;
                    const inp = document.getElementById('doc_input_' + key);
                    if (!inp || !inp.files || inp.files.length === 0) {
                        dropdown.selectedIndex = i;
                        updateActiveBanner();
                        return;
                    }
                }
            }

            dropdown.addEventListener('change', updateActiveBanner);

            triggerBtn.addEventListener('click', function() {
                const key = dropdown.value;
                const inp = document.getElementById('doc_input_' + key);
                if (inp) inp.click();
            });

            nativeInputs.forEach(function(inp) {
                inp.addEventListener('change', function() {
                    if (this.files && this.files.length > 0) {
                        const f = this.files[0];
                        if (f.size > 5 * 1024 * 1024) {
                            alert('File size exceeds 5MB limit. Please select a file smaller than 5MB.');
                            this.value = '';
                            renderQueue();
                            return;
                        }
                        renderQueue();
                        advanceToNextUnuploaded();
                    } else {
                        renderQueue();
                    }
                });
            });

            uploadedList.addEventListener('click', function(e) {
                const replaceBtn = e.target.closest('[data-replace-key]');
                if (replaceBtn) {
                    const k = replaceBtn.getAttribute('data-replace-key');
                    dropdown.value = k;
                    updateActiveBanner();
                    const inp = document.getElementById('doc_input_' + k);
                    if (inp) inp.click();
                    return;
                }
                const removeBtn = e.target.closest('[data-remove-key]');
                if (removeBtn) {
                    const k = removeBtn.getAttribute('data-remove-key');
                    const inp = document.getElementById('doc_input_' + k);
                    if (inp) inp.value = '';
                    dropdown.value = k;
                    renderQueue();
                }
            });

            updateActiveBanner();
        }

        // --- 2. Paystack Hosted Checkout Redirect & Return Handling ---
        const paymentSection = document.getElementById('onlinePaymentSection');
        const methodOptions = document.querySelectorAll('.pay-method-option');
        const onlineGatewayPanel = document.getElementById('onlineGatewayPanel');
        const bankTransferPanel = document.getElementById('bankTransferPanel');
        const payAtSchoolPanel = document.getElementById('payAtSchoolPanel');
        const paymentRefInput = document.getElementById('input_payment_reference');
        const paymentStatusInput = document.getElementById('input_payment_status');
        const verifiedUiInput = document.getElementById('input_payment_verified_ui');
        const openModalBtn = document.getElementById('openPaymentModalBtn');

        // If user just returned from Paystack Checkout, restore their form draft and scroll to payment section
        if (paymentSection && paymentSection.getAttribute('data-paystack-returned') === '1') {
            restoreFormDraft();

            // If on multi-step apply.php, jump straight to Step 4
            const step4 = document.getElementById('step4');
            if (step4) {
                document.querySelectorAll('.form-step').forEach(function(s) { s.classList.remove('active'); });
                step4.classList.add('active');
                document.querySelectorAll('.progress-step').forEach(function(ps, idx) {
                    if (idx < 3) ps.classList.add('completed');
                    if (idx === 3) ps.classList.add('active');
                });
            }

            setTimeout(function() {
                paymentSection.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }, 250);

            if (window.history && window.history.replaceState) {
                const cleanUrl = window.location.pathname + window.location.hash;
                window.history.replaceState({}, document.title, cleanUrl);
            }
        }

        methodOptions.forEach(function(opt) {
            opt.addEventListener('click', function() {
                methodOptions.forEach(o => o.classList.remove('active'));
                this.classList.add('active');
                const radio = this.querySelector('input[type="radio"]');
                if (radio) radio.checked = true;
                const method = this.getAttribute('data-method');

                if (method === 'pay_at_school') {
                    if (onlineGatewayPanel) onlineGatewayPanel.style.display = 'none';
                    if (bankTransferPanel) bankTransferPanel.style.display = 'none';
                    if (payAtSchoolPanel) payAtSchoolPanel.style.display = 'block';
                    if (paymentRefInput) {
                        paymentRefInput.readOnly = true;
                        paymentRefInput.value = 'SCH-SLIP-' + Math.floor(100000 + Math.random() * 900000);
                    }
                    if (paymentStatusInput) paymentStatusInput.value = 'pending_school_payment';
                } else if (method === 'bank_transfer') {
                    if (onlineGatewayPanel) onlineGatewayPanel.style.display = 'none';
                    if (bankTransferPanel) bankTransferPanel.style.display = 'block';
                    if (payAtSchoolPanel) payAtSchoolPanel.style.display = 'none';
                    if (paymentRefInput) {
                        paymentRefInput.readOnly = false;
                        if (paymentRefInput.value.indexOf('PSTK-STB-') === 0 || paymentRefInput.value.indexOf('TXN-STB-') === 0 || paymentRefInput.value.indexOf('SCH-SLIP-') === 0) {
                            paymentRefInput.value = 'ZENITH-TRF-' + Math.floor(100000 + Math.random() * 900000);
                        }
                        paymentRefInput.focus();
                    }
                    if (paymentStatusInput) paymentStatusInput.value = 'pending_verification';
                } else {
                    if (onlineGatewayPanel) onlineGatewayPanel.style.display = 'block';
                    if (bankTransferPanel) bankTransferPanel.style.display = 'none';
                    if (payAtSchoolPanel) payAtSchoolPanel.style.display = 'none';
                    if (paymentRefInput) {
                        paymentRefInput.readOnly = true;
                        if (paymentRefInput.value.indexOf('ZENITH-TRF-') === 0 || paymentRefInput.value.indexOf('SCH-SLIP-') === 0) {
                            paymentRefInput.value = 'PSTK-STB-' + Math.floor(100000 + Math.random() * 900000);
                        }
                    }
                    if (paymentStatusInput) paymentStatusInput.value = 'paid';
                }
            });
        });

        // Route user to Paystack Hosted Checkout interface
        if (openModalBtn) {
            openModalBtn.addEventListener('click', function() {
                saveFormDraft();

                const emailEl = document.querySelector('input[name="parent_email"]');
                const parentNameEl = document.querySelector('input[name="parent_name"], input[name="parent_first_name"]');
                const childNameEl = document.querySelector('input[name="first_name"], input[name="child_first_name"]');
                const phoneEl = document.querySelector('input[name="parent_phone"]');

                const applicantEmail = (emailEl && emailEl.value && emailEl.value.indexOf('@') !== -1)
                    ? emailEl.value.trim()
                    : 'admissions@stbenedicts.edu.ng';

                const origHtml = openModalBtn.innerHTML;
                openModalBtn.disabled = true;
                openModalBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Routing to Paystack...';

                // Compute relative path from BASE_URL so callback_url matches current page (/public/admissions or /public/apply)
                let returnPath = '/public/admissions';
                if (window.location.pathname.indexOf('/apply') !== -1) {
                    returnPath = '/public/apply';
                }

                const initUrl = (window.BASE_URL || '') + '/api/paystack-init.php';
                fetch(initUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': window.CSRF_TOKEN || ''
                    },
                    body: JSON.stringify({
                        email: applicantEmail,
                        parent_name: parentNameEl ? parentNameEl.value : '',
                        child_name: childNameEl ? childNameEl.value : '',
                        phone: phoneEl ? phoneEl.value : '',
                        return_path: returnPath,
                        csrf_token: window.CSRF_TOKEN || ''
                    })
                })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (data && data.success && data.authorization_url) {
                        // Redirect browser directly to Paystack Hosted Checkout interface
                        window.location.href = data.authorization_url;
                    } else {
                        openModalBtn.disabled = false;
                        openModalBtn.innerHTML = origHtml;
                        alert((data && data.message) ? data.message : 'Unable to connect to Paystack right now. Please try again.');
                    }
                })
                .catch(function() {
                    openModalBtn.disabled = false;
                    openModalBtn.innerHTML = origHtml;
                    alert('Network error while connecting to Paystack. Please check your internet connection and try again.');
                });
            });
        }
    });
    </script>
    <?php
}
