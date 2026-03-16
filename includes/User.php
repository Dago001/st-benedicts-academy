<?php
// includes/User.php

class User {
    private $db;
    private $id;
    private $data;
    
    public function __construct($id = null) {
        $this->db = db();
        if ($id) {
            $this->find($id);
        }
    }
    
    public function find($id) {
        $this->data = $this->db->getRow(
            "SELECT * FROM users WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );
        if ($this->data) {
            $this->id = $id;
        }
        return $this->data;
    }
    
    public function create($data) {
        $data['password_hash'] = Security::hashPassword($data['password']);
        unset($data['password']);
        
        $id = $this->db->insert(
            "INSERT INTO users (username, email, password_hash, first_name, last_name, phone, role) 
             VALUES (:username, :email, :password_hash, :first_name, :last_name, :phone, :role)",
            $data
        );
        
        Security::logAudit('CREATED_USER', 'users', $id, null, $data);
        
        return $id;
    }
    
    public function update($id, $data) {
        if (isset($data['password'])) {
            $data['password_hash'] = Security::hashPassword($data['password']);
            unset($data['password']);
        }
        
        $oldData = $this->db->getRow("SELECT * FROM users WHERE id = ?", [$id]);
        
        $set = [];
        $params = [];
        foreach ($data as $key => $value) {
            $set[] = "$key = ?";
            $params[] = $value;
        }
        $params[] = $id;
        
        $this->db->query(
            "UPDATE users SET " . implode(', ', $set) . " WHERE id = ?",
            $params
        );
        
        Security::logAudit('UPDATED_USER', 'users', $id, $oldData, $data);
        
        return true;
    }
    
    public function delete($id) {
        $oldData = $this->db->getRow("SELECT * FROM users WHERE id = ?", [$id]);
        
        // Soft delete
        $this->db->query(
            "UPDATE users SET deleted_at = NOW() WHERE id = ?",
            [$id]
        );
        
        Security::logAudit('DELETED_USER', 'users', $id, $oldData);
        
        return true;
    }
    
    public function getStudents() {
        return $this->db->getRows(
            "SELECT s.*, u.first_name, u.last_name, u.email, u.phone, u.profile_image,
                    c.class_name, c.section
             FROM students s
             JOIN users u ON s.user_id = u.id
             LEFT JOIN classes c ON s.class_id = c.id
             WHERE s.parent_id = ?",
            [$this->id]
        );
    }
    
    public function hasPermission($permission) {
        // Implement permission checking logic
        $rolePermissions = [
            'admin' => ['*'],
            'teacher' => ['view_students', 'mark_attendance', 'add_results', 'view_own_classes'],
            'student' => ['view_own_results', 'view_own_attendance', 'submit_assignments'],
            'parent' => ['view_children', 'view_fees', 'communicate']
        ];
        
        $role = $this->data['role'] ?? '';
        
        if ($role === 'admin') return true;
        
        return in_array($permission, $rolePermissions[$role] ?? []);
    }
    
    public function getRole() {
        return $this->data['role'] ?? null;
    }
    
    public function getFullName() {
        return ($this->data['first_name'] ?? '') . ' ' . ($this->data['last_name'] ?? '');
    }
    
    public function isActive() {
        return ($this->data['is_active'] ?? false) && ($this->data['deleted_at'] === null);
    }
}