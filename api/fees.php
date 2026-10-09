<?php
// api/fees.php - Fees API
require_once __DIR__ . '/../includes/api.php';

$input = api_init(['GET', 'POST'], ['admin', 'parent', 'student']);
$db = db();
$isAdmin = Security::hasRole('admin');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    switch ($_GET['action'] ?? '') {
        case 'get_student_fees':
            $studentId = api_int($_GET['student_id'] ?? null);
            if (!$studentId) api_error('Student ID required');
            if (!Security::canAccessStudent($studentId)) api_error('Permission denied', 403);
            $year = Security::sanitize($_GET['academic_year'] ?? '');
            if ($year !== '' && !preg_match('/^\d{4}(-\d{4})?$/', $year)) api_error('Invalid academic year');

            $student = $db->getRow('SELECT class_id FROM students WHERE id = ?', [$studentId]);
            if (!$student) api_error('Student not found', 404);
            if ($year === '') {
                $latest = $db->getRow('SELECT academic_year FROM fee_structure WHERE class_id = ? ORDER BY academic_year DESC LIMIT 1', [$student['class_id']]);
                $year = $latest['academic_year'] ?? currentAcademicYear();
            }

            $fees = $db->getRows('SELECT * FROM fee_structure WHERE class_id = ? AND academic_year = ? ORDER BY term, is_mandatory DESC', [$student['class_id'], $year]);
            $payments = $db->getRows('SELECT * FROM payments WHERE student_id = ? AND academic_year = ? ORDER BY payment_date DESC', [$studentId, $year]);

            $totalFees = array_sum(array_column($fees, 'amount'));
            $totalPaid = $totalPending = 0;
            foreach ($payments as $p) {
                if ($p['status'] === 'completed') $totalPaid += $p['amount'];
                elseif ($p['status'] === 'pending') $totalPending += $p['amount'];
            }
            api_ok(['data' => [
                'academic_year' => $year,
                'fee_structure' => $fees,
                'payments' => $payments,
                'summary' => [
                    'total_fees' => $totalFees, 'total_paid' => $totalPaid,
                    'total_pending' => $totalPending, 'balance' => $totalFees - $totalPaid,
                ],
            ]]);

        case 'get_outstanding':
            if (!$isAdmin) api_error('Permission denied', 403);
            $classId = api_int($_GET['class_id'] ?? null);
            $sql = "SELECT s.id, u.first_name, u.last_name, s.admission_number, c.class_name,
                           COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.student_id = s.id AND p.status = 'completed'), 0) AS paid,
                           COALESCE((SELECT SUM(f.amount) FROM fee_structure f WHERE f.class_id = s.class_id), 0) AS expected
                    FROM students s
                    JOIN users u ON s.user_id = u.id
                    LEFT JOIN classes c ON s.class_id = c.id
                    WHERE u.is_active = 1 AND u.deleted_at IS NULL";
            $params = [];
            if ($classId) { $sql .= ' AND s.class_id = ?'; $params[] = $classId; }
            $sql .= ' HAVING expected > paid ORDER BY (expected - paid) DESC';
            api_ok(['data' => $db->getRows($sql, $params)]);

        case 'get_receipt':
            $id = api_int($_GET['id'] ?? null);
            if (!$id) api_error('Receipt ID required');
            $receipt = $db->getRow(
                "SELECT p.*, CONCAT(u.first_name, ' ', u.last_name) AS student_name, s.admission_number,
                        c.class_name, c.section, CONCAT(ru.first_name, ' ', ru.last_name) AS recorded_by_name
                 FROM payments p
                 JOIN students s ON p.student_id = s.id
                 JOIN users u ON s.user_id = u.id
                 LEFT JOIN classes c ON s.class_id = c.id
                 LEFT JOIN users ru ON p.recorded_by = ru.id
                 WHERE p.id = ?",
                [$id]
            );
            // Same answer for "missing" and "not yours" so IDs cannot be probed
            if (!$receipt || !Security::canAccessStudent($receipt['student_id'])) api_error('Receipt not found', 404);
            api_ok(['data' => $receipt]);

        default:
            api_error('Invalid action');
    }
}

// ---- POST (admin only) ----
if (!$isAdmin) api_error('Permission denied', 403);

switch ($input['action'] ?? '') {
    case 'record_payment':
        $studentId = api_int($input['student_id'] ?? null);
        $amount = filter_var($input['amount'] ?? null, FILTER_VALIDATE_FLOAT);
        $method = $input['payment_method'] ?? 'cash';
        $date = api_date($input['payment_date'] ?? date('Y-m-d'));
        $term = Security::sanitize($input['term'] ?? '');
        $year = Security::sanitize($input['academic_year'] ?? '');

        if (!$studentId || !$term || !$year) api_error('Student, term and academic year are required');
        if ($amount === false || $amount <= 0 || $amount > 100000000) api_error('Enter a valid amount');
        if (!in_array($method, ['cash', 'bank_transfer', 'card', 'cheque'], true)) api_error('Invalid payment method');
        if (!$date || $date > date('Y-m-d')) api_error('Invalid payment date');
        if (!$db->getRow('SELECT id FROM students WHERE id = ?', [$studentId])) api_error('Student not found', 404);

        try {
            $receipt = generateReceiptNumber();
            $paymentId = $db->insert(
                "INSERT INTO payments (student_id, receipt_number, payment_date, amount, payment_method, transaction_id,
                                       bank_name, cheque_number, term, academic_year, status, remarks, recorded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', ?, ?)",
                [$studentId, $receipt, $date, $amount, $method,
                 Security::sanitize($input['transaction_id'] ?? ''), Security::sanitize($input['bank_name'] ?? ''),
                 Security::sanitize($input['cheque_number'] ?? ''), $term, $year,
                 Security::sanitize($input['remarks'] ?? ''), $_SESSION['user_id']]
            );
            Security::logAudit('RECORDED_PAYMENT_API', 'payments', $paymentId);
            api_ok(['message' => 'Payment recorded successfully', 'receipt_number' => $receipt, 'payment_id' => (int)$paymentId]);
        } catch (Throwable $e) {
            api_exception($e);
        }

    case 'update_payment_status':
        $paymentId = api_int($input['payment_id'] ?? null);
        $status = $input['status'] ?? '';
        if (!$paymentId || !in_array($status, ['pending', 'completed', 'failed', 'refunded'], true)) api_error('Valid payment ID and status required');
        if (!$db->getRow('SELECT id FROM payments WHERE id = ?', [$paymentId])) api_error('Payment not found', 404);
        try {
            $db->query('UPDATE payments SET status = ?, approved_by = ?, approved_at = NOW() WHERE id = ?', [$status, $_SESSION['user_id'], $paymentId]);
            Security::logAudit('UPDATED_PAYMENT_STATUS', 'payments', $paymentId);
            api_ok(['message' => 'Payment status updated']);
        } catch (Throwable $e) {
            api_exception($e);
        }

    default:
        api_error('Invalid action');
}
