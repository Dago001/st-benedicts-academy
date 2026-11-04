<?php
// parent/fees.php - View Fee Status
require_once '../config/config.php';
require_once '../config/security.php';

Security::requireRole('parent');

$pageTitle = 'Fee Status';
$extraCSS = ['dashboard.css'];

include '../includes/header.php';

$db = db();
$userId = $_SESSION['user_id'];

// Get parent info
$parent = $db->getRow(
    "SELECT p.*, u.first_name, u.last_name
     FROM parents p
     JOIN users u ON p.user_id = u.id
     WHERE p.user_id = ?",
    [$userId]
);

if (!$parent) {
    echo '<div class="container" style="padding:24px"><div class="alert alert-error">Your parent profile is incomplete. Please contact the school office.</div></div>';
    include __DIR__ . '/../includes/footer.php';
    exit;
}

// Get children of this parent
$children = $db->getRows(
    "SELECT s.*, u.first_name, u.last_name, u.email,
            c.class_name, c.section
     FROM students s
     JOIN users u ON s.user_id = u.id
     LEFT JOIN classes c ON s.class_id = c.id
     WHERE s.parent_id = ? AND u.is_active = 1",
    [$parent['id']]
);

// Get selected child
// A parent may only look at their own children: unknown ids fall back to the first child
$selectedChildId = $children[0]['id'] ?? null;
if (isset($_GET['child']) && in_array((int)$_GET['child'], array_map('intval', array_column($children, 'id')), true)) {
    $selectedChildId = (int)$_GET['child'];
}
$selectedYear = $_GET['year'] ?? (currentAcademicYear());

// Support searching and paying fees for another student / ward
$otherStudentQuery = trim($_GET['other_student'] ?? $_POST['other_student_admission'] ?? '');
$otherStudent = null;
$otherStudentFees = [];
$otherStudentError = '';

if ($otherStudentQuery !== '') {
    $otherStudent = $db->getRow(
        "SELECT s.*, u.first_name, u.last_name, u.email, c.class_name, c.section, c.id as class_id
         FROM students s
         JOIN users u ON s.user_id = u.id
         LEFT JOIN classes c ON s.class_id = c.id
         WHERE s.admission_number = ? OR s.admission_number = ? LIMIT 1",
        [$otherStudentQuery, strtoupper($otherStudentQuery)]
    );
    if ($otherStudent && !empty($otherStudent['class_id'])) {
        $otherStudentFees = $db->getRows(
            "SELECT * FROM fee_structure WHERE class_id = ? AND academic_year = ? ORDER BY term, is_mandatory DESC",
            [$otherStudent['class_id'], $selectedYear]
        );
    } elseif (!$otherStudent) {
        $otherStudentError = "No student found with admission number '{$otherStudentQuery}'. Please verify the student's admission number.";
    }
}

// Get academic years for filter
$academicYears = $db->getRows(
    "SELECT DISTINCT academic_year FROM fee_structure ORDER BY academic_year DESC"
);

// Get fee data for selected child
$feeStructure = [];
$payments = [];
$summary = [];

if ($selectedChildId) {
    // Get child's class
    $child = $db->getRow(
        "SELECT s.*, c.class_name, c.section, c.id as class_id
         FROM students s
         LEFT JOIN classes c ON s.class_id = c.id
         WHERE s.id = ?",
        [$selectedChildId]
    );

    if ($child && $child['class_id']) {
        // Get fee structure for child's class
        $feeStructure = $db->getRows(
            "SELECT * FROM fee_structure
             WHERE class_id = ? AND academic_year = ?
             ORDER BY term, is_mandatory DESC",
            [$child['class_id'], $selectedYear]
        );

        // Get payments made
        $payments = $db->getRows(
            "SELECT p.*,
                    CONCAT(ru.first_name, ' ', ru.last_name) as recorded_by_name
             FROM payments p
             LEFT JOIN users ru ON p.recorded_by = ru.id
             WHERE p.student_id = ? AND p.academic_year = ?
             ORDER BY p.payment_date DESC",
            [$selectedChildId, $selectedYear]
        );

        // Calculate summary
        $totalFees = 0;
        $totalPaid = 0;
        $totalPending = 0;

        foreach ($feeStructure as $fee) {
            $totalFees += $fee['amount'];
        }

        foreach ($payments as $payment) {
            if ($payment['status'] === 'completed') {
                $totalPaid += $payment['amount'];
            } elseif ($payment['status'] === 'pending') {
                $totalPending += $payment['amount'];
            }
        }

        $balance = $totalFees - $totalPaid;

        $summary = [
            'total_fees' => $totalFees,
            'total_paid' => $totalPaid,
            'total_pending' => $totalPending,
            'balance' => $balance,
            'payment_percentage' => $totalFees > 0 ? round(($totalPaid / $totalFees) * 100, 2) : 0
        ];
    }
}
?>

