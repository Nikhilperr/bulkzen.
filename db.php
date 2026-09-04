<?php
/**
 * Database Connection Helper
 */
function getDbConnection() {
    static $conn;
    if ($conn) return $conn;

    if (is_file(__DIR__ . '/vps.php')) {
        require_once __DIR__ . '/vps.php';
    }

    $host = defined('DB_HOST') ? DB_HOST : 'localhost';
    $username = defined('DB_USER') ? DB_USER : 'root';
    $password = defined('DB_PASS') ? DB_PASS : '';
    $database = defined('DB_NAME') ? DB_NAME : 'bulkzen';
    
    // Create connection
    try {
        $conn = new mysqli($host, $username, $password, $database);
        if ($conn->connect_error) {
            throw new Exception("Database connection failed: " . $conn->connect_error);
        }
    } catch (Exception $e) {
        // If database doesn't exist, try connecting without it to create it (migration scenario)
        // But for normal app usage, we expect it to exist.
        throw $e;
    }
    
    return $conn;
}

function bulkzen_has_column($conn, $table, $column) {
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $column = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
    $res = @$conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    $cache[$key] = $res && $res->num_rows > 0;
    return $cache[$key];
}
