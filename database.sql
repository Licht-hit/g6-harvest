CREATE DATABASE IF NOT EXISTS vegetable_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE vegetable_system;

-- Stores admin, staff, farmer, and buyer login accounts.
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'staff', 'farmer', 'buyer') NOT NULL DEFAULT 'buyer',
    email_verified_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Add the verification date column when upgrading an older database.
SET @email_verified_column_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'users'
        AND COLUMN_NAME = 'email_verified_at'
);
SET @email_verified_migration = IF(
    @email_verified_column_exists = 0,
    'ALTER TABLE users ADD COLUMN email_verified_at DATETIME NULL AFTER role',
    'SELECT 1'
);
PREPARE email_verified_migration_statement FROM @email_verified_migration;
EXECUTE email_verified_migration_statement;
DEALLOCATE PREPARE email_verified_migration_statement;

-- Stores one-time, expiring email verification tokens.
CREATE TABLE IF NOT EXISTS email_verification_tokens (
    user_id INT UNSIGNED PRIMARY KEY,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_verification_tokens_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Stores one-time, expiring password reset tokens.
CREATE TABLE IF NOT EXISTS password_reset_tokens (
    user_id INT UNSIGNED PRIMARY KEY,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_password_reset_tokens_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Stores products that can be planted, harvested, and sold.
CREATE TABLE IF NOT EXISTS vegetables (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    category VARCHAR(80) NOT NULL DEFAULT 'Other',
    image_path VARCHAR(255) NULL,
    variety VARCHAR(120) NULL,
    description TEXT NULL,
    unit VARCHAR(30) NOT NULL DEFAULT 'kg',
    price DECIMAL(10,2) NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_vegetables_category (category)
) ENGINE=InnoDB;

-- Stores when and how much of each vegetable was planted.
CREATE TABLE IF NOT EXISTS plantings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vegetable_id INT UNSIGNED NOT NULL,
    quantity_planted DECIMAL(10,2) NOT NULL,
    planted_on DATE NOT NULL,
    expected_harvest_on DATE NULL,
    status ENUM('growing', 'harvested', 'cancelled') NOT NULL DEFAULT 'growing',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_plantings_vegetable
        FOREIGN KEY (vegetable_id) REFERENCES vegetables(id)
) ENGINE=InnoDB;

-- Stores harvest quantities and notes for each planting.
CREATE TABLE IF NOT EXISTS harvests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    planting_id INT UNSIGNED NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    harvested_on DATE NOT NULL,
    quality_notes TEXT NULL,
    archived_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_harvests_planting
        FOREIGN KEY (planting_id) REFERENCES plantings(id)
) ENGINE=InnoDB;

-- Add the archive date when upgrading an existing database.
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

-- Stores the current quantity available for each vegetable.
CREATE TABLE IF NOT EXISTS inventory (
    vegetable_id INT UNSIGNED PRIMARY KEY,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_inventory_vegetable
        FOREIGN KEY (vegetable_id) REFERENCES vegetables(id)
) ENGINE=InnoDB;

-- Keeps an auditable record of each stock change, including the stock snapshot.
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

-- Stores one row per item in a buyer's order.
CREATE TABLE IF NOT EXISTS sales (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_code VARCHAR(24) NOT NULL,
    buyer_id INT UNSIGNED NOT NULL,
    vegetable_id INT UNSIGNED NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    total DECIMAL(10,2) NOT NULL,
    status ENUM('pending', 'paid', 'cancelled') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sales_order_code (order_code),
    CONSTRAINT fk_sales_buyer
        FOREIGN KEY (buyer_id) REFERENCES users(id),
    CONSTRAINT fk_sales_vegetable
        FOREIGN KEY (vegetable_id) REFERENCES vegetables(id)
) ENGINE=InnoDB;

-- Stores order-wide totals and delivery/payment details; sales stores each item.
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

-- Keeps a history of payment creation and payment status changes.
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

