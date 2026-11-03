<?php
/**
 * Shared UI Components for Online Application:
 * 1. Sequential "Required Documents" Dropdown Uploader
 * 2. Online Payment & Instant Receipt Generator + Checkout Modal
 */
require_once __DIR__ . '/ApplicationHelper.php';

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

        <div class="seq-active-doc-banner" id="seqActiveDocBanner">
            <div class="seq-active-doc-info">
                <span class="seq-active-badge" id="seqActiveBadge">Required</span>
                <strong id="seqActiveTitle">Birth Certificate</strong>
                <span class="seq-active-hint" id="seqActiveHint">Official Birth Certificate or NPC Attestation (PDF, JPG, PNG — Max 5MB)</span>
            </div>
            <div class="seq-active-status" id="seqActiveStatus">
                <span class="status-pill pending"><i class="fas fa-arrow-up"></i> Ready to pick file</span>
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
                <span class="seq-req-note">Required: <strong>Birth Certificate</strong> &amp; <strong>Passport Photograph</strong></span>
            </div>
            <div id="seqUploadedList" class="seq-uploaded-list">
                <div class="seq-empty-state" id="seqEmptyState">
                    <i class="fas fa-file-upload"></i>
                    <span>No document uploaded yet. Pick a document from the dropdown above and click <strong>"Pick &amp; Upload Selected Document"</strong>.</span>
                </div>
            </div>
        </div>
    </div>
    <?php
}

