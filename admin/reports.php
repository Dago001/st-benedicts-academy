<?php
// admin/reports.php - Simple Reports Generation
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Require admin role
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$pageTitle = 'Reports';
$extraCSS = ['dashboard.css'];

// Check if header exists
$headerPath = __DIR__ . '/../includes/header.php';
if (!file_exists($headerPath)) {
    die("Error: Header file not found. Please check the path: $headerPath");
}
include $headerPath;

// Get database instance
try {
    $db = Database::getInstance();
} catch (Exception $e) {
    echo '<div style="background:#f8d7da; color:#721c24; padding:15px; margin:20px; border-radius:5px;">';
    echo 'Database connection error: ' . htmlspecialchars($e->getMessage());
    echo '</div>';
    include __DIR__ . '/../includes/footer.php';
    exit;
}

// Get classes for dropdown
$classes = $db->getRows("SELECT * FROM classes WHERE is_active = 1 ORDER BY class_name");

// Get current selections with defaults
$reportType = isset($_GET['type']) ? $_GET['type'] : 'academic';
$classId = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$term = isset($_GET['term']) ? $_GET['term'] : 'Term 1';
$academicYear = isset($_GET['academic_year']) ? $_GET['academic_year'] : (date('Y') . '-' . (date('Y') + 1));

// Get available academic years
$academicYears = $db->getRows("SELECT DISTINCT academic_year FROM classes ORDER BY academic_year DESC");

// Initialize report data
$reportTitle = '';
$reportHeaders = [];
$reportRows = [];
$summaryData = [];

// Generate report based on type
if ($reportType == 'academic') {
    $reportTitle = "Academic Report - $term $academicYear";
    $reportHeaders = ['S/N', 'Admission No.', 'Student Name', 'Subject', 'Score', 'Grade'];
    
    if ($classId > 0) {
        $reportRows = $db->getRows(
            "SELECT 
                s.admission_number,
                u.first_name,
                u.last_name,
                sub.subject_name,
                r.score,
                r.grade
             FROM results r
             JOIN students s ON r.student_id = s.id
             JOIN users u ON s.user_id = u.id
             JOIN subjects sub ON r.subject_id = sub.id
             WHERE r.class_id = ? AND r.term = ? AND r.academic_year = ?
             ORDER BY u.first_name, sub.subject_name",
            [$classId, $term, $academicYear]
        );
    }
}
elseif ($reportType == 'attendance') {
    $reportTitle = "Attendance Report - " . date('d M Y', strtotime($startDate)) . " to " . date('d M Y', strtotime($endDate));
    $reportHeaders = ['S/N', 'Admission No.', 'Student Name', 'Date', 'Status'];
    
    if ($classId > 0) {
        $reportRows = $db->getRows(
            "SELECT 
                s.admission_number,
                u.first_name,
                u.last_name,
                a.date,
                a.status
             FROM attendance a
             JOIN students s ON a.student_id = s.id
             JOIN users u ON s.user_id = u.id
             WHERE a.class_id = ? AND a.date BETWEEN ? AND ?
             ORDER BY a.date DESC, u.first_name",
            [$classId, $startDate, $endDate]
        );
        
        // Get summary data
        $summaryData = $db->getRow(
            "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
                SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent,
                SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late
             FROM attendance a
             JOIN students s ON a.student_id = s.id
             WHERE a.class_id = ? AND a.date BETWEEN ? AND ?",
            [$classId, $startDate, $endDate]
        );
    }
}
elseif ($reportType == 'financial') {
    $reportTitle = "Financial Report - " . date('d M Y', strtotime($startDate)) . " to " . date('d M Y', strtotime($endDate));
    $reportHeaders = ['S/N', 'Receipt No.', 'Date', 'Student', 'Amount', 'Method', 'Status'];
    
    $query = "SELECT 
                p.receipt_number,
                p.payment_date,
                p.amount,
                p.payment_method,
                p.status,
                u.first_name,
                u.last_name,
                s.admission_number
             FROM payments p
             JOIN students s ON p.student_id = s.id
             JOIN users u ON s.user_id = u.id";
    
    $params = [];
    
    if ($classId > 0) {
        $query .= " WHERE s.class_id = ?";
        $params[] = $classId;
    }
    
    $query .= " AND p.payment_date BETWEEN ? AND ? ORDER BY p.payment_date DESC";
    $params[] = $startDate;
    $params[] = $endDate;
    
    $reportRows = $db->getRows($query, $params);
    
    // Get total
    $totalQuery = "SELECT SUM(amount) as total FROM payments p JOIN students s ON p.student_id = s.id";
    if ($classId > 0) {
        $totalQuery .= " WHERE s.class_id = ? AND p.payment_date BETWEEN ? AND ?";
        $totalParams = [$classId, $startDate, $endDate];
    } else {
        $totalQuery .= " WHERE p.payment_date BETWEEN ? AND ?";
        $totalParams = [$startDate, $endDate];
    }
    $summaryData = $db->getRow($totalQuery, $totalParams);
}
elseif ($reportType == 'class_list') {
    $reportTitle = "Class List Report";
    $reportHeaders = ['S/N', 'Admission No.', 'Student Name', 'Gender', 'Date of Birth', 'Parent'];
    
    if ($classId > 0) {
        $classInfo = $db->getRow("SELECT class_name, section FROM classes WHERE id = ?", [$classId]);
        if ($classInfo) {
            $reportTitle .= " - {$classInfo['class_name']} {$classInfo['section']}";
        }
        
        $reportRows = $db->getRows(
            "SELECT 
                s.admission_number,
                u.first_name,
                u.last_name,
                s.gender,
                s.date_of_birth,
                CONCAT(pu.first_name, ' ', pu.last_name) as parent_name
             FROM students s
             JOIN users u ON s.user_id = u.id
             LEFT JOIN parents p ON s.parent_id = p.id
             LEFT JOIN users pu ON p.user_id = pu.id
             WHERE s.class_id = ? AND u.is_active = 1
             ORDER BY u.first_name",
            [$classId]
        );
        
        // Get gender distribution
        $genderStats = $db->getRows(
            "SELECT gender, COUNT(*) as count 
             FROM students s
             JOIN users u ON s.user_id = u.id
             WHERE s.class_id = ? AND u.is_active = 1
             GROUP BY gender",
            [$classId]
        );
        
        foreach ($genderStats as $g) {
            $summaryData[$g['gender']] = $g['count'];
        }
    }
}
?>

