<?php
// admin/mark-attendance.php - Mark Student Attendance
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

$pageTitle = 'Mark Attendance';
$extraCSS = ['attendance.css'];
$extraJS = ['attendance.js'];

// Check if header exists
$headerPath = __DIR__ . '/../includes/header.php';
if (!file_exists($headerPath)) {
    die("Error: Header file not found at: $headerPath");
}
include $headerPath;

// Get database instance
$db = Database::getInstance();

$message = '';
$messageType = '';

// Get parameters
$selectedClass = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$selectedDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// Validate date
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = date('Y-m-d');
}

// Don't allow future dates
if ($selectedDate > date('Y-m-d')) {
    $selectedDate = date('Y-m-d');
    $message = 'Cannot mark attendance for future dates';
    $messageType = 'warning';
}

// Get all active classes
$classes = $db->getRows(
    "SELECT c.*, 
            CONCAT(u.first_name, ' ', u.last_name) as teacher_name
     FROM classes c
     LEFT JOIN teachers t ON c.teacher_id = t.id
     LEFT JOIN users u ON t.user_id = u.id
     WHERE c.is_active = 1
     ORDER BY c.class_name, c.section"
);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid security token';
        $messageType = 'error';
    } else {
        $classId = (int)($_POST['class_id'] ?? 0);
        $attendanceDate = $_POST['attendance_date'] ?? date('Y-m-d');
        $attendance = $_POST['attendance'] ?? [];
        $remarks = $_POST['remarks'] ?? [];
        
        if (!$classId) {
            $message = 'Please select a class';
            $messageType = 'error';
        } elseif (empty($attendance)) {
            $message = 'No attendance data submitted';
            $messageType = 'error';
        } else {
            try {
                $db->beginTransaction();
                
                // Delete existing attendance for this class and date (if any)
                $db->query(
                    "DELETE FROM attendance WHERE class_id = ? AND date = ?",
                    [$classId, $attendanceDate]
                );
                
                // Insert new attendance records
                $insertCount = 0;
                foreach ($attendance as $studentId => $status) {
                    if (!empty($status)) {
                        $remark = isset($remarks[$studentId]) ? $remarks[$studentId] : '';
                        
                        $db->insert(
                            "INSERT INTO attendance (student_id, class_id, date, status, remarks, marked_by) 
                             VALUES (?, ?, ?, ?, ?, ?)",
                            [
                                (int)$studentId,
                                $classId,
                                $attendanceDate,
                                $status,
                                $remark,
                                $_SESSION['user_id']
                            ]
                        );
                        $insertCount++;
                    }
                }
                
                $db->commit();
                
                Security::logAudit('MARKED_ATTENDANCE', 'attendance', null, 
                                  ['class' => $classId, 'date' => $attendanceDate, 'count' => $insertCount]);
                
                $message = "Attendance marked successfully for $insertCount students";
                $messageType = 'success';
                
            } catch (Exception $e) {
                $db->rollback();
                $message = 'Error: ' . $e->getMessage();
                $messageType = 'error';
                error_log("Attendance marking error: " . $e->getMessage());
            }
        }
    }
}

// Get students for selected class
$students = [];
$existingAttendance = [];
$classInfo = null;

if ($selectedClass) {
    // Get class info
    $classInfo = $db->getRow(
        "SELECT c.*, 
                CONCAT(u.first_name, ' ', u.last_name) as teacher_name
         FROM classes c
         LEFT JOIN teachers t ON c.teacher_id = t.id
         LEFT JOIN users u ON t.user_id = u.id
         WHERE c.id = ?",
        [$selectedClass]
    );
    
    // Get students in this class
    $students = $db->getRows(
        "SELECT s.id, s.admission_number, 
                u.first_name, u.last_name, u.profile_image
         FROM students s
         JOIN users u ON s.user_id = u.id
         WHERE s.class_id = ? AND u.is_active = 1
         ORDER BY u.first_name, u.last_name",
        [$selectedClass]
    );
    
    // Check if attendance already marked for this date
    $existingAttendance = $db->getRows(
        "SELECT student_id, status, remarks 
         FROM attendance 
         WHERE class_id = ? AND date = ?",
        [$selectedClass, $selectedDate]
    );
    
    // Convert to associative array for easy lookup
    $attendanceMap = [];
    foreach ($existingAttendance as $a) {
        $attendanceMap[$a['student_id']] = [
            'status' => $a['status'],
            'remarks' => $a['remarks']
        ];
    }
}