-- Stores quality grades and notes for harvested produce.
CREATE TABLE IF NOT EXISTS quality_inspections (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    harvest_id INT UNSIGNED NOT NULL,
    inspector_id INT UNSIGNED NOT NULL,
    grade ENUM('A', 'B', 'C', 'Rejected') NOT NULL,
    notes TEXT NULL,
    inspected_on DATE NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_inspections_harvest
        FOREIGN KEY (harvest_id) REFERENCES harvests(id),
    CONSTRAINT fk_inspections_inspector
        FOREIGN KEY (inspector_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- Records important actions for accountability and review.
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

-- Stores produce saved by each buyer.
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

INSERT INTO vegetables (id, name, category, variety, description, unit, price, active) VALUES
    (1, 'Tomato', 'Fruiting vegetables', 'Roma', 'Firm, flavorful tomatoes, ideal for salads and cooking.', 'kg', 40.50, 1),
    (2, 'Carrot', 'Root vegetables', 'Nantes', 'Sweet, crisp carrots freshly harvested from the farm.', 'kg', 25.25, 1),
    (3, 'Lettuce', 'Leafy greens', 'Butterhead', 'Tender leafy lettuce, harvested to order.', 'head', 10.75, 1),
    (4, 'Cucumber', 'Cucurbits', 'Marketmore', 'Crisp cucumbers for salads, sandwiches, and pickling.', 'kg', 10.80, 1),
    (5, 'Spinach', 'Leafy greens', 'Baby Leaf', 'Young, tender spinach leaves, packed fresh.', 'bunch', 15.00, 1),
    (6, 'Bell Pepper', 'Fruiting vegetables', 'California Wonder', 'Colorful sweet peppers with a crisp texture.', 'kg', 40.25, 1),
    (7, 'Zucchini', 'Cucurbits', 'Black Beauty', 'Fresh green summer squash, versatile for cooking.', 'kg', 20.60, 1),
    (8, 'Broccoli', 'Cruciferous vegetables', 'Calabrese', 'Compact green florets, rich in flavor.', 'head', 20.40, 1),
    (9, 'Kale', 'Leafy greens', 'Curly Green', 'Hearty leafy greens for salads, soups, and sautés.', 'bunch', 20.15, 1),
    (10, 'Green Beans', 'Legumes', 'Provider', 'Tender green beans, picked at peak freshness.', 'kg', 30.20, 1)
ON DUPLICATE KEY UPDATE
    id = VALUES(id);

INSERT INTO inventory (vegetable_id, quantity) VALUES
    (1, 45.00),
    (2, 60.00),
    (3, 32.00),
    (4, 38.00),
    (5, 25.00),
    (6, 28.00),
    (7, 40.00),
    (8, 24.00),
    (9, 20.00),
    (10, 30.00)
ON DUPLICATE KEY UPDATE vegetable_id = VALUES(vegetable_id);

-- Add three sample plantings if they are not already in the database.
INSERT INTO plantings (vegetable_id, quantity_planted, planted_on, expected_harvest_on, status)
SELECT
    v.id,
    80.00,
    '2026-09-10',
    '2026-11-20',
    'growing'
FROM vegetables AS v
WHERE v.id = 1
    AND NOT EXISTS (
        SELECT 1
        FROM plantings AS p
        WHERE p.vegetable_id = v.id
            AND p.planted_on = '2026-09-10'
    );

INSERT INTO plantings (vegetable_id, quantity_planted, planted_on, expected_harvest_on, status)
SELECT
    v.id,
    100.00,
    '2026-09-15',
    '2026-11-15',
    'growing'
FROM vegetables AS v
WHERE v.id = 2
    AND NOT EXISTS (
        SELECT 1
        FROM plantings AS p
        WHERE p.vegetable_id = v.id
            AND p.planted_on = '2026-09-15'
    );

INSERT INTO plantings (vegetable_id, quantity_planted, planted_on, expected_harvest_on, status)
SELECT
    v.id,
    50.00,
    '2026-10-01',
    '2026-11-05',
    'growing'
FROM vegetables AS v
WHERE v.id = 3
    AND NOT EXISTS (
        SELECT 1
        FROM plantings AS p
        WHERE p.vegetable_id = v.id
            AND p.planted_on = '2026-10-01'
    );
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
