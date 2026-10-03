<?php
/**
 * MarketMoke - Global Database Connection (PDO)
 * Enterprise-grade PDO singleton with strict prepared statements,
 * full UTF-8 mb4 encoding, and robust error handling.
 */

require_once dirname(__DIR__) . '/config.php';

class Database
{
    private static ?Database $instance = null;
    private ?PDO $conn = null;

    private function __construct()
    {
        $host = defined('DB_HOST') ? DB_HOST : 'localhost';
        $port = defined('DB_PORT') ? DB_PORT : 3306;
        $dbname = defined('DB_NAME') ? DB_NAME : 'marketmoke_db';
        $charset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';
        $user = defined('DB_USER') ? DB_USER : 'root';
        $pass = defined('DB_PASS') ? DB_PASS : '';

        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false, // Forces native prepared statements for SQL injection prevention
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset} COLLATE utf8mb4_unicode_ci",
            PDO::ATTR_PERSISTENT => false,
            PDO::ATTR_TIMEOUT => 5
        ];

        try {
            $this->conn = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            error_log("[MarketMoke DB Error] Connection failed: " . $e->getMessage());

            // Check if request expects JSON (API request)
            if (
                (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) ||
                (!empty($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/api/') !== false)
            ) {
                http_response_code(500);
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode([
                    'status' => 'error',
                    'code' => 500,
                    'message' => 'Internal database service is temporarily unavailable.'
                ]);
                exit();
            }

            die("MarketMoke Database Error: Unable to establish secure database connection.");
        }
    }

    /**
     * Singleton Instance Getter
     */
    public static function getInstance(): Database
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get active PDO connection object
     */
    public function getConnection(): PDO
    {
        return $this->conn;
    }

    // Prevent object cloning & unserialization
    private function __clone()
    {
    }
    public function __wakeup()
    {
        throw new Exception("Cannot unserialize singleton Database instance.");
    }
}

/**
 * =========================================================================
 * SECURE QUERY HELPER FUNCTIONS (SQL Injection Prevention & Safe Execution)
 * =========================================================================
 */

/**
 * Execute a parameterized query and return the PDOStatement
 */
function dbQuery(string $sql, array $params = []): PDOStatement
{
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/**
 * Fetch a single row safely using prepared statements
 */
function dbFetchOne(string $sql, array $params = []): ?array
{
    $stmt = dbQuery($sql, $params);
    $result = $stmt->fetch();
    return $result ?: null;
}

/**
 * Fetch all matching rows safely using prepared statements
 */
function dbFetchAll(string $sql, array $params = []): array
{
    $stmt = dbQuery($sql, $params);
    return $stmt->fetchAll();
}

/**
 * Insert record into table with strictly parameterized columns
 */
function dbInsert(string $table, array $data): int
{
    $db = Database::getInstance()->getConnection();

    // Whitelist table name characters
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        throw new InvalidArgumentException("Invalid table name supplied.");
    }

    $columns = array_keys($data);
    $escapedCols = array_map(fn($col) => "`" . str_replace("`", "", $col) . "`", $columns);
    $placeholders = array_map(fn($col) => ":" . $col, $columns);

    $sql = "INSERT INTO `{$table}` (" . implode(', ', $escapedCols) . ") VALUES (" . implode(', ', $placeholders) . ")";
    $stmt = $db->prepare($sql);

    $boundParams = [];
    foreach ($data as $col => $val) {
        $boundParams[':' . $col] = $val;
    }
    $stmt->execute($boundParams);

    return (int) $db->lastInsertId();
}

/**
 * Update records safely with parameterized values and WHERE conditions
 */
function dbUpdate(string $table, array $data, string $whereClause, array $whereParams = []): int
{
    $db = Database::getInstance()->getConnection();

    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        throw new InvalidArgumentException("Invalid table name supplied.");
    }

    $setClauses = [];
    $boundParams = [];
    foreach ($data as $col => $val) {
        $paramKey = ':set_' . $col;
        $setClauses[] = "`" . str_replace("`", "", $col) . "` = {$paramKey}";
        $boundParams[$paramKey] = $val;
    }

    // Merge WHERE parameters
    foreach ($whereParams as $k => $v) {
        $boundParams[$k] = $v;
    }

    $sql = "UPDATE `{$table}` SET " . implode(', ', $setClauses) . " WHERE {$whereClause}";
    $stmt = $db->prepare($sql);
    $stmt->execute($boundParams);

    return $stmt->rowCount();
}

/**
 * Sanitize email address
 */
function sanitizeEmail(string $email): string
{
    $clean = filter_var(trim($email), FILTER_SANITIZE_EMAIL);
    return strtolower($clean ?: '');
}

/**
 * Sanitize plain string for safe HTML display (XSS defense)
 */
function sanitizeString(string $input): string
{
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Sanitize integer
 */
function sanitizeInt($val): int
{
    return (int) filter_var($val, FILTER_VALIDATE_INT);
}
