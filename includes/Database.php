<?php
// includes/Database.php
// Enhanced Database class with query builder methods

class Database {
    private $pdo;
    private $table;
    private $where = [];
    private $orderBy = [];
    private $limit = null;
    private $offset = null;
    
    public function __construct() {
        $this->pdo = Database::getInstance()->getConnection();
    }
    
    // Query builder methods
    public function table($table) {
        $this->table = $table;
        return $this;
    }
    
    public function where($column, $operator, $value = null) {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }
        
        $this->where[] = [
            'column' => $column,
            'operator' => $operator,
            'value' => $value
        ];
        
        return $this;
    }
    
    public function orderBy($column, $direction = 'ASC') {
        $this->orderBy[] = "$column $direction";
        return $this;
    }
    
    public function limit($limit, $offset = 0) {
        $this->limit = $limit;
        $this->offset = $offset;
        return $this;
    }
    
    public function get() {
        $sql = "SELECT * FROM {$this->table}";
        
        if (!empty($this->where)) {
            $conditions = [];
            $params = [];
            
            foreach ($this->where as $w) {
                $conditions[] = "{$w['column']} {$w['operator']} ?";
                $params[] = $w['value'];
            }
            
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }
        
        if (!empty($this->orderBy)) {
            $sql .= " ORDER BY " . implode(', ', $this->orderBy);
        }
        
        if ($this->limit !== null) {
            $sql .= " LIMIT {$this->limit}";
            if ($this->offset) {
                $sql .= " OFFSET {$this->offset}";
            }
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }
    
    public function first() {
        $this->limit(1);
        $results = $this->get();
        return $results[0] ?? null;
    }
    
    public function insert($data) {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        
        $sql = "INSERT INTO {$this->table} ($columns) VALUES ($placeholders)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($data));
        
        return $this->pdo->lastInsertId();
    }
    
    public function update($data) {
        if (empty($this->where)) {
            throw new Exception("Update requires WHERE clause for safety");
        }
        
        $set = [];
        $params = [];
        
        foreach ($data as $column => $value) {
            $set[] = "$column = ?";
            $params[] = $value;
        }
        
        $sql = "UPDATE {$this->table} SET " . implode(', ', $set);
        
        $conditions = [];
        foreach ($this->where as $w) {
            $conditions[] = "{$w['column']} {$w['operator']} ?";
            $params[] = $w['value'];
        }
        
        $sql .= " WHERE " . implode(' AND ', $conditions);
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }
    
    public function delete() {
        if (empty($this->where)) {
            throw new Exception("Delete requires WHERE clause for safety");
        }
        
        $sql = "DELETE FROM {$this->table} WHERE ";
        $conditions = [];
        $params = [];
        
        foreach ($this->where as $w) {
            $conditions[] = "{$w['column']} {$w['operator']} ?";
            $params[] = $w['value'];
        }
        
        $sql .= implode(' AND ', $conditions);
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }
    
    public function count() {
        $sql = "SELECT COUNT(*) as count FROM {$this->table}";
        
        if (!empty($this->where)) {
            $conditions = [];
            $params = [];
            
            foreach ($this->where as $w) {
                $conditions[] = "{$w['column']} {$w['operator']} ?";
                $params[] = $w['value'];
            }
            
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        
        return $result['count'];
    }
    
    public function sum($column) {
        $sql = "SELECT SUM($column) as total FROM {$this->table}";
        
        if (!empty($this->where)) {
            $conditions = [];
            $params = [];
            
            foreach ($this->where as $w) {
                $conditions[] = "{$w['column']} {$w['operator']} ?";
                $params[] = $w['value'];
            }
            
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        
        return $result['total'] ?? 0;
    }
}