<?php
// student/fee.php - Student Fee View Page (Redesigned with Attendance UI)
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';

Security::requireRole('student');

$pageTitle = 'My Fees';
$extraCSS = ['dashboard.css'];
$extraJS = ['charts.js'];

include __DIR__ . '/../includes/header.php';

// Get database instance
try {
    $db = Database::getInstance();
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Database connection error: ' . htmlspecialchars(DEBUG_MODE ? $e->getMessage() : 'Please try again later.') . '</div>';
    exit;
}

$userId = $_SESSION['user_id'];

// Get student info
$student = $db->getRow(
    "SELECT s.*, u.first_name, u.last_name, u.email, u.profile_image,
            c.class_name, c.section
     FROM students s
     JOIN users u ON s.user_id = u.id
     LEFT JOIN classes c ON s.class_id = c.id
     WHERE s.user_id = ?",
    [$userId]
);

if (!$student) {
    echo '<div class="alert alert-danger">Student record not found.</div>';
    exit;
}

// Get current academic year
$currentAcademicYear = currentAcademicYear();
$selectedAcademicYear = isset($_GET['academic_year']) ? Security::sanitize($_GET['academic_year']) : $currentAcademicYear;

// Get all available academic years for dropdown
$academicYears = $db->getRows(
    "SELECT DISTINCT academic_year FROM fee_structure
     WHERE class_id = ?
     ORDER BY academic_year DESC",
    [$student['class_id']]
);

// Get all fee structures for student's class
$feeStructures = $db->getRows(
    "SELECT fs.*,
            COALESCE((
                SELECT SUM(amount)
                FROM payments
                WHERE fee_structure_id = fs.id
                AND student_id = ?
                AND status = 'completed'
            ), 0) as paid_amount
     FROM fee_structure fs
     WHERE fs.class_id = ? AND fs.academic_year = ?
     ORDER BY fs.term, fs.is_mandatory DESC, fs.fee_type",
    [$student['id'], $student['class_id'], $selectedAcademicYear]
);

// Calculate totals
$totalFees = 0;
$totalPaid = 0;
$outstandingByTerm = [
    'Term 1' => ['total' => 0, 'paid' => 0],
    'Term 2' => ['total' => 0, 'paid' => 0],
    'Term 3' => ['total' => 0, 'paid' => 0]
];

foreach ($feeStructures as $fee) {
    $totalFees += $fee['amount'];
    $totalPaid += $fee['paid_amount'];

    if (isset($outstandingByTerm[$fee['term']])) {
        $outstandingByTerm[$fee['term']]['total'] += $fee['amount'];
        $outstandingByTerm[$fee['term']]['paid'] += $fee['paid_amount'];
    }
}

$outstandingBalance = $totalFees - $totalPaid;
$paymentPercentage = $totalFees > 0 ? round(($totalPaid / $totalFees) * 100, 1) : 0;

// Get payment history for selected year
$paymentHistory = $db->getRows(
    "SELECT p.*, fs.fee_type
     FROM payments p
     LEFT JOIN fee_structure fs ON p.fee_structure_id = fs.id
     WHERE p.student_id = ?
       AND p.status = 'completed'
       AND p.academic_year = ?
     ORDER BY p.payment_date DESC",
    [$student['id'], $selectedAcademicYear]
);

// Get recent payments (last 5 across all years)
$recentPayments = $db->getRows(
    "SELECT p.*, fs.fee_type, fs.term
     FROM payments p
     LEFT JOIN fee_structure fs ON p.fee_structure_id = fs.id
     WHERE p.student_id = ? AND p.status = 'completed'
     ORDER BY p.payment_date DESC
     LIMIT 5",
    [$student['id']]
);

