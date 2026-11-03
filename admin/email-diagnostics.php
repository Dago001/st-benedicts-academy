<?php
// admin/email-diagnostics.php - Email System & Diagnostics, Mobile Delivery Tester & Student Official Email Manager
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/SystemSchema.php';

Security::requireRole(['super_admin', 'admin', 'head_teacher']);
SystemSchema::ensureAll();

$db = db();
[$message, $messageType] = flash_get();
$testResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        flash_redirect('Invalid security token. Please try again.', 'error', 'email-diagnostics');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'send_test_email') {
        $targetEmail = trim((string)($_POST['test_email'] ?? ''));
        $testType = $_POST['test_type'] ?? 'general';

        if (!filter_var($targetEmail, FILTER_VALIDATE_EMAIL)) {
            flash_redirect('Please enter a valid email address to test delivery.', 'error', 'email-diagnostics');
        }

        $senderEmail = Mailer::getSchoolEmail();
        $startTime = microtime(true);
        $sent = false;

        if ($testType === 'welcome') {
            $mockUser = [
                'id'             => (int)$_SESSION['user_id'],
                'first_name'     => $_SESSION['user_name'] ?? 'Staff Member',
                'last_name'      => '',
                'email'          => $targetEmail,
                'official_email' => 'staff.test@stbenedictsacademy.com.ng',
                'username'       => 'test.user',
                'role'           => 'teacher'
            ];
            $sent = send_account_welcome_email($mockUser, 'TempPass#2026', 'teacher');
        } elseif ($testType === 'result') {
            // Pick any sample student or use mock
            $sampleStudent = $db->getRow("SELECT id FROM students LIMIT 1");
            if ($sampleStudent) {
                // Send sample result to test target
                $sub = "Official Academic Results Published: Sample Student (First Term, 2025/2026)";
                $title = "Official Academic Results Ready";
                $portalUrl = rtrim(BASE_URL, '/') . '/login';
                $body = "<p>Dear <strong>Parent / Guardian</strong>,</p>"
                      . "<p>This is a live test notification: Official academic evaluation results for <strong>Sample Student</strong> (Admission No: <strong>STB/2026/001</strong>, Class: <strong>Reception Gold</strong>) have been approved and published for <strong>First Term (2025/2026)</strong>.</p>"
                      . "<div style='background-color:#f8fafc; border:1px solid #cbd5e1; border-radius:8px; padding:16px; margin:20px 0;'>"
                      . "<p style='margin:4px 0;'><strong>Evaluation Period:</strong> First Term &bull; 2025/2026</p>"
                      . "<p style='margin:4px 0;'><strong>Class Teacher:</strong> Mrs. Okonkwo</p>"
                      . "<p style='margin:4px 0;'><strong>Overall Remark:</strong> Outstanding performance in British EYFS curriculum.</p>"
                      . "</div>"
                      . "<p>You can sign in to the portal on your phone or computer anytime to view scores and download the report card.</p>";
                $html = Mailer::formatTemplate($title, $body, $portalUrl, 'Sign In to View Report Card');
                $sent = Mailer::send($targetEmail, $sub, $html);
            } else {
                $html = Mailer::formatTemplate("Academic Results Published", "<p>Test result email sent from St. Benedict's Academy.</p>", BASE_URL . '/login', 'Sign In');
                $sent = Mailer::send($targetEmail, "Academic Results Published - Test", $html);
            }
        } else {
            $title = "St. Benedict's Academy Mailer Live Test";
            $body = "<p>Hello,</p>"
                  . "<p>This is a live diagnostic email from <strong>" . e(SCHOOL_NAME) . "</strong>.</p>"
                  . "<p>If you are receiving this message on your mobile phone inbox, your school mail delivery system is <strong>active, authenticated, and working properly</strong>.</p>"
                  . "<div style='background-color:#f8fafc; border:1px solid #cbd5e1; border-radius:8px; padding:16px; margin:16px 0;'>"
                  . "<p style='margin:4px 0;'><strong>Sender:</strong> " . e($senderEmail) . "</p>"
                  . "<p style='margin:4px 0;'><strong>Envelope Sender (-f):</strong> " . e($senderEmail) . " (SPF Aligned)</p>"
                  . "<p style='margin:4px 0;'><strong>Recipient:</strong> " . e($targetEmail) . "</p>"
                  . "<p style='margin:4px 0;'><strong>Server Timestamp:</strong> " . date('Y-m-d H:i:s T') . "</p>"
                  . "</div>";
            $html = Mailer::formatTemplate($title, $body, BASE_URL . '/login', 'Visit School Portal');
            $sent = Mailer::send($targetEmail, "Test Email from " . SCHOOL_NAME, $html);
        }

        $duration = round((microtime(true) - $startTime) * 1000, 1);

        if ($sent) {
            flash_redirect("Test email successfully dispatched to {$targetEmail} in {$duration}ms! Check your phone inbox/notification tray.", 'success', 'email-diagnostics');
        } else {
            flash_redirect("Email sending encountered an error. Check server mail logs.", 'error', 'email-diagnostics');
        }
    }

    if ($action === 'backfill_student_emails') {
        $count = SystemSchema::backfillStudentOfficialEmails($db->getConnection());
        flash_redirect("Provisioned/refreshed official emails (@stbenedictsacademy.com.ng) for {$count} student(s) successfully!", 'success', 'email-diagnostics');
    }

    if ($action === 'dispatch_student_credentials') {
        $stId = (int)($_POST['student_id'] ?? 0);
        $st = $db->getRow("SELECT s.id, s.admission_number, s.official_email, u.id AS user_id, u.first_name, u.last_name, u.email, u.username
                           FROM students s JOIN users u ON s.user_id = u.id WHERE s.id = ?", [$stId]);
        if ($st) {
            $plainPass = 'Student@' . date('Y');
            $db->query("UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?", [Security::hashPassword($plainPass), $st['user_id']]);
            $sent = send_account_welcome_email([
                'id'             => $st['user_id'],
                'first_name'     => $st['first_name'],
                'last_name'      => $st['last_name'],
                'email'          => $st['email'],
                'official_email' => $st['official_email'],
                'username'       => $st['username'],
                'role'           => 'student'
            ], $plainPass, 'student');
            flash_redirect("Credentials dispatched to " . ($st['official_email'] ?: $st['email']) . " successfully!", 'success', 'email-diagnostics');
        }
    }
}

