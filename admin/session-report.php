<?php
// admin/session-report.php - Academic Session Executive Summary & Analytics
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/SystemSchema.php';

Security::requireRole(['super_admin', 'admin', 'head_teacher', 'supervisor']);
SystemSchema::ensureAll();

$db = db();
[$message, $messageType] = flash_get();

$session = trim((string)($_GET['session'] ?? '2026/2027'));

// Summary KPIs
$totalStudents = (int)($db->getRow("SELECT COUNT(*) as c FROM students s JOIN users u ON s.user_id = u.id WHERE u.is_active = 1")['c'] ?? 0);
$totalTeachers = (int)($db->getRow("SELECT COUNT(*) as c FROM teachers t JOIN users u ON t.user_id = u.id WHERE u.is_active = 1")['c'] ?? 0);
$totalClasses  = (int)($db->getRow("SELECT COUNT(*) as c FROM classes WHERE is_active = 1")['c'] ?? 0);

// Class enrollment table
$classStats = $db->getRows(
    "SELECT c.id, c.class_name, c.section, c.capacity,
            COUNT(s.id) as enrolled,
            SUM(CASE WHEN s.gender = 'male' THEN 1 ELSE 0 END) as male_count,
            SUM(CASE WHEN s.gender = 'female' THEN 1 ELSE 0 END) as female_count
     FROM classes c
     LEFT JOIN students s ON s.class_id = c.id AND s.id IN (SELECT id FROM students WHERE user_id IN (SELECT id FROM users WHERE is_active = 1))
     WHERE c.is_active = 1
     GROUP BY c.id
     ORDER BY c.class_name, c.section"
);

// Attendance summary
$attSummary = $db->getRow(
    "SELECT COUNT(*) as total_marks,
            SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as presents,
            SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absents
     FROM attendance"
);
$attRate = (!empty($attSummary['total_marks']) && $attSummary['total_marks'] > 0)
    ? round(($attSummary['presents'] / $attSummary['total_marks']) * 100, 1)
    : 100;

// Fee Summary
$feeTotal = (float)($db->getRow("SELECT SUM(amount) as s FROM fee_structure")['s'] ?? 0.0);
$paymentsTotal = (float)($db->getRow("SELECT SUM(amount) as s FROM payments WHERE status = 'completed'")['s'] ?? 0.0);

$pageTitle = 'Session Academic Report';
$extraCSS = ['admin.css', 'dashboard.css'];
include __DIR__ . '/../includes/header.php';
dashboard_open('admin', 'Session Report');
render_alert($message, $messageType);
?>

<div class="card" style="margin-bottom:20px;">
    <div class="card-body form-inline" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <div>
            <h3 style="margin:0; font-size:1.15rem; color:#002147;"><i class="fas fa-file-invoice" style="color:#f59e0b;"></i> Academic Session Overview (<?php echo e($session); ?>)</h3>
            <span class="text-muted" style="font-size:0.85rem;">Consolidated institutional metrics and student analytics</span>
        </div>
        <div style="display:flex; gap:10px;">
            <button type="button" onclick="window.print()" class="btn btn-outline">
                <i class="fas fa-print"></i> Print Session Report
            </button>
        </div>
    </div>
</div>

<div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); margin-bottom:24px;">
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(0,33,71,0.1);"><i class="fas fa-user-graduate" style="color:#002147;"></i></div>
        <div class="stat-content">
            <h3><?php echo $totalStudents; ?></h3>
            <p>Enrolled Students</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(16,185,129,0.1);"><i class="fas fa-chalkboard-teacher" style="color:#10b981;"></i></div>
        <div class="stat-content">
            <h3><?php echo $totalTeachers; ?></h3>
            <p>Teaching Staff</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(245,158,11,0.1);"><i class="fas fa-calendar-check" style="color:#f59e0b;"></i></div>
        <div class="stat-content">
            <h3><?php echo $attRate; ?>%</h3>
            <p>Avg Attendance Rate</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(59,130,246,0.1);"><i class="fas fa-money-bill-wave" style="color:#3b82f6;"></i></div>
        <div class="stat-content">
            <h3>₦<?php echo number_format($paymentsTotal, 2); ?></h3>
            <p>Total Fees Realized</p>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-layer-group"></i> Class Capacity &amp; Gender Ratio Breakdown</h3>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Class &amp; Section</th>
                        <th>Capacity</th>
                        <th>Enrolled Students</th>
                        <th>Male</th>
                        <th>Female</th>
                        <th>Occupancy Rate</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($classStats as $cs):
                        $cap = (int)$cs['capacity'] ?: 30;
                        $enr = (int)$cs['enrolled'];
                        $occ = round(($enr / $cap) * 100, 1);
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($cs['class_name'] . ($cs['section'] ? ' ' . $cs['section'] : '')); ?></strong></td>
                        <td><?php echo $cap; ?> seats</td>
                        <td><strong><?php echo $enr; ?></strong> learners</td>
                        <td><?php echo (int)$cs['male_count']; ?></td>
                        <td><?php echo (int)$cs['female_count']; ?></td>
                        <td>
                            <div style="display:flex; align-items:center; gap:8px;">
                                <div style="flex:1; height:6px; background:#e2e8f0; border-radius:3px; overflow:hidden;">
                                    <div style="width:<?php echo min(100, $occ); ?>%; height:100%; background:#0066ff;"></div>
                                </div>
                                <small style="font-weight:700;"><?php echo $occ; ?>%</small>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
dashboard_close();
include __DIR__ . '/../includes/footer.php';
