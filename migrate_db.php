<?php
require_once __DIR__ . '/config.php';
if (is_file(__DIR__ . '/vps.php')) {
    require_once __DIR__ . '/vps.php';
}

$host = defined('DB_HOST') ? DB_HOST : 'localhost';
$username = defined('DB_USER') ? DB_USER : 'root';
$password = defined('DB_PASS') ? DB_PASS : '';
$database = defined('DB_NAME') ? DB_NAME : 'bulkzen';

$conn = new mysqli($host, $username, $password);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create database if not exists
$sql = "CREATE DATABASE IF NOT EXISTS bulkzen";
if ($conn->query($sql) === TRUE) {
    echo "Database created successfully or already exists\n";
} else {
    die("Error creating database: " . $conn->error);
}

$conn->select_db($database);

echo "Connected successfully\n";

// Check if pages table exists
$checkTable = $conn->query("SHOW TABLES LIKE 'pages'");
if ($checkTable->num_rows == 0) {
    echo "Initializing database schema...\n";
    $schema = file_get_contents(__DIR__ . '/database.sql');
    if ($conn->multi_query($schema)) {
        do {
            // consume all results
            if ($result = $conn->store_result()) {
                $result->free();
            }
        } while ($conn->more_results() && $conn->next_result());
        echo "Schema initialized\n";
    } else {
        die("Error initializing schema: " . $conn->error);
    }
}

// Add columns to pages table
$sql = "SHOW COLUMNS FROM pages LIKE 'total_messages_sent'";
$result = $conn->query($sql);
if ($result->num_rows == 0) {
    $sql = "ALTER TABLE pages ADD COLUMN total_messages_sent INT NOT NULL DEFAULT 0";
    if ($conn->query($sql) === TRUE) {
        echo "Column 'total_messages_sent' added to 'pages' table\n";
    } else {
        echo "Error adding column: " . $conn->error . "\n";
    }
} else {
    echo "Column 'total_messages_sent' already exists\n";
}

$sql = "SHOW COLUMNS FROM pages LIKE 'subscription_expires_at'";
$result = $conn->query($sql);
if ($result->num_rows == 0) {
    $sql = "ALTER TABLE pages ADD COLUMN subscription_expires_at TIMESTAMP NULL DEFAULT NULL";
    if ($conn->query($sql) === TRUE) {
        echo "Column 'subscription_expires_at' added to 'pages' table\n";
    } else {
        echo "Error adding column: " . $conn->error . "\n";
    }
} else {
    echo "Column 'subscription_expires_at' already exists\n";
}

// Create orders table
$sql = "CREATE TABLE IF NOT EXISTS orders (
    id VARCHAR(64) NOT NULL PRIMARY KEY,
    page_id BIGINT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    currency VARCHAR(10) NOT NULL,
    status VARCHAR(20) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    binance_prepay_id VARCHAR(64),
    CONSTRAINT fk_orders_page FOREIGN KEY (page_id) REFERENCES pages(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql) === TRUE) {
    echo "Table 'orders' created or already exists\n";
} else {
    echo "Error creating table: " . $conn->error . "\n";
}

// Create user_pages table (mapping between users and pages)
$sql = "CREATE TABLE IF NOT EXISTS user_pages (
    user_id VARCHAR(64) NOT NULL,
    page_id BIGINT NOT NULL,
    PRIMARY KEY (user_id, page_id),
    CONSTRAINT fk_user_pages_page FOREIGN KEY (page_id) REFERENCES pages(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql) === TRUE) {
    echo "Table 'user_pages' created or already exists\n";
} else {
    echo "Error creating table: " . $conn->error . "\n";
}

// Create credits table
$sql = "CREATE TABLE IF NOT EXISTS credits (
    id VARCHAR(64) NOT NULL PRIMARY KEY,
    user_id VARCHAR(64) NOT NULL UNIQUE,
    monthly_credits INT NOT NULL DEFAULT 0,
    plan_type VARCHAR(20) NOT NULL DEFAULT 'free',
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql) === TRUE) {
    echo "Table 'credits' created or already exists\n";
} else {
    echo "Error creating table: " . $conn->error . "\n";
}

// Add plan_type column to credits table if it doesn't exist (for existing tables)
$sql = "SHOW COLUMNS FROM credits LIKE 'plan_type'";
$result = $conn->query($sql);
if ($result && $result->num_rows == 0) {
    $sql = "ALTER TABLE credits ADD COLUMN plan_type VARCHAR(20) NOT NULL DEFAULT 'free'";
    if ($conn->query($sql) === TRUE) {
        echo "Column 'plan_type' added to 'credits' table\n";
    } else {
        echo "Error adding column: " . $conn->error . "\n";
    }
}
?>