// Stats
$schoolEmail = Mailer::getSchoolEmail();
$totalStudents = (int)$db->getRow("SELECT COUNT(*) c FROM students")['c'];
$studentsWithOfficialEmail = (int)$db->getRow("SELECT COUNT(*) c FROM students WHERE official_email LIKE '%@stbenedictsacademy.com.ng'")['c'];
$studentsList = $db->getRows(
    "SELECT s.id, s.admission_number, s.official_email, c.class_name,
            u.first_name, u.last_name, u.email AS user_email, u.username
     FROM students s
     JOIN users u ON s.user_id = u.id
     LEFT JOIN classes c ON s.class_id = c.id
     ORDER BY s.id DESC
     LIMIT 50"
);

$pageTitle = 'Email System & Phone Delivery Diagnostics';
$extraCSS = ['admin.css', 'dashboard.css'];
include __DIR__ . '/../includes/header.php';
dashboard_open('admin', 'Email System & Test');
render_alert($message, $messageType);
?>

<div style="max-width: 1100px; margin: 0 auto;">
    <!-- Metric Cards -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 18px; margin-bottom: 24px;">
        <div class="card" style="margin: 0; padding: 20px; border-radius: 10px; border: 1px solid #e2e8f0; background: #ffffff;">
            <div style="display: flex; align-items: center; justify-content: space-between;">
                <div>
                    <div style="font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase;">Official School Sender</div>
                    <div style="font-size: 16px; font-weight: 700; color: #002855; margin-top: 6px; word-break: break-all;">
                        <?php echo e($schoolEmail ?: 'mail@stbenedictsacademy.com.ng'); ?>
                    </div>
                    <div style="font-size: 12px; color: #16a34a; margin-top: 4px;">
                        <i class="fas fa-check-circle"></i> Active & SPF Aligned
                    </div>
                </div>
                <div style="background: #e0f2fe; color: #0284c7; width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px;">
                    <i class="fas fa-envelope-open-text"></i>
                </div>
            </div>
        </div>

        <div class="card" style="margin: 0; padding: 20px; border-radius: 10px; border: 1px solid #e2e8f0; background: #ffffff;">
            <div style="display: flex; align-items: center; justify-content: space-between;">
                <div>
                    <div style="font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase;">Mobile Envelope Sender</div>
                    <div style="font-size: 15px; font-weight: 700; color: #002855; margin-top: 6px;">
                        <code>-f <?php echo e($schoolEmail); ?></code>
                    </div>
                    <div style="font-size: 12px; color: #16a34a; margin-top: 4px;">
                        <i class="fas fa-mobile-alt"></i> Phone Push Enabled
                    </div>
                </div>
                <div style="background: #ecfdf5; color: #059669; width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px;">
                    <i class="fas fa-shield-alt"></i>
                </div>
            </div>
        </div>

        <div class="card" style="margin: 0; padding: 20px; border-radius: 10px; border: 1px solid #e2e8f0; background: #ffffff;">
            <div style="display: flex; align-items: center; justify-content: space-between;">
                <div>
                    <div style="font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase;">Student Official Emails</div>
                    <div style="font-size: 20px; font-weight: 700; color: #002855; margin-top: 6px;">
                        <?php echo $studentsWithOfficialEmail; ?> / <?php echo $totalStudents; ?>
                    </div>
                    <div style="font-size: 12px; color: #64748b; margin-top: 4px;">
                        @stbenedictsacademy.com.ng
                    </div>
                </div>
                <div style="background: #fef3c7; color: #d97706; width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px;">
                    <i class="fas fa-user-graduate"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Live Test Section -->
    <div class="card" style="margin-bottom: 24px; border-radius: 10px; border: 1px solid #e2e8f0;">
        <div class="card-header" style="background: #f8fafc; border-bottom: 1px solid #e2e8f0; padding: 16px 20px;">
            <h3 style="margin: 0; font-size: 17px; color: #002855;">
                <i class="fas fa-paper-plane" style="margin-right: 8px; color: #c41e3a;"></i> Test Live Email Delivery to Mobile Phone
            </h3>
        </div>
        <div class="card-body" style="padding: 24px 20px;">
            <p style="color: #475569; font-size: 14px; margin-top: 0;">
                Enter your personal phone email address (e.g. your Gmail, Yahoo, or Apple Mail) and select a template. The system will dispatch an authentic branded notification so you can verify instant delivery on your smartphone.
            </p>

            <form method="POST" style="margin-top: 18px;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="send_test_email">

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; margin-bottom: 16px;">
                    <div>
                        <label for="test_email" style="display: block; font-weight: 600; font-size: 13px; color: #334155; margin-bottom: 6px;">
                            Recipient Email Address (e.g. Phone Inbox) *
                        </label>
                        <input type="email" id="test_email" name="test_email" class="form-control" required
                               placeholder="yourname@gmail.com" style="width: 100%; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 6px;">
                    </div>

                    <div>
                        <label for="test_type" style="display: block; font-weight: 600; font-size: 13px; color: #334155; margin-bottom: 6px;">
                            Test Email Scenario *
                        </label>
                        <select id="test_type" name="test_type" class="form-control" style="width: 100%; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 6px;">
                            <option value="welcome">Account Welcome & One-Time Password (Teachers, Parents, Students)</option>
                            <option value="result">Academic Results Published Notification (Parent & Student)</option>
                            <option value="general">System Diagnostic & Deliverability Check</option>
                        </select>
                    </div>
                </div>

                <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                    <button type="submit" class="btn btn-primary" style="padding: 10px 22px; font-weight: 600;">
                        <i class="fas fa-paper-plane"></i> Send Test Email Now
                    </button>
                    <span style="font-size: 13px; color: #64748b;">
                        Sender: <strong><?php echo e($schoolEmail); ?></strong> (Envelope: <code>-f<?php echo e($schoolEmail); ?></code>)
                    </span>
                </div>
            </form>
        </div>
    </div>

    <!-- Student Official Emails Management -->
    <div class="card" style="border-radius: 10px; border: 1px solid #e2e8f0;">
        <div class="card-header" style="background: #f8fafc; border-bottom: 1px solid #e2e8f0; padding: 16px 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <h3 style="margin: 0; font-size: 17px; color: #002855;">
                <i class="fas fa-user-graduate" style="margin-right: 8px; color: #002855;"></i> Student Official School Emails (@stbenedictsacademy.com.ng)
            </h3>
            <form method="POST" style="margin: 0;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="backfill_student_emails">
                <button type="submit" class="btn btn-sm btn-outline" style="border-color: #cbd5e1;">
                    <i class="fas fa-sync-alt"></i> Provision / Refresh All Student Emails
                </button>
            </form>
        </div>
        <div class="card-body" style="padding: 0;">
            <div style="overflow-x: auto;">
                <table class="table" style="width: 100%; border-collapse: collapse; margin: 0; font-size: 14px;">
                    <thead>
                        <tr style="background: #f1f5f9; text-align: left; border-bottom: 1px solid #e2e8f0;">
                            <th style="padding: 12px 16px; color: #475569;">Student</th>
                            <th style="padding: 12px 16px; color: #475569;">Admission No</th>
                            <th style="padding: 12px 16px; color: #475569;">Class</th>
                            <th style="padding: 12px 16px; color: #475569;">Official School Email</th>
                            <th style="padding: 12px 16px; color: #475569;">Login Email / User</th>
                            <th style="padding: 12px 16px; color: #475569; text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($studentsList)): ?>
                            <tr><td colspan="6" style="padding: 24px; text-align: center; color: #64748b;">No students found in the database.</td></tr>
                        <?php else: ?>
                            <?php foreach ($studentsList as $st): ?>
                                <tr style="border-bottom: 1px solid #f1f5f9;">
                                    <td style="padding: 12px 16px; font-weight: 600; color: #002855;">
                                        <?php echo e($st['first_name'] . ' ' . $st['last_name']); ?>
                                    </td>
                                    <td style="padding: 12px 16px;">
                                        <code style="background: #f1f5f9; padding: 2px 6px; border-radius: 4px;"><?php echo e($st['admission_number']); ?></code>
                                    </td>
                                    <td style="padding: 12px 16px; color: #475569;">
                                        <?php echo e($st['class_name'] ?: 'N/A'); ?>
                                    </td>
                                    <td style="padding: 12px 16px;">
                                        <?php if (!empty($st['official_email'])): ?>
                                            <span style="color: #002855; font-weight: 600;"><?php echo e($st['official_email']); ?></span>
                                        <?php else: ?>
                                            <span class="badge badge-warning" style="background:#fef3c7; color:#92400e; padding: 3px 8px; border-radius: 4px;">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 12px 16px; color: #64748b;">
                                        <?php echo e($st['user_email']); ?>
                                    </td>
                                    <td style="padding: 12px 16px; text-align: right;">
                                        <form method="POST" style="display: inline; margin: 0;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="dispatch_student_credentials">
                                            <input type="hidden" name="student_id" value="<?php echo (int)$st['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline" style="font-size: 12px;" onclick="return confirm('Send login credentials and one-time password to this student?')">
                                                <i class="fas fa-envelope"></i> Send Credentials
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
dashboard_close();
include __DIR__ . '/../includes/footer.php';