// Get payment summary by term
$paymentSummary = $db->getRows(
    "SELECT
        fs.term,
        COUNT(DISTINCT fs.id) as total_fee_items,
        COALESCE(SUM(fs.amount), 0) as total_amount,
        COALESCE(SUM(CASE WHEN p.id IS NOT NULL THEN p.amount ELSE 0 END), 0) as paid_amount
     FROM fee_structure fs
     LEFT JOIN payments p ON fs.id = p.fee_structure_id
        AND p.student_id = ?
        AND p.status = 'completed'
     WHERE fs.class_id = ? AND fs.academic_year = ?
     GROUP BY fs.term
     ORDER BY fs.term",
    [$student['id'], $student['class_id'], $selectedAcademicYear]
);

// Get monthly payment trends for chart (last 6 months)
$monthlyPayments = $db->getRows(
    "SELECT
        DATE_FORMAT(payment_date, '%Y-%m') as month,
        COUNT(*) as transaction_count,
        COALESCE(SUM(amount), 0) as total
     FROM payments
     WHERE student_id = ?
       AND status = 'completed'
       AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
     GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
     ORDER BY month",
    [$student['id']]
);

// Get color class for payment percentage
$percentageColorClass = 'text-danger';
if ($paymentPercentage >= 90) {
    $percentageColorClass = 'text-success';
} elseif ($paymentPercentage >= 50) {
    $percentageColorClass = 'text-info';
}

// Function to get status badge
function getStatusBadge($paid, $total) {
    if ($total == 0) return '<span class="badge badge-secondary"><i class="fas fa-minus-circle"></i> No Fees</span>';
    if ($paid >= $total) return '<span class="badge badge-success"><i class="fas fa-check-circle"></i> Fully Paid</span>';
    if ($paid > 0) return '<span class="badge badge-warning"><i class="fas fa-hourglass-half"></i> Partially Paid</span>';
    return '<span class="badge badge-danger"><i class="fas fa-times-circle"></i> Outstanding</span>';
}
?>

<style>
/* Fee page specific styles - using attendance UI design */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: white;
    border-radius: 10px;
    padding: 20px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    display: flex;
    align-items: center;
    gap: 15px;
    transition: all 0.3s ease;
    border-bottom: 3px solid transparent;
}

.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 5px 20px rgba(0,0,0,0.1);
    border-bottom-color: #ffd700;
}

.stat-icon {
    width: 60px;
    height: 60px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    flex-shrink: 0;
}

.stat-content {
    flex: 1;
}

.stat-content h3 {
    font-size: 28px;
    font-weight: 700;
    margin: 0;
    color: #002855;
    line-height: 1.2;
}

.stat-content p {
    margin: 5px 0 0;
    color: #6c757d;
    font-size: 14px;
}

/* Payment progress circle */
.payment-progress-circle {
    display: flex;
    align-items: center;
    gap: 40px;
    padding: 20px;
    flex-wrap: wrap;
}

.rate-circle {
    flex-shrink: 0;
    margin: 0 auto;
}

.circle-progress {
    width: 180px;
    height: 180px;
    border-radius: 50%;
    background: #f8f9fa;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    box-shadow: 0 10px 25px rgba(0,0,0,0.1);
}

.circle-progress::before {
    content: '';
    position: absolute;
    top: 15px;
    left: 15px;
    right: 15px;
    bottom: 15px;
    border-radius: 50%;
    background: white;
    z-index: 1;
}

.circle-progress::after {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    border-radius: 50%;
    background: conic-gradient(
        from 0deg,
        #28a745 0deg <?php echo $paymentPercentage * 3.6; ?>deg,
        #dc3545 <?php echo $paymentPercentage * 3.6; ?>deg 360deg
    );
    mask: radial-gradient(circle at 30% 30%, transparent 68%, black 69%);
    -webkit-mask: radial-gradient(circle at 30% 30%, transparent 68%, black 69%);
}

.percentage {
    position: relative;
    z-index: 2;
    font-size: 2.5rem;
    font-weight: 700;
    color: #002855;
    text-align: center;
    line-height: 1.2;
}

.percentage small {
    display: block;
    font-size: 0.9rem;
    font-weight: 400;
    color: #6c757d;
}

.rate-details {
    flex: 1;
    min-width: 250px;
}

.rate-details p {
    font-size: 1.1rem;
    margin-bottom: 15px;
}

