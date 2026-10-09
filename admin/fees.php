<?php
// admin/fees.php - Complete Fee Management System
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';

Security::requireRole('admin');

$pageTitle = 'Fee Management';
$extraCSS = ['admin.css', 'dashboard.css'];
$extraJS = ['fees.js', 'charts.js'];

include __DIR__ . '/../includes/header.php';

// Get database instance
try {
    $db = Database::getInstance();
} catch (Exception $e) {
    die("Database connection error: " . $e->getMessage());
}

[$message, $messageType] = flash_get();

// Handle actions
$action = isset($_GET['action']) ? $_GET['action'] : 'list';
$id = isset($_POST['id']) ? (int)$_POST['id'] : (isset($_GET['id']) ? (int)$_GET['id'] : null);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid security token';
        $messageType = 'error';
    } else {
        $postAction = $_POST['action'] ?? '';

        switch ($postAction) {
            case 'add_fee_structure':
            case 'edit_fee_structure':
                // Validate required fields
                $classId = (int)($_POST['class_id'] ?? 0);
                $feeType = Security::sanitize($_POST['fee_type'] ?? '');
                $amount = (float)($_POST['amount'] ?? 0);
                $term = Security::sanitize($_POST['term'] ?? '');
                $academicYear = Security::sanitize($_POST['academic_year'] ?? '');
                $dueDate = valid_date($_POST['due_date'] ?? '');
                $isMandatory = isset($_POST['is_mandatory']) ? 1 : 0;
                $description = Security::sanitize($_POST['description'] ?? '');

                if (!$classId || empty($feeType) || $amount <= 0 || empty($term) || empty($academicYear)) {
                    $message = 'Please fill in all required fields';
                    $messageType = 'error';
                    break;
                }

                // Validate academic year format
                if (!preg_match('/^\d{4}-\d{4}$/', $academicYear)) {
                    $message = 'Academic year must be in format YYYY-YYYY';
                    $messageType = 'error';
                    break;
                }

                if (!empty($_POST['due_date']) && !$dueDate) {
                    $message = 'Invalid due date format';
                    $messageType = 'error';
                    break;
                }
                if ($amount > 100000000 || mb_strlen($feeType) > 50 || mb_strlen($term) > 20) {
                    $message = 'Amount, fee type or term is out of range';
                    $messageType = 'error';
                    break;
                }
                if (!$db->getRow('SELECT id FROM classes WHERE id = ?', [$classId])) {
                    $message = 'Selected class does not exist';
                    $messageType = 'error';
                    break;
                }

                try {
                    if ($postAction === 'add_fee_structure') {
                        // Check if fee structure already exists
                        $existing = $db->getRow(
                            "SELECT id FROM fee_structure
                             WHERE class_id = ? AND fee_type = ? AND term = ? AND academic_year = ?",
                            [$classId, $feeType, $term, $academicYear]
                        );

                        if ($existing) {
                            throw new Exception("Fee structure already exists for this class, term and academic year");
                        }

                        $result = $db->insert(
                            "INSERT INTO fee_structure (class_id, fee_type, amount, term, academic_year, due_date, is_mandatory, description)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                            [
                                $classId,
                                $feeType,
                                $amount,
                                $term,
                                $academicYear,
                                $dueDate,
                                $isMandatory,
                                $description
                            ]
                        );

                        if (!$result) {
                            throw new Exception("Failed to insert fee structure");
                        }

                        Security::logAudit('ADDED_FEE_STRUCTURE', 'fee_structure');
                        $message = 'Fee structure added successfully';
                        $messageType = 'success';

                    } else {
                        if (!$id || !$db->getRow('SELECT id FROM fee_structure WHERE id = ?', [$id])) {
                            throw new Exception("Fee structure not found");
                        }

                        $result = $db->query(
                            "UPDATE fee_structure SET class_id = ?, fee_type = ?, amount = ?, term = ?,
                             academic_year = ?, due_date = ?, is_mandatory = ?, description = ? WHERE id = ?",
                            [
                                $classId,
                                $feeType,
                                $amount,
                                $term,
                                $academicYear,
                                $dueDate,
                                $isMandatory,
                                $description,
                                $id
                            ]
                        );

                        if (!$result) {
                            throw new Exception("Failed to update fee structure");
                        }

                        Security::logAudit('UPDATED_FEE_STRUCTURE', 'fee_structure', $id);
                        $message = 'Fee structure updated successfully';
                        $messageType = 'success';
                    }

                } catch (Exception $e) {
                    $message = 'Error: ' . $e->getMessage();
                    $messageType = 'error';
                    error_log("Fee structure error: " . $e->getMessage());
                }
                break;

            case 'record_payment':
                // Validate required fields
                $studentId = (int)($_POST['student_id'] ?? 0);
                $amount = (float)($_POST['amount'] ?? 0);
                $paymentDate = Security::sanitize($_POST['payment_date'] ?? '');
                $paymentMethod = Security::sanitize($_POST['payment_method'] ?? '');
                $term = Security::sanitize($_POST['term'] ?? '');
                $academicYear = Security::sanitize($_POST['academic_year'] ?? '');
                $feeStructureId = !empty($_POST['fee_structure_id']) ? (int)$_POST['fee_structure_id'] : null;
                $transactionId = Security::sanitize($_POST['transaction_id'] ?? '');
                $bankName = Security::sanitize($_POST['bank_name'] ?? '');
                $chequeNumber = Security::sanitize($_POST['cheque_number'] ?? '');
                $remarks = Security::sanitize($_POST['remarks'] ?? '');

                if (!$studentId || $amount <= 0 || empty($paymentDate) || empty($paymentMethod) || empty($term) || empty($academicYear)) {
                    $message = 'Please fill in all required fields';
                    $messageType = 'error';
                    break;
                }
                if (!valid_date($paymentDate) || $paymentDate > date('Y-m-d')) {
                    $message = 'Payment date is invalid or in the future';
                    $messageType = 'error';
                    break;
                }
                if (!in_array($paymentMethod, ['cash', 'bank_transfer', 'card', 'cheque'], true)) {
                    $message = 'Invalid payment method';
                    $messageType = 'error';
                    break;
                }
                if ($amount > 100000000 || !preg_match('/^\d{4}-\d{4}$/', $academicYear)) {
                    $message = 'Amount or academic year is invalid';
                    $messageType = 'error';
                    break;
                }
                if ($feeStructureId && !$db->getRow('SELECT id FROM fee_structure WHERE id = ?', [$feeStructureId])) {
                    $feeStructureId = null;
                }

                $receiptNumber = generateReceiptNumber();

                try {
                    // Check if student exists
                    $studentExists = $db->getRow("SELECT id FROM students WHERE id = ?", [$studentId]);
                    if (!$studentExists) {
                        throw new Exception("Selected student does not exist");
                    }

                    // Insert payment - using the correct columns based on your table structure
                    $sql = "INSERT INTO payments (
                        student_id,
                        receipt_number,
                        fee_structure_id,
                        amount,
                        payment_date,
                        payment_method,
                        transaction_id,
                        bank_name,
                        cheque_number,
                        term,
                        academic_year,
                        remarks,
                        recorded_by,
                        status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

                    $params = [
                        $studentId,
                        $receiptNumber,
                        $feeStructureId,
                        $amount,
                        $paymentDate,
                        $paymentMethod,
                        $transactionId,
                        $bankName,
                        $chequeNumber,
                        $term,
                        $academicYear,
                        $remarks,
                        $_SESSION['user_id'],
                        'completed'
                    ];

                    $result = $db->insert($sql, $params);

                    if (!$result) {
                        throw new Exception("Failed to record payment - database error");
                    }

                    Security::logAudit('RECORDED_PAYMENT', 'payments', $result);
                    $message = 'Payment recorded successfully. Receipt: ' . $receiptNumber;
                    $messageType = 'success';

                } catch (Exception $e) {
                    $message = 'Error recording payment. Please check the details and try again.';
                    $messageType = 'error';
                    error_log("Payment recording error: " . $e->getMessage());
                }
                break;

            case 'delete_fee_structure':
                try {
                    if (!$id) {
                        throw new Exception("Invalid fee structure ID");
                    }

                    // Check if fee structure has payments
                    $paymentCount = $db->getRow(
                        "SELECT COUNT(*) as count FROM payments WHERE fee_structure_id = ?",
                        [$id]
                    )['count'] ?? 0;

                    if ($paymentCount > 0) {
                        $message = 'Cannot delete fee structure with existing payments';
                        $messageType = 'error';
                    } else {
                        $db->query("DELETE FROM fee_structure WHERE id = ?", [$id]);
                        Security::logAudit('DELETED_FEE_STRUCTURE', 'fee_structure', $id);
                        $message = 'Fee structure deleted successfully';
                        $messageType = 'success';
                    }
                } catch (Exception $e) {
                    $message = 'Error: ' . $e->getMessage();
                    $messageType = 'error';
                }
                break;

            case 'update_payment_status':
                $paymentId = (int)($_POST['payment_id'] ?? 0);
                $status = Security::sanitize($_POST['status'] ?? '');

                if (!$paymentId || !in_array($status, ['completed', 'pending', 'failed', 'refunded'])) {
                    $message = 'Invalid payment or status';
                    $messageType = 'error';
                    break;
                }

                try {
                    $db->query(
                        "UPDATE payments SET status = ?, approved_by = ?, approved_at = NOW() WHERE id = ?",
                        [$status, $_SESSION['user_id'], $paymentId]
                    );

                    Security::logAudit('UPDATED_PAYMENT_STATUS', 'payments', $paymentId);
                    $message = 'Payment status updated successfully';
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
    flash_redirect($message, 'success', BASE_URL . '/admin/fees');
}

// Get fee structure for editing
$feeStructure = null;
if ($action === 'edit_fee_structure' && $id) {
    $feeStructure = $db->getRow("SELECT * FROM fee_structure WHERE id = ?", [$id]);
}

// Get classes for dropdown (only active classes)
$classes = $db->getRows(
    "SELECT * FROM classes WHERE is_active = 1 ORDER BY class_name, section"
);

// Get students for payment dropdown (only active students)
$students = $db->getRows(
    "SELECT s.id, s.admission_number, u.first_name, u.last_name, c.class_name, c.section, c.id as class_id
     FROM students s
     JOIN users u ON s.user_id = u.id
     LEFT JOIN classes c ON s.class_id = c.id
     WHERE u.is_active = 1
     ORDER BY u.first_name, u.last_name"
);

// Get fee structures with class names
$feeStructures = $db->getRows(
    "SELECT fs.*, c.class_name, c.section
     FROM fee_structure fs
     JOIN classes c ON fs.class_id = c.id
     ORDER BY fs.academic_year DESC, fs.term, c.class_name"
);

// Get recent payments with student and staff details
$recentPayments = $db->getRows(
    "SELECT p.*,
            CONCAT(u.first_name, ' ', u.last_name) as student_name,
            s.admission_number,
            c.class_name, c.section,
            CONCAT(ru.first_name, ' ', ru.last_name) as recorded_by_name
     FROM payments p
     JOIN students s ON p.student_id = s.id
     JOIN users u ON s.user_id = u.id
     LEFT JOIN classes c ON s.class_id = c.id
     LEFT JOIN users ru ON p.recorded_by = ru.id
     ORDER BY p.created_at DESC LIMIT 20"
);

// Get summary statistics
$summary = $db->getRow(
    "SELECT
        COUNT(DISTINCT student_id) as total_students_with_payments,
        COUNT(*) as total_transactions,
        COALESCE(SUM(amount), 0) as total_collected,
        COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) as pending_amount
     FROM payments"
);

