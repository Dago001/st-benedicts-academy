<?php
// includes/Fee.php - Fee Management Model Class

require_once __DIR__ . '/Database.php';

class Fee {
    private $db;
    private $id;
    private $data;
    
    /**
     * Constructor
     * @param int|null $id Fee structure ID or payment ID
     */
    public function __construct($id = null) {
        $this->db = Database::getInstance();
        if ($id) {
            $this->find($id);
        }
    }
    
    /**
     * Find fee structure by ID
     * @param int $id Fee structure ID
     * @return array|false Fee structure data or false
     */
    public function find($id) {
        $this->data = $this->db->getRow(
            "SELECT fs.*, c.class_name 
             FROM fee_structure fs
             JOIN classes c ON fs.class_id = c.id
             WHERE fs.id = ?",
            [$id]
        );
        
        if ($this->data) {
            $this->id = $id;
        }
        
        return $this->data;
    }
    
    /**
     * Find payment by ID
     * @param int $id Payment ID
     * @return array|false Payment data or false
     */
    public function findPayment($id) {
        return $this->db->getRow(
            "SELECT p.*, 
                    CONCAT(u.first_name, ' ', u.last_name) as student_name,
                    s.admission_number,
                    c.class_name,
                    CONCAT(ru.first_name, ' ', ru.last_name) as recorded_by_name,
                    CONCAT(au.first_name, ' ', au.last_name) as approved_by_name
             FROM payments p
             JOIN students s ON p.student_id = s.id
             JOIN users u ON s.user_id = u.id
             LEFT JOIN classes c ON s.class_id = c.id
             LEFT JOIN users ru ON p.recorded_by = ru.id
             LEFT JOIN users au ON p.approved_by = au.id
             WHERE p.id = ?",
            [$id]
        );
    }
    
    /**
     * Get all fee structures with optional filters
     * @param array $filters Optional filters
     * @return array List of fee structures
     */
    public function getAllStructures($filters = []) {
        $sql = "SELECT fs.*, c.class_name 
                FROM fee_structure fs
                JOIN classes c ON fs.class_id = c.id
                WHERE 1=1";
        $params = [];
        
        if (!empty($filters['class_id'])) {
            $sql .= " AND fs.class_id = ?";
            $params[] = $filters['class_id'];
        }
        
        if (!empty($filters['academic_year'])) {
            $sql .= " AND fs.academic_year = ?";
            $params[] = $filters['academic_year'];
        }
        
        if (!empty($filters['term'])) {
            $sql .= " AND fs.term = ?";
            $params[] = $filters['term'];
        }
        
        if (isset($filters['is_mandatory'])) {
            $sql .= " AND fs.is_mandatory = ?";
            $params[] = $filters['is_mandatory'];
        }
        
        $sql .= " ORDER BY fs.academic_year DESC, fs.term, c.class_name";
        
        return $this->db->getRows($sql, $params);
    }
    
    /**
     * Get fee structure for a specific class
     * @param int $classId Class ID
     * @param string $academicYear Academic year
     * @return array Fee structure
     */
    public function getClassStructure($classId, $academicYear) {
        return $this->db->getRows(
            "SELECT * FROM fee_structure 
             WHERE class_id = ? AND academic_year = ? 
             ORDER BY term, is_mandatory DESC",
            [$classId, $academicYear]
        );
    }
    
