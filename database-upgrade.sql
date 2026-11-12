-- Run this against an existing vegetable_system database after backing it up.

-- Farmers have the same farm-operation permissions as staff.
ALTER TABLE users
    MODIFY COLUMN role ENUM('admin', 'staff', 'farmer', 'buyer') NOT NULL DEFAULT 'buyer';

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

-- Add produce categories without changing existing vegetable records.
SET @vegetable_category_column_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'vegetables'
        AND COLUMN_NAME = 'category'
);
SET @vegetable_category_migration = IF(
    @vegetable_category_column_exists = 0,
    'ALTER TABLE vegetables ADD COLUMN category VARCHAR(80) NOT NULL DEFAULT ''Other'' AFTER name',
    'SELECT 1'
);
PREPARE vegetable_category_migration_statement FROM @vegetable_category_migration;
EXECUTE vegetable_category_migration_statement;
DEALLOCATE PREPARE vegetable_category_migration_statement;

-- Store an optional uploaded product photo for each vegetable.
SET @vegetable_photo_column_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'vegetables'
        AND COLUMN_NAME = 'image_path'
);
SET @vegetable_photo_migration = IF(
    @vegetable_photo_column_exists = 0,
    'ALTER TABLE vegetables ADD COLUMN image_path VARCHAR(255) NULL AFTER category',
    'SELECT 1'
);
PREPARE vegetable_photo_migration_statement FROM @vegetable_photo_migration;
EXECUTE vegetable_photo_migration_statement;
DEALLOCATE PREPARE vegetable_photo_migration_statement;

SET @vegetable_category_index_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'vegetables'
        AND INDEX_NAME = 'idx_vegetables_category'
);
SET @vegetable_category_index_migration = IF(
    @vegetable_category_index_exists = 0,
    'ALTER TABLE vegetables ADD INDEX idx_vegetables_category (category)',
    'SELECT 1'
);
PREPARE vegetable_category_index_statement FROM @vegetable_category_index_migration;
EXECUTE vegetable_category_index_statement;
DEALLOCATE PREPARE vegetable_category_index_statement;

SET @vegetable_category_backfill = IF(
    @vegetable_category_column_exists = 0,
    'UPDATE vegetables
     SET category = CASE LOWER(name)
         WHEN ''tomato'' THEN ''Fruiting vegetables''
         WHEN ''bell pepper'' THEN ''Fruiting vegetables''
         WHEN ''carrot'' THEN ''Root vegetables''
         WHEN ''lettuce'' THEN ''Leafy greens''
         WHEN ''spinach'' THEN ''Leafy greens''
         WHEN ''kale'' THEN ''Leafy greens''
         WHEN ''cucumber'' THEN ''Cucurbits''
         WHEN ''zucchini'' THEN ''Cucurbits''
         WHEN ''broccoli'' THEN ''Cruciferous vegetables''
         WHEN ''green beans'' THEN ''Legumes''
         ELSE ''Other''
     END',
    'SELECT 1'
);
PREPARE vegetable_category_backfill_statement FROM @vegetable_category_backfill;
EXECUTE vegetable_category_backfill_statement;
DEALLOCATE PREPARE vegetable_category_backfill_statement;