if (!$summary) {
    $summary = [
        'total_students_with_payments' => 0,
        'total_transactions' => 0,
        'total_collected' => 0,
        'pending_amount' => 0
    ];
}

// Get current academic year
$currentAcademicYear = currentAcademicYear();

// Get ALL students with their fee structures and payments for real-time outstanding calculation
$outstanding = $db->getRows(
    "SELECT
        s.id,
        u.first_name,
        u.last_name,
        s.admission_number,
        c.class_name,
        c.section,
        COALESCE((
            SELECT SUM(fs.amount)
            FROM fee_structure fs
            WHERE fs.class_id = s.class_id
            AND fs.academic_year = ?
        ), 0) as total_fees,
        COALESCE((
            SELECT SUM(p.amount)
            FROM payments p
            WHERE p.student_id = s.id
            AND p.academic_year = ?
            AND p.status = 'completed'
        ), 0) as total_paid
     FROM students s
     JOIN users u ON s.user_id = u.id
     LEFT JOIN classes c ON s.class_id = c.id
     WHERE u.is_active = 1
     ORDER BY (total_fees - total_paid) DESC",
    [$currentAcademicYear, $currentAcademicYear]
);

// Calculate balance and filter only those with outstanding fees
$outstandingList = [];
$totalOutstanding = 0;