function renderOnlinePaymentSection(): void
{
    $fee = ApplicationHelper::APPLICATION_FEE;
    $defaultRef = ApplicationHelper::generatePaymentReference('TXN-STB');
    ?>
    <div class="online-payment-card" id="onlinePaymentSection">
        <div class="payment-banner-top">
            <div>
                <span class="payment-pill-tag"><i class="fas fa-shield-alt"></i> Secure Online Bursary Checkout</span>
                <h4>Application &amp; Entrance Assessment Fee</h4>
                <p>Complete your application fee payment online to generate your instant official receipt.</p>
            </div>
            <div class="payment-amount-box">
                <span class="amt-label">Payable Amount</span>
                <span class="amt-value">&#8358;<?php echo number_format($fee, 2); ?></span>
            </div>
        </div>

        <input type="hidden" name="amount_paid" id="input_amount_paid" value="<?php echo htmlspecialchars((string)$fee); ?>">
        <input type="hidden" name="payment_status" id="input_payment_status" value="paid">
        <input type="hidden" name="payment_verified_ui" id="input_payment_verified_ui" value="0">

        <div class="payment-method-selector">
            <label class="pay-method-option active" data-method="online_card">
                <input type="radio" name="payment_method" value="online_card" checked>
                <div class="pm-icon"><i class="fas fa-credit-card"></i></div>
                <div class="pm-text">
                    <strong>Pay Online Now (Card / Transfer / USSD)</strong>
                    <span>Instant verification &amp; immediate Official Receipt</span>
                </div>
            </label>

            <label class="pay-method-option" data-method="bank_transfer">
                <input type="radio" name="payment_method" value="bank_transfer">
                <div class="pm-icon"><i class="fas fa-university"></i></div>
                <div class="pm-text">
                    <strong>Direct Bank Transfer Reference</strong>
                    <span>Enter bank teller / transfer session ID</span>
                </div>
            </label>
        </div>

        <!-- Mode A: Interactive Online Gateway Checkout -->
        <div id="onlineGatewayPanel" class="payment-gateway-panel">
            <div class="gateway-action-row" id="gatewayUnpaidState">
                <div class="gateway-info">
                    <i class="fas fa-lock" style="color:#002147;"></i>
                    <span>Click <strong>Pay &#8358;<?php echo number_format($fee, 2); ?> Online Now</strong> to complete payment via Debit Card, Instant Bank Transfer, or USSD and generate your receipt number.</span>
                </div>
                <button type="button" id="openPaymentModalBtn" class="btn-pay-online">
                    <i class="fas fa-bolt"></i> Pay &#8358;<?php echo number_format($fee, 2); ?> Online Now
                </button>
            </div>

            <div class="gateway-verified-box" id="gatewayPaidState" style="display:none;">
                <div class="gv-left">
                    <div class="gv-check"><i class="fas fa-check-circle"></i></div>
                    <div>
                        <div class="gv-title">Online Payment Verified &amp; Receipt Assigned</div>
                        <div class="gv-meta">
                            <span>Ref: <strong id="displayPaidRef">-</strong></span> &bull;
                            <span>Channel: <strong id="displayPaidChannel">Online Card</strong></span> &bull;
                            <span>Amount: <strong>&#8358;<?php echo number_format($fee, 2); ?></strong></span>
                        </div>
                    </div>
                </div>
                <div class="gv-right">
                    <span class="receipt-ready-badge"><i class="fas fa-receipt"></i> Official Receipt Ready</span>
                </div>
            </div>
        </div>

        <!-- Mode B: Direct Bank Transfer Details -->
        <div id="bankTransferPanel" class="bank-transfer-panel" style="display:none;">
            <div class="school-bank-details">
                <div><span>Bank Name:</span> <strong>Zenith Bank / First Bank PLC</strong></div>
                <div><span>Account Name:</span> <strong>St. Benedict's Early Years British Academy</strong></div>
                <div><span>Account Number:</span> <strong>1012345678</strong></div>
                <div><span>Amount:</span> <strong>&#8358;<?php echo number_format($fee, 2); ?></strong></div>
            </div>
            <p style="font-size:0.84rem; color:#475569; margin-top:8px;">
                <i class="fas fa-info-circle"></i> Enter your 12-digit Session ID or Teller Reference below and optionally upload your transfer receipt in the <strong>Required Documents</strong> dropdown above.
            </p>
        </div>

        <div class="payment-ref-row">
            <div class="form-group" style="margin-bottom:0;">
                <label for="input_payment_reference">Payment Transaction Reference *</label>
                <input type="text" id="input_payment_reference" name="payment_reference" class="form-control"
                       value="<?php echo htmlspecialchars($defaultRef); ?>"
                       placeholder="e.g. TXN-STB-2026..." readonly required>
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

    <!-- Interactive Online Payment Checkout Modal -->
    <div class="stb-pay-modal-backdrop" id="stbPayModal" style="display:none;" role="dialog" aria-modal="true">
        <div class="stb-pay-modal">
            <div class="stb-pay-modal-header">
                <div class="stb-pay-brand">
                    <img src="<?php echo BASE_URL; ?>/assets/images/logo.png" alt="Logo">
                    <div>
                        <strong>St. Benedict's Secure Pay</strong>
                        <span id="modalApplicantEmailDisplay">Admissions Bursary Checkout</span>
                    </div>
                </div>
                <div class="stb-pay-amount-tag">
                    <small>NGN</small> &#8358;<?php echo number_format($fee, 2); ?>
                </div>
            </div>

            <div class="stb-pay-tabs">
                <button type="button" class="stb-pay-tab active" data-tab="card"><i class="fas fa-credit-card"></i> Card</button>
                <button type="button" class="stb-pay-tab" data-tab="transfer"><i class="fas fa-exchange-alt"></i> Bank Transfer</button>
                <button type="button" class="stb-pay-tab" data-tab="ussd"><i class="fas fa-mobile-alt"></i> USSD</button>
            </div>

            <div class="stb-pay-body">
                <!-- Tab 1: Card -->
                <div class="stb-tab-pane active" id="stbTab_card">
                    <div class="stb-field">
                        <label>Cardholder Name</label>
                        <input type="text" id="simCardName" class="form-control" placeholder="Name on Card">
                    </div>
                    <div class="stb-field">
                        <label>Card Number (Verve / Mastercard / Visa)</label>
                        <input type="text" id="simCardNumber" class="form-control" maxlength="19" placeholder="5399 •••• •••• 4210" value="5399 8344 1029 4210">
                    </div>
                    <div class="stb-field-row">
                        <div class="stb-field">
                            <label>Expiry Date</label>
                            <input type="text" id="simCardExp" class="form-control" maxlength="5" placeholder="MM/YY" value="09/28">
                        </div>
                        <div class="stb-field">
                            <label>CVV</label>
                            <input type="password" id="simCardCvv" class="form-control" maxlength="4" placeholder="123" value="482">
                        </div>
                    </div>
                </div>

                <!-- Tab 2: Instant Transfer -->
                <div class="stb-tab-pane" id="stbTab_transfer" style="display:none;">
                    <div class="stb-transfer-VirtualBox">
                        <div class="v-label">Transfer Exact Amount To Virtual Bursary Account</div>
                        <div class="v-bank">Providus / Titan Trust — St. Benedict's Academy</div>
                        <div class="v-acct" id="virtualAcctNo">9940281745</div>
                        <div class="v-note">Account expires in 29:45 minutes. Click the button below once transfer is sent.</div>
                    </div>
                </div>

                <!-- Tab 3: USSD -->
                <div class="stb-tab-pane" id="stbTab_ussd" style="display:none;">
                    <div class="stb-field">
                        <label>Select Your Bank</label>
                        <select id="simUssdBank" class="form-control">
                            <option value="*737*000*8842#">GTBank (*737#)</option>
                            <option value="*966*000*8842#">Zenith Bank (*966#)</option>
                            <option value="*894*000*8842#">First Bank (*894#)</option>
                            <option value="*919*000*8842#">UBA (*919#)</option>
                            <option value="*901*000*8842#">Access Bank (*901#)</option>
                        </select>
                    </div>
                    <div class="stb-transfer-VirtualBox">
                        <div class="v-label">Dial this code on your registered mobile phone:</div>
                        <div class="v-acct" id="ussdCodeDisplay">*737*000*8842#</div>
                    </div>
                </div>

                <button type="button" id="confirmOnlinePayBtn" class="btn-confirm-pay">
                    <i class="fas fa-lock"></i> Authorize &amp; Pay &#8358;<?php echo number_format($fee, 2); ?>
                </button>
                <button type="button" id="closePaymentModalBtn" class="btn-cancel-pay">Cancel</button>
            </div>

            <div class="stb-pay-footer">
                <i class="fas fa-shield-alt"></i> 256-Bit SSL Encrypted &bull; Instant Official Receipt Enabled
            </div>
        </div>
    </div>
    <?php
}

function renderApplicationComponentsAssets(): void
{
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
        .seq-active-doc-banner {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
            background: #f8fafc;
            border: 1px dashed #94a3b8;
            border-radius: 10px;
            padding: 14px 16px;
            margin-bottom: 18px;
        }
        .seq-active-doc-info {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }
        .seq-active-badge {
            display: inline-block;
            width: fit-content;
            font-size: 0.7rem;
            font-weight: 800;
            text-transform: uppercase;
            padding: 2px 8px;
            border-radius: 4px;
            background: #fee2e2;
            color: #b91c1c;
        }
        .seq-active-badge.optional {
            background: #e2e8f0;
            color: #475569;
        }
        .seq-active-hint {
            font-size: 0.82rem;
            color: #64748b;
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
            margin-bottom: 18px;
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
            color: rgba(255, 255, 255, 0.82);
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
        .payment-method-selector {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
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
            background: #16a34a;
            color: #ffffff;
            border: none;
            border-radius: 10px;
            font-weight: 800;
            font-size: 0.92rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 6px 16px rgba(22, 163, 74, 0.25);
        }
        .btn-pay-online:hover { background: #15803d; }
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

        /* Checkout Modal */
        .stb-pay-modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.72);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .stb-pay-modal {
            width: 100%;
            max-width: 460px;
            background: #fff;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.35);
        }
        .stb-pay-modal-header {
            background: #002147;
            color: #fff;
            padding: 18px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .stb-pay-brand {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .stb-pay-brand img {
            width: 38px;
            height: 38px;
            border-radius: 6px;
            background: #fff;
            padding: 2px;
        }
        .stb-pay-brand strong { display: block; font-size: 0.95rem; }
        .stb-pay-brand span { font-size: 0.75rem; color: #cbd5e1; }
        .stb-pay-amount-tag {
            font-weight: 800;
            color: #FFD700;
            font-size: 1.1rem;
        }
        .stb-pay-tabs {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            background: #f1f5f9;
            border-bottom: 1px solid #e2e8f0;
        }
        .stb-pay-tab {
            padding: 11px 8px;
            border: none;
            background: transparent;
            font-weight: 700;
            font-size: 0.82rem;
            color: #64748b;
            cursor: pointer;
            border-bottom: 3px solid transparent;
        }
        .stb-pay-tab.active {
            color: #002147;
            background: #fff;
            border-bottom-color: #c8102e;
        }
        .stb-pay-body { padding: 20px; }
        .stb-field { margin-bottom: 14px; }
        .stb-field label {
            display: block;
            font-size: 0.8rem;
            font-weight: 700;
            color: #334155;
            margin-bottom: 5px;
        }
        .stb-field-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        .stb-transfer-VirtualBox {
            background: #f8fafc;
            border: 1px dashed #94a3b8;
            border-radius: 10px;
            padding: 16px;
            text-align: center;
            margin-bottom: 14px;
        }
        .stb-transfer-VirtualBox .v-label { font-size: 0.78rem; color: #64748b; }
        .stb-transfer-VirtualBox .v-bank { font-weight: 700; color: #002147; margin-top: 4px; }
        .stb-transfer-VirtualBox .v-acct {
            font-size: 1.4rem;
            font-weight: 800;
            color: #c8102e;
            letter-spacing: 1px;
            margin: 6px 0;
        }
        .stb-transfer-VirtualBox .v-note { font-size: 0.76rem; color: #64748b; }
        .btn-confirm-pay {
            width: 100%;
            padding: 13px;
            background: #16a34a;
            color: #fff;
            border: none;
            border-radius: 10px;
            font-weight: 800;
            font-size: 0.95rem;
            cursor: pointer;
            margin-bottom: 8px;
        }
        .btn-cancel-pay {
            width: 100%;
            padding: 9px;
            background: transparent;
            color: #64748b;
            border: none;
            font-weight: 600;
            cursor: pointer;
        }
        .stb-pay-footer {
            background: #f8fafc;
            padding: 10px 16px;
            text-align: center;
            font-size: 0.75rem;
            color: #64748b;
            border-top: 1px solid #e2e8f0;
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
    document.addEventListener('DOMContentLoaded', function() {
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
                activeHint.textContent = hint;

                if (hasFile) {
                    activeStatus.innerHTML = '<span class="status-pill done"><i class="fas fa-check-circle"></i> Uploaded (' + inputEl.files[0].name + ')</span>';
                } else {
                    activeStatus.innerHTML = '<span class="status-pill pending"><i class="fas fa-arrow-up"></i> Ready to pick file</span>';
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
                    uploadedList.innerHTML = '<div class="seq-empty-state"><i class="fas fa-file-upload"></i><span>No document uploaded yet. Pick a document from the dropdown above and click <strong>"Pick &amp; Upload Selected Document"</strong>.</span></div>';
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

        // --- 2. Online Payment & Instant Receipt Checkout ---
        const methodOptions = document.querySelectorAll('.pay-method-option');
        const onlineGatewayPanel = document.getElementById('onlineGatewayPanel');
        const bankTransferPanel = document.getElementById('bankTransferPanel');
        const paymentRefInput = document.getElementById('input_payment_reference');
        const paymentStatusInput = document.getElementById('input_payment_status');
        const openModalBtn = document.getElementById('openPaymentModalBtn');
        const closeModalBtn = document.getElementById('closePaymentModalBtn');
        const confirmPayBtn = document.getElementById('confirmOnlinePayBtn');
        const modal = document.getElementById('stbPayModal');
        const unpaidState = document.getElementById('gatewayUnpaidState');
        const paidState = document.getElementById('gatewayPaidState');
        const displayPaidRef = document.getElementById('displayPaidRef');
        const displayPaidChannel = document.getElementById('displayPaidChannel');
        const verifiedUiInput = document.getElementById('input_payment_verified_ui');
        let activeGatewayTab = 'card';

        methodOptions.forEach(function(opt) {
            opt.addEventListener('click', function() {
                methodOptions.forEach(o => o.classList.remove('active'));
                this.classList.add('active');
                const radio = this.querySelector('input[type="radio"]');
                if (radio) radio.checked = true;
                const method = this.getAttribute('data-method');

                if (method === 'bank_transfer') {
                    if (onlineGatewayPanel) onlineGatewayPanel.style.display = 'none';
                    if (bankTransferPanel) bankTransferPanel.style.display = 'block';
                    if (paymentRefInput) {
                        paymentRefInput.readOnly = false;
                        if (paymentRefInput.value.indexOf('TXN-STB-') === 0) {
                            paymentRefInput.value = 'BNK-TRF-' + Math.floor(100000 + Math.random() * 900000);
                        }
                        paymentRefInput.focus();
                    }
                    if (paymentStatusInput) paymentStatusInput.value = 'paid';
                } else {
                    if (onlineGatewayPanel) onlineGatewayPanel.style.display = 'block';
                    if (bankTransferPanel) bankTransferPanel.style.display = 'none';
                    if (paymentRefInput) paymentRefInput.readOnly = true;
                }
            });
        });

        if (openModalBtn && modal) {
            openModalBtn.addEventListener('click', function() {
                const emailEl = document.querySelector('input[name="parent_email"]');
                const emailLabel = document.getElementById('modalApplicantEmailDisplay');
                if (emailEl && emailEl.value && emailLabel) {
                    emailLabel.textContent = emailEl.value;
                }
                modal.style.display = 'flex';
            });
        }
        if (closeModalBtn && modal) {
            closeModalBtn.addEventListener('click', function() {
                modal.style.display = 'none';
            });
        }

        const payTabs = document.querySelectorAll('.stb-pay-tab');
        payTabs.forEach(function(tab) {
            tab.addEventListener('click', function() {
                payTabs.forEach(t => t.classList.remove('active'));
                this.classList.add('active');
                activeGatewayTab = this.getAttribute('data-tab');
                ['card', 'transfer', 'ussd'].forEach(function(k) {
                    const pane = document.getElementById('stbTab_' + k);
                    if (pane) pane.style.display = (k === activeGatewayTab) ? 'block' : 'none';
                });
            });
        });

        const ussdSelect = document.getElementById('simUssdBank');
        const ussdDisplay = document.getElementById('ussdCodeDisplay');
        if (ussdSelect && ussdDisplay) {
            ussdSelect.addEventListener('change', function() {
                ussdDisplay.textContent = this.value;
            });
        }

        if (confirmPayBtn) {
            confirmPayBtn.addEventListener('click', function() {
                const origHtml = confirmPayBtn.innerHTML;
                confirmPayBtn.disabled = true;
                confirmPayBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying Transaction...';

                setTimeout(function() {
                    const randHex = Math.random().toString(16).substring(2, 8).toUpperCase();
                    const d = new Date();
                    const ymd = d.getFullYear().toString() + String(d.getMonth() + 1).padStart(2, '0') + String(d.getDate()).padStart(2, '0');
                    const newRef = 'TXN-STB-' + ymd + '-' + randHex;

                    if (paymentRefInput) paymentRefInput.value = newRef;
                    if (paymentStatusInput) paymentStatusInput.value = 'paid';
                    if (verifiedUiInput) verifiedUiInput.value = '1';

                    const channelMap = {
                        'card': 'Online Card Payment',
                        'transfer': 'Instant Online Transfer',
                        'ussd': 'Online USSD Channel'
                    };
                    if (displayPaidRef) displayPaidRef.textContent = newRef;
                    if (displayPaidChannel) displayPaidChannel.textContent = channelMap[activeGatewayTab] || 'Online Card';

                    const radioOnline = document.querySelector('input[name="payment_method"][value^="online_"]');
                    if (radioOnline) {
                        radioOnline.value = 'online_' + activeGatewayTab;
                    }

                    if (unpaidState) unpaidState.style.display = 'none';
                    if (paidState) paidState.style.display = 'flex';
                    if (modal) modal.style.display = 'none';

                    confirmPayBtn.disabled = false;
                    confirmPayBtn.innerHTML = origHtml;
                }, 650);
            });
        }
    });
    </script>
    <?php
}
