<?php
// config/database.php
// Database connection using PDO with prepared statements

class Database {
    private static $instance = null;
    private $connection;
    private $statement;

    private $charset = 'utf8mb4';

    private function __construct() {
        try {
            if (!defined('DB_HOST')) {
                require_once __DIR__ . '/environment.php';
            }
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset={$this->charset}";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ];

            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log("Database Connection Error: " . $e->getMessage());
            http_response_code(503);
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
            // Bind with explicit types so integers (LIMIT/OFFSET, ids) stay integers
            foreach (array_values($params) as $i => $value) {
                if (is_int($value)) $type = PDO::PARAM_INT;
                elseif (is_bool($value)) { $type = PDO::PARAM_INT; $value = (int)$value; }
                elseif ($value === null) $type = PDO::PARAM_NULL;
                else $type = PDO::PARAM_STR;
                $key = is_string(array_keys($params)[$i]) ? array_keys($params)[$i] : $i + 1;
                $this->statement->bindValue($key, $value, $type);
            }
            $this->statement->execute();
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

    // Last inserted ID
    public function lastInsertId() {
        return $this->connection->lastInsertId();
    }

    // Number of rows changed by the last statement
    public function rowCount() {
        return $this->statement ? $this->statement->rowCount() : 0;
    }

    public function inTransaction() {
        return $this->connection->inTransaction();
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
        return $this->connection->inTransaction() ? $this->connection->rollBack() : false;
    }

    // Prevent cloning
    private function __clone() {}

    // Prevent unserialize
    public function __wakeup() {
        throw new Exception('Cannot unserialize a singleton');
    }
}

// Helper function for quick database access
if (!function_exists('db')) {
    function db() {
        return Database::getInstance();
    }
}