foreach ($outstanding as $item) {
    $balance = $item['total_fees'] - $item['total_paid'];
    $item['balance'] = $balance;

    // Only include if there's an outstanding balance
    if ($balance > 0) {
        $item['payment_percentage'] = $item['total_fees'] > 0
            ? round(($item['total_paid'] / $item['total_fees']) * 100, 1)
            : 0;
        $outstandingList[] = $item;
        $totalOutstanding += $balance;
    }
}

// Get total expected fees for the current academic year
$totalExpectedFees = $db->getRow(
    "SELECT COALESCE(SUM(amount), 0) as total
     FROM fee_structure
     WHERE academic_year = ?",
    [$currentAcademicYear]
)['total'];

// Get monthly collection data for chart
$monthlyCollection = $db->getRows(
    "SELECT
        DATE_FORMAT(payment_date, '%Y-%m') as month,
        COUNT(*) as transaction_count,
        SUM(amount) as total
     FROM payments
     WHERE status = 'completed'
       AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
     GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
     ORDER BY month DESC
     LIMIT 12"
);

// Payment status helper
function getPaymentStatus($paid, $total) {
    if ($total == 0) return 'no-fees';
    if ($paid >= $total) return 'completed';
    if ($paid > 0) return 'partial';
    return 'pending';
}
?>

<style>
/* Modal positioning fix - ensures modals appear above sidebar */
.modal {
    display: none;
    position: fixed;
    z-index: 9999;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    overflow: auto;
    background-color: rgba(0,0,0,0.5);
}

.modal-content {
    background-color: #fefefe;
    margin: 5% auto;
    padding: 0;
    border: 1px solid #888;
    width: 90%;
    max-width: 600px;
    border-radius: 10px;
    box-shadow: 0 5px 30px rgba(0,0,0,0.3);
    position: relative;
    z-index: 10000;
}

/* Ensure sidebar doesn't interfere */
.sidebar {
    z-index: 1000;
}