-- Store one-time, expiring password reset tokens for existing installations.
CREATE TABLE IF NOT EXISTS password_reset_tokens (
    user_id INT UNSIGNED PRIMARY KEY,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_password_reset_tokens_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Allow new sale items to remain unpaid until COD collection or a gateway webhook.
ALTER TABLE sales
    MODIFY COLUMN status ENUM('pending', 'paid', 'cancelled') NOT NULL DEFAULT 'pending';

-- Store order-wide totals separately from the existing sale-item rows.
CREATE TABLE IF NOT EXISTS orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_code VARCHAR(24) NOT NULL UNIQUE,
    buyer_id INT UNSIGNED NOT NULL,
    checkout_token_hash CHAR(64) NULL,
    checkout_request_hash CHAR(64) NULL,
    gateway_checkout_url VARCHAR(1000) NULL,
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
    UNIQUE KEY uq_orders_buyer_checkout_token (buyer_id, checkout_token_hash),
    CONSTRAINT fk_orders_buyer
        FOREIGN KEY (buyer_id) REFERENCES users(id),
    CONSTRAINT fk_orders_payment_confirmer
        FOREIGN KEY (payment_confirmed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

SET @checkout_token_column_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'orders'
        AND COLUMN_NAME = 'checkout_token_hash'
);
SET @checkout_token_column_migration = IF(
    @checkout_token_column_exists = 0,
    'ALTER TABLE orders ADD COLUMN checkout_token_hash CHAR(64) NULL AFTER buyer_id',
    'SELECT 1'
);
PREPARE checkout_token_column_statement FROM @checkout_token_column_migration;
EXECUTE checkout_token_column_statement;
DEALLOCATE PREPARE checkout_token_column_statement;

SET @checkout_request_column_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'orders'
        AND COLUMN_NAME = 'checkout_request_hash'
);
SET @checkout_request_column_migration = IF(
    @checkout_request_column_exists = 0,
    'ALTER TABLE orders ADD COLUMN checkout_request_hash CHAR(64) NULL AFTER checkout_token_hash',
    'SELECT 1'
);
PREPARE checkout_request_column_statement FROM @checkout_request_column_migration;
EXECUTE checkout_request_column_statement;
DEALLOCATE PREPARE checkout_request_column_statement;

SET @checkout_url_column_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'orders'
        AND COLUMN_NAME = 'gateway_checkout_url'
);
SET @checkout_url_column_migration = IF(
    @checkout_url_column_exists = 0,
    'ALTER TABLE orders ADD COLUMN gateway_checkout_url VARCHAR(1000) NULL AFTER checkout_request_hash',
    'SELECT 1'
);
PREPARE checkout_url_column_statement FROM @checkout_url_column_migration;
EXECUTE checkout_url_column_statement;
DEALLOCATE PREPARE checkout_url_column_statement;

SET @checkout_token_index_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'orders'
        AND INDEX_NAME = 'uq_orders_buyer_checkout_token'
);
SET @checkout_token_index_migration = IF(
    @checkout_token_index_exists = 0,
    'ALTER TABLE orders ADD UNIQUE KEY uq_orders_buyer_checkout_token (buyer_id, checkout_token_hash)',
    'SELECT 1'
);
PREPARE checkout_token_index_statement FROM @checkout_token_index_migration;
EXECUTE checkout_token_index_statement;
DEALLOCATE PREPARE checkout_token_index_statement;

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

CREATE TABLE IF NOT EXISTS inventory_movements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vegetable_id INT UNSIGNED NULL,
    vegetable_name VARCHAR(120) NOT NULL,
    unit VARCHAR(30) NOT NULL,
    movement_type VARCHAR(40) NOT NULL,
    quantity_before DECIMAL(10,2) NOT NULL,
    quantity_after DECIMAL(10,2) NOT NULL,
    quantity_change DECIMAL(10,2) NOT NULL,
    reason VARCHAR(255) NOT NULL,
    reference_code VARCHAR(64) NULL,
    actor_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_inventory_movements_created_at (created_at, id),
    INDEX idx_inventory_movements_vegetable (vegetable_id, created_at),
    INDEX idx_inventory_movements_type (movement_type, created_at),
    INDEX idx_inventory_movements_actor (actor_id, created_at),
    CONSTRAINT fk_inventory_movements_vegetable
        FOREIGN KEY (vegetable_id) REFERENCES vegetables(id) ON DELETE SET NULL,
    CONSTRAINT fk_inventory_movements_actor
        FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Store each buyer's saved produce for the customer dashboard and shop.
CREATE TABLE IF NOT EXISTS buyer_favorites (
    buyer_id INT UNSIGNED NOT NULL,
    vegetable_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (buyer_id, vegetable_id),
    INDEX idx_buyer_favorites_vegetable (vegetable_id),
    CONSTRAINT fk_buyer_favorites_buyer
        FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_buyer_favorites_vegetable
        FOREIGN KEY (vegetable_id) REFERENCES vegetables(id) ON DELETE CASCADE
) ENGINE=InnoDB;
