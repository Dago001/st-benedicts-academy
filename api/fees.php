<?php
// api/fees.php - Fees API endpoints
header('Content-Type: application/json');
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/security.php';

// Require authentication
if (!Security::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = db();
$response = ['success' => false];

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        $action = $_GET['action'] ?? '';
        
        switch ($action) {
            case 'get_student_fees':
                $studentId = Security::sanitize($_GET['student_id'] ?? '');
                $academicYear = Security::sanitize($_GET['academic_year'] ?? date('Y') . '-' . (date('Y') + 1));
                
                if (!$studentId) {
                    $response['message'] = 'Student ID required';
                    break;
                }
                
                // Get fee structure for student's class
                $student = $db->getRow("SELECT class_id FROM students WHERE id = ?", [$studentId]);
                
                if (!$student) {
                    $response['message'] = 'Student not found';
                    break;
                }
                
                $feeStructure = $db->getRows(
                    "SELECT * FROM fee_structure 
                     WHERE class_id = ? AND academic_year = ? 
                     ORDER BY term, is_mandatory DESC",
                    [$student['class_id'], $academicYear]
                );
                
                // Get payments made
                $payments = $db->getRows(
                    "SELECT * FROM payments 
                     WHERE student_id = ? AND academic_year = ? 
                     ORDER BY payment_date DESC",
                    [$studentId, $academicYear]
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
                
                $response['success'] = true;
                $response['data'] = [
                    'fee_structure' => $feeStructure,
                    'payments' => $payments,
                    'summary' => [
                        'total_fees' => $totalFees,
                        'total_paid' => $totalPaid,
                        'total_pending' => $totalPending,
                        'balance' => $totalFees - $totalPaid
                    ]
                ];
                break;
                
            case 'get_outstanding':
                $classId = Security::sanitize($_GET['class_id'] ?? '');
                
                $query = "SELECT s.id, u.first_name, u.last_name, s.admission_number, c.class_name,
                                 COALESCE(SUM(p.amount), 0) as paid,
                                 (SELECT SUM(amount) FROM fee_structure WHERE class_id = s.class_id) as expected
                          FROM students s
                          JOIN users u ON s.user_id = u.id
                          LEFT JOIN classes c ON s.class_id = c.id
                          LEFT JOIN payments p ON s.id = p.student_id AND p.status = 'completed'
                          WHERE u.is_active = 1";
                
                $params = [];
                
                if ($classId) {
                    $query .= " AND s.class_id = ?";
                    $params[] = $classId;
                }
                
                $query .= " GROUP BY s.id
                           HAVING expected > paid
                           ORDER BY (expected - paid) DESC";
                
                $outstanding = $db->getRows($query, $params);
                
                $response['success'] = true;
                $response['data'] = $outstanding;
                break;
                
            case 'get_receipt':
                $receiptId = Security::sanitize($_GET['id'] ?? '');
                
                $receipt = $db->getRow(
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
                     WHERE p.id = ?",
                    [$receiptId]
                );
                
                if ($receipt) {
                    $response['success'] = true;
                    $response['data'] = $receipt;
                } else {
                    $response['message'] = 'Receipt not found';
                }
                break;
                
            default:
                $response['message'] = 'Invalid action';
        }
        break;
        
    case 'POST':
        // Verify CSRF token for POST requests
        $input = json_decode(file_get_contents('php://input'), true);
        $token = $_POST['csrf_token'] ?? $input['csrf_token'] ?? '';
        
        if (!Security::verifyCSRFToken($token)) {
            http_response_code(419);
            $response['message'] = 'Invalid CSRF token';
            echo json_encode($response);
            exit;
        }
        
        $action = $_POST['action'] ?? $input['action'] ?? '';
        
        switch ($action) {
            case 'record_payment':
                if (!in_array($_SESSION['user_role'], ['admin', 'accounts'])) {
                    $response['message'] = 'Permission denied';
                    break;
                }
                
                $data = [
                    'student_id' => Security::sanitize($_POST['student_id'] ?? $input['student_id'] ?? ''),
                    'amount' => Security::sanitize($_POST['amount'] ?? $input['amount'] ?? ''),
                    'payment_date' => Security::sanitize($_POST['payment_date'] ?? $input['payment_date'] ?? date('Y-m-d')),
                    'payment_method' => Security::sanitize($_POST['payment_method'] ?? $input['payment_method'] ?? 'cash'),
                    'term' => Security::sanitize($_POST['term'] ?? $input['term'] ?? ''),
                    'academic_year' => Security::sanitize($_POST['academic_year'] ?? $input['academic_year'] ?? ''),
                    'transaction_id' => Security::sanitize($_POST['transaction_id'] ?? $input['transaction_id'] ?? ''),
                    'bank_name' => Security::sanitize($_POST['bank_name'] ?? $input['bank_name'] ?? ''),
                    'cheque_number' => Security::sanitize($_POST['cheque_number'] ?? $input['cheque_number'] ?? ''),
                    'remarks' => Security::sanitize($_POST['remarks'] ?? $input['remarks'] ?? '')
                ];
                
                // Validate required fields
                $required = ['student_id', 'amount', 'payment_method', 'term', 'academic_year'];
                foreach ($required as $field) {
                    if (empty($data[$field])) {
                        $response['message'] = "Missing required field: $field";
                        echo json_encode($response);
                        exit;
                    }
                }
                
                // Generate receipt number
                $receiptNumber = 'RCP-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
                
                try {
                    $db->insert(
                        "INSERT INTO payments (student_id, receipt_number, amount, payment_date, payment_method,
                         transaction_id, bank_name, cheque_number, term, academic_year, remarks, recorded_by) 
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        [$data['student_id'], $receiptNumber, $data['amount'], $data['payment_date'],
                         $data['payment_method'], $data['transaction_id'], $data['bank_name'],
                         $data['cheque_number'], $data['term'], $data['academic_year'], 
                         $data['remarks'], $_SESSION['user_id']]
                    );
                    
                    $paymentId = $db->lastInsertId();
                    
                    Security::logAudit('RECORDED_PAYMENT_API', 'payments', $paymentId);
                    
                    $response['success'] = true;
                    $response['message'] = 'Payment recorded successfully';
                    $response['receipt_number'] = $receiptNumber;
                    $response['payment_id'] = $paymentId;
                    
                } catch (Exception $e) {
                    $response['message'] = 'Database error: ' . $e->getMessage();
                }
                break;
                
            case 'update_payment_status':
                if ($_SESSION['user_role'] !== 'admin') {
                    $response['message'] = 'Permission denied';
                    break;
                }
                
                $paymentId = Security::sanitize($_POST['payment_id'] ?? $input['payment_id'] ?? '');
                $status = Security::sanitize($_POST['status'] ?? $input['status'] ?? '');
                
                if (!$paymentId || !$status) {
                    $response['message'] = 'Payment ID and status required';
                    break;
                }
                
                try {
                    $db->query(
                        "UPDATE payments SET status = ?, approved_by = ?, approved_at = NOW() WHERE id = ?",
                        [$status, $_SESSION['user_id'], $paymentId]
                    );
                    
                    Security::logAudit('UPDATED_PAYMENT_STATUS', 'payments', $paymentId);
                    
                    $response['success'] = true;
                    $response['message'] = 'Payment status updated';
                    
                } catch (Exception $e) {
                    $response['message'] = 'Database error: ' . $e->getMessage();
                }
                break;
        }
        break;
}

echo json_encode($response);
?>