.dashboard-main {
    position: relative;
    z-index: 1;
}

/* Fee status badges */
.status-badge {
    display: inline-block;
    padding: 5px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-align: center;
}

.status-completed {
    background-color: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.status-partial {
    background-color: #fff3cd;
    color: #856404;
    border: 1px solid #ffeeba;
}

.status-pending {
    background-color: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

.status-no-fees {
    background-color: #e2e3e5;
    color: #383d41;
    border: 1px solid #d6d8db;
}

/* Progress bar for payment percentage */
.payment-progress {
    width: 100%;
    height: 6px;
    background-color: #e9ecef;
    border-radius: 3px;
    margin-top: 5px;
    overflow: hidden;
}

.payment-progress-bar {
    height: 100%;
    background: linear-gradient(90deg, #28a745, #20c997);
    border-radius: 3px;
    transition: width 0.3s ease;
}

.payment-progress-bar.warning {
    background: linear-gradient(90deg, #ffc107, #fd7e14);
}

.payment-progress-bar.danger {
    background: linear-gradient(90deg, #dc3545, #c82333);
}
</style>

<div class="dashboard-container">
    <?php render_sidebar('admin'); ?>

    <main class="dashboard-main">
        <div class="dashboard-header">
            <h1>Fee Management</h1>
            <div class="header-actions">
                <button class="btn btn-primary" onclick="showAddFeeModal()">
                    <i class="fas fa-plus"></i> Add Fee Structure
                </button>
                <button class="btn btn-success" onclick="showRecordPaymentModal()">
                    <i class="fas fa-money-bill-wave"></i> Record Payment
                </button>
                <a href="export?type=fees" class="btn btn-outline">
                    <i class="fas fa-download"></i> Export
                </a>
            </div>
        </div>

        <?php if ($message): ?>
        <div class="alert alert-<?php echo e($messageType); ?> alert-dismissible">
            <i class="fas <?php echo $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="close" onclick="this.parentElement.remove()">&times;</button>
        </div>
        <?php endif; ?>

        <!-- Summary Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(0,40,85,0.1);">
                    <i class="fas fa-users" style="color: #002855;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo e($summary['total_students_with_payments']); ?></h3>
                    <p>Students with Payments</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(40,167,69,0.1);">
                    <i class="fas fa-credit-card" style="color: #28a745;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo e($summary['total_transactions']); ?></h3>
                    <p>Total Transactions</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(255,193,7,0.1);">
                    <i class="fas fa-money-bill-wave" style="color: #ffc107;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo '₦' . number_format($summary['total_collected'], 2); ?></h3>
                    <p>Total Collected</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(220,53,69,0.1);">
                    <i class="fas fa-clock" style="color: #dc3545;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo '₦' . number_format($totalOutstanding, 2); ?></h3>
                    <p>Total Outstanding</p>
                </div>
            </div>
        </div>

        <!-- Additional Stats -->
        <div class="stats-grid secondary" style="grid-template-columns: repeat(3, 1fr); margin-top: -10px;">
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(108,117,125,0.1);">
                    <i class="fas fa-calculator" style="color: #6c757d;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo '₦' . number_format($totalExpectedFees, 2); ?></h3>
                    <p>Total Expected Fees</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(23,162,184,0.1);">
                    <i class="fas fa-percent" style="color: #17a2b8;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php
                        $collectionRate = $totalExpectedFees > 0
                            ? round(($summary['total_collected'] / $totalExpectedFees) * 100, 1)
                            : 0;
                        echo $collectionRate . '%';
                    ?></h3>
                    <p>Collection Rate</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(111,66,193,0.1);">
                    <i class="fas fa-users" style="color: #6f42c1;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo count($outstandingList); ?></h3>
                    <p>Students with Outstanding</p>
                </div>
            </div>
        </div>

        <!-- Monthly Collection Chart -->
        <?php if (!empty($monthlyCollection)): ?>
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-chart-bar"></i> Monthly Collection (Last 12 Months)</h3>
            </div>
            <div class="card-body">
                <canvas id="monthlyChart" width="400" height="200"></canvas>
            </div>
        </div>
        <?php endif; ?>

        <!-- Tabs -->
        <div class="card">
            <div class="card-header">
                <ul class="nav nav-tabs" id="feeTabs" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active" id="structures-tab" data-toggle="tab" href="#feeStructures" role="tab">
                            <i class="fas fa-list"></i> Fee Structures
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="payments-tab" data-toggle="tab" href="#payments" role="tab">
                            <i class="fas fa-history"></i> Recent Payments
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="outstanding-tab" data-toggle="tab" href="#outstanding" role="tab">
                            <i class="fas fa-exclamation-triangle"></i> Outstanding Fees
                            <?php if (count($outstandingList) > 0): ?>
                            <span class="badge badge-danger"><?php echo count($outstandingList); ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                </ul>
            </div>
            <div class="card-body">
                <div class="tab-content">
                    <!-- Fee Structures Tab -->
                    <div class="tab-pane active" id="feeStructures" role="tabpanel">
                        <?php if (!empty($feeStructures)): ?>
                        <div class="table-responsive">
                            <table class="data-table" id="feeStructuresTable">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Class</th>
                                        <th>Fee Type</th>
                                        <th>Amount (₦)</th>
                                        <th>Term</th>
                                        <th>Academic Year</th>
                                        <th>Due Date</th>
                                        <th>Mandatory</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($feeStructures as $fs): ?>
                                    <tr>
                                        <td><?php echo e($fs['id']); ?></td>
                                        <td><?php echo htmlspecialchars($fs['class_name'] . ' ' . ($fs['section'] ?? '')); ?></td>
                                        <td><?php echo htmlspecialchars($fs['fee_type']); ?></td>
                                        <td class="text-right">₦<?php echo number_format($fs['amount'], 2); ?></td>
                                        <td><?php echo htmlspecialchars($fs['term']); ?></td>
                                        <td><?php echo htmlspecialchars($fs['academic_year']); ?></td>
                                        <td>
                                            <?php
                                            if (!empty($fs['due_date'])):
                                                echo date('d M Y', strtotime($fs['due_date']));
                                            else:
                                                echo '<span class="text-muted">No due date</span>';
                                            endif;
                                            ?>
                                        </td>
                                        <td>
                                            <?php if ($fs['is_mandatory']): ?>
                                            <span class="badge badge-success">Yes</span>
                                            <?php else: ?>
                                            <span class="badge badge-secondary">No</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="?action=edit_fee_structure&id=<?php echo e($fs['id']); ?>" class="btn-icon" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <button class="btn-icon text-danger"
                                                        onclick="deleteFeeStructure(<?php echo e($fs['id']); ?>, '<?php echo htmlspecialchars(addslashes($fs['fee_type'])); ?>')"
                                                        title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            No fee structures found. Click "Add Fee Structure" to create one.
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Payments Tab -->
                    <div class="tab-pane" id="payments" role="tabpanel">
                        <?php if (!empty($recentPayments)): ?>
                        <div class="table-responsive">
                            <table class="data-table" id="paymentsTable">
                                <thead>
                                    <tr>
                                        <th>Receipt No.</th>
                                        <th>Date</th>
                                        <th>Student</th>
                                        <th>Class</th>
                                        <th>Amount (₦)</th>
                                        <th>Method</th>
                                        <th>Term</th>
                                        <th>Status</th>
                                        <th>Recorded By</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentPayments as $payment): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($payment['receipt_number']); ?></strong></td>
                                        <td><?php echo date('d M Y', strtotime($payment['payment_date'])); ?></td>
                                        <td>
                                            <?php echo htmlspecialchars($payment['student_name']); ?><br>
                                            <small class="text-muted"><?php echo htmlspecialchars($payment['admission_number']); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars(($payment['class_name'] ?? '') . ' ' . ($payment['section'] ?? '')); ?></td>
                                        <td class="text-right">₦<?php echo number_format($payment['amount'], 2); ?></td>
                                        <td><?php echo ucfirst(str_replace('_', ' ', $payment['payment_method'])); ?></td>
                                        <td><?php echo htmlspecialchars($payment['term']); ?></td>
                                        <td>
                                            <?php
                                            $statusClass = '';
                                            switch($payment['status']) {
                                                case 'completed':
                                                    $statusClass = 'badge-success';
                                                    break;
                                                case 'pending':
                                                    $statusClass = 'badge-warning';
                                                    break;
                                                case 'failed':
                                                    $statusClass = 'badge-danger';
                                                    break;
                                                case 'refunded':
                                                    $statusClass = 'badge-info';
                                                    break;
                                                default:
                                                    $statusClass = 'badge-secondary';
                                            }
                                            ?>
                                            <span class="badge <?php echo e($statusClass); ?>"><?php echo e(ucfirst($payment['status'])); ?></span>
                                        </td>
                                        <td><?php echo htmlspecialchars($payment['recorded_by_name'] ?? 'System'); ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="print-receipt?id=<?php echo e($payment['id']); ?>" class="btn-icon" target="_blank" title="Print Receipt">
                                                    <i class="fas fa-print"></i>
                                                </a>
                                                <?php if ($payment['status'] === 'pending'): ?>
                                                <button class="btn-icon text-success" onclick="updatePaymentStatus(<?php echo e($payment['id']); ?>, 'completed')" title="Mark as Completed">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            No payment records found.
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Outstanding Tab - NOW WITH REAL-TIME CALCULATIONS -->
                    <div class="tab-pane" id="outstanding" role="tabpanel">
                        <?php if (!empty($outstandingList)): ?>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Admission No.</th>
                                        <th>Student Name</th>
                                        <th>Class</th>
                                        <th>Total Fees (₦)</th>
                                        <th>Paid (₦)</th>
                                        <th>Balance (₦)</th>
                                        <th>Progress</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($outstandingList as $item):
                                        $status = getPaymentStatus($item['total_paid'], $item['total_fees']);
                                        $progressClass = $item['payment_percentage'] >= 100 ? 'success' :
                                                         ($item['payment_percentage'] >= 50 ? 'warning' : 'danger');
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($item['admission_number']); ?></td>
                                        <td><?php echo htmlspecialchars($item['first_name'] . ' ' . $item['last_name']); ?></td>
                                        <td><?php echo htmlspecialchars(($item['class_name'] ?? '') . ' ' . ($item['section'] ?? '')); ?></td>
                                        <td class="text-right">₦<?php echo number_format($item['total_fees'], 2); ?></td>
                                        <td class="text-right text-success">₦<?php echo number_format($item['total_paid'], 2); ?></td>
                                        <td class="text-right text-danger font-weight-bold">₦<?php echo number_format($item['balance'], 2); ?></td>
                                        <td style="min-width: 120px;">
                                            <div class="payment-progress">
                                                <div class="payment-progress-bar <?php echo e($progressClass); ?>"
                                                     style="width: <?php echo min(100, $item['payment_percentage']); ?>%;">
                                                </div>
                                            </div>
                                            <small class="text-muted"><?php echo e($item['payment_percentage']); ?>% paid</small>
                                        </td>
                                        <td>
                                            <?php if ($status === 'completed'): ?>
                                            <span class="status-badge status-completed">Fully Paid</span>
                                            <?php elseif ($status === 'partial'): ?>
                                            <span class="status-badge status-partial">Partial</span>
                                            <?php elseif ($status === 'pending'): ?>
                                            <span class="status-badge status-pending">No Payment</span>
                                            <?php else: ?>
                                            <span class="status-badge status-no-fees">No Fees</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-primary" onclick="recordPayment(<?php echo e($item['id']); ?>)">
                                                <i class="fas fa-money-bill"></i> Record Payment
                                            </button>
                                            <a href="student-fees?student_id=<?php echo e($item['id']); ?>" class="btn btn-sm btn-outline" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr class="font-weight-bold">
                                        <td colspan="3" class="text-right">TOTAL:</td>
                                        <td class="text-right">₦<?php echo number_format(array_sum(array_column($outstandingList, 'total_fees')), 2); ?></td>
                                        <td class="text-right text-success">₦<?php echo number_format(array_sum(array_column($outstandingList, 'total_paid')), 2); ?></td>
                                        <td class="text-right text-danger">₦<?php echo number_format(array_sum(array_column($outstandingList, 'balance')), 2); ?></td>
                                        <td colspan="3"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle fa-2x mb-3"></i>
                            <h4>No Outstanding Fees!</h4>
                            <p>All students have fully paid their fees for the current academic year (<?php echo e($currentAcademicYear); ?>).</p>
                            <hr>
                            <p class="mb-0">
                                <strong>Total Collected:</strong> ₦<?php echo number_format($summary['total_collected'], 2); ?><br>
                                <strong>Total Expected:</strong> ₦<?php echo number_format($totalExpectedFees, 2); ?>
                            </p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- Add Fee Structure Modal -->
<div id="addFeeModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Add Fee Structure</h3>
            <button type="button" class="close" onclick="closeModal('addFeeModal')">&times;</button>
        </div>
        <form method="POST" id="addFeeForm">
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="add_fee_structure">

                <div class="form-group">
                    <label for="class_id">Class *</label>
                    <select id="class_id" name="class_id" class="form-control" required>
                        <option value="">-- Select Class --</option>
                        <?php foreach ($classes as $class): ?>
                        <option value="<?php echo e($class['id']); ?>">
                            <?php echo htmlspecialchars($class['class_name'] . ' ' . ($class['section'] ?? '')); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="fee_type">Fee Type *</label>
                    <input type="text" id="fee_type" name="fee_type" class="form-control"
                           placeholder="e.g., Tuition Fee, Development Levy" required>
                </div>

                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label for="amount">Amount (₦) *</label>
                        <input type="number" id="amount" name="amount" class="form-control" step="0.01" min="0" required>
                    </div>

                    <div class="form-group col-md-6">
                        <label for="term">Term *</label>
                        <select id="term" name="term" class="form-control" required>
                            <option value="Term 1">Term 1</option>
                            <option value="Term 2">Term 2</option>
                            <option value="Term 3">Term 3</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label for="academic_year">Academic Year *</label>
                        <input type="text" id="academic_year" name="academic_year" class="form-control"
                               value="<?php echo currentAcademicYear(); ?>"
                               placeholder="YYYY-YYYY" required>
                        <small class="form-text text-muted">Format: 2024-2025</small>
                    </div>

                    <div class="form-group col-md-6">
                        <label for="due_date">Due Date</label>
                        <input type="date" id="due_date" name="due_date" class="form-control">
                        <small class="form-text text-muted">Optional</small>
                    </div>
                </div>

                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="is_mandatory" value="1" checked>
                        Mandatory Fee
                    </label>
                </div>

                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" class="form-control" rows="3"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addFeeModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Fee Structure</button>
            </div>
        </form>
    </div>
</div>

<!-- Record Payment Modal -->
<div id="paymentModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Record Payment</h3>
            <button type="button" class="close" onclick="closeModal('paymentModal')">&times;</button>
        </div>
        <form method="POST" id="paymentForm">
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="record_payment">

                <div class="form-group">
                    <label for="student_id">Student *</label>
                    <select id="student_id" name="student_id" class="form-control" required>
                        <option value="">-- Select Student --</option>
                        <?php foreach ($students as $student): ?>
                        <option value="<?php echo e($student['id']); ?>">
                            <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name'] . ' (' . $student['admission_number'] . ') - ' . ($student['class_name'] ?? 'No Class') . ' ' . ($student['section'] ?? '')); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="fee_structure_id">Fee Structure (Optional)</label>
                    <select id="fee_structure_id" name="fee_structure_id" class="form-control">
                        <option value="">-- Select Fee Type (Optional) --</option>
                        <?php foreach ($feeStructures as $fee): ?>
                        <option value="<?php echo e($fee['id']); ?>">
                            <?php echo htmlspecialchars($fee['fee_type'] . ' - ' . $fee['term'] . ' ' . $fee['academic_year'] . ' (₦' . number_format($fee['amount'], 2) . ')'); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label for="amount">Amount (₦) *</label>
                        <input type="number" id="amount" name="amount" class="form-control" step="0.01" min="0" required>
                    </div>

                    <div class="form-group col-md-6">
                        <label for="payment_date">Payment Date *</label>
                        <input type="date" id="payment_date" name="payment_date" class="form-control"
                               value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="payment_method">Payment Method *</label>
                    <select id="payment_method" name="payment_method" class="form-control" required onchange="togglePaymentFields()">
                        <option value="cash">Cash</option>
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="card">Card</option>
                        <option value="cheque">Cheque</option>
                        <option value="pos">POS</option>
                    </select>
                </div>

                <div id="bankFields" style="display: none;">
                    <div class="form-group">
                        <label for="bank_name">Bank Name</label>
                        <input type="text" id="bank_name" name="bank_name" class="form-control">
                    </div>

                    <div class="form-group">
                        <label for="transaction_id">Transaction ID / Reference</label>
                        <input type="text" id="transaction_id" name="transaction_id" class="form-control">
                    </div>
                </div>

                <div id="chequeFields" style="display: none;">
                    <div class="form-group">
                        <label for="cheque_number">Cheque Number</label>
                        <input type="text" id="cheque_number" name="cheque_number" class="form-control">
                    </div>
                    <div class="form-group">
                        <label for="bank_name_cheque">Bank Name</label>
                        <input type="text" id="bank_name_cheque" name="bank_name" class="form-control">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label for="term">Term *</label>
                        <select id="term" name="term" class="form-control" required>
                            <option value="Term 1">Term 1</option>
                            <option value="Term 2">Term 2</option>
                            <option value="Term 3">Term 3</option>
                        </select>
                    </div>

                    <div class="form-group col-md-6">
                        <label for="academic_year">Academic Year *</label>
                        <input type="text" id="academic_year" name="academic_year" class="form-control"
                               value="<?php echo currentAcademicYear(); ?>"
                               placeholder="YYYY-YYYY" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="remarks">Remarks / Notes</label>
                    <textarea id="remarks" name="remarks" class="form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('paymentModal')">Cancel</button>
                <button type="submit" class="btn btn-success">Record Payment</button>
            </div>
        </form>
    </div>
</div>

<!-- Payment Status Update Form (hidden) -->
<form id="statusUpdateForm" method="POST" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
    <input type="hidden" name="action" value="update_payment_status">
    <input type="hidden" name="payment_id" id="status_payment_id">
    <input type="hidden" name="status" id="status_value">
</form>

<script>
<?php if (!empty($monthlyCollection)): ?>
// Monthly Collection Chart
const monthlyCtx = document.getElementById('monthlyChart')?.getContext('2d');
if (monthlyCtx) {
    new Chart(monthlyCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_map(function($item) {
                return date('M Y', strtotime($item['month'] . '-01'));
            }, array_reverse($monthlyCollection))); ?>,
            datasets: [{
                label: 'Monthly Collection (₦)',
                data: <?php echo json_encode(array_map(function($item) {
                    return $item['total'];
                }, array_reverse($monthlyCollection)), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
                backgroundColor: '#ffd700',
                borderColor: '#002855',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return '₦' + value.toLocaleString();
                        }
                    }
                }
            }
        }
    });
}
<?php endif; ?>

