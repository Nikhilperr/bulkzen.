-- BulkZen schema (MySQL)
CREATE TABLE IF NOT EXISTS users (
    id CHAR(36) NOT NULL PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pages (
    id BIGINT NOT NULL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    verified TINYINT(1) NOT NULL DEFAULT 0,
    free_used TINYINT(1) NOT NULL DEFAULT 0,
    first_free_used_at TIMESTAMP NULL DEFAULT NULL,
    total_messages_sent INT NOT NULL DEFAULT 0,
    subscription_expires_at TIMESTAMP NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
    id VARCHAR(64) NOT NULL PRIMARY KEY,
    page_id BIGINT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    currency VARCHAR(10) NOT NULL,
    status VARCHAR(20) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    binance_prepay_id VARCHAR(64),
    CONSTRAINT fk_orders_page FOREIGN KEY (page_id) REFERENCES pages(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_pages (
    id CHAR(36) NOT NULL PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    page_id BIGINT NOT NULL,
    role VARCHAR(32) NOT NULL,
    connected_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_pages_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_user_pages_page FOREIGN KEY (page_id) REFERENCES pages(id),
    INDEX idx_user_pages_user (user_id),
    INDEX idx_user_pages_page (page_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS credits (
    id CHAR(36) NOT NULL PRIMARY KEY,
    user_id CHAR(36) NOT NULL UNIQUE,
    monthly_credits INT NOT NULL DEFAULT 0,
    extra_credits INT NOT NULL DEFAULT 0,
    free_credits INT NOT NULL DEFAULT 3000,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_credits_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subscriptions (
    id CHAR(36) NOT NULL PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    status VARCHAR(16) NOT NULL,
    started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL DEFAULT NULL,
    next_billing_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_subscriptions_user FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_subscriptions_user (user_id),
    INDEX idx_subscriptions_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transactions (
    id CHAR(36) NOT NULL PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    type VARCHAR(32) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    credits_added INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    metadata JSON,
    CONSTRAINT fk_transactions_user FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_transactions_user (user_id),
    INDEX idx_transactions_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messages_log (
    id CHAR(36) NOT NULL PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    page_id BIGINT NOT NULL,
    recipient_id VARCHAR(64) NOT NULL,
    message_text TEXT,
    credits_used INT NOT NULL DEFAULT 1,
    status VARCHAR(16) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_messages_log_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_messages_log_page FOREIGN KEY (page_id) REFERENCES pages(id),
    INDEX idx_messages_log_user (user_id),
    INDEX idx_messages_log_page (page_id),
    INDEX idx_messages_log_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- helper view for credit totals
CREATE OR REPLACE VIEW credit_totals AS
SELECT
    user_id,
    free_credits,
    monthly_credits,
    extra_credits,
    (free_credits + monthly_credits + extra_credits) AS total_available
FROM credits;

