<?php
// config/database.php
// Database connection using PDO with prepared statements

class Database {
    private static $instance = null;
    private $connection;
    private $statement;
    
    private $host = 'localhost';
    private $dbname = 'st_benedicts_academy';
    private $username = 'root'; // Change in production
    private $password = ''; // Change in production
    private $charset = 'utf8mb4';
    
    private function __construct() {
        try {
            $dsn = "mysql:host={$this->host};dbname={$this->dbname};charset={$this->charset}";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ];
            
            $this->connection = new PDO($dsn, $this->username, $this->password, $options);
        } catch (PDOException $e) {
            error_log("Database Connection Error: " . $e->getMessage());
            die("Database connection failed. Please try again later.");
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
    
    // Prepare and execute query with parameters
    public function query($sql, $params = []) {
        try {
            $this->statement = $this->connection->prepare($sql);
            $this->statement->execute($params);
            return $this->statement;
        } catch (PDOException $e) {
            error_log("Query Error: " . $e->getMessage() . " SQL: " . $sql);
            throw new Exception("Database query failed");
        }
    }
    
    // Get single row
    public function getRow($sql, $params = []) {
        return $this->query($sql, $params)->fetch();
    }
    
    // Get multiple rows
    public function getRows($sql, $params = []) {
        return $this->query($sql, $params)->fetchAll();
    }
    
    // Insert and get last insert ID
    public function insert($sql, $params = []) {
        $this->query($sql, $params);
        return $this->connection->lastInsertId();
    }
    
    // Begin transaction
    public function beginTransaction() {
        return $this->connection->beginTransaction();
    }
    
    // Commit transaction
    public function commit() {
        return $this->connection->commit();
    }
    
    // Rollback transaction
    public function rollback() {
        return $this->connection->rollback();
    }
    
    // Prevent cloning
    private function __clone() {}
    
    // Prevent unserialize
    public function __wakeup() {}
}

// Helper function for quick database access
function db() {
    return Database::getInstance();
}