<div class="dashboard-container">
    <?php render_sidebar('parent'); ?>

    <main class="dashboard-main">
        <div class="dashboard-header">
            <h1>Fee Status</h1>
        </div>

        <?php if (empty($children)): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i>
            No children are linked to your account. Please contact the school administration.
        </div>
        <?php else: ?>

        <!-- Child and Year Selector -->
        <div class="card">
            <div class="card-body">
                <form method="GET" class="form-row">
                    <div class="form-group col-md-5">
                        <label for="child">Select Child</label>
                        <select id="child" name="child" class="form-control" onchange="this.form.submit()">
                            <?php foreach ($children as $child): ?>
                            <option value="<?php echo e($child['id']); ?>" <?php echo $selectedChildId == $child['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($child['first_name'] . ' ' . $child['last_name']); ?>
                                - <?php echo htmlspecialchars($child['class_name'] . ' ' . $child['section']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group col-md-5">
                        <label for="year">Academic Year</label>
                        <select id="year" name="year" class="form-control" onchange="this.form.submit()">
                            <?php foreach ($academicYears as $year): ?>
                            <option value="<?php echo e($year['academic_year']); ?>" <?php echo $selectedYear == $year['academic_year'] ? 'selected' : ''; ?>>
                                <?php echo e($year['academic_year']); ?>
                            </option>
                            <?php endforeach; ?>
                            <option value="<?php echo currentAcademicYear(); ?>" <?php echo $selectedYear == (currentAcademicYear()) ? 'selected' : ''; ?>>
                                Current Year
                            </option>
                        </select>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($selectedChildId && isset($child)): ?>

        <!-- Fee Summary Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(0,40,85,0.1);">
                    <i class="fas fa-calculator" style="color: #002855;"></i>
                </div>
                <div class="stat-content">
                    <h3>₦<?php echo number_format($summary['total_fees'], 2); ?></h3>
                    <p>Total Fees</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(40,167,69,0.1);">
                    <i class="fas fa-check-circle" style="color: #28a745;"></i>
                </div>
                <div class="stat-content">
                    <h3>₦<?php echo number_format($summary['total_paid'], 2); ?></h3>
                    <p>Total Paid</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(255,193,7,0.1);">
                    <i class="fas fa-clock" style="color: #ffc107;"></i>
                </div>
                <div class="stat-content">
                    <h3>₦<?php echo number_format($summary['total_pending'], 2); ?></h3>
                    <p>Pending</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(220,53,69,0.1);">
                    <i class="fas fa-exclamation-triangle" style="color: #dc3545;"></i>
                </div>
                <div class="stat-content">
                    <h3>₦<?php echo number_format($summary['balance'], 2); ?></h3>
                    <p>Balance</p>
                </div>
            </div>
        </div>

        <!-- Payment Progress -->
        <div class="card">
            <div class="card-header">
                <h3>Payment Progress</h3>
            </div>
            <div class="card-body">
                <div class="progress" style="height: 30px;">
                    <div class="progress-bar bg-success"
                         style="width: <?php echo e($summary['payment_percentage']); ?>%;">
                        <?php echo e($summary['payment_percentage']); ?>% Paid
                    </div>
                </div>
                <div class="row mt-4">
                    <div class="col-md-6">
                        <h5>Fee Structure</h5>
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Fee Type</th>
                                    <th>Term</th>
                                    <th class="text-right">Amount (₦)</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($feeStructure as $fee): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($fee['fee_type']); ?></td>
                                    <td><?php echo e($fee['term']); ?></td>
                                    <td class="text-right"><?php echo number_format($fee['amount'], 2); ?></td>
                                    <td>
                                        <?php
                                        // Check if this fee has been paid
                                        $paid = false;
                                        foreach ($payments as $p) {
                                            if ($p['fee_structure_id'] == $fee['id'] && $p['status'] == 'completed') {
                                                $paid = true;
                                                break;
                                            }
                                        }
                                        ?>
                                        <?php if ($paid): ?>
                                        <span class="badge badge-success">Paid</span>
                                        <?php else: ?>
                                        <span class="badge badge-warning">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="font-weight-bold">
                                    <td colspan="2">TOTAL</td>
                                    <td class="text-right">₦<?php echo number_format($summary['total_fees'], 2); ?></td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <div class="col-md-6">
                        <h5>Payment History</h5>
                        <?php if (!empty($payments)): ?>
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Receipt</th>
                                    <th class="text-right">Amount (₦)</th>
                                    <th>Method</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payments as $payment): ?>
                                <tr>
                                    <td><?php echo date('d/m/Y', strtotime($payment['payment_date'])); ?></td>
                                    <td>
                                        <a href="view-receipt?id=<?php echo e($payment['id']); ?>" target="_blank">
                                            <?php echo e($payment['receipt_number']); ?>
                                        </a>
                                    </td>
                                    <td class="text-right"><?php echo number_format($payment['amount'], 2); ?></td>
                                    <td><?php echo e(ucfirst($payment['payment_method'])); ?></td>
                                    <td>
                                        <?php if ($payment['status'] == 'completed'): ?>
                                        <span class="badge badge-success">Completed</span>
                                        <?php elseif ($payment['status'] == 'pending'): ?>
                                        <span class="badge badge-warning">Pending</span>
                                        <?php else: ?>
                                        <span class="badge badge-danger"><?php echo e($payment['status']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                        <p class="text-muted">No payment records found.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pay Fees for Another Student / Ward (Benefactor & Community Support) -->
        <div class="card" id="payOtherStudentSection" style="margin-bottom:20px;">
            <div class="card-header" style="background:#002147; color:#fff; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                <h3 style="color:#FFD700; margin:0; font-size:1.1rem;">
                    <i class="fas fa-hand-holding-heart"></i> Pay School Fees for Another Student / Ward
                </h3>
                <span style="font-size:0.8rem; color:rgba(255,255,255,0.85);">Support other parents, relatives, or sponsor a student at St. Benedict's</span>
            </div>
            <div class="card-body">
                <p style="font-size:0.9rem; color:#475569; margin-bottom:16px;">
                    Parents and guardians can pay school fees, tuition, and development levies for other students, wards, or relatives directly through the portal using their official Admission Number.
                </p>

                <form method="GET" action="#payOtherStudentSection" style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:20px;">
                    <div style="flex:1; min-width:240px;">
                        <input type="text" name="other_student" class="form-control"
                               placeholder="Enter Student Admission Number (e.g. STB/2026/001)"
                               value="<?php echo htmlspecialchars($otherStudentQuery); ?>" required>
                    </div>
                    <button type="submit" class="btn btn-primary" style="background:#002147; color:#FFD700; font-weight:700;">
                        <i class="fas fa-search"></i> Look Up Student
                    </button>
                    <?php if ($otherStudentQuery !== ''): ?>
                        <a href="fees#payOtherStudentSection" class="btn btn-outline">Clear</a>
                    <?php endif; ?>
                </form>

                <?php if ($otherStudentError !== ''): ?>
                    <div class="alert alert-warning" style="background:#fffbeb; border:1px solid #fde68a; color:#92400e; padding:12px 16px; border-radius:8px;">
                        <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($otherStudentError); ?>
                    </div>
                <?php endif; ?>

                <?php if ($otherStudent): ?>
                    <div style="background:#f8fafc; border:1px solid #cbd5e1; border-radius:12px; padding:18px 20px; margin-bottom:20px;">
                        <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px; margin-bottom:14px; border-bottom:1px solid #e2e8f0; padding-bottom:12px;">
                            <div>
                                <span class="badge badge-success" style="font-size:0.75rem; text-transform:uppercase;"><i class="fas fa-check-circle"></i> Student Verified</span>
                                <h4 style="color:#002147; margin:6px 0 2px; font-size:1.15rem;">
                                    <?php echo htmlspecialchars($otherStudent['first_name'] . ' ' . $otherStudent['last_name']); ?>
                                </h4>
                                <div style="font-size:0.85rem; color:#475569;">
                                    Admission No: <strong style="color:#002147;"><?php echo htmlspecialchars($otherStudent['admission_number']); ?></strong> &bull;
                                    Class: <strong><?php echo htmlspecialchars($otherStudent['class_name'] . ' ' . $otherStudent['section']); ?></strong>
                                </div>
                            </div>
                            <div style="text-align:right;">
                                <span style="font-size:0.75rem; color:#64748b; text-transform:uppercase; font-weight:700;">Academic Session</span>
                                <div style="font-size:1.05rem; font-weight:800; color:#002147;"><?php echo htmlspecialchars($selectedYear); ?></div>
                            </div>
                        </div>

                        <h5 style="color:#002147; margin-bottom:10px; font-size:0.95rem;">Applicable Fees for <?php echo htmlspecialchars($otherStudent['first_name']); ?>:</h5>
                        <?php if (!empty($otherStudentFees)): ?>
                            <table class="table table-sm" style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; margin-bottom:16px;">
                                <thead>
                                    <tr style="background:#f1f5f9;">
                                        <th>Fee Item</th>
                                        <th>Term</th>
                                        <th class="text-right">Amount (₦)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $totalOther = 0; foreach ($otherStudentFees as $of): $totalOther += (float)$of['amount']; ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($of['fee_type']); ?></td>
                                        <td><?php echo htmlspecialchars($of['term']); ?></td>
                                        <td class="text-right font-weight-bold">₦<?php echo number_format($of['amount'], 2); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr style="background:#f8fafc; font-weight:800;">
                                        <td colspan="2">Total Term Fees</td>
                                        <td class="text-right" style="color:#002147; font-size:1.05rem;">₦<?php echo number_format($totalOther, 2); ?></td>
                                    </tr>
                                </tfoot>
                            </table>
                        <?php else: ?>
                            <p class="text-muted" style="font-size:0.88rem;">Standard terminal tuition &amp; maintenance fees apply for this class.</p>
                        <?php endif; ?>

                        <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:10px; padding:14px 18px; margin-top:14px;">
                            <div style="font-weight:700; color:#1e40af; margin-bottom:6px; font-size:0.9rem;">
                                <i class="fas fa-university"></i> How to Pay Fees for <?php echo htmlspecialchars($otherStudent['first_name'] . ' ' . $otherStudent['last_name']); ?>:
                            </div>
                            <p style="font-size:0.86rem; color:#1e3a8a; margin:0 0 8px;">
                                Transfer directly to the official school bank account using the student's admission number as payment narration:
                            </p>
                            <div style="background:#fff; border:1px solid #cbd5e1; border-radius:8px; padding:10px 14px; font-size:0.88rem; color:#0f172a;">
                                <div><strong>Bank Name:</strong> <?php echo htmlspecialchars(school_bank_name()); ?></div>
                                <div><strong>Account Name:</strong> St. Benedict's Early Years British Academy</div>
                                <div><strong>Account Number:</strong> <span style="color:#c8102e; font-weight:800; font-size:1.05rem;"><?php echo htmlspecialchars(school_account_number()); ?></span></div>
                                <div><strong>Payment Narration:</strong> <code style="color:#002147; font-weight:700;">FEE - <?php echo htmlspecialchars($otherStudent['admission_number']); ?> - <?php echo htmlspecialchars($parent['first_name'] . ' ' . $parent['last_name']); ?></code></div>
                            </div>
                            <p style="font-size:0.8rem; color:#64748b; margin:8px 0 0;">
                                <i class="fas fa-info-circle"></i> Once payment is completed, the school bursary will automatically record your sponsorship payment under <?php echo htmlspecialchars($otherStudent['first_name']); ?>'s ledger and issue an official payment receipt.
                            </p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Payment Instructions -->
        <div class="card">
            <div class="card-header">
                <h3>Payment Instructions</h3>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h5>Bank Transfer</h5>
                        <p><strong>Bank Name:</strong> <?php echo htmlspecialchars(school_bank_name()); ?></p>
                        <p><strong>Account Name:</strong> St. Benedict's Early Years British Academy</p>
                        <p><strong>Account Number:</strong> <span style="color:#c8102e; font-weight:800; font-size:1.05rem;"><?php echo htmlspecialchars(school_account_number()); ?></span></p>
                        <p><strong>Online Gateway:</strong> Paystack (Instant Card / Bank Transfer / USSD)</p>
                    </div>
                    <div class="col-md-6">
                        <h5>Important Notes</h5>
                        <ul>
                            <li>Include child's name and admission number as payment reference</li>
                            <li>Allow 2-3 working days for bank transfers to reflect</li>
                            <li>Receipts are generated automatically upon payment confirmation</li>
                            <li>For cash payments, please visit the school accounts office</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <?php endif; ?>
        <?php endif; ?>
    </main>
</div>

<style>
.progress {
    background-color: #f0f0f0;
    border-radius: 15px;
    overflow: hidden;
}

.progress-bar {
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: bold;
    font-size: 14px;
}

.table-sm td, .table-sm th {
    padding: 0.5rem;
}

.badge {
    padding: 0.4rem 0.6rem;
    font-size: 0.75rem;
}
</style>

<?php
include '../includes/footer.php';
?>