// Get today's stats
$todayStats = $db->getRow(
    "SELECT 
        COUNT(DISTINCT student_id) as total_marked,
        SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
        SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent,
        SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late,
        SUM(CASE WHEN status = 'excused' THEN 1 ELSE 0 END) as excused
     FROM attendance 
     WHERE date = ?",
    [$selectedDate]
);
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
                <li><a href="teachers.php"><i class="fas fa-chalkboard-teacher"></i> Teachers</a></li>
                <li><a href="classes.php"><i class="fas fa-school"></i> Classes</a></li>
                <li><a href="subjects.php"><i class="fas fa-book"></i> Subjects</a></li>
                <li class="active"><a href="attendance.php"><i class="fas fa-calendar-check"></i> Attendance</a></li>
                <li><a href="fees.php"><i class="fas fa-money-bill"></i> Fees</a></li>
                <li><a href="results.php"><i class="fas fa-chart-line"></i> Results</a></li>
                <li><a href="announcements.php"><i class="fas fa-bullhorn"></i> Announcements</a></li>
                <li><a href="gallery.php"><i class="fas fa-images"></i> Gallery</a></li>
                <li><a href="reports.php"><i class="fas fa-file-alt"></i> Reports</a></li>
                <li><a href="audit-logs.php"><i class="fas fa-history"></i> Audit Logs</a></li>
            </ul>
        </nav>
    </aside>
    
    <main class="dashboard-main">
        <div class="dashboard-header">
            <h1>Mark Attendance</h1>
            <div class="header-actions">
                <a href="attendance.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i> Back to Attendance
                </a>
            </div>
        </div>
        
        <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible">
            <i class="fas <?php echo $messageType === 'success' ? 'fa-check-circle' : ($messageType === 'warning' ? 'fa-exclamation-triangle' : 'fa-exclamation-circle'); ?>"></i>
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="close" onclick="this.parentElement.remove()">&times;</button>
        </div>
        <?php endif; ?>
        
        <!-- Class Selection Form -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-school"></i> Select Class and Date</h3>
            </div>
            <div class="card-body">
                <form method="GET" class="form-row">
                    <div class="form-group col-md-5">
                        <label for="class_id">Class *</label>
                        <select id="class_id" name="class_id" class="form-control" required>
                            <option value="">-- Select Class --</option>
                            <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>" <?php echo $selectedClass == $class['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['class_name'] . ' ' . $class['section']); ?>
                                <?php if ($class['teacher_name']): ?>- <?php echo htmlspecialchars($class['teacher_name']); ?><?php endif; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group col-md-4">
                        <label for="date">Date *</label>
                        <input type="date" id="date" name="date" class="form-control" 
                               value="<?php echo htmlspecialchars($selectedDate); ?>" 
                               max="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    
                    <div class="form-group col-md-3">
                        <label>&nbsp;</label>
                        <button type="submit" class="btn btn-primary form-control">
                            <i class="fas fa-search"></i> Load Students
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <?php if ($selectedClass && !empty($students)): ?>
        
        <!-- Class Info -->
        <div class="class-info-card">
            <div class="class-info-header">
                <h2>
                    <i class="fas fa-users"></i> 
                    <?php echo htmlspecialchars($classInfo['class_name'] . ' ' . $classInfo['section']); ?>
                </h2>
                <div class="class-meta">
                    <span><i class="fas fa-calendar"></i> <?php echo date('l, d F Y', strtotime($selectedDate)); ?></span>
                    <span><i class="fas fa-user-tie"></i> Teacher: <?php echo htmlspecialchars($classInfo['teacher_name'] ?? 'Not Assigned'); ?></span>
                    <span><i class="fas fa-users"></i> Total Students: <?php echo count($students); ?></span>
                </div>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="quick-actions-bar">
            <button type="button" class="btn btn-sm btn-success" onclick="setAllStatus('present')">
                <i class="fas fa-check-circle"></i> All Present
            </button>
            <button type="button" class="btn btn-sm btn-danger" onclick="setAllStatus('absent')">
                <i class="fas fa-times-circle"></i> All Absent
            </button>
            <button type="button" class="btn btn-sm btn-warning" onclick="setAllStatus('late')">
                <i class="fas fa-clock"></i> All Late
            </button>
            <button type="button" class="btn btn-sm btn-info" onclick="setAllStatus('excused')">
                <i class="fas fa-check"></i> All Excused
            </button>
            <button type="button" class="btn btn-sm btn-outline" onclick="clearAllStatus()">
                <i class="fas fa-undo"></i> Clear All
            </button>
        </div>
        
        <!-- Attendance Form -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-check-square"></i> Mark Attendance</h3>
                <?php if (!empty($existingAttendance)): ?>
                <span class="badge badge-warning">
                    <i class="fas fa-info-circle"></i> Editing existing attendance for this date
                </span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <form method="POST" id="attendanceForm">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                    <input type="hidden" name="class_id" value="<?php echo $selectedClass; ?>">
                    <input type="hidden" name="attendance_date" value="<?php echo $selectedDate; ?>">
                    
                    <div class="table-responsive">
                        <table class="data-table" id="attendanceTable">
                            <thead>
                                <tr>
                                    <th width="50">S/N</th>
                                    <th width="80">Photo</th>
                                    <th>Admission No.</th>
                                    <th>Student Name</th>
                                    <th width="150">Status</th>
                                    <th>Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($students as $index => $student): 
                                    $existing = $attendanceMap[$student['id']] ?? null;
                                    $selectedStatus = $existing ? $existing['status'] : '';
                                    $remarkText = $existing ? $existing['remarks'] : '';
                                ?>
                                <tr>
                                    <td><?php echo $index + 1; ?></td>
                                    <td>
                                        <?php if (!empty($student['profile_image'])): ?>
                                        <img src="<?php echo BASE_URL; ?>/uploads/students/<?php echo $student['profile_image']; ?>" 
                                             alt="Profile" class="student-thumbnail">
                                        <?php else: ?>
                                        <div class="avatar-placeholder">
                                            <i class="fas fa-user-graduate"></i>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong><?php echo htmlspecialchars($student['admission_number']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></td>
                                    <td>
                                        <select name="attendance[<?php echo $student['id']; ?>]" class="form-control status-select" required>
                                            <option value="">-- Select --</option>
                                            <option value="present" <?php echo $selectedStatus === 'present' ? 'selected' : ''; ?> data-color="success">Present</option>
                                            <option value="absent" <?php echo $selectedStatus === 'absent' ? 'selected' : ''; ?> data-color="danger">Absent</option>
                                            <option value="late" <?php echo $selectedStatus === 'late' ? 'selected' : ''; ?> data-color="warning">Late</option>
                                            <option value="excused" <?php echo $selectedStatus === 'excused' ? 'selected' : ''; ?> data-color="info">Excused</option>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="text" name="remarks[<?php echo $student['id']; ?>]" 
                                               class="form-control" value="<?php echo htmlspecialchars($remarkText); ?>" 
                                               placeholder="Optional remarks">
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="fas fa-save"></i> Save Attendance
                        </button>
                        <a href="attendance.php?date=<?php echo urlencode($selectedDate); ?>" class="btn btn-outline btn-lg">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
        
        <?php elseif ($selectedClass): ?>
        <!-- No Students Found -->
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle fa-2x mb-3"></i>
            <h4>No Students Found</h4>
            <p>There are no active students in this class. Please <a href="students.php?action=add">add students</a> first.</p>
        </div>
        <?php endif; ?>
        
        <!-- Today's Summary (if any attendance marked) -->
        <?php if ($todayStats && $todayStats['total_marked'] > 0): ?>
        <div class="summary-card">
            <h3><i class="fas fa-chart-pie"></i> Today's Attendance Summary (<?php echo date('d M Y', strtotime($selectedDate)); ?>)</h3>
            <div class="summary-stats">
                <div class="summary-item">
                    <span class="label">Present:</span>
                    <span class="value present"><?php echo $todayStats['present']; ?></span>
                </div>
                <div class="summary-item">
                    <span class="label">Absent:</span>
                    <span class="value absent"><?php echo $todayStats['absent']; ?></span>
                </div>
                <div class="summary-item">
                    <span class="label">Late:</span>
                    <span class="value late"><?php echo $todayStats['late']; ?></span>
                </div>
                <div class="summary-item">
                    <span class="label">Excused:</span>
                    <span class="value excused"><?php echo $todayStats['excused']; ?></span>
                </div>
                <div class="summary-item">
                    <span class="label">Total:</span>
                    <span class="value total"><?php echo $todayStats['total_marked']; ?></span>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </main>
</div>

<style>
.student-thumbnail {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    object-fit: cover;
}

.avatar-placeholder {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background-color: #f0f0f0;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #999;
}

.class-info-card {
    background: linear-gradient(135deg, var(--navy), var(--navy-dark));
    color: white;
    border-radius: 10px;
    padding: 25px;
    margin-bottom: 20px;
    box-shadow: 0 5px 20px rgba(0,0,0,0.2);
}

.class-info-header h2 {
    margin: 0 0 15px 0;
    color: white;
    display: flex;
    align-items: center;
    gap: 10px;
}

.class-info-header h2 i {
    color: var(--gold);
}

.class-meta {
    display: flex;
    gap: 30px;
    flex-wrap: wrap;
}

.class-meta span {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    opacity: 0.9;
}

.class-meta i {
    color: var(--gold);
}

.quick-actions-bar {
    background: white;
    border-radius: 10px;
    padding: 15px;
    margin-bottom: 20px;
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}

.btn-sm {
    padding: 8px 15px;
    font-size: 13px;
    border-radius: 5px;
    cursor: pointer;
    border: none;
    transition: all 0.3s ease;
}

.btn-success { background: #28a745; color: white; }
.btn-success:hover { background: #218838; transform: translateY(-2px); }

.btn-danger { background: #dc3545; color: white; }
.btn-danger:hover { background: #c82333; transform: translateY(-2px); }

.btn-warning { background: #ffc107; color: #333; }
.btn-warning:hover { background: #e0a800; transform: translateY(-2px); }

.btn-info { background: #17a2b8; color: white; }
.btn-info:hover { background: #138496; transform: translateY(-2px); }

.btn-outline {
    background: transparent;
    border: 1px solid var(--navy);
    color: var(--navy);
}

.btn-outline:hover {
    background: var(--navy);
    color: white;
}

.form-actions {
    display: flex;
    gap: 15px;
    justify-content: flex-end;
    margin-top: 30px;
}

.btn-lg {
    padding: 12px 30px;
    font-size: 16px;
}

.status-select {
    width: 100%;
    padding: 8px;
    border: 2px solid #ddd;
    border-radius: 5px;
    font-size: 14px;
}

.status-select option[value="present"] { background-color: #d4edda; }
.status-select option[value="absent"] { background-color: #f8d7da; }
.status-select option[value="late"] { background-color: #fff3cd; }
.status-select option[value="excused"] { background-color: #d1ecf1; }

.summary-card {
    background: white;
    border-radius: 10px;
    padding: 20px;
    margin-top: 30px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}

.summary-card h3 {
    margin-top: 0;
    margin-bottom: 20px;
    color: var(--navy);
    display: flex;
    align-items: center;
    gap: 10px;
}

.summary-card h3 i {
    color: var(--gold);
}

.summary-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 15px;
}

.summary-item {
    text-align: center;
    padding: 15px;
    background: var(--light-gray);
    border-radius: 8px;
}

.summary-item .label {
    display: block;
    font-size: 14px;
    color: var(--gray);
    margin-bottom: 5px;
}

.summary-item .value {
    display: block;
    font-size: 28px;
    font-weight: 700;
    line-height: 1;
}

.summary-item .value.present { color: #28a745; }
.summary-item .value.absent { color: #dc3545; }
.summary-item .value.late { color: #ffc107; }
.summary-item .value.excused { color: #17a2b8; }
.summary-item .value.total { color: var(--navy); }

.badge-warning {
    background: #fff3cd;
    color: #856404;
    padding: 5px 10px;
    border-radius: 20px;
    font-size: 12px;
}

@media (max-width: 768px) {
    .class-meta {
        flex-direction: column;
        gap: 10px;
    }
    
    .quick-actions-bar {
        justify-content: center;
    }
    
    .form-actions {
        flex-direction: column;
    }
    
    .form-actions .btn {
        width: 100%;
    }
    
    .summary-stats {
        grid-template-columns: repeat(2, 1fr);
    }
}
</style>

<script>
// Set all status selects to a specific value
function setAllStatus(status) {
    const selects = document.querySelectorAll('.status-select');
    selects.forEach(select => {
        select.value = status;
        highlightSelect(select);
    });
}

// Clear all status selects
function clearAllStatus() {
    const selects = document.querySelectorAll('.status-select');
    selects.forEach(select => {
        select.value = '';
        select.style.backgroundColor = '';
    });
}

// Highlight select based on value
function highlightSelect(select) {
    const value = select.value;
    switch(value) {
        case 'present':
            select.style.backgroundColor = '#d4edda';
            break;
        case 'absent':
            select.style.backgroundColor = '#f8d7da';
            break;
        case 'late':
            select.style.backgroundColor = '#fff3cd';
            break;
        case 'excused':
            select.style.backgroundColor = '#d1ecf1';
            break;
        default:
            select.style.backgroundColor = '';
    }
}

// Add change event listeners to all status selects
document.addEventListener('DOMContentLoaded', function() {
    const selects = document.querySelectorAll('.status-select');
    selects.forEach(select => {
        // Initial highlight
        highlightSelect(select);
        
        // Add change event
        select.addEventListener('change', function() {
            highlightSelect(this);
        });
    });
});

// Confirm before leaving with unsaved changes
let formChanged = false;

document.getElementById('attendanceForm')?.addEventListener('change', function() {
    formChanged = true;
});

window.addEventListener('beforeunload', function(e) {
    if (formChanged) {
        e.preventDefault();
        e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
    }
});

// Auto-save functionality (optional)
let autoSaveTimer;
document.getElementById('attendanceForm')?.addEventListener('input', function() {
    clearTimeout(autoSaveTimer);
    autoSaveTimer = setTimeout(function() {
        // Could implement auto-save via AJAX here
        console.log('Auto-save triggered');
    }, 5000);
});
</script>