    /**
     * Create new fee structure
     * @param array $data Fee structure data
     * @return int|false New fee structure ID or false
     */
    public function createStructure($data) {
        try {
            // Check for duplicate
            $existing = $this->db->getRow(
                "SELECT id FROM fee_structure 
                 WHERE class_id = ? AND fee_type = ? AND term = ? AND academic_year = ?",
                [$data['class_id'], $data['fee_type'], $data['term'], $data['academic_year']]
            );
            
            if ($existing) {
                throw new Exception("Fee structure already exists for this class, term and academic year");
            }
            
            $id = $this->db->insert(
                "INSERT INTO fee_structure (class_id, fee_type, amount, term, academic_year, due_date, is_mandatory, description) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $data['class_id'],
                    $data['fee_type'],
                    $data['amount'],
                    $data['term'],
                    $data['academic_year'],
                    $data['due_date'] ?? null,
                    $data['is_mandatory'] ?? 1,
                    $data['description'] ?? null
                ]
            );
            
            Security::logAudit('CREATED_FEE_STRUCTURE', 'fee_structure', $id, null, $data);
            
            return $id;
            
        } catch (Exception $e) {
            error_log("Error creating fee structure: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update fee structure
     * @param int $id Fee structure ID
     * @param array $data Updated data
     * @return bool Success or failure
     */
    public function updateStructure($id, $data) {
        try {
            $structure = $this->find($id);
            if (!$structure) {
                throw new Exception("Fee structure not found");
            }
            
            $this->db->query(
                "UPDATE fee_structure SET fee_type = ?, amount = ?, term = ?, 
                 academic_year = ?, due_date = ?, is_mandatory = ?, description = ? 
                 WHERE id = ?",
                [
                    $data['fee_type'] ?? $structure['fee_type'],
                    $data['amount'] ?? $structure['amount'],
                    $data['term'] ?? $structure['term'],
                    $data['academic_year'] ?? $structure['academic_year'],
                    $data['due_date'] ?? $structure['due_date'],
                    $data['is_mandatory'] ?? $structure['is_mandatory'],
                    $data['description'] ?? $structure['description'],
                    $id
                ]
            );
            
            Security::logAudit('UPDATED_FEE_STRUCTURE', 'fee_structure', $id, $structure, $data);
            
            return true;
            
        } catch (Exception $e) {
            error_log("Error updating fee structure: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Delete fee structure
     * @param int $id Fee structure ID
     * @return bool Success or failure
     */
    public function deleteStructure($id) {
        try {
            $structure = $this->find($id);
            if (!$structure) {
                throw new Exception("Fee structure not found");
            }
            
            // Check if structure has payments
            $paymentCount = $this->db->getRow(
                "SELECT COUNT(*) as count FROM payments WHERE fee_structure_id = ?",
                [$id]
            )['count'];
            
            if ($paymentCount > 0) {
                throw new Exception("Cannot delete fee structure with existing payments");
            }
            
            $this->db->query("DELETE FROM fee_structure WHERE id = ?", [$id]);
            
            Security::logAudit('DELETED_FEE_STRUCTURE', 'fee_structure', $id, $structure);
            
            return true;
            
        } catch (Exception $e) {
            error_log("Error deleting fee structure: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Record a payment
     * @param array $data Payment data
     * @return int|false Payment ID or false
     */
    public function recordPayment($data) {
        try {
            // Generate receipt number
            $receiptNumber = $this->generateReceiptNumber();
            
            $id = $this->db->insert(
                "INSERT INTO payments (student_id, receipt_number, fee_structure_id, amount, 
                 payment_date, payment_method, transaction_id, bank_name, cheque_number, 
                 term, academic_year, remarks, recorded_by) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $data['student_id'],
                    $receiptNumber,
                    $data['fee_structure_id'] ?? null,
                    $data['amount'],
                    $data['payment_date'] ?? date('Y-m-d'),
                    $data['payment_method'],
                    $data['transaction_id'] ?? null,
                    $data['bank_name'] ?? null,
                    $data['cheque_number'] ?? null,
                    $data['term'],
                    $data['academic_year'],
                    $data['remarks'] ?? null,
                    $data['recorded_by']
                ]
            );
            
            Security::logAudit('RECORDED_PAYMENT', 'payments', $id, null, $data);
            
            return $id;
            
        } catch (Exception $e) {
            error_log("Error recording payment: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update payment status
     * @param int $paymentId Payment ID
     * @param string $status New status
     * @param int $approvedBy User ID who approved
     * @return bool Success or failure
     */
    public function updatePaymentStatus($paymentId, $status, $approvedBy) {
        try {
            $payment = $this->findPayment($paymentId);
            if (!$payment) {
                throw new Exception("Payment not found");
            }
            
            $this->db->query(
                "UPDATE payments SET status = ?, approved_by = ?, approved_at = NOW() 
                 WHERE id = ?",
                [$status, $approvedBy, $paymentId]
            );
            
            Security::logAudit('UPDATED_PAYMENT_STATUS', 'payments', $paymentId, $payment, 
                              ['status' => $status]);
            
            return true;
            
        } catch (Exception $e) {
            error_log("Error updating payment status: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get payments for a student
     * @param int $studentId Student ID
     * @param string|null $academicYear Academic year (optional)
     * @return array Payments
     */
    public function getStudentPayments($studentId, $academicYear = null) {
        $sql = "SELECT p.*, fs.fee_type 
                FROM payments p
                LEFT JOIN fee_structure fs ON p.fee_structure_id = fs.id
                WHERE p.student_id = ?";
        $params = [$studentId];
        
        if ($academicYear) {
            $sql .= " AND p.academic_year = ?";
            $params[] = $academicYear;
        }
        
        $sql .= " ORDER BY p.payment_date DESC";
        
        return $this->db->getRows($sql, $params);
    }
    
    /**
     * Get fee summary for a student
     * @param int $studentId Student ID
     * @param string $academicYear Academic year
     * @return array Fee summary
     */
    public function getStudentSummary($studentId, $academicYear) {
        // Get student's class
        $student = $this->db->getRow(
            "SELECT class_id FROM students WHERE id = ?",
            [$studentId]
        );
        
        if (!$student || !$student['class_id']) {
            return [
                'total_fees' => 0,
                'total_paid' => 0,
                'balance' => 0,
                'pending' => 0,
                'fee_structure' => [],
                'payments' => []
            ];
        }
        
        // Get fee structure
        $feeStructure = $this->getClassStructure($student['class_id'], $academicYear);
        
        // Get payments
        $payments = $this->getStudentPayments($studentId, $academicYear);
        
        $totalFees = 0;
        $totalPaid = 0;
        $pendingAmount = 0;
        
        foreach ($feeStructure as $fee) {
            $totalFees += $fee['amount'];
        }
        
        foreach ($payments as $payment) {
            if ($payment['status'] === 'completed') {
                $totalPaid += $payment['amount'];
            } elseif ($payment['status'] === 'pending') {
                $pendingAmount += $payment['amount'];
            }
        }
        
        return [
            'total_fees' => $totalFees,
            'total_paid' => $totalPaid,
            'balance' => $totalFees - $totalPaid,
            'pending' => $pendingAmount,
            'fee_structure' => $feeStructure,
            'payments' => $payments
        ];
    }
    
    /**
     * Get outstanding fees report
     * @param int|null $classId Class ID (optional)
     * @param string|null $academicYear Academic year (optional)
     * @return array Outstanding fees
     */
    public function getOutstanding($classId = null, $academicYear = null) {
        if (!$academicYear) {
            $academicYear = date('Y') . '-' . (date('Y') + 1);
        }
        
        $sql = "SELECT s.id, u.first_name, u.last_name, s.admission_number, c.class_name,
                       COALESCE((
                           SELECT SUM(amount) FROM fee_structure 
                           WHERE class_id = s.class_id AND academic_year = ?
                       ), 0) as expected,
                       COALESCE((
                           SELECT SUM(amount) FROM payments 
                           WHERE student_id = s.id AND academic_year = ? AND status = 'completed'
                       ), 0) as paid
                FROM students s
                JOIN users u ON s.user_id = u.id
                LEFT JOIN classes c ON s.class_id = c.id
                WHERE u.is_active = 1 AND u.deleted_at IS NULL";
        $params = [$academicYear, $academicYear];
        
        if ($classId) {
            $sql .= " AND s.class_id = ?";
            $params[] = $classId;
        }
        
        $sql .= " HAVING expected > paid
                  ORDER BY (expected - paid) DESC";
        
        $results = $this->db->getRows($sql, $params);
        
        foreach ($results as &$result) {
            $result['balance'] = $result['expected'] - $result['paid'];
            $result['payment_percentage'] = $result['expected'] > 0 
                ? round(($result['paid'] / $result['expected']) * 100, 1) 
                : 0;
        }
        
        return $results;
    }
    
    /**
     * Get payment report for date range
     * @param string $startDate Start date
     * @param string $endDate End date
     * @param int|null $classId Class ID (optional)
     * @return array Payments
     */
    public function getPaymentReport($startDate, $endDate, $classId = null) {
        $sql = "SELECT p.*, 
                       CONCAT(u.first_name, ' ', u.last_name) as student_name,
                       s.admission_number,
                       c.class_name,
                       CONCAT(ru.first_name, ' ', ru.last_name) as recorded_by_name
                FROM payments p
                JOIN students s ON p.student_id = s.id
                JOIN users u ON s.user_id = u.id
                LEFT JOIN classes c ON s.class_id = c.id
                LEFT JOIN users ru ON p.recorded_by = ru.id
                WHERE p.payment_date BETWEEN ? AND ?";
        $params = [$startDate, $endDate];
        
        if ($classId) {
            $sql .= " AND s.class_id = ?";
            $params[] = $classId;
        }
        
        $sql .= " ORDER BY p.payment_date DESC";
        
        return $this->db->getRows($sql, $params);
    }
    
    /**
     * Get payment statistics
     * @param string|null $startDate Start date
     * @param string|null $endDate End date
     * @return array Statistics
     */
    public function getStatistics($startDate = null, $endDate = null) {
        if (!$startDate) {
            $startDate = date('Y-m-01');
        }
        if (!$endDate) {
            $endDate = date('Y-m-t');
        }
        
        $stats = $this->db->getRow(
            "SELECT 
                COUNT(*) as total_transactions,
                SUM(amount) as total_amount,
                SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as completed_amount,
                SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END) as pending_amount,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_count,
                COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_count
             FROM payments 
             WHERE payment_date BETWEEN ? AND ?",
            [$startDate, $endDate]
        );
        
        if (!$stats) {
            $stats = [
                'total_transactions' => 0,
                'total_amount' => 0,
                'completed_amount' => 0,
                'pending_amount' => 0,
                'completed_count' => 0,
                'pending_count' => 0
            ];
        }
        
        // Get payment methods breakdown
        $methods = $this->db->getRows(
            "SELECT payment_method, COUNT(*) as count, SUM(amount) as total
             FROM payments 
             WHERE payment_date BETWEEN ? AND ?
             GROUP BY payment_method",
            [$startDate, $endDate]
        );
        
        $stats['methods'] = $methods;
        
        return $stats;
    }
    
    /**
     * Generate unique receipt number
     * @return string Receipt number
     */
    private function generateReceiptNumber() {
        $year = date('Y');
        $month = date('m');
        $day = date('d');
        $random = str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        $receipt = "RCP-{$year}{$month}{$day}-{$random}";
        
        // Check if exists
        $exists = $this->db->getRow(
            "SELECT id FROM payments WHERE receipt_number = ?",
            [$receipt]
        );
        
        if ($exists) {
            return $this->generateReceiptNumber();
        }
        
        return $receipt;
    }
    
    /**
     * Get receipt details
     * @param string $receiptNumber Receipt number
     * @return array|false Receipt data or false
     */
    public function getReceipt($receiptNumber) {
        return $this->db->getRow(
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
             WHERE p.receipt_number = ?",
            [$receiptNumber]
        );
    }
    
    /**
     * Delete payment
     * @param int $id Payment ID
     * @return bool Success or failure
     */
    public function deletePayment($id) {
        try {
            $payment = $this->findPayment($id);
            if (!$payment) {
                throw new Exception("Payment not found");
            }
            
            $this->db->query("DELETE FROM payments WHERE id = ?", [$id]);
            
            Security::logAudit('DELETED_PAYMENT', 'payments', $id, $payment);
            
            return true;
            
        } catch (Exception $e) {
            error_log("Error deleting payment: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get fee collection by month
     * @param string $year Year
     * @return array Monthly collection
     */
    public function getMonthlyCollection($year) {
        return $this->db->getRows(
            "SELECT 
                MONTH(payment_date) as month,
                COUNT(*) as count,
                SUM(amount) as total
             FROM payments 
             WHERE YEAR(payment_date) = ? AND status = 'completed'
             GROUP BY MONTH(payment_date)
             ORDER BY month",
            [$year]
        );
    }
    
    /**
     * Get current instance data
     * @return array|null Fee structure data
     */
    public function getData() {
        return $this->data;
    }
    
    /**
     * Get fee structure ID
     * @return int|null
     */
    public function getId() {
        return $this->id;
    }
}