function showAddFeeModal() {
    document.getElementById('addFeeModal').style.display = 'block';
}

function showRecordPaymentModal() {
    document.getElementById('paymentModal').style.display = 'block';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

function togglePaymentFields() {
    const method = document.getElementById('payment_method').value;
    const bankFields = document.getElementById('bankFields');
    const chequeFields = document.getElementById('chequeFields');

    bankFields.style.display = 'none';
    chequeFields.style.display = 'none';

    if (method === 'bank_transfer' || method === 'pos') {
        bankFields.style.display = 'block';
    } else if (method === 'cheque') {
        chequeFields.style.display = 'block';
    }
}

function deleteFeeStructure(id, name) {
    if (confirm(`Are you sure you want to delete the fee structure "${name}"?`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
            <input type="hidden" name="action" value="delete_fee_structure">
            <input type="hidden" name="id" value="${id}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function recordPayment(studentId) {
    document.getElementById('student_id').value = studentId;
    showRecordPaymentModal();
}

function updatePaymentStatus(paymentId, status) {
    if (confirm(`Mark this payment as ${status}?`)) {
        document.getElementById('status_payment_id').value = paymentId;
        document.getElementById('status_value').value = status;
        document.getElementById('statusUpdateForm').submit();
    }
}

// Close modals when clicking outside
window.onclick = function(event) {
    const addModal = document.getElementById('addFeeModal');
    const paymentModal = document.getElementById('paymentModal');

    if (event.target === addModal) {
        addModal.style.display = 'none';
    }
    if (event.target === paymentModal) {
        paymentModal.style.display = 'none';
    }
}

// Initialize DataTables
$(document).ready(function() {
    if ($.fn.DataTable) {
        $('#feeStructuresTable').DataTable({
            pageLength: 10,
            order: [[5, 'desc'], [4, 'asc']],
            language: {
                search: "Search:",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ entries"
            }
        });

        $('#paymentsTable').DataTable({
            pageLength: 10,
            order: [[1, 'desc']],
            language: {
                search: "Search:",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ entries"
            }
        });
    }
});
</script>

<style>
/* Additional styles for fee management */
.action-buttons {
    display: flex;
    gap: 5px;
    flex-wrap: wrap;
}

.btn-icon {
    width: 32px;
    height: 32px;
    border-radius: 4px;
    border: none;
    background: #f0f0f0;
    color: #333;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
    text-decoration: none;
}

.btn-icon:hover {
    background: var(--gold);
    color: var(--navy);
}

.btn-icon.text-danger:hover {
    background: #dc3545;
    color: white;
}

.btn-icon.text-success:hover {
    background: #28a745;
    color: white;
}

.badge-success {
    background-color: #d4edda;
    color: #155724;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 0.75rem;
}

.badge-warning {
    background-color: #fff3cd;
    color: #856404;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 0.75rem;
}

.badge-danger {
    background-color: #f8d7da;
    color: #721c24;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 0.75rem;
}

.badge-info {
    background-color: #d1ecf1;
    color: #0c5460;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 0.75rem;
}

.badge-secondary {
    background-color: #e2e3e5;
    color: #383d41;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 0.75rem;
}

.text-right {
    text-align: right;
}

.font-weight-bold {
    font-weight: 700;
}

.btn-sm {
    padding: 5px 10px;
    font-size: 12px;
    border-radius: 4px;
}

.nav-tabs {
    border-bottom: 2px solid var(--light-gray);
    margin-bottom: 20px;
}

.nav-tabs .nav-link {
    border: none;
    color: var(--gray);
    font-weight: 500;
    padding: 10px 20px;
    transition: all 0.3s ease;
}

.nav-tabs .nav-link:hover {
    color: var(--navy);
    background: transparent;
}

.nav-tabs .nav-link.active {
    color: var(--navy);
    border-bottom: 2px solid var(--gold);
    background: transparent;
}

.nav-tabs .nav-link i {
    margin-right: 5px;
}

.badge {
    margin-left: 5px;
}

.text-muted {
    color: #6c757d;
    font-style: italic;
}

/* Progress bar styles */
.payment-progress {
    width: 100%;
    height: 6px;
    background-color: #e9ecef;
    border-radius: 3px;
    margin: 5px 0;
    overflow: hidden;
}

.payment-progress-bar {
    height: 100%;
    border-radius: 3px;
    transition: width 0.3s ease;
}

.payment-progress-bar.success {
    background: linear-gradient(90deg, #28a745, #20c997);
}

.payment-progress-bar.warning {
    background: linear-gradient(90deg, #ffc107, #fd7e14);
}

.payment-progress-bar.danger {
    background: linear-gradient(90deg, #dc3545, #c82333);
}

/* Status badges */
.status-badge {
    display: inline-block;
    padding: 5px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    text-align: center;
    white-space: nowrap;
}

.status-completed {
    background-color: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.status-partial {
    background-color: #fff3cd;
    color: #856404;
    border: 1px solid #ffeeba;
}

.status-pending {
    background-color: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

.status-no-fees {
    background-color: #e2e3e5;
    color: #383d41;
    border: 1px solid #d6d8db;
}

@media (max-width: 768px) {
    .action-buttons {
        justify-content: center;
    }

    .nav-tabs .nav-link {
        padding: 8px 12px;
        font-size: 13px;
    }

    .status-badge {
        white-space: normal;
    }
}
</style>

<?php include __DIR__ . '/../includes/footer.php'; ?>