<div class="dashboard-container">
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <h3>Admin Panel</h3>
        </div>
        <nav class="sidebar-nav">
            <ul>
                <li><a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a></li>
                <li><a href="students.php"><i class="fas fa-user-graduate"></i> Students</a></li>
                <li><a href="parents.php"><i class="fas fa-users"></i> Parents</a></li>
                <li><a href="teachers.php"><i class="fas fa-chalkboard-teacher"></i> Teachers</a></li>
                <li><a href="classes.php"><i class="fas fa-school"></i> Classes</a></li>
                <li><a href="subjects.php"><i class="fas fa-book"></i> Subjects</a></li>
                <li><a href="attendance.php"><i class="fas fa-calendar-check"></i> Attendance</a></li>
                <li><a href="fees.php"><i class="fas fa-money-bill"></i> Fees</a></li>
                <li><a href="results.php"><i class="fas fa-chart-line"></i> Results</a></li>
                <li><a href="announcements.php"><i class="fas fa-bullhorn"></i> Announcements</a></li>
                <li class="active"><a href="reports.php"><i class="fas fa-file-alt"></i> Reports</a></li>
                <li><a href="audit-logs.php"><i class="fas fa-history"></i> Audit Logs</a></li>
            </ul>
        </nav>
    </aside>
    
    <main class="dashboard-main">
        <div class="dashboard-header">
            <h1>Reports</h1>
        </div>
        
        <!-- Filter Form -->
        <div class="card">
            <div class="card-header">
                <h3>Generate Report</h3>
            </div>
            <div class="card-body">
                <form method="GET" class="form-row">
                    <div class="form-group col-md-3">
                        <label for="type">Report Type</label>
                        <select id="type" name="type" class="form-control" onchange="this.form.submit()">
                            <option value="academic" <?php echo $reportType == 'academic' ? 'selected' : ''; ?>>Academic Report</option>
                            <option value="attendance" <?php echo $reportType == 'attendance' ? 'selected' : ''; ?>>Attendance Report</option>
                            <option value="financial" <?php echo $reportType == 'financial' ? 'selected' : ''; ?>>Financial Report</option>
                            <option value="class_list" <?php echo $reportType == 'class_list' ? 'selected' : ''; ?>>Class List</option>
                        </select>
                    </div>
                    
                    <?php if ($reportType == 'academic'): ?>
                    <div class="form-group col-md-3">
                        <label for="academic_year">Academic Year</label>
                        <select id="academic_year" name="academic_year" class="form-control">
                            <?php foreach ($academicYears as $year): ?>
                            <option value="<?php echo $year['academic_year']; ?>" <?php echo $academicYear == $year['academic_year'] ? 'selected' : ''; ?>>
                                <?php echo $year['academic_year']; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group col-md-3">
                        <label for="term">Term</label>
                        <select id="term" name="term" class="form-control">
                            <option value="Term 1" <?php echo $term == 'Term 1' ? 'selected' : ''; ?>>Term 1</option>
                            <option value="Term 2" <?php echo $term == 'Term 2' ? 'selected' : ''; ?>>Term 2</option>
                            <option value="Term 3" <?php echo $term == 'Term 3' ? 'selected' : ''; ?>>Term 3</option>
                        </select>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($reportType == 'attendance' || $reportType == 'financial'): ?>
                    <div class="form-group col-md-3">
                        <label for="start_date">Start Date</label>
                        <input type="date" id="start_date" name="start_date" class="form-control" value="<?php echo $startDate; ?>">
                    </div>
                    
                    <div class="form-group col-md-3">
                        <label for="end_date">End Date</label>
                        <input type="date" id="end_date" name="end_date" class="form-control" value="<?php echo $endDate; ?>">
                    </div>
                    <?php endif; ?>
                    
                    <div class="form-group col-md-3">
                        <label for="class_id">Class</label>
                        <select id="class_id" name="class_id" class="form-control">
                            <option value="0">All Classes</option>
                            <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>" <?php echo $classId == $class['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['class_name'] . ' ' . ($class['section'] ?? '')); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group col-md-3">
                        <label>&nbsp;</label>
                        <button type="submit" class="btn btn-primary form-control">Generate</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Summary Cards (if available) -->
        <?php if (!empty($summaryData)): ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px;">
            <?php if ($reportType == 'attendance'): ?>
            <div style="background: white; border-radius: 10px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05);">
                <h3 style="margin:0 0 10px 0; color:#002855;">Present</h3>
                <p style="font-size:28px; font-weight:700; margin:0; color:#28a745;"><?php echo $summaryData['present'] ?? 0; ?></p>
            </div>
            <div style="background: white; border-radius: 10px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05);">
                <h3 style="margin:0 0 10px 0; color:#002855;">Absent</h3>
                <p style="font-size:28px; font-weight:700; margin:0; color:#dc3545;"><?php echo $summaryData['absent'] ?? 0; ?></p>
            </div>
            <div style="background: white; border-radius: 10px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05);">
                <h3 style="margin:0 0 10px 0; color:#002855;">Late</h3>
                <p style="font-size:28px; font-weight:700; margin:0; color:#ffc107;"><?php echo $summaryData['late'] ?? 0; ?></p>
            </div>
            <div style="background: white; border-radius: 10px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05);">
                <h3 style="margin:0 0 10px 0; color:#002855;">Total</h3>
                <p style="font-size:28px; font-weight:700; margin:0; color:#002855;"><?php echo $summaryData['total'] ?? 0; ?></p>
            </div>
            <?php endif; ?>
            
            <?php if ($reportType == 'financial' && isset($summaryData['total'])): ?>
            <div style="background: white; border-radius: 10px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05);">
                <h3 style="margin:0 0 10px 0; color:#002855;">Total Collected</h3>
                <p style="font-size:28px; font-weight:700; margin:0; color:#28a745;">₦<?php echo number_format($summaryData['total'], 2); ?></p>
            </div>
            <?php endif; ?>
            
            <?php if ($reportType == 'class_list'): ?>
            <div style="background: white; border-radius: 10px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05);">
                <h3 style="margin:0 0 10px 0; color:#002855;">Male</h3>
                <p style="font-size:28px; font-weight:700; margin:0; color:#002855;"><?php echo $summaryData['male'] ?? 0; ?></p>
            </div>
            <div style="background: white; border-radius: 10px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05);">
                <h3 style="margin:0 0 10px 0; color:#002855;">Female</h3>
                <p style="font-size:28px; font-weight:700; margin:0; color:#c41e3a;"><?php echo $summaryData['female'] ?? 0; ?></p>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <!-- Report Results -->
        <div class="card">
            <div class="card-header">
                <h3><?php echo htmlspecialchars($reportTitle ?: 'Select filters to generate report'); ?></h3>
                <?php if (!empty($reportRows)): ?>
                <button class="btn btn-sm btn-success" onclick="exportToExcel()">
                    <i class="fas fa-file-excel"></i> Export to Excel
                </button>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (!empty($reportRows)): ?>
                <div class="table-responsive">
                    <table class="data-table" id="reportTable">
                        <thead>
                            <tr>
                                <?php foreach ($reportHeaders as $header): ?>
                                <th><?php echo $header; ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportRows as $index => $row): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <?php if ($reportType == 'academic'): ?>
                                <td><?php echo htmlspecialchars($row['admission_number']); ?></td>
                                <td><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['subject_name']); ?></td>
                                <td><?php echo $row['score']; ?></td>
                                <td><span class="badge" style="background:<?php 
                                    if($row['grade']=='A') echo '#28a745';
                                    elseif($row['grade']=='B') echo '#17a2b8';
                                    elseif($row['grade']=='C') echo '#ffc107';
                                    elseif($row['grade']=='D') echo '#fd7e14';
                                    elseif($row['grade']=='E') echo '#6c757d';
                                    else echo '#dc3545';
                                ?>; color:white;"><?php echo $row['grade']; ?></span></td>
                                <?php elseif ($reportType == 'attendance'): ?>
                                <td><?php echo htmlspecialchars($row['admission_number']); ?></td>
                                <td><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></td>
                                <td><?php echo date('d M Y', strtotime($row['date'])); ?></td>
                                <td>
                                    <span class="badge" style="background:<?php 
                                        if($row['status']=='present') echo '#28a745';
                                        elseif($row['status']=='absent') echo '#dc3545';
                                        elseif($row['status']=='late') echo '#ffc107';
                                        else echo '#17a2b8';
                                    ?>; color:white;"><?php echo ucfirst($row['status']); ?></span>
                                </td>
                                <?php elseif ($reportType == 'financial'): ?>
                                <td><?php echo htmlspecialchars($row['receipt_number']); ?></td>
                                <td><?php echo date('d M Y', strtotime($row['payment_date'])); ?></td>
                                <td><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name'] . ' (' . $row['admission_number'] . ')'); ?></td>
                                <td>₦<?php echo number_format($row['amount'], 2); ?></td>
                                <td><?php echo ucfirst(str_replace('_', ' ', $row['payment_method'])); ?></td>
                                <td>
                                    <span class="badge" style="background:<?php 
                                        if($row['status']=='completed') echo '#28a745';
                                        elseif($row['status']=='pending') echo '#ffc107';
                                        else echo '#dc3545';
                                    ?>; color:white;"><?php echo ucfirst($row['status']); ?></span>
                                </td>
                                <?php elseif ($reportType == 'class_list'): ?>
                                <td><?php echo htmlspecialchars($row['admission_number']); ?></td>
                                <td><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></td>
                                <td><?php echo ucfirst($row['gender'] ?? 'N/A'); ?></td>
                                <td><?php echo !empty($row['date_of_birth']) ? date('d M Y', strtotime($row['date_of_birth'])) : 'N/A'; ?></td>
                                <td><?php echo htmlspecialchars($row['parent_name'] ?? 'N/A'); ?></td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    No data found. Please select filters and click Generate.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<!-- Simple Excel Export -->
<script src="https://cdn.sheetjs.com/xlsx-0.19.2/package/dist/xlsx.full.min.js"></script>
<script>
function exportToExcel() {
    const table = document.getElementById('reportTable');
    if (!table) return;
    
    const wb = XLSX.utils.table_to_book(table, { sheet: "Report" });
    XLSX.writeFile(wb, 'report_<?php echo date('Ymd_His'); ?>.xlsx');
}
</script>

