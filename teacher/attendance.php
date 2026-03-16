<?php
// teacher/attendance.php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/security.php';

Security::requireRole('teacher');

$pageTitle = 'Mark Attendance';
$extraJS = ['attendance.js'];

include '../includes/header.php';

$db = db();
$userId = $_SESSION['user_id'];
$message = '';
$messageType = '';

// Get teacher info
$teacher = $db->getRow(
    "SELECT id FROM teachers WHERE user_id = ?",
    [$userId]
);

// Get teacher's classes
$classes = $db->getRows(
    "SELECT c.* FROM classes c 
     WHERE c.teacher_id = ? AND c.is_active = 1",
    [$teacher['id']]
);

$selectedClass = $_GET['class'] ?? null;
$selectedDate = $_GET['date'] ?? date('Y-m-d');
$students = [];

if ($selectedClass) {
    // Check if attendance already marked for this date
    $attendanceMarked = $db->getRow(
        "SELECT COUNT(*) as count FROM attendance 
         WHERE class_id = ? AND date = ?",
        [$selectedClass, $selectedDate]
    )['count'] > 0;
    
    if (!$attendanceMarked) {
        // Get students for this class
        $students = $db->getRows(
            "SELECT s.id, s.admission_number, u.first_name, u.last_name 
             FROM students s 
             JOIN users u ON s.user_id = u.id 
             WHERE s.class_id = ? AND u.is_active = 1 
             ORDER BY u.first_name",
            [$selectedClass]
        );
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid security token';
        $messageType = 'error';
    } else {
        $classId = Security::sanitize($_POST['class_id']);
        $date = Security::sanitize($_POST['date']);
        $attendance = $_POST['attendance'] ?? [];
        
        try {
            $db->beginTransaction();
            
            foreach ($attendance as $studentId => $status) {
                $db->insert(
                    "INSERT INTO attendance (student_id, class_id, date, status, marked_by) 
                     VALUES (?, ?, ?, ?, ?)",
                    [$studentId, $classId, $date, $status, $userId]
                );
            }
            
            $db->commit();
            
            Security::logAudit('MARKED_ATTENDANCE', 'attendance', null, null, 
                              ['class' => $classId, 'date' => $date, 'count' => count($attendance)]);
            
            $message = 'Attendance marked successfully!';
            $messageType = 'success';
            
        } catch (Exception $e) {
            $db->rollback();
            $message = 'Error marking attendance: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}
?>

<div class="dashboard-container">
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <h3>Teacher Panel</h3>
        </div>
        <nav class="sidebar-nav">
            <ul>
                <li><a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a></li>
                <li><a href="classes.php"><i class="fas fa-school"></i> My Classes</a></li>
                <li class="active"><a href="attendance.php"><i class="fas fa-calendar-check"></i> Attendance</a></li>
                <li><a href="results.php"><i class="fas fa-chart-line"></i> Results</a></li>
                <li><a href="assignments.php"><i class="fas fa-tasks"></i> Assignments</a></li>
                <li><a href="students.php"><i class="fas fa-user-graduate"></i> Students</a></li>
                <li><a href="messages.php"><i class="fas fa-envelope"></i> Messages</a></li>
                <li><a href="profile.php"><i class="fas fa-user-cog"></i> Profile</a></li>
            </ul>
        </nav>
    </aside>
    
    <main class="dashboard-main">
        <div class="dashboard-header">
            <h1>Mark Attendance</h1>
        </div>
        
        <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?>">
            <?php echo $message; ?>
        </div>
        <?php endif; ?>
        
        <!-- Class Selection -->
        <div class="card">
            <div class="card-body">
                <form method="GET" class="form-inline">
                    <div class="form-group">
                        <label for="class">Select Class:</label>
                        <select name="class" id="class" required>
                            <option value="">Choose a class</option>
                            <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>" 
                                <?php echo ($selectedClass == $class['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['class_name'] . ' ' . $class['section']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="date">Date:</label>
                        <input type="date" name="date" id="date" 
                               value="<?php echo $selectedDate; ?>" 
                               max="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Load Students</button>
                </form>
            </div>
        </div>
        
        <?php if ($selectedClass && isset($attendanceMarked) && $attendanceMarked): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle"></i>
            Attendance has already been marked for this class on <?php echo formatDate($selectedDate); ?>.
            <a href="attendance-report.php?class=<?php echo $selectedClass; ?>&date=<?php echo $selectedDate; ?>" 
               class="btn btn-small btn-primary">View Report</a>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($students)): ?>
        <!-- Attendance Form -->
        <div class="card">
            <div class="card-header">
                <h3>Mark Attendance for <?php echo formatDate($selectedDate); ?></h3>
            </div>
            <div class="card-body">
                <form method="POST" id="attendanceForm">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                    <input type="hidden" name="class_id" value="<?php echo $selectedClass; ?>">
                    <input type="hidden" name="date" value="<?php echo $selectedDate; ?>">
                    
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>S/N</th>
                                <th>Admission No.</th>
                                <th>Student Name</th>
                                <th>Status</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $index => $student): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td><?php echo htmlspecialchars($student['admission_number']); ?></td>
                                <td><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></td>
                                <td>
                                    <select name="attendance[<?php echo $student['id']; ?>]" class="attendance-status" required>
                                        <option value="">Select</option>
                                        <option value="present">Present</option>
                                        <option value="absent">Absent</option>
                                        <option value="late">Late</option>
                                        <option value="excused">Excused</option>
                                    </select>
                                </td>
                                <td>
                                    <input type="text" name="remarks[<?php echo $student['id']; ?>]" 
                                           placeholder="Optional remarks" class="remarks-input">
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline" id="markAllPresent">
                            <i class="fas fa-check-circle"></i> Mark All Present
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Attendance
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Recent Attendance Records -->
        <div class="card">
            <div class="card-header">
                <h3>Recent Attendance Records</h3>
            </div>
            <div class="card-body">
                <?php
                $recentAttendance = $db->getRows(
                    "SELECT a.date, c.class_name, 
                            COUNT(*) as total,
                            SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count
                     FROM attendance a
                     JOIN classes c ON a.class_id = c.id
                     WHERE c.teacher_id = ?
                     GROUP BY a.date, c.class_name
                     ORDER BY a.date DESC LIMIT 10",
                    [$teacher['id']]
                );
                ?>
                
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Class</th>
                            <th>Present</th>
                            <th>Total</th>
                            <th>Percentage</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentAttendance as $record): ?>
                        <tr>
                            <td><?php echo formatDate($record['date']); ?></td>
                            <td><?php echo htmlspecialchars($record['class_name']); ?></td>
                            <td><?php echo $record['present_count']; ?></td>
                            <td><?php echo $record['total']; ?></td>
                            <td>
                                <?php 
                                $percentage = ($record['present_count'] / $record['total']) * 100;
                                echo number_format($percentage, 1) . '%';
                                ?>
                            </td>
                            <td>
                                <a href="attendance-report.php?date=<?php echo $record['date']; ?>" 
                                   class="btn-icon"><i class="fas fa-chart-bar"></i></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<script>
document.getElementById('markAllPresent')?.addEventListener('click', function() {
    const selects = document.querySelectorAll('.attendance-status');
    selects.forEach(select => {
        select.value = 'present';
    });
});
</script>

<?php
include '../includes/footer.php';
?>