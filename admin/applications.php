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

    // Action 2b: Confirm School Payment (Admissions Office / Bursary Receipt)
    if ($postAction === 'confirm_school_payment') {
        $appNumber = trim((string)($_POST['application_number'] ?? ''));
        $rcpNo = trim((string)($_POST['receipt_number'] ?? '')) ?: ApplicationHelper::generateReceiptNumber();
        $channel = trim((string)($_POST['payment_channel'] ?? 'cash_at_school'));

        if ($appNumber === '') {
            flash_redirect('Application number is required to confirm school payment.', 'error');
        }

        if (ApplicationHelper::confirmSchoolPayment($appNumber, $rcpNo, $channel, (int)($_SESSION['user_id'] ?? 0))) {
            $appRow = ApplicationHelper::findApplication($appNumber);
            if ($appRow && !empty($appRow['parent_email'])) {
                $receiptUrl = BASE_URL . '/public/application-receipt?app=' . urlencode($appNumber) . '&token=' . urlencode($appRow['receipt_token']);
                sendEmail($appRow['parent_email'], 'Official Payment Receipt Confirmed - ' . SCHOOL_NAME,
                    "<div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;border:1px solid #e2e8f0;border-radius:10px;'>"
                    . "<h2 style='background:#002147;color:#FFD700;padding:16px;text-align:center;border-radius:6px;margin-top:0;'>Official Payment Receipt Confirmed</h2>"
                    . "<p>Dear " . e($appRow['parent_name']) . ",</p>"
                    . "<p>Your payment of <strong>&#8358;" . number_format($appRow['amount_paid'], 2) . "</strong> for admission application <strong>" . e($appNumber) . "</strong> has been confirmed by St. Benedict's Academy Bursary.</p>"
                    . "<ul><li><strong>Candidate:</strong> " . e($appRow['child_full_name']) . "</li>"
                    . "<li><strong>Class Applying For:</strong> " . e($appRow['class_applying']) . "</li>"
                    . "<li><strong>Receipt Number:</strong> " . e($rcpNo) . "</li>"
                    . "<li><strong>Payment Channel:</strong> " . e(ApplicationHelper::formatPaymentMethod($channel)) . "</li></ul>"
                    . "<p style='text-align:center;margin:24px 0;'><a href='" . e($receiptUrl) . "' style='background:#002147;color:#FFD700;padding:12px 24px;text-decoration:none;border-radius:6px;font-weight:bold;'>View &amp; Print Official Receipt</a></p>"
                    . "<p>Our admissions office will reach out regarding your child's teacher assessment interview schedule.</p>"
                    . "<p><strong>Admissions &amp; Bursary Office</strong><br>" . e(SCHOOL_NAME) . "</p></div>"
                );
            }
            Security::logAudit('CONFIRMED_SCHOOL_PAYMENT', 'applications', null, null, ['app_number' => $appNumber, 'receipt_number' => $rcpNo]);
            flash_redirect("School payment confirmed and official receipt ({$rcpNo}) issued for application {$appNumber}.", 'success');
        } else {
            flash_redirect("Could not confirm school payment for {$appNumber}.", 'error');
        }
    }

    // Action 2c: Teacher Interview & Admissions Decision (Accept or Reject)
    if ($postAction === 'interview_decision') {
        $kind = $_POST['kind'] ?? 'app';
        $id = (int)($_POST['id'] ?? 0);
        $decision = $_POST['decision'] ?? 'accepted'; // 'accepted' or 'rejected'
        $interviewResult = $_POST['interview_result'] ?? ($decision === 'accepted' ? 'passed' : 'failed');
        $interviewNotes = mb_substr(Security::sanitize($_POST['interview_notes'] ?? ''), 0, 1500);
        $interviewTeacher = mb_substr(Security::sanitize($_POST['interview_teacher'] ?? ''), 0, 150);
        $admittedClass = mb_substr(Security::sanitize($_POST['admitted_class'] ?? ''), 0, 100);

        $table = ['app' => 'applications', 'enq' => 'admissions'][$kind] ?? 'applications';
        if (!$id || !in_array($decision, ['accepted', 'rejected', 'reviewing', 'pending'], true)) {
            flash_redirect('Invalid admissions decision request.', 'error');
        }

        $appData = $db->getRow("SELECT * FROM {$table} WHERE id = ?", [$id]);
        if (!$appData) {
            flash_redirect('Application not found.', 'error');
        }

        $norm = ApplicationHelper::normalizeAdmissionRow($appData, $table);
        if ($admittedClass === '') {
            $admittedClass = $norm['class_applying'];
        }

        $db->query("UPDATE {$table} SET status = ?, remarks = ?, interview_result = ?, interview_notes = ?, interview_teacher = ?, admitted_class = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?",
            [$decision, $interviewNotes, $interviewResult, $interviewNotes, $interviewTeacher, $admittedClass, $_SESSION['user_id'], $id]
        );

        Security::logAudit('ADMISSIONS_DECISION', $table, $id, null, [
            'decision' => $decision,
            'interview_result' => $interviewResult,
            'admitted_class' => $admittedClass
        ]);

        // Send Email to Parent based on decision
        if (!empty($norm['parent_email'])) {
            if ($decision === 'accepted') {
                sendEmail($norm['parent_email'], 'Offer of Admission - ' . SCHOOL_NAME,
                    "<div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:24px;border:1px solid #e2e8f0;border-radius:12px;'>"
                    . "<h2 style='background:#002147;color:#FFD700;padding:16px;text-align:center;border-radius:6px;margin-top:0;'>Provisional Offer of Admission</h2>"
                    . "<p>Dear " . e($norm['parent_name']) . ",</p>"
                    . "<p>We are delighted to inform you that following the entrance assessment and interview by our teaching faculty, <strong>" . e($norm['child_full_name']) . "</strong> has been offered admission into <strong>" . e($admittedClass) . "</strong> at " . e(SCHOOL_NAME) . " for the " . e($norm['academic_session']) . " academic session!</p>"
                    . "<div style='background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:14px;margin:18px 0;'>"
                    . "<strong style='color:#15803d;'>Faculty Interview Assessment:</strong>"
                    . "<p style='margin:6px 0 0;font-size:0.9rem;color:#166534;'>" . nl2br(e($interviewNotes ?: 'Candidate demonstrated excellent readiness for the class.')) . "</p>"
                    . "</div>"
                    . "<p>Please visit the school admissions office to complete enrolment formalities or access your parent portal to view term dates, school fees, and requirements.</p>"
                    . "<p>Congratulations once again!</p>"
                    . "<p><strong>Admissions Committee</strong><br>" . e(SCHOOL_NAME) . "</p></div>"
                );
            } elseif ($decision === 'rejected') {
                sendEmail($norm['parent_email'], 'Admission Assessment Feedback - ' . SCHOOL_NAME,
                    "<div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:24px;border:1px solid #e2e8f0;border-radius:12px;'>"
                    . "<h2 style='background:#002147;color:#ffffff;padding:16px;text-align:center;border-radius:6px;margin-top:0;'>Admission Assessment Update</h2>"
                    . "<p>Dear " . e($norm['parent_name']) . ",</p>"
                    . "<p>Thank you for giving us the privilege of meeting <strong>" . e($norm['child_full_name']) . "</strong> for the entrance assessment and teacher interview for <strong>" . e($norm['class_applying']) . "</strong>.</p>"
                    . "<p>Following careful evaluation by our early years / primary teachers, the candidate did not meet the specific academic / age developmental readiness criteria required for this class at this time.</p>"
                    . "<div style='background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:14px;margin:18px 0;'>"
                    . "<strong style='color:#b91c1c;'>Teacher Assessment Remarks:</strong>"
                    . "<p style='margin:6px 0 0;font-size:0.9rem;color:#991b1b;'>" . nl2br(e($interviewNotes ?: 'Candidate did not meet the readiness threshold for this class.')) . "</p>"
                    . "</div>"
                    . "<p>We warmly encourage you to discuss developmental coaching options or a reassessment for the next term with our academic head. Please contact the school office at " . e(school_phone()) . ".</p>"
                    . "<p>Sincerely,</p>"
                    . "<p><strong>Admissions Committee</strong><br>" . e(SCHOOL_NAME) . "</p></div>"
                );
            }
        }

        $msgText = ($decision === 'accepted')
            ? "Candidate {$norm['child_full_name']} ACCEPTED for {$admittedClass} (Interview Passed). Acceptance email sent."
            : "Candidate {$norm['child_full_name']} marked as REJECTED (Did Not Pass Interview for that class). Parent notified.";
        flash_redirect($msgText, $decision === 'accepted' ? 'success' : 'warning');
    }

    // Action 2d: Create Parent Portal Login
    if ($postAction === 'create_parent_login') {
        $kind = $_POST['kind'] ?? 'app';
        $id = (int)($_POST['id'] ?? 0);
        $table = ['app' => 'applications', 'enq' => 'admissions'][$kind] ?? 'applications';

        $appData = $db->getRow("SELECT * FROM {$table} WHERE id = ?", [$id]);
        if (!$appData) {
            flash_redirect('Application not found.', 'error');
        }

        $norm = ApplicationHelper::normalizeAdmissionRow($appData, $table);
        $parentEmail = trim($norm['parent_email']);
        $parentPhone = trim($norm['parent_phone']);

        if ($parentEmail === '') {
            flash_redirect('Parent email address is required to create a parent portal login.', 'error');
        }

        try {
            $db->beginTransaction();

            // 1. Check or create parent user in `users`
            $existingUser = $db->getRow("SELECT id, username, first_name, last_name FROM users WHERE email = ? LIMIT 1", [$parentEmail]);
            $oneTimePassword = null;
            $parentUserId = null;
            $username = null;

            if ($existingUser) {
                $parentUserId = (int)$existingUser['id'];
                $username = $existingUser['username'];
            } else {
                do {
                    $oneTimePassword = generateRandomString(10);
                } while (!preg_match('/[A-Za-z]/', $oneTimePassword) || !preg_match('/\d/', $oneTimePassword));

                // Generate clean unique username
                $emailPrefix = strtolower(preg_replace('/[^a-z0-9]/', '', explode('@', $parentEmail)[0]));
                $username = 'p_' . substr($emailPrefix, 0, 16);
                $uCheck = $db->getRow("SELECT id FROM users WHERE username = ? LIMIT 1", [$username]);
                if ($uCheck) {
                    $username .= rand(10, 99);
                }

                $parentFirst = $norm['child_first_name'] ? ($appData['parent_first_name'] ?? 'Parent') : 'Parent';
                $parentLast  = $appData['parent_last_name'] ?? $norm['child_last_name'];

                $parentUserId = $db->insert(
                    "INSERT INTO users (username, email, password_hash, first_name, last_name, phone, role, is_active, must_change_password)
                     VALUES (?, ?, ?, ?, ?, ?, 'parent', 1, 1)",
                    [
                        $username,
                        $parentEmail,
                        Security::hashPassword($oneTimePassword),
                        $parentFirst,
                        $parentLast,
                        $parentPhone
                    ]
                );
            }

            // 2. Check or create `parents` table record
            $parentRecord = $db->getRow("SELECT id FROM parents WHERE user_id = ? LIMIT 1", [$parentUserId]);
            $parentId = null;
            if ($parentRecord) {
                $parentId = (int)$parentRecord['id'];
            } else {
                $parentId = $db->insert(
                    "INSERT INTO parents (user_id, occupation, address, emergency_contact, relationship)
                     VALUES (?, ?, ?, ?, ?)",
                    [
                        $parentUserId,
                        $norm['parent_occupation'] ?: 'Parent',
                        $norm['address'] ?: 'Enugu',
                        $norm['emergency_phone'] ?: $parentPhone,
                        $norm['parent_relationship'] ?: 'Parent'
                    ]
                );
            }

            // 3. Create or link Student record for the child
            $classId = null;
            $classTarget = $norm['admitted_class'] ?: $norm['class_applying'];
            $classRow = $db->getRow("SELECT id FROM classes WHERE class_name = ? OR CONCAT(class_name, ' ', section) = ? LIMIT 1", [$classTarget, $classTarget]);
            if ($classRow) {
                $classId = (int)$classRow['id'];
            } else {
                $anyClass = $db->getRow("SELECT id FROM classes ORDER BY id ASC LIMIT 1");
                $classId = $anyClass ? (int)$anyClass['id'] : null;
            }

            // Check if student already enrolled
            $studentId = (int)($norm['student_id'] ?: 0);
            $studentAdmissionNo = null;

            if (!$studentId) {
                // Generate unique Admission Number: STB/{Year}/{Seq}
                $yearCode = date('Y');
                $seq = rand(100, 999);
                $studentAdmissionNo = "STB/{$yearCode}/{$seq}";

                // Create student user record
                $stuUsername = 'stu_' . strtolower(preg_replace('/[^a-z0-9]/', '', $norm['child_first_name'])) . rand(10, 99);
                $stuUserId = $db->insert(
                    "INSERT INTO users (username, email, password_hash, first_name, last_name, role, is_active)
                     VALUES (?, ?, ?, ?, ?, 'student', 1)",
                    [
                        $stuUsername,
                        'stu_' . bin2hex(random_bytes(4)) . '@stbenedictsacademy.com.ng',
                        Security::hashPassword(generateRandomString(12)),
                        $norm['child_first_name'],
                        $norm['child_last_name']
                    ]
                );

                $studentId = $db->insert(
                    "INSERT INTO students (user_id, admission_number, class_id, parent_id, date_of_birth, gender, admission_date, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, NOW(), 1)",
                    [
                        $stuUserId,
                        $studentAdmissionNo,
                        $classId,
                        $parentId,
                        $norm['date_of_birth'] ?: date('Y-m-d', strtotime('-4 years')),
                        strtolower($norm['gender']) === 'female' ? 'female' : 'male'
                    ]
                );
            }

            // Update application with parent_user_id and student_id
            $db->query("UPDATE {$table} SET parent_user_id = ?, student_id = ? WHERE id = ?", [$parentUserId, $studentId, $id]);

            $db->commit();

            // Dispatch Parent Welcome & Login Credentials Email
            $loginUrl = BASE_URL . '/login';
            $pwMessage = $oneTimePassword
                ? "<p><strong>One-Time Password:</strong> <code style='font-size:1.15rem;font-weight:bold;color:#002147;background:#f1f5f9;padding:4px 8px;border-radius:4px;'>{$oneTimePassword}</code> (You will be prompted to choose a new password upon first login).</p>"
                : "<p>Your account is active. You may use your existing parent portal password or click 'Forgot Password' on the login page.</p>";

            sendEmail($parentEmail, 'Parent Portal Access Credentials - ' . SCHOOL_NAME,
                "<div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:24px;border:1px solid #e2e8f0;border-radius:12px;'>"
                . "<h2 style='background:#002147;color:#FFD700;padding:16px;text-align:center;border-radius:6px;margin-top:0;'>St. Benedict's Academy Parent Portal</h2>"
                . "<p>Dear " . e($norm['parent_name']) . ",</p>"
                . "<p>Your official parent portal account for <strong>" . e(SCHOOL_NAME) . "</strong> has been created successfully.</p>"
                . "<div style='background:#f8fafc;border:1px solid #cbd5e1;border-radius:8px;padding:16px;margin:18px 0;'>"
                . "<p style='margin:0 0 8px;'><strong>Portal URL:</strong> <a href='{$loginUrl}'>{$loginUrl}</a></p>"
                . "<p style='margin:0 0 8px;'><strong>Username:</strong> <code>{$username}</code></p>"
                . $pwMessage
                . "</div>"
                . "<h4 style='color:#002147;margin-bottom:8px;'>What you can do on the Parent Portal:</h4>"
                . "<ul style='color:#334155;line-height:1.6;margin-top:0;'>"
                . "<li><strong>Academic Report Cards:</strong> Check, review and print your child's terminal and annual report sheets.</li>"
                . "<li><strong>Official Notifications:</strong> Receive real-time announcements, newsletters, and messages from school management.</li>"
                . "<li><strong>School Fee Payments:</strong> View fee invoices and make secure online card/transfer payments for your children and other students/wards.</li>"
                . "</ul>"
                . "<p style='text-align:center;margin:24px 0;'><a href='{$loginUrl}' style='background:#002147;color:#FFD700;padding:12px 24px;text-decoration:none;border-radius:6px;font-weight:bold;'>Log In to Parent Portal</a></p>"
                . "<p>Warm regards,<br><strong>School Administration</strong><br>" . e(SCHOOL_NAME) . "</p></div>"
            );

            Security::logAudit('CREATED_PARENT_LOGIN', 'users', $parentUserId, null, ['parent_id' => $parentId, 'student_id' => $studentId]);

            // Save credentials in session to display in copy / WhatsApp modal
            $_SESSION['parent_login_modal'] = [
                'parent_name' => $norm['parent_name'],
                'child_name'  => $norm['child_full_name'],
                'email'       => $parentEmail,
                'phone'       => $parentPhone,
                'username'    => $username,
                'password'    => $oneTimePassword ?: '(Existing Password)',
                'login_url'   => $loginUrl,
                'admission_no'=> $studentAdmissionNo ?: ('Student #' . $studentId)
            ];

            flash_redirect("Parent Portal Login created successfully for {$norm['parent_name']} ({$username}). Credentials dispatched to {$parentEmail}.", 'success');

        } catch (Throwable $e) {
            $db->rollBack();
            error_log("create_parent_login error: " . $e->getMessage());
            flash_redirect("Could not create parent login: " . $e->getMessage(), 'error');
        }
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
$apps = $db->getRows("SELECT id, application_number, CONCAT(child_first_name, ' ', child_last_name) AS child, child_first_name, child_last_name, child_dob, child_gender, class_applying AS class_name,
        CONCAT(parent_first_name, ' ', parent_last_name) AS parent_name, parent_first_name, parent_last_name, parent_email, parent_phone, address, city, state, lga, country, previous_school,
        reason_applying, birth_certificate_path, passport_photo_path, documents_path,
        payment_method, receipt_number, payment_reference, amount_paid, payment_status, paid_at, status, remarks,
        interview_result, interview_notes, interview_teacher, admitted_class, parent_user_id, student_id, created_at
        FROM applications $where ORDER BY created_at DESC LIMIT 200", $params);
$enquiries = $db->getRows("SELECT id, application_number, CONCAT(first_name, ' ', last_name) AS child, first_name AS child_first_name, last_name AS child_last_name, date_of_birth AS child_dob, gender AS child_gender,
        class_applying_for AS class_name, parent_name, parent_email, parent_phone, address, city, state, lga, country, previous_school, documents_path,
        payment_method, receipt_number, payment_reference, amount_paid, payment_status, paid_at, status, remarks,
        interview_result, interview_notes, interview_teacher, admitted_class, parent_user_id, student_id, submitted_at AS created_at
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

$credModal = $_SESSION['parent_login_modal'] ?? null;
if ($credModal):
    unset($_SESSION['parent_login_modal']);
    $waText = urlencode("Hello " . $credModal['parent_name'] . ", here are your official Parent Portal login details for St. Benedict's Early Years British Academy:\n\nPortal: " . $credModal['login_url'] . "\nUsername: " . $credModal['username'] . "\nTemporary Password: " . $credModal['password'] . "\nChild: " . $credModal['child_name'] . " (" . $credModal['admission_no'] . ")\n\nYou can now log in to check results, view school notifications, and pay school fees.");
    $cleanPhone = preg_replace('/\D+/', '', $credModal['phone']);
    if (strpos($cleanPhone, '0') === 0) { $cleanPhone = '234' . substr($cleanPhone, 1); }
?>
<div class="credential-modal-backdrop" id="credModalBackdrop">
    <div class="credential-modal-card">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; border-bottom:1px solid #e2e8f0; padding-bottom:10px;">
            <h3 style="color:#002147; margin:0; font-size:1.15rem;"><i class="fas fa-key" style="color:#FFD700;"></i> Parent Portal Login Created</h3>
            <button type="button" onclick="document.getElementById('credModalBackdrop').style.display='none'" style="background:none; border:none; font-size:1.2rem; cursor:pointer; color:#64748b;">&times;</button>
        </div>
        <p style="font-size:0.88rem; color:#475569; margin-bottom:14px;">The parent account has been created and credentials sent to <strong><?php echo e($credModal['email']); ?></strong>. You can also copy or dispatch directly to the parent via WhatsApp below:</p>

        <div style="background:#f8fafc; border:1px solid #cbd5e1; border-radius:10px; padding:14px; margin-bottom:16px; font-size:0.9rem;">
            <div><strong>Parent:</strong> <?php echo e($credModal['parent_name']); ?></div>
            <div><strong>Student / Ward:</strong> <?php echo e($credModal['child_name']); ?> (<?php echo e($credModal['admission_no']); ?>)</div>
            <div style="margin-top:6px;"><strong>Username:</strong> <code id="copyUsername" style="font-size:1rem; color:#002147; font-weight:700;"><?php echo e($credModal['username']); ?></code></div>
            <div><strong>One-Time Password:</strong> <code id="copyPassword" style="font-size:1.1rem; color:#c8102e; font-weight:800;"><?php echo e($credModal['password']); ?></code></div>
            <div><strong>Login URL:</strong> <a href="<?php echo e($credModal['login_url']); ?>" target="_blank"><?php echo e($credModal['login_url']); ?></a></div>
        </div>

        <div style="display:flex; gap:10px; flex-wrap:wrap; justify-content:flex-end;">
            <button type="button" class="btn btn-outline" onclick="
                const text = 'Username: <?php echo e($credModal['username']); ?>\nPassword: <?php echo e($credModal['password']); ?>\nLogin URL: <?php echo e($credModal['login_url']); ?>';
                navigator.clipboard && navigator.clipboard.writeText(text);
                this.innerHTML = '<i class=\'fas fa-check\'></i> Copied!';
                setTimeout(() => this.innerHTML = '<i class=\'fas fa-copy\'></i> Copy Credentials', 2000);
            ">
                <i class="fas fa-copy"></i> Copy Credentials
            </button>
            <?php if ($cleanPhone): ?>
            <a href="https://wa.me/<?php echo e($cleanPhone); ?>?text=<?php echo $waText; ?>" target="_blank" class="btn btn-primary" style="background:#25D366; color:#fff; border:none; font-weight:700;">
                <i class="fab fa-whatsapp"></i> Share on WhatsApp
            </a>
            <?php endif; ?>
            <button type="button" class="btn btn-secondary" onclick="document.getElementById('credModalBackdrop').style.display='none'">Close</button>
        </div>
    </div>
</div>
<?php endif; ?>

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
.pay-pill.pending_school_payment {
    background: #fef3c7;
    color: #92400e;
}
.app-photo-thumb {
    width: 48px;
    height: 58px;
    border-radius: 6px;
    border: 1px solid #cbd5e1;
    object-fit: cover;
    background: #f1f5f9;
}
.app-details-header {
    display: flex;
    align-items: center;
    gap: 16px;
    margin-bottom: 14px;
    padding-bottom: 14px;
    border-bottom: 1px solid #e2e8f0;
}
.interview-decision-card {
    background: #f8fafc;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    padding: 16px 18px;
    margin-top: 14px;
}
.credential-modal-backdrop {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(15, 23, 42, 0.65);
    z-index: 99999;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
}
.credential-modal-card {
    background: #ffffff;
    border-radius: 14px;
    max-width: 520px;
    width: 100%;
    padding: 24px;
    box-shadow: 0 20px 40px rgba(0,0,0,0.25);
    border: 1px solid #e2e8f0;
}
</style>

<!-- Backend Payment KPI Summary -->
<div class="payment-kpi-grid">
    <div class="payment-kpi-card">
        <div class="kpi-label"><i class="fas fa-receipt"></i> Total Payment Records</div>
        <div class="kpi-val"><?php echo (int)$totalLedgerCount; ?></div>
    </div>
    <div class="payment-kpi-card">
        <div class="kpi-label"><i class="fas fa-check-circle" style="color:#16a34a;"></i> Verified / Paid Payments</div>
        <div class="kpi-val" style="color:#15803d;">₦<?php echo number_format($verifiedTotalAmount, 2); ?></div>
    </div>
    <div class="payment-kpi-card">
        <div class="kpi-label"><i class="fas fa-clock" style="color:#d97706;"></i> Pending Verification</div>
        <div class="kpi-val" style="color:#b45309;"><?php echo (int)$pendingVerifyCount; ?></div>
    </div>
    <div class="payment-kpi-card">
        <div class="kpi-label"><i class="fas fa-university"></i> School Bank Account</div>
        <div class="kpi-val" style="font-size:1.1rem; color:#002147; line-height:1.25;"><?php echo e(school_bank_name()); ?><br><span style="font-size:0.95rem; font-weight:600; color:#475569;"><?php echo e(school_account_number()); ?></span></div>
    </div>
</div>

<!-- Instant Paystack Reference Verification Bar -->
<div class="verify-bar-card">
    <h3 style="color:#FFD700; margin:0; font-size:1rem;"><i class="fas fa-shield-alt"></i> Verify Paystack Reference</h3>
    <form method="POST" class="verify-bar-form">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="post_action" value="verify_paystack">
        <input type="text" name="payment_reference" placeholder="Enter Reference (e.g. PSTK-STB-2026...)" required>
        <button type="submit" class="btn btn-primary" style="background:#FFD700; color:#002147; border:none; font-weight:700;">
            <i class="fas fa-sync-alt"></i> Verify
        </button>
    </form>
</div>

<!-- Unified Online & Bank Application Payments Ledger -->
<div class="card" id="payments-ledger">
    <div class="card-header">
        <h3><i class="fas fa-credit-card"></i> Payments Ledger (<?php echo count($paymentsLedger); ?>)</h3>
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
    $isSchoolPayment = ($payStatus === 'pending_school_payment' || ($a['payment_method'] ?? '') === 'pay_at_school');
    $amtPaid = (float)($a['amount_paid'] ?? ApplicationHelper::APPLICATION_FEE);
    $payRef = $a['payment_reference'] ?? '';
    $interviewRes = strtolower((string)($a['interview_result'] ?? 'pending'));

    // Check for passport photo
    $docsArr = !empty($a['documents_path']) ? (json_decode($a['documents_path'], true) ?: []) : [];
    $photoFilename = $docsArr['passport_photo'] ?? ($a['passport_photo_path'] ?? null);
    $hasPhoto = !empty($photoFilename);
    $photoUrl = $hasPhoto ? "?file={$kind}&id={$a['id']}&doc=passport_photo" : null;
?>
    <details class="app-item">
        <summary style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
            <div style="display:flex; align-items:center; gap:10px;">
                <?php if ($photoUrl): ?>
                    <img src="<?php echo e($photoUrl); ?>" alt="Photo" class="app-photo-thumb" onerror="this.style.display='none';">
                <?php else: ?>
                    <div style="width:40px; height:48px; border-radius:6px; background:#e2e8f0; display:flex; align-items:center; justify-content:center; color:#94a3b8;"><i class="fas fa-user"></i></div>
                <?php endif; ?>
                <div>
                    <strong style="font-size:0.95rem; color:#002147;"><?php echo e($a['child']); ?></strong>
                    <div style="font-size:0.8rem; color:#475569;"><?php echo e($a['class_name']); ?> &bull; <?php echo e($a['application_number']); ?></div>
                </div>
            </div>

            <div style="margin-left:auto; display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                <!-- Admissions Status Badge -->
                <span class="badge badge-<?php echo ['pending' => 'warning', 'reviewing' => 'info', 'accepted' => 'success', 'rejected' => 'danger'][$a['status']] ?? 'info'; ?>">
                    <?php echo e(ucfirst($a['status'])); ?>
                </span>

                <!-- Teacher Interview Badge -->
                <?php if ($interviewRes === 'passed'): ?>
                    <span class="badge badge-success"><i class="fas fa-check"></i> Passed Interview</span>
                <?php elseif ($interviewRes === 'failed'): ?>
                    <span class="badge badge-danger"><i class="fas fa-times"></i> Did Not Pass Interview</span>
                <?php else: ?>
                    <span class="badge badge-warning"><i class="fas fa-user-clock"></i> Interview Pending</span>
                <?php endif; ?>

                <!-- Fee Paid Status Badge -->
                <?php if ($isPayVerified): ?>
                    <span class="badge badge-success"><i class="fas fa-check-circle"></i> Paid (₦<?php echo number_format($amtPaid); ?>)</span>
                <?php elseif ($isSchoolPayment): ?>
                    <span class="badge badge-warning" style="background:#fef3c7; color:#92400e; border:1px solid #fcd34d;">
                        <i class="fas fa-school"></i> Pending School Payment (₦<?php echo number_format($amtPaid); ?>)
                    </span>
                <?php else: ?>
                    <span class="badge badge-warning"><i class="fas fa-clock"></i> Pending Verification (₦<?php echo number_format($amtPaid); ?>)</span>
                <?php endif; ?>

                <small class="text-muted"><?php echo e(formatDate($a['created_at'], 'd M Y')); ?></small>
            </div>
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
        <!-- Teacher Interview Evaluation & Admissions Decision Form -->
        <div class="interview-decision-card">
            <h4 style="color:#002147; margin:0 0 10px; font-size:0.95rem;">
                <i class="fas fa-chalkboard-teacher" style="color:#c8102e;"></i> Teacher Interview Evaluation &amp; Admissions Decision (Accept / Reject)
            </h4>
            <form method="POST" style="margin-bottom:12px;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="post_action" value="interview_decision">
                <input type="hidden" name="kind" value="<?php echo e($kind); ?>">
                <input type="hidden" name="id" value="<?php echo (int)$a['id']; ?>">

                <div class="form-row" style="margin-bottom:8px;">
                    <div class="form-group" style="flex:1;">
                        <label>Admissions Decision *</label>
                        <select name="decision" class="form-control" required>
                            <option value="accepted" <?php echo $a['status'] === 'accepted' ? 'selected' : ''; ?>>Accept Child (Admit to Class)</option>
                            <option value="rejected" <?php echo $a['status'] === 'rejected' ? 'selected' : ''; ?>>Reject Child (Did Not Pass Interview for Class)</option>
                            <option value="reviewing" <?php echo $a['status'] === 'reviewing' ? 'selected' : ''; ?>>Reviewing / Interview Scheduled</option>
                            <option value="pending" <?php echo $a['status'] === 'pending' ? 'selected' : ''; ?>>Pending Decision</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label>Teacher Interview Outcome *</label>
                        <select name="interview_result" class="form-control">
                            <option value="passed" <?php echo ($a['interview_result'] ?? '') === 'passed' ? 'selected' : ''; ?>>Passed Interview / Assessment</option>
                            <option value="failed" <?php echo ($a['interview_result'] ?? '') === 'failed' ? 'selected' : ''; ?>>Did Not Pass Interview for this Class</option>
                            <option value="scheduled" <?php echo ($a['interview_result'] ?? '') === 'scheduled' ? 'selected' : ''; ?>>Interview Scheduled</option>
                            <option value="pending" <?php echo ($a['interview_result'] ?? '') === 'pending' || empty($a['interview_result']) ? 'selected' : ''; ?>>Awaiting Interview</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label>Class Admitted To</label>
                        <input type="text" name="admitted_class" class="form-control" value="<?php echo e($a['admitted_class'] ?: $a['class_name']); ?>" placeholder="e.g. Primary 1 or Nursery 2">
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label>Evaluating Teacher</label>
                        <input type="text" name="interview_teacher" class="form-control" value="<?php echo e($a['interview_teacher'] ?? ''); ?>" placeholder="e.g. Mrs. Okafor (Early Years Lead)">
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:10px;">
                    <label>Teacher Evaluation Remarks &amp; Feedback Notes *</label>
                    <textarea name="interview_notes" class="form-control" rows="2" placeholder="Record teacher's assessment notes (e.g. phonetic mastery, motor skills, or reasons why child did not pass interview for that class)"><?php echo e($a['interview_notes'] ?: $a['remarks']); ?></textarea>
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                    <div style="font-size:0.8rem; color:#64748b;">
                        <i class="fas fa-envelope"></i> Formal decision letter with teacher notes will be automatically emailed to <strong><?php echo e($a['parent_email']); ?></strong>.
                    </div>
                    <button type="submit" class="btn btn-primary" style="background:#002147; color:#FFD700; font-weight:700;">
                        <i class="fas fa-save"></i> Save Admissions Decision &amp; Notify Parent
                    </button>
                </div>
            </form>

            <!-- Parent Portal Account Creation Section -->
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; padding-top:10px; border-top:1px dashed #cbd5e1; margin-top:8px;">
                <div>
                    <strong style="font-size:0.88rem; color:#002147;"><i class="fas fa-user-shield"></i> Parent Portal Account:</strong>
                    <?php if (!empty($a['parent_user_id'])): ?>
                        <span class="badge badge-success"><i class="fas fa-check"></i> Account Created &amp; Active</span>
                    <?php else: ?>
                        <span class="badge badge-warning">Not Created Yet</span>
                    <?php endif; ?>
                    <span style="font-size:0.8rem; color:#64748b; margin-left:6px;">Allows parents to check &amp; print results, get school notices, and pay fees.</span>
                </div>
                <div>
                    <form method="POST" style="display:inline;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="post_action" value="create_parent_login">
                        <input type="hidden" name="kind" value="<?php echo e($kind); ?>">
                        <input type="hidden" name="id" value="<?php echo (int)$a['id']; ?>">
                        <button type="submit" class="btn btn-sm btn-primary" style="background:#002147; color:#fff;" onclick="return confirm('Create parent login credentials for <?php echo e(addslashes($a['parent_name'])); ?> and send credentials via email?')">
                            <i class="fas fa-user-plus"></i> <?php echo !empty($a['parent_user_id']) ? 'Re-send Parent Credentials' : 'Create Parent Portal Login'; ?>
                        </button>
                    </form>
                </div>
            </div>

            <!-- School Payment Confirmation for Slip submissions -->
            <?php if ($isSchoolPayment): ?>
            <div style="background:#fffbeb; border:1px solid #fde68a; border-radius:8px; padding:12px 14px; margin-top:10px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                <div>
                    <strong style="color:#92400e; font-size:0.88rem;"><i class="fas fa-file-invoice"></i> Application Slip Submitted (Payment Due at School: ₦<?php echo number_format($amtPaid); ?>)</strong>
                    <div style="font-size:0.8rem; color:#78350f;">Parent brought or transferred fee to Zenith Bank (1310057496). Click to validate and generate official receipt.</div>
                </div>
                <form method="POST" style="display:inline; display:flex; gap:6px; align-items:center;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="post_action" value="confirm_school_payment">
                    <input type="hidden" name="application_number" value="<?php echo e($a['application_number']); ?>">
                    <select name="payment_channel" class="form-control form-control-sm" style="width:auto; font-size:0.82rem;">
                        <option value="cash_at_school">Paid Cash at School</option>
                        <option value="pos_at_school">Paid POS at School</option>
                        <option value="bank_transfer">Paid via Zenith Bank Transfer</option>
                    </select>
                    <button type="submit" class="btn btn-sm btn-success" style="font-weight:700;" onclick="return confirm('Confirm payment of ₦<?php echo number_format($amtPaid); ?> received at school for <?php echo e(addslashes($a['child'])); ?>?')">
                        <i class="fas fa-check-circle"></i> Confirm Payment &amp; Issue Receipt
                    </button>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </details>
<?php endforeach; ?>
</div></div>
<?php endforeach;
dashboard_close();
include __DIR__ . '/../includes/footer.php';
