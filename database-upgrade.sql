-- Run this against an existing vegetable_system database after backing it up.

SET @harvest_archive_column_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'harvests'
        AND COLUMN_NAME = 'archived_at'
);
SET @harvest_archive_migration = IF(
    @harvest_archive_column_exists = 0,
    'ALTER TABLE harvests ADD COLUMN archived_at DATETIME NULL AFTER quality_notes',
    'SELECT 1'
);
PREPARE harvest_archive_migration_statement FROM @harvest_archive_migration;
EXECUTE harvest_archive_migration_statement;
DEALLOCATE PREPARE harvest_archive_migration_statement;

-- Allow new sale items to remain unpaid until COD collection or a gateway webhook.
ALTER TABLE sales
    MODIFY COLUMN status ENUM('pending', 'paid', 'cancelled') NOT NULL DEFAULT 'pending';

-- Store order-wide totals separately from the existing sale-item rows.
CREATE TABLE IF NOT EXISTS orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_code VARCHAR(24) NOT NULL UNIQUE,
    buyer_id INT UNSIGNED NOT NULL,
    subtotal DECIMAL(10,2) NOT NULL,
    eligible_quantity DECIMAL(10,2) NOT NULL DEFAULT 0,
    discount_percentage DECIMAL(5,2) NOT NULL DEFAULT 0,
    discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
    final_total DECIMAL(10,2) NOT NULL,
    delivery_address TEXT NOT NULL,
    payment_method ENUM('cod', 'online', 'legacy') NOT NULL,
    payment_status ENUM('pending_payment', 'paid', 'failed', 'refunded') NOT NULL DEFAULT 'pending_payment',
    order_status ENUM(
        'pending',
        'confirmed',
        'preparing',
        'ready_for_delivery',
        'out_for_delivery',
        'delivered',
        'cancelled'
    ) NOT NULL DEFAULT 'pending',
    payment_reference VARCHAR(120) NULL,
    gateway_session_id VARCHAR(120) NULL,
    payment_confirmed_by INT UNSIGNED NULL,
    payment_confirmed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_orders_buyer_id (buyer_id),
    INDEX idx_orders_payment_status (payment_status),
    INDEX idx_orders_created_at (created_at),
    INDEX idx_orders_payment_reference (payment_reference),
    CONSTRAINT fk_orders_buyer
        FOREIGN KEY (buyer_id) REFERENCES users(id),
    CONSTRAINT fk_orders_payment_confirmer
        FOREIGN KEY (payment_confirmed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payment_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_code VARCHAR(24) NOT NULL,
    payment_method ENUM('cod', 'online', 'legacy') NOT NULL,
    event_type VARCHAR(60) NOT NULL,
    payment_status ENUM('pending_payment', 'paid', 'failed', 'refunded') NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_reference VARCHAR(120) NULL,
    provider_event_id VARCHAR(160) NULL UNIQUE,
    actor_id INT UNSIGNED NULL,
    details VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_payment_history_order (order_code, created_at),
    CONSTRAINT fk_payment_history_order
        FOREIGN KEY (order_code) REFERENCES orders(order_code) ON DELETE CASCADE,
    CONSTRAINT fk_payment_history_actor
        FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Preserve existing order groups with their historical totals and statuses.
INSERT INTO orders (
    order_code,
    buyer_id,
    subtotal,
    eligible_quantity,
    discount_percentage,
    discount_amount,
    delivery_fee,
    final_total,
    delivery_address,
    payment_method,
    payment_status,
    order_status,
    created_at
)
SELECT
    s.order_code,
    s.buyer_id,
    SUM(s.total),
    SUM(s.quantity),
    0,
    0,
    0,
    SUM(s.total),
    'No delivery address was recorded for this historical order.',
    'legacy',
    CASE
        WHEN SUM(s.status = 'paid') = COUNT(*) THEN 'paid'
        WHEN SUM(s.status = 'cancelled') > 0 THEN 'failed'
        ELSE 'pending_payment'
    END,
    CASE
        WHEN SUM(s.status = 'cancelled') > 0 THEN 'cancelled'
        ELSE 'confirmed'
    END,
    MIN(s.created_at)
FROM sales AS s
GROUP BY s.order_code, s.buyer_id
ON DUPLICATE KEY UPDATE order_code = VALUES(order_code);

CREATE TABLE IF NOT EXISTS activity_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    action VARCHAR(80) NOT NULL,
    entity_type VARCHAR(60) NOT NULL,
    entity_id INT UNSIGNED NULL,
    details VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_activity_logs_created_at (created_at),
    INDEX idx_activity_logs_user_id (user_id),
    CONSTRAINT fk_activity_logs_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
