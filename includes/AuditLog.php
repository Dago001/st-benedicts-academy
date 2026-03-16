<?php
// includes/AuditLog.php - Audit Log Model Class

require_once __DIR__ . '/Database.php';

class AuditLog {
    private $db;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Log an action
     * @param array $data Log data
     * @return int|false Log ID or false
     */
    public function log($data) {
        try {
            return $this->db->insert(
                "INSERT INTO audit_logs (user_id, action, table_affected, record_id, old_values, new_values, ip_address, user_agent) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $data['user_id'] ?? null,
                    $data['action'],
                    $data['table_affected'] ?? null,
                    $data['record_id'] ?? null,
                    isset($data['old_values']) ? json_encode($data['old_values']) : null,
                    isset($data['new_values']) ? json_encode($data['new_values']) : null,
                    $data['ip_address'] ?? $_SERVER['REMOTE_ADDR'] ?? null,
                    $data['user_agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? null
                ]
            );
        } catch (Exception $e) {
            error_log("Error creating audit log: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get audit logs with filters
     * @param array $filters Optional filters
     * @param int $limit Limit
     * @param int $offset Offset
     * @return array Audit logs
     */
    public function getLogs($filters = [], $limit = 50, $offset = 0) {
        $sql = "SELECT a.*, u.username, u.first_name, u.last_name, u.role 
                FROM audit_logs a
                LEFT JOIN users u ON a.user_id = u.id
                WHERE 1=1";
        $params = [];
        
        if (!empty($filters['user_id'])) {
            $sql .= " AND a.user_id = ?";
            $params[] = $filters['user_id'];
        }
        
        if (!empty($filters['action'])) {
            $sql .= " AND a.action LIKE ?";
            $params[] = "%{$filters['action']}%";
        }
        
        if (!empty($filters['table'])) {
            $sql .= " AND a.table_affected = ?";
            $params[] = $filters['table'];
        }
        
        if (!empty($filters['date_from'])) {
            $sql .= " AND DATE(a.created_at) >= ?";
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $sql .= " AND DATE(a.created_at) <= ?";
            $params[] = $filters['date_to'];
        }
        
        if (!empty($filters['search'])) {
            $sql .= " AND (a.action LIKE ? OR a.table_affected LIKE ?)";
            $search = "%{$filters['search']}%";
            $params[] = $search;
            $params[] = $search;
        }
        
        $sql .= " ORDER BY a.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        
        return $this->db->getRows($sql, $params);
    }
    
    /**
     * Count audit logs with filters
     * @param array $filters Optional filters
     * @return int Count
     */
    public function countLogs($filters = []) {
        $sql = "SELECT COUNT(*) as count FROM audit_logs a WHERE 1=1";
        $params = [];
        
        if (!empty($filters['user_id'])) {
            $sql .= " AND a.user_id = ?";
            $params[] = $filters['user_id'];
        }
        
        if (!empty($filters['action'])) {
            $sql .= " AND a.action LIKE ?";
            $params[] = "%{$filters['action']}%";
        }
        
        if (!empty($filters['table'])) {
            $sql .= " AND a.table_affected = ?";
            $params[] = $filters['table'];
        }
        
        if (!empty($filters['date_from'])) {
            $sql .= " AND DATE(a.created_at) >= ?";
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $sql .= " AND DATE(a.created_at) <= ?";
            $params[] = $filters['date_to'];
        }
        
        $result = $this->db->getRow($sql, $params);
        return $result['count'] ?? 0;
    }
    
    /**
     * Get a single log by ID
     * @param int $id Log ID
     * @return array|false Log data or false
     */
    public function getLog($id) {
        return $this->db->getRow(
            "SELECT a.*, u.username, u.first_name, u.last_name, u.role 
             FROM audit_logs a
             LEFT JOIN users u ON a.user_id = u.id
             WHERE a.id = ?",
            [$id]
        );
    }
    
    /**
     * Get user activity summary
     * @param int $userId User ID
     * @param string|null $startDate Start date
     * @param string|null $endDate End date
     * @return array Activity summary
     */
    public function getUserActivity($userId, $startDate = null, $endDate = null) {
        $sql = "SELECT 
                    COUNT(*) as total_actions,
                    COUNT(DISTINCT DATE(created_at)) as active_days,
                    MIN(created_at) as first_action,
                    MAX(created_at) as last_action
                FROM audit_logs 
                WHERE user_id = ?";
        $params = [$userId];
        
        if ($startDate) {
            $sql .= " AND DATE(created_at) >= ?";
            $params[] = $startDate;
        }
        
        if ($endDate) {
            $sql .= " AND DATE(created_at) <= ?";
            $params[] = $endDate;
        }
        
        $summary = $this->db->getRow($sql, $params);
        
        // Get action breakdown
        $actions = $this->db->getRows(
            "SELECT action, COUNT(*) as count 
             FROM audit_logs 
             WHERE user_id = ? 
             GROUP BY action 
             ORDER BY count DESC",
            [$userId]
        );
        
        $summary['actions'] = $actions;
        
        return $summary;
    }
    
    /**
     * Get action summary
     * @param string|null $startDate Start date
     * @param string|null $endDate End date
     * @return array Action summary
     */
    public function getActionSummary($startDate = null, $endDate = null) {
        $sql = "SELECT 
                    action,
                    COUNT(*) as count,
                    COUNT(DISTINCT user_id) as unique_users
                FROM audit_logs 
                WHERE 1=1";
        $params = [];
        
        if ($startDate) {
            $sql .= " AND DATE(created_at) >= ?";
            $params[] = $startDate;
        }
        
        if ($endDate) {
            $sql .= " AND DATE(created_at) <= ?";
            $params[] = $endDate;
        }
        
        $sql .= " GROUP BY action ORDER BY count DESC";
        
        return $this->db->getRows($sql, $params);
    }
    
    /**
     * Get table summary
     * @param string|null $startDate Start date
     * @param string|null $endDate End date
     * @return array Table summary
     */
    public function getTableSummary($startDate = null, $endDate = null) {
        $sql = "SELECT 
                    table_affected,
                    COUNT(*) as count
                FROM audit_logs 
                WHERE table_affected IS NOT NULL";
        $params = [];
        
        if ($startDate) {
            $sql .= " AND DATE(created_at) >= ?";
            $params[] = $startDate;
        }
        
        if ($endDate) {
            $sql .= " AND DATE(created_at) <= ?";
            $params[] = $endDate;
        }
        
        $sql .= " GROUP BY table_affected ORDER BY count DESC";
        
        return $this->db->getRows($sql, $params);
    }
    
    /**
     * Get daily activity for a period
     * @param string $startDate Start date
     * @param string $endDate End date
     * @return array Daily activity
     */
    public function getDailyActivity($startDate, $endDate) {
        return $this->db->getRows(
            "SELECT 
                DATE(created_at) as date,
                COUNT(*) as count
             FROM audit_logs 
             WHERE DATE(created_at) BETWEEN ? AND ?
             GROUP BY DATE(created_at)
             ORDER BY date",
            [$startDate, $endDate]
        );
    }
    
    /**
     * Get hourly activity for a date
     * @param string $date Date
     * @return array Hourly activity
     */
    public function getHourlyActivity($date) {
        return $this->db->getRows(
            "SELECT 
                HOUR(created_at) as hour,
                COUNT(*) as count
             FROM audit_logs 
             WHERE DATE(created_at) = ?
             GROUP BY HOUR(created_at)
             ORDER BY hour",
            [$date]
        );
    }
    
    /**
     * Get distinct actions
     * @return array List of actions
     */
    public function getDistinctActions() {
        return $this->db->getRows(
            "SELECT DISTINCT action FROM audit_logs ORDER BY action"
        );
    }
    
    /**
     * Get distinct tables
     * @return array List of tables
     */
    public function getDistinctTables() {
        return $this->db->getRows(
            "SELECT DISTINCT table_affected FROM audit_logs WHERE table_affected IS NOT NULL ORDER BY table_affected"
        );
    }
    
    /**
     * Clear old logs
     * @param int $daysOld Delete logs older than this many days
     * @return int Number of logs deleted
     */
    public function clearOldLogs($daysOld = 90) {
        try {
            $date = date('Y-m-d', strtotime("-{$daysOld} days"));
            
            $this->db->query(
                "DELETE FROM audit_logs WHERE DATE(created_at) < ?",
                [$date]
            );
            
            $count = $this->db->getRow("SELECT ROW_COUNT() as count")['count'];
            
            Security::logAudit('CLEARED_AUDIT_LOGS', 'audit_logs', null, 
                              ['days_old' => $daysOld, 'deleted' => $count]);
            
            return $count;
            
        } catch (Exception $e) {
            error_log("Error clearing old logs: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Export logs to CSV
     * @param array $filters Optional filters
     * @return string CSV data
     */
    public function exportToCSV($filters = []) {
        $logs = $this->getLogs($filters, 10000, 0);
        
        $csv = "ID,Date/Time,User,Action,Table,Record ID,IP Address,Old Values,New Values\n";
        
        foreach ($logs as $log) {
            $userName = $log['first_name'] 
                ? $log['first_name'] . ' ' . $log['last_name'] 
                : ($log['username'] ?? 'System');
            
            $csv .= implode(',', [
                $log['id'],
                $log['created_at'],
                '"' . $userName . '"',
                '"' . $log['action'] . '"',
                '"' . ($log['table_affected'] ?? '') . '"',
                $log['record_id'] ?? '',
                '"' . ($log['ip_address'] ?? '') . '"',
                '"' . str_replace('"', '""', ($log['old_values'] ?? '')) . '"',
                '"' . str_replace('"', '""', ($log['new_values'] ?? '')) . '"'
            ]) . "\n";
        }
        
        return $csv;
    }
    
    /**
     * Get user's last activity
     * @param int $userId User ID
     * @return array|false Last activity or false
     */
    public function getLastActivity($userId) {
        return $this->db->getRow(
            "SELECT * FROM audit_logs 
             WHERE user_id = ? 
             ORDER BY created_at DESC 
             LIMIT 1",
            [$userId]
        );
    }
    
    /**
     * Check if user has performed an action recently
     * @param int $userId User ID
     * @param string $action Action to check
     * @param int $minutes Minutes to look back
     * @return bool True if performed
     */
    public function hasPerformedRecently($userId, $action, $minutes = 5) {
        $result = $this->db->getRow(
            "SELECT COUNT(*) as count FROM audit_logs 
             WHERE user_id = ? AND action = ? 
             AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)",
            [$userId, $action, $minutes]
        );
        
        return ($result['count'] ?? 0) > 0;
    }
}