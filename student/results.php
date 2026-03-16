<?php
// student/results.php - Student Results View
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';

// Note: FPDF library needs to be installed separately
// Download from: http://www.fpdf.org/
// Extract to: includes/fpdf/fpdf.php
if (file_exists(__DIR__ . '/../includes/fpdf/fpdf.php')) {
    require_once __DIR__ . '/../includes/fpdf/fpdf.php';
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Require student role
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'student') {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$pageTitle = 'My Results';
$extraJS = ['results.js', 'chart.js'];

// Check if header exists
$headerPath = __DIR__ . '/../includes/header.php';
if (!file_exists($headerPath)) {
    die("Error: Header file not found at: $headerPath");
}
include $headerPath;

// Get database instance
try {
    $db = Database::getInstance();
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Database connection error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    include __DIR__ . '/../includes/footer.php';
    exit;
}

$userId = $_SESSION['user_id'];

// Get student info
$student = $db->getRow(
    "SELECT s.*, u.first_name, u.last_name, u.email, c.class_name, c.section
     FROM students s 
     JOIN users u ON s.user_id = u.id 
     LEFT JOIN classes c ON s.class_id = c.id 
     WHERE s.user_id = ?",
    [$userId]
);

if (!$student) {
    echo '<div class="alert alert-danger">Student record not found.</div>';
    include __DIR__ . '/../includes/footer.php';
    exit;
}

// Get available terms and academic years
$terms = $db->getRows(
    "SELECT DISTINCT term, academic_year 
     FROM results 
     WHERE student_id = ? AND is_approved = 1
     ORDER BY academic_year DESC, term DESC",
    [$student['id']]
);

$selectedTerm = isset($_GET['term']) ? $_GET['term'] : ($terms[0]['term'] ?? '');
$selectedYear = isset($_GET['year']) ? $_GET['year'] : ($terms[0]['academic_year'] ?? '');

// Get results for selected term
$results = [];
$termSummary = [];

if ($selectedTerm && $selectedYear) {
    $results = $db->getRows(
        "SELECT r.*, s.subject_name, s.subject_code
         FROM results r
         JOIN subjects s ON r.subject_id = s.id
         WHERE r.student_id = ? AND r.term = ? AND r.academic_year = ? AND r.is_approved = 1
         ORDER BY s.subject_name",
        [$student['id'], $selectedTerm, $selectedYear]
    );
    
    // Calculate summary
    if (!empty($results)) {
        $totalScore = 0;
        $totalMaxScore = 0;
        $subjectCount = count($results);
        
        foreach ($results as $result) {
            $totalScore += $result['score'];
            $totalMaxScore += $result['max_score'];
        }
        
        $average = $subjectCount > 0 ? round($totalScore / $subjectCount, 2) : 0;
        $percentage = $totalMaxScore > 0 ? round(($totalScore / $totalMaxScore) * 100, 2) : 0;
        
        // Determine grade
        if ($percentage >= 70) $grade = 'A';
        elseif ($percentage >= 60) $grade = 'B';
        elseif ($percentage >= 50) $grade = 'C';
        elseif ($percentage >= 45) $grade = 'D';
        elseif ($percentage >= 40) $grade = 'E';
        else $grade = 'F';
        
        $termSummary = [
            'total_score' => $totalScore,
            'total_max' => $totalMaxScore,
            'average' => $average,
            'percentage' => $percentage,
            'grade' => $grade,
            'subject_count' => $subjectCount
        ];
    }
}

// Handle PDF download
if (isset($_GET['download']) && $_GET['download'] === 'report' && !empty($results)) {
    if (function_exists('generateReportCard')) {
        generateReportCard($student, $results, $termSummary, $selectedTerm, $selectedYear);
    } else {
        // Simple CSV export as fallback if FPDF not available
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="report_' . $student['admission_number'] . '_' . $selectedTerm . '.csv"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Subject', 'Score', 'Max Score', 'Percentage', 'Grade', 'Remarks']);
        
        foreach ($results as $result) {
            $percentage = round(($result['score'] / $result['max_score']) * 100, 2);
            fputcsv($output, [
                $result['subject_name'],
                $result['score'],
                $result['max_score'],
                $percentage . '%',
                $result['grade'],
                $result['remarks'] ?? '-'
            ]);
        }
        
        fputcsv($output, []);
        fputcsv($output, ['SUMMARY']);
        fputcsv($output, ['Total Score', $termSummary['total_score'] . ' / ' . $termSummary['total_max']]);
        fputcsv($output, ['Average', $termSummary['average']]);
        fputcsv($output, ['Percentage', $termSummary['percentage'] . '%']);
        fputcsv($output, ['Overall Grade', $termSummary['grade']]);
        
        fclose($output);
        exit;
    }
}

function generateReportCard($student, $results, $summary, $term, $year) {
    // Check if FPDF class exists
    if (!class_exists('FPDF')) {
        header('Content-Type: text/html');
        echo '<div class="alert alert-error">FPDF library not found. Please install it to use PDF export.</div>';
        return;
    }
    
    try {
        // Create PDF
        $pdf = new FPDF();
        $pdf->AddPage();
        
        // Add logo if exists
        $logoPath = __DIR__ . '/../assets/images/logo.png';
        if (file_exists($logoPath)) {
            $pdf->Image($logoPath, 10, 10, 30);
        }
        
        // School Header
        $pdf->SetY(20);
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 10, defined('SCHOOL_NAME') ? SCHOOL_NAME : 'ST. BENEDICT\'S ACADEMY', 0, 1, 'C');
        $pdf->SetFont('Arial', 'I', 10);
        $pdf->Cell(0, 5, defined('SCHOOL_ADDRESS') ? SCHOOL_ADDRESS : 'Enugu, Nigeria', 0, 1, 'C');
        $pdf->Cell(0, 5, 'Phone: ' . (defined('SCHOOL_PHONE') ? SCHOOL_PHONE : '09044472688'), 0, 1, 'C');
        $pdf->Ln(10);
        
        // Report Title
        $pdf->SetFont('Arial', 'B', 14);
        $pdf->Cell(0, 10, 'STUDENT REPORT CARD', 0, 1, 'C');
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 10, $term . ' - ' . $year, 0, 1, 'C');
        $pdf->Ln(5);
        
        // Student Information
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(50, 8, 'Student Name:', 0, 0);
        $pdf->SetFont('Arial', '', 11);
        $pdf->Cell(0, 8, $student['first_name'] . ' ' . $student['last_name'], 0, 1);
        
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(50, 8, 'Admission No:', 0, 0);
        $pdf->SetFont('Arial', '', 11);
        $pdf->Cell(0, 8, $student['admission_number'], 0, 1);
        
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(50, 8, 'Class:', 0, 0);
        $pdf->SetFont('Arial', '', 11);
        $pdf->Cell(0, 8, ($student['class_name'] ?? '') . ' ' . ($student['section'] ?? ''), 0, 1);
        $pdf->Ln(10);
        
        // Results Table
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->SetFillColor(0, 40, 85); // Navy color
        $pdf->SetTextColor(255, 255, 255); // White text
        $pdf->Cell(70, 10, 'Subject', 1, 0, 'C', true);
        $pdf->Cell(25, 10, 'Score', 1, 0, 'C', true);
        $pdf->Cell(25, 10, 'Max', 1, 0, 'C', true);
        $pdf->Cell(25, 10, '%', 1, 0, 'C', true);
        $pdf->Cell(25, 10, 'Grade', 1, 0, 'C', true);
        $pdf->Cell(30, 10, 'Remarks', 1, 1, 'C', true);
        
        $pdf->SetTextColor(0, 0, 0); // Black text
        $pdf->SetFont('Arial', '', 10);
        
        $fill = false;
        foreach ($results as $result) {
            $percentage = round(($result['score'] / $result['max_score']) * 100, 1);
            
            // Set background color based on performance
            if ($percentage >= 70) {
                $pdf->SetFillColor(212, 237, 218); // Light green
            } elseif ($percentage >= 50) {
                $pdf->SetFillColor(255, 243, 205); // Light yellow
            } else {
                $pdf->SetFillColor(248, 215, 218); // Light red
            }
            
            $pdf->Cell(70, 8, $result['subject_name'], 1, 0, 'L', true);
            $pdf->Cell(25, 8, $result['score'], 1, 0, 'C', true);
            $pdf->Cell(25, 8, $result['max_score'], 1, 0, 'C', true);
            $pdf->Cell(25, 8, $percentage . '%', 1, 0, 'C', true);
            $pdf->Cell(25, 8, $result['grade'], 1, 0, 'C', true);
            $pdf->Cell(30, 8, $result['remarks'] ?? '-', 1, 1, 'C', true);
            
            $fill = !$fill;
        }
        
        // Summary
        $pdf->Ln(10);
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(50, 8, 'Total Score:', 0, 0);
        $pdf->SetFont('Arial', '', 11);
        $pdf->Cell(50, 8, $summary['total_score'] . ' / ' . $summary['total_max'], 0, 1);
        
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(50, 8, 'Average:', 0, 0);
        $pdf->SetFont('Arial', '', 11);
        $pdf->Cell(50, 8, $summary['average'], 0, 1);
        
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(50, 8, 'Percentage:', 0, 0);
        $pdf->SetFont('Arial', '', 11);
        $pdf->Cell(50, 8, $summary['percentage'] . '%', 0, 1);
        
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(50, 8, 'Overall Grade:', 0, 0);
        $pdf->SetFont('Arial', '', 11);
        $pdf->Cell(50, 8, $summary['grade'], 0, 1);
        
        // Teacher's comment
        $pdf->Ln(10);
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(50, 8, 'Class Teacher\'s Comment:', 0, 1);
        $pdf->SetFont('Arial', '', 11);
        $pdf->Cell(0, 8, '_________________________________________________', 0, 1);
        $pdf->Ln(5);
        $pdf->Cell(0, 8, '_________________________________________________', 0, 1);
        $pdf->Ln(10);
        
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(100, 8, 'Class Teacher', 0, 0);
        $pdf->Cell(0, 8, 'Principal', 0, 1);
        
        // Footer
        $pdf->Ln(10);
        $pdf->SetFont('Arial', 'I', 8);
        $pdf->Cell(0, 5, defined('SCHOOL_MOTTO') ? SCHOOL_MOTTO : 'Christo Duce, Una Sapientia et Virtute Crescimus', 0, 1, 'C');
        
        // Output PDF
        $pdf->Output('D', 'Report_Card_' . $student['admission_number'] . '_' . $term . '.pdf');
        exit;
        
    } catch (Exception $e) {
        error_log("PDF Generation Error: " . $e->getMessage());
        header('Content-Type: text/html');
        echo '<div class="alert alert-error">Error generating PDF: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

// Get performance chart data
$performanceData = $db->getRows(
    "SELECT r.term, r.academic_year, AVG((r.score / r.max_score) * 100) as average
     FROM results r
     WHERE r.student_id = ? AND r.is_approved = 1
     GROUP BY r.academic_year, r.term
     ORDER BY r.academic_year DESC, r.term DESC
     LIMIT 6",
    [$student['id']]
);
?>

<style>
/* Results page specific styles */
.summary-card {
    background: linear-gradient(135deg, #002855 0%, #001a3a 100%);
    color: white;
    border-radius: 10px;
    padding: 25px;
    margin-top: 20px;
    box-shadow: 0 5px 20px rgba(0,0,0,0.2);
}

.summary-card h3 {
    color: white;
    margin-top: 0;
    margin-bottom: 20px;
    font-size: 18px;
}

.summary-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
}

.summary-item {
    text-align: center;
    padding: 15px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 8px;
    backdrop-filter: blur(10px);
}

.summary-item label {
    display: block;
    font-size: 12px;
    opacity: 0.8;
    margin-bottom: 5px;
}

.summary-item .value {
    display: block;
    font-size: 24px;
    font-weight: 700;
    color: #ffd700;
}

.summary-item .value.high { color: #28a745; }
.summary-item .value.medium { color: #ffc107; }
.summary-item .value.low { color: #dc3545; }

.grade-badge {
    display: inline-block;
    width: 50px;
    height: 50px;
    line-height: 50px;
    text-align: center;
    border-radius: 50%;
    font-size: 24px;
    font-weight: 700;
}

.grade-a {
    background-color: #28a745;
    color: white;
}

.grade-b {
    background-color: #17a2b8;
    color: white;
}

.grade-c {
    background-color: #ffc107;
    color: #333;
}

.grade-d {
    background-color: #fd7e14;
    color: white;
}

.grade-e {
    background-color: #6c757d;
    color: white;
}

.grade-f {
    background-color: #dc3545;
    color: white;
}

/* Progress bar */
.progress-bar {
    width: 100%;
    height: 20px;
    background-color: #f0f0f0;
    border-radius: 10px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    color: white;
    font-size: 11px;
    line-height: 20px;
    text-align: center;
    transition: width 0.3s ease;
}

.progress-fill.high { background: linear-gradient(90deg, #28a745, #20c997); }
.progress-fill.medium { background: linear-gradient(90deg, #ffc107, #fd7e14); }
.progress-fill.low { background: linear-gradient(90deg, #dc3545, #c82333); }

.score.high { color: #28a745; font-weight: 600; }
.score.medium { color: #ffc107; font-weight: 600; }
.score.low { color: #dc3545; font-weight: 600; }

.grade.high { color: #28a745; font-weight: 600; }
.grade.medium { color: #ffc107; font-weight: 600; }
.grade.low { color: #dc3545; font-weight: 600; }

/* Form styles */
.form-inline {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    align-items: flex-end;
}

.form-group {
    display: flex;
    flex-direction: column;
    min-width: 150px;
}

.form-group label {
    font-size: 13px;
    color: #002855;
    font-weight: 500;
    margin-bottom: 5px;
}

.form-group select {
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
}

.btn-accent {
    background: #ffd700;
    color: #002855;
    border: none;
    padding: 10px 20px;
    border-radius: 4px;
    cursor: pointer;
    font-weight: 500;
    transition: all 0.3s ease;
}

.btn-accent:hover {
    background: #e6c200;
    transform: translateY(-2px);
}

/* Responsive */
@media (max-width: 768px) {
    .summary-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .form-inline {
        flex-direction: column;
        align-items: stretch;
    }
    
    .form-group {
        width: 100%;
    }
    
    .btn {
        width: 100%;
    }
}

@media (max-width: 480px) {
    .summary-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="dashboard-container">
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <h3>Student Panel</h3>
        </div>
        <nav class="sidebar-nav">
            <ul>
                <li><a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a></li>
                <li class="active"><a href="results.php"><i class="fas fa-chart-line"></i> My Results</a></li>
                <li><a href="attendance.php"><i class="fas fa-calendar-check"></i> Attendance</a></li>
                <li><a href="assignments.php"><i class="fas fa-tasks"></i> Assignments</a></li>
                <li><a href="fees.php"><i class="fas fa-money-bill"></i> Fees</a></li>
                <li><a href="messages.php"><i class="fas fa-envelope"></i> Messages</a></li>
                <li><a href="profile.php"><i class="fas fa-user-cog"></i> Profile</a></li>
            </ul>
        </nav>
    </aside>
    
    <main class="dashboard-main">
        <div class="dashboard-header">
            <h1>My Academic Results</h1>
            <div class="user-info">
                <i class="fas fa-user-graduate"></i>
                <span><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></span>
                <small><?php echo htmlspecialchars(($student['class_name'] ?? '') . ' ' . ($student['section'] ?? '')); ?></small>
            </div>
        </div>
        
        <!-- Term Selection -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-filter"></i> Select Term</h3>
            </div>
            <div class="card-body">
                <?php if (empty($terms)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    No results available yet.
                </div>
                <?php else: ?>
                <form method="GET" class="form-inline">
                    <div class="form-group">
                        <label for="year">Academic Year</label>
                        <select name="year" id="year" class="form-control" onchange="this.form.submit()">
                            <?php 
                            $uniqueYears = array_unique(array_column($terms, 'academic_year'));
                            foreach ($uniqueYears as $year): 
                            ?>
                            <option value="<?php echo htmlspecialchars($year); ?>" 
                                <?php echo ($selectedYear == $year) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($year); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="term">Term</label>
                        <select name="term" id="term" class="form-control" onchange="this.form.submit()">
                            <?php foreach ($terms as $t): ?>
                            <?php if ($t['academic_year'] == $selectedYear): ?>
                            <option value="<?php echo htmlspecialchars($t['term']); ?>" 
                                <?php echo ($selectedTerm == $t['term']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($t['term']); ?>
                            </option>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">View Results</button>
                    
                    <?php if (!empty($results)): ?>
                    <a href="?download=report&term=<?php echo urlencode($selectedTerm); ?>&year=<?php echo urlencode($selectedYear); ?>" 
                       class="btn btn-accent">
                        <i class="fas fa-download"></i> Download Report
                    </a>
                    <?php endif; ?>
                </form>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if (!empty($results)): ?>
        <!-- Results Table -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-table"></i> Results for <?php echo htmlspecialchars($selectedTerm . ' - ' . $selectedYear); ?></h3>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Subject</th>
                                <th>Assessment Type</th>
                                <th>Score</th>
                                <th>Max Score</th>
                                <th>Percentage</th>
                                <th>Grade</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results as $result): 
                                $percentage = ($result['score'] / $result['max_score']) * 100;
                                $gradeClass = $percentage >= 70 ? 'high' : ($percentage >= 50 ? 'medium' : 'low');
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($result['subject_name']); ?></strong></td>
                                <td><?php echo ucfirst($result['assessment_type']); ?></td>
                                <td class="score <?php echo $gradeClass; ?>">
                                    <?php echo $result['score']; ?>
                                </td>
                                <td><?php echo $result['max_score']; ?></td>
                                <td style="min-width: 150px;">
                                    <div class="progress-bar">
                                        <div class="progress-fill <?php echo $gradeClass; ?>" 
                                             style="width: <?php echo $percentage; ?>%">
                                            <?php echo number_format($percentage, 1); ?>%
                                        </div>
                                    </div>
                                </td>
                                <td class="grade <?php echo $gradeClass; ?>">
                                    <?php echo $result['grade']; ?>
                                </td>
                                <td><?php echo htmlspecialchars($result['remarks'] ?? '-'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Summary Card -->
        <div class="summary-card">
            <h3><i class="fas fa-chart-pie"></i> Term Summary</h3>
            <div class="summary-grid">
                <div class="summary-item">
                    <label>Total Score</label>
                    <span class="value"><?php echo $termSummary['total_score']; ?> / <?php echo $termSummary['total_max']; ?></span>
                </div>
                <div class="summary-item">
                    <label>Average</label>
                    <span class="value"><?php echo $termSummary['average']; ?></span>
                </div>
                <div class="summary-item">
                    <label>Percentage</label>
                    <span class="value <?php echo $termSummary['percentage'] >= 70 ? 'high' : ($termSummary['percentage'] >= 50 ? 'medium' : 'low'); ?>">
                        <?php echo $termSummary['percentage']; ?>%
                    </span>
                </div>
                <div class="summary-item">
                    <label>Overall Grade</label>
                    <span class="grade-badge grade-<?php echo strtolower($termSummary['grade']); ?>">
                        <?php echo $termSummary['grade']; ?>
                    </span>
                </div>
            </div>
        </div>
        
        <?php elseif ($selectedTerm && $selectedYear): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle fa-2x mb-3"></i>
            <h4>No Results Available</h4>
            <p>No results found for the selected term.</p>
        </div>
        <?php endif; ?>
        
        <!-- Performance Trend Chart -->
        <?php if (!empty($performanceData)): ?>
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-chart-line"></i> Performance Trend</h3>
            </div>
            <div class="card-body">
                <canvas id="trendChart" width="400" height="200"></canvas>
            </div>
        </div>
        
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('trendChart').getContext('2d');
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode(array_map(function($item) {
                        return $item['term'] . ' ' . $item['academic_year'];
                    }, array_reverse($performanceData))); ?>,
                    datasets: [{
                        label: 'Average Performance (%)',
                        data: <?php echo json_encode(array_map(function($item) {
                            return round($item['average'], 1);
                        }, array_reverse($performanceData))); ?>,
                        borderColor: '#ffd700',
                        backgroundColor: 'rgba(255, 215, 0, 0.1)',
                        tension: 0.4,
                        fill: true,
                        pointBackgroundColor: '#002855',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 5
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100,
                            title: {
                                display: true,
                                text: 'Percentage (%)'
                            }
                        }
                    },
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.raw + '%';
                                }
                            }
                        }
                    }
                }
            });
        });
        </script>
        <?php endif; ?>
        
        <!-- Performance by Subject Chart -->
        <?php if (!empty($results)): ?>
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-chart-bar"></i> Performance by Subject</h3>
            </div>
            <div class="card-body">
                <canvas id="subjectChart" width="400" height="200"></canvas>
            </div>
        </div>
        
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const ctx2 = document.getElementById('subjectChart').getContext('2d');
            new Chart(ctx2, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode(array_column($results, 'subject_name')); ?>,
                    datasets: [{
                        label: 'Score',
                        data: <?php echo json_encode(array_map(function($r) {
                            return round(($r['score'] / $r['max_score']) * 100, 1);
                        }, $results)); ?>,
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
                            max: 100,
                            title: {
                                display: true,
                                text: 'Percentage (%)'
                            }
                        }
                    }
                }
            });
        });
        </script>
        <?php endif; ?>
    </main>
</div>