.rate-details i {
    margin-right: 8px;
}

/* Badge styles - matching attendance */
.badge {
    display: inline-block;
    padding: 5px 10px;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 600;
}

.badge-success {
    background-color: #d4edda;
    color: #155724;
}

.badge-success i {
    color: #28a745;
}

.badge-danger {
    background-color: #f8d7da;
    color: #721c24;
}

.badge-danger i {
    color: #dc3545;
}

.badge-warning {
    background-color: #fff3cd;
    color: #856404;
}

.badge-warning i {
    color: #ffc107;
}

.badge-info {
    background-color: #d1ecf1;
    color: #0c5460;
}

.badge-info i {
    color: #17a2b8;
}

.badge-secondary {
    background-color: #e2e3e5;
    color: #383d41;
}

.badge-secondary i {
    color: #6c757d;
}

/* Table styles - matching attendance */
.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table th {
    background: #002855;
    color: white;
    padding: 12px;
    text-align: left;
    font-weight: 500;
    white-space: nowrap;
}

.data-table td {
    padding: 12px;
    border-bottom: 1px solid #e9ecef;
}

.data-table tbody tr:hover {
    background-color: #f8f9fa;
}

.data-table tfoot td {
    background: #f8f9fa;
    font-weight: 700;
    color: #002855;
    border-top: 2px solid #dee2e6;
}

/* Form styles - matching attendance */
.form-row {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    align-items: flex-end;
}

.form-group {
    flex: 1 1 200px;
}

.form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 500;
    color: #002855;
}

.form-control {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
}

.form-control:focus {
    outline: none;
    border-color: #ffd700;
    box-shadow: 0 0 0 2px rgba(255, 215, 0, 0.2);
}

/* Card styles - matching attendance */
.card {
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    margin-bottom: 30px;
    overflow: hidden;
}

.card-header {
    padding: 15px 20px;
    border-bottom: 1px solid #e9ecef;
    background: #f8f9fa;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
}

.card-header h3 {
    margin: 0;
    font-size: 18px;
    color: #002855;
    display: flex;
    align-items: center;
    gap: 8px;
}

.card-header h3 i {
    color: #ffd700;
}

.card-body {
    padding: 20px;
}

/* Alert styles - matching attendance */
.alert {
    padding: 15px 20px;
    border-radius: 8px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.alert-info {
    background-color: #d1ecf1;
    color: #0c5460;
    border: 1px solid #bee5eb;
}

.alert-info i {
    color: #17a2b8;
}

.alert-success {
    background-color: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.alert-success i {
    color: #28a745;
}

/* Table responsive */
.table-responsive {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

/* User info - matching attendance */
.user-info {
    display: flex;
    align-items: center;
    gap: 15px;
    background: white;
    padding: 10px 20px;
    border-radius: 50px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}

.user-info i {
    font-size: 2rem;
    color: #002855;
}

.user-info span {
    font-weight: 600;
    color: #002855;
}

.user-info small {
    color: #6c757d;
    font-size: 0.8rem;
    display: block;
}

/* Dashboard header - matching attendance */
.dashboard-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    flex-wrap: wrap;
    gap: 15px;
}

.dashboard-header h1 {
    margin: 0;
    font-size: 28px;
    color: #002855;
}

/* Text colors */
.text-success { color: #28a745; }
.text-info { color: #17a2b8; }
.text-danger { color: #dc3545; }
.text-warning { color: #ffc107; }
.text-right { text-align: right; }
.font-weight-bold { font-weight: 700; }

/* Progress bar */
.progress {
    height: 10px;
    background-color: #e9ecef;
    border-radius: 5px;
    overflow: hidden;
    margin-top: 10px;
}

.progress-bar {
    height: 100%;
    transition: width 0.3s ease;
}

.progress-bar.bg-success { background-color: #28a745; }
.progress-bar.bg-info { background-color: #17a2b8; }
.progress-bar.bg-danger { background-color: #dc3545; }

/* Payment method badges */
.payment-method {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 0.8rem;
    background: #f0f0f0;
    color: #495057;
}

.payment-method i {
    margin-right: 4px;
}

/* Receipt link */
.receipt-link {
    color: #002855;
    text-decoration: none;
    font-weight: 500;
    transition: color 0.3s ease;
}

.receipt-link:hover {
    color: #ffd700;
}

/* Responsive */
@media (max-width: 768px) {
    .payment-progress-circle {
        flex-direction: column;
        text-align: center;
    }

    .stats-grid {
        grid-template-columns: 1fr;
    }

    .dashboard-header {
        flex-direction: column;
        text-align: center;
    }

    .form-row {
        flex-direction: column;
    }

    .form-group {
        width: 100%;
    }

    .circle-progress {
        width: 150px;
        height: 150px;
    }

    .percentage {
        font-size: 2rem;
    }

    .card-header {
        flex-direction: column;
        gap: 10px;
        text-align: center;
    }
}
</style>

<div class="dashboard-container">
    <?php render_sidebar('student'); ?>

    <main class="dashboard-main">
        <div class="dashboard-header">
            <h1>My Fees</h1>
            <div class="user-info">
                <i class="fas fa-user-graduate"></i>
                <div>
                    <span><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></span>
                    <small><?php echo htmlspecialchars(($student['class_name'] ?? '') . ' ' . ($student['section'] ?? '')); ?></small>
                </div>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(0,40,85,0.1);">
                    <i class="fas fa-calculator" style="color: #002855;"></i>
                </div>
                <div class="stat-content">
                    <h3>₦<?php echo number_format($totalFees, 2); ?></h3>
                    <p>Total Fees</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(40,167,69,0.1);">
                    <i class="fas fa-check-circle" style="color: #28a745;"></i>
                </div>
                <div class="stat-content">
                    <h3>₦<?php echo number_format($totalPaid, 2); ?></h3>
                    <p>Total Paid</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(220,53,69,0.1);">
                    <i class="fas fa-exclamation-triangle" style="color: #dc3545;"></i>
                </div>
                <div class="stat-content">
                    <h3>₦<?php echo number_format($outstandingBalance, 2); ?></h3>
                    <p>Outstanding Balance</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(23,162,184,0.1);">
                    <i class="fas fa-receipt" style="color: #17a2b8;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo count($paymentHistory); ?></h3>
                    <p>Payments Made</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(255,193,7,0.1);">
                    <i class="fas fa-percent" style="color: #ffc107;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo e($paymentPercentage); ?>%</h3>
                    <p>Payment Rate</p>
                </div>
            </div>
        </div>

        <!-- Payment Progress Circle -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-chart-pie"></i> Payment Progress - <?php echo htmlspecialchars($selectedAcademicYear); ?></h3>
            </div>
            <div class="card-body">
                <div class="payment-progress-circle">
                    <div class="rate-circle">
                        <div class="circle-progress">
                            <span class="percentage">
                                <?php echo e($paymentPercentage); ?>%
                                <small>paid</small>
                            </span>
                        </div>
                    </div>
                    <div class="rate-details">
                        <p>
                            <i class="fas fa-info-circle"></i>
                            Your payment progress for <strong><?php echo htmlspecialchars($selectedAcademicYear); ?></strong> is
                            <strong class="<?php echo e($percentageColorClass); ?>"><?php echo e($paymentPercentage); ?>%</strong>
                        </p>

                        <?php if ($paymentPercentage >= 90): ?>
                        <p class="text-success">
                            <i class="fas fa-star"></i>
                            Excellent! You've almost completed your payments. Thank you for your timely payments!
                        </p>
                        <div class="progress">
                            <div class="progress-bar bg-success" style="width: <?php echo e($paymentPercentage); ?>%;"></div>
                        </div>
                        <?php elseif ($paymentPercentage >= 50): ?>
                        <p class="text-info">
                            <i class="fas fa-thumbs-up"></i>
                            Good progress! You've paid more than half of your fees. Keep it up!
                        </p>
                        <div class="progress">
                            <div class="progress-bar bg-info" style="width: <?php echo e($paymentPercentage); ?>%;"></div>
                        </div>
                        <?php elseif ($paymentPercentage > 0): ?>
                        <p class="text-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            You've started paying your fees. Please complete the remaining balance.
                        </p>
                        <div class="progress">
                            <div class="progress-bar bg-warning" style="width: <?php echo e($paymentPercentage); ?>%;"></div>
                        </div>
                        <?php else: ?>
                        <p class="text-danger">
                            <i class="fas fa-exclamation-triangle"></i>
                            No payments recorded yet. Please clear your outstanding fees.
                        </p>
                        <div class="progress">
                            <div class="progress-bar bg-danger" style="width: 0%;"></div>
                        </div>
                        <?php endif; ?>

                        <div style="margin-top: 15px; display: flex; gap: 20px; flex-wrap: wrap;">
                            <div><span class="badge badge-success">Paid: ₦<?php echo number_format($totalPaid, 2); ?></span></div>
                            <div><span class="badge badge-danger">Outstanding: ₦<?php echo number_format($outstandingBalance, 2); ?></span></div>
                            <div><span class="badge badge-info">Fee Items: <?php echo count($feeStructures); ?></span></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Academic Year Selector -->
        <?php if (!empty($academicYears)): ?>
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-filter"></i> Select Academic Year</h3>
            </div>
            <div class="card-body">
                <form method="GET" class="form-row">
                    <div class="form-group">
                        <label for="academic_year">Academic Year</label>
                        <select id="academic_year" name="academic_year" class="form-control" onchange="this.form.submit()">
                            <?php foreach ($academicYears as $year): ?>
                            <option value="<?php echo htmlspecialchars($year['academic_year']); ?>"
                                <?php echo $selectedAcademicYear == $year['academic_year'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($year['academic_year']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>&nbsp;</label>
                        <a href="fees.php" class="btn btn-outline" style="padding: 8px 20px; display: inline-block; background: #f8f9fa; border: 1px solid #ddd; border-radius: 4px; text-decoration: none; color: #333;">
                            <i class="fas fa-redo"></i> Current Year
                        </a>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Fee Structure Details -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-list"></i> Fee Structure - <?php echo htmlspecialchars($selectedAcademicYear); ?></h3>
                <?php if (!empty($feeStructures)): ?>
                <span class="badge badge-info"><?php echo count($feeStructures); ?> items</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (!empty($feeStructures)): ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Fee Type</th>
                                <th>Term</th>
                                <th class="text-right">Amount (₦)</th>
                                <th class="text-right">Paid (₦)</th>
                                <th class="text-right">Balance (₦)</th>
                                <th>Due Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($feeStructures as $fee):
                                $balance = $fee['amount'] - $fee['paid_amount'];
                                $isOverdue = !empty($fee['due_date']) && strtotime($fee['due_date']) < time() && $balance > 0;
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($fee['fee_type']); ?></strong>
                                    <?php if ($fee['is_mandatory']): ?>
                                        <span class="badge badge-info" style="margin-left: 5px;">Mandatory</span>
                                    <?php endif; ?>
                                    <?php if (!empty($fee['description'])): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($fee['description']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($fee['term']); ?></td>
                                <td class="text-right">₦<?php echo number_format($fee['amount'], 2); ?></td>
                                <td class="text-right text-success">₦<?php echo number_format($fee['paid_amount'], 2); ?></td>
                                <td class="text-right <?php echo $balance > 0 ? 'text-danger' : 'text-success'; ?> font-weight-bold">
                                    ₦<?php echo number_format($balance, 2); ?>
                                </td>
                                <td>
                                    <?php if (!empty($fee['due_date'])): ?>
                                        <?php echo date('d M Y', strtotime($fee['due_date'])); ?>
                                        <?php if ($isOverdue): ?>
                                            <br><span class="badge badge-danger">Overdue</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">Not set</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo getStatusBadge($fee['paid_amount'], $fee['amount']); ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="2" class="text-right font-weight-bold">TOTAL:</td>
                                <td class="text-right font-weight-bold">₦<?php echo number_format($totalFees, 2); ?></td>
                                <td class="text-right font-weight-bold text-success">₦<?php echo number_format($totalPaid, 2); ?></td>
                                <td class="text-right font-weight-bold <?php echo $outstandingBalance > 0 ? 'text-danger' : 'text-success'; ?>">
                                    ₦<?php echo number_format($outstandingBalance, 2); ?>
                                </td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php else: ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    No fee structure found for <?php echo htmlspecialchars($selectedAcademicYear); ?>.
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Payment Summary by Term -->
        <?php if (!empty($paymentSummary)): ?>
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-chart-bar"></i> Payment Summary by Term</h3>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Term</th>
                                <th class="text-right">Total Fees (₦)</th>
                                <th class="text-right">Paid (₦)</th>
                                <th class="text-right">Outstanding (₦)</th>
                                <th>Progress</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($paymentSummary as $summary): ?>
                                <?php
                                $termOutstanding = $summary['total_amount'] - $summary['paid_amount'];
                                $termPercentage = $summary['total_amount'] > 0
                                    ? round(($summary['paid_amount'] / $summary['total_amount']) * 100, 1)
                                    : 0;
                                $termColor = $termPercentage >= 90 ? 'success' : ($termPercentage >= 50 ? 'info' : 'danger');
                                ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($summary['term']); ?></strong></td>
                                    <td class="text-right">₦<?php echo number_format($summary['total_amount'], 2); ?></td>
                                    <td class="text-right text-success">₦<?php echo number_format($summary['paid_amount'], 2); ?></td>
                                    <td class="text-right <?php echo $termOutstanding > 0 ? 'text-danger' : 'text-success'; ?>">
                                        ₦<?php echo number_format($termOutstanding, 2); ?>
                                    </td>
                                    <td style="min-width: 150px;">
                                        <div class="progress" style="height: 8px;">
                                            <div class="progress-bar bg-<?php echo e($termColor); ?>"
                                                 style="width: <?php echo min(100, $termPercentage); ?>%;"></div>
                                        </div>
                                        <small class="text-muted"><?php echo e($termPercentage); ?>% paid</small>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Payment History -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-history"></i> Payment History - <?php echo htmlspecialchars($selectedAcademicYear); ?></h3>
                <?php if (!empty($paymentHistory)): ?>
                <span class="badge badge-info"><?php echo count($paymentHistory); ?> payments</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (!empty($paymentHistory)): ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Receipt No.</th>
                                <th>Date</th>
                                <th>Fee Type</th>
                                <th>Term</th>
                                <th class="text-right">Amount (₦)</th>
                                <th>Payment Method</th>
                                <th>Reference</th>
                                <th>Receipt</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($paymentHistory as $payment): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($payment['receipt_number']); ?></strong></td>
                                <td><?php echo date('d M Y', strtotime($payment['payment_date'])); ?></td>
                                <td><?php echo htmlspecialchars($payment['fee_type'] ?? 'General Payment'); ?></td>
                                <td><?php echo htmlspecialchars($payment['term'] ?? '-'); ?></td>
                                <td class="text-right text-success font-weight-bold">
                                    ₦<?php echo number_format($payment['amount'], 2); ?>
                                </td>
                                <td>
                                    <span class="payment-method">
                                        <?php
                                        $method = $payment['payment_method'];
                                        switch($method) {
                                            case 'bank_transfer':
                                                echo '<i class="fas fa-university"></i> Transfer';
                                                break;
                                            case 'cash':
                                                echo '<i class="fas fa-money-bill-wave"></i> Cash';
                                                break;
                                            case 'card':
                                                echo '<i class="fas fa-credit-card"></i> Card';
                                                break;
                                            case 'cheque':
                                                echo '<i class="fas fa-money-check"></i> Cheque';
                                                break;
                                            case 'pos':
                                                echo '<i class="fas fa-terminal"></i> POS';
                                                break;
                                            default:
                                                echo ucfirst($method);
                                        }
                                        ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($payment['transaction_id'])): ?>
                                        <small class="text-muted"><?php echo htmlspecialchars($payment['transaction_id']); ?></small>
                                    <?php elseif (!empty($payment['cheque_number'])): ?>
                                        <small class="text-muted">Chq: <?php echo htmlspecialchars($payment['cheque_number']); ?></small>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="print-receipt.php?id=<?php echo e($payment['id']); ?>" class="receipt-link" target="_blank">
                                        <i class="fas fa-print"></i> Print
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="4" class="text-right font-weight-bold">TOTAL:</td>
                                <td class="text-right font-weight-bold text-success">
                                    ₦<?php echo number_format(array_sum(array_column($paymentHistory, 'amount')), 2); ?>
                                </td>
                                <td colspan="3"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php else: ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    No payment history found for <?php echo htmlspecialchars($selectedAcademicYear); ?>.
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Payments (All Time) -->
        <?php if (!empty($recentPayments)): ?>
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-clock"></i> Recent Payments (All Time)</h3>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Receipt No.</th>
                                <th>Date</th>
                                <th>Academic Year</th>
                                <th>Fee Type</th>
                                <th class="text-right">Amount (₦)</th>
                                <th>Method</th>
                                <th>Receipt</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentPayments as $payment): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($payment['receipt_number']); ?></strong></td>
                                <td><?php echo date('d M Y', strtotime($payment['payment_date'])); ?></td>
                                <td><?php echo htmlspecialchars($payment['academic_year']); ?></td>
                                <td><?php echo htmlspecialchars($payment['fee_type'] ?? 'General Payment'); ?></td>
                                <td class="text-right text-success">₦<?php echo number_format($payment['amount'], 2); ?></td>
                                <td><?php echo ucfirst(str_replace('_', ' ', $payment['payment_method'])); ?></td>
                                <td>
                                    <a href="print-receipt.php?id=<?php echo e($payment['id']); ?>" class="receipt-link" target="_blank">
                                        <i class="fas fa-print"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Monthly Payment Chart -->
        <?php if (!empty($monthlyPayments) && count($monthlyPayments) > 1): ?>
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-chart-line"></i> Monthly Payment Trend (Last 6 Months)</h3>
            </div>
            <div class="card-body">
                <canvas id="paymentChart" height="300"></canvas>
            </div>
        </div>
        <?php endif; ?>


    </main>
</div>

<script>
<?php if (!empty($monthlyPayments) && count($monthlyPayments) > 1): ?>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('paymentChart').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode(array_map(function($item) {
                return date('M Y', strtotime($item['month'] . '-01'));
            }, $monthlyPayments)); ?>,
            datasets: [{
                label: 'Payment Amount (₦)',
                data: <?php echo json_encode(array_column($monthlyPayments, 'total'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
                borderColor: '#28a745',
                backgroundColor: 'rgba(40, 167, 69, 0.1)',
                tension: 0.4,
                fill: true,
                pointBackgroundColor: '#28a745',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointRadius: 5
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return '₦' + context.raw.toLocaleString();
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return '₦' + value.toLocaleString();
                        }
                    },
                    grid: {
                        color: 'rgba(0,0,0,0.05)'
                    }
                },
                x: {
                    grid: {
                        display: false
                    }
                }
            }
        }
    });
});
<?php endif; ?>

// Auto-hide alerts after 5 seconds
setTimeout(function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        alert.style.transition = 'opacity 0.5s ease';
        alert.style.opacity = '0';
        setTimeout(function() {
            if (alert.parentNode) {
                alert.remove();
            }
        }, 500);
    });
}, 5000);

// Add smooth scrolling
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
        e.preventDefault();
        const target = document.querySelector(this.getAttribute('href'));
        if (target) {
            target.scrollIntoView({
                behavior: 'smooth'
            });
        }
    });
});

// Confirm before printing receipt
document.querySelectorAll('.receipt-link').forEach(link => {
    link.addEventListener('click', function(e) {
        // Just let it open normally
        console.log('Opening receipt...');
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
