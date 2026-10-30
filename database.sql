CREATE DATABASE IF NOT EXISTS vegetable_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE vegetable_system;

-- Stores admin, staff, and buyer login accounts.
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'staff', 'buyer') NOT NULL DEFAULT 'buyer',
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

-- Stores products that can be planted, harvested, and sold.
CREATE TABLE IF NOT EXISTS vegetables (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    variety VARCHAR(120) NULL,
    description TEXT NULL,
    unit VARCHAR(30) NOT NULL DEFAULT 'kg',
    price DECIMAL(10,2) NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
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

-- Stores one row per item in a buyer's order.
CREATE TABLE IF NOT EXISTS sales (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_code VARCHAR(24) NOT NULL,
    buyer_id INT UNSIGNED NOT NULL,
    vegetable_id INT UNSIGNED NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    total DECIMAL(10,2) NOT NULL,
    status ENUM('paid', 'cancelled') NOT NULL DEFAULT 'paid',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sales_order_code (order_code),
    CONSTRAINT fk_sales_buyer
        FOREIGN KEY (buyer_id) REFERENCES users(id),
    CONSTRAINT fk_sales_vegetable
        FOREIGN KEY (vegetable_id) REFERENCES vegetables(id)
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

-- Sample login accounts:
-- Administrator: admin@example.com / admin123
-- Farm staff:    staff@example.com / staff123
-- Buyer:         buyer@example.com / buyer123
--
-- Passwords are stored as hashes so the login page can verify them securely.
-- Re-running this section resets these three sample account passwords.
INSERT INTO users (name, email, password, role, email_verified_at) VALUES
    (
        'Administrator',
        'admin@example.com',
        '$2y$12$EJIjdr.v28JaSK8JF8hd3OagUg8fc9TedG6b8EhCcA1LiZ77W53FO',
        'admin',
        CURRENT_TIMESTAMP
    ),
    (
        'Farm Staff',
        'staff@example.com',
        '$2y$12$j5PHQ6x6wEBmF5pPVSwc7.ZM3RatTLQPtA5pyBTwnMmed0HMr5ZF.',
        'staff',
        CURRENT_TIMESTAMP
    ),
    (
        'Buyer',
        'buyer@example.com',
        '$2y$12$pTX1NQd82aAncafWUa.t.upJ0eAqj7CtwWNZO/2cj1h4WYPoTqAne',
        'buyer',
        CURRENT_TIMESTAMP
    )
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    password = VALUES(password),
    role = VALUES(role),
    email_verified_at = VALUES(email_verified_at);

INSERT INTO vegetables (id, name, variety, description, unit, price, active) VALUES
    (1, 'Tomato', 'Roma', 'Firm, flavorful tomatoes, ideal for salads and cooking.', 'kg', 3.50, 1),
    (2, 'Carrot', 'Nantes', 'Sweet, crisp carrots freshly harvested from the farm.', 'kg', 2.25, 1),
    (3, 'Lettuce', 'Butterhead', 'Tender leafy lettuce, harvested to order.', 'head', 1.75, 1),
    (4, 'Cucumber', 'Marketmore', 'Crisp cucumbers for salads, sandwiches, and pickling.', 'kg', 2.80, 1),
    (5, 'Spinach', 'Baby Leaf', 'Young, tender spinach leaves, packed fresh.', 'bunch', 2.00, 1),
    (6, 'Bell Pepper', 'California Wonder', 'Colorful sweet peppers with a crisp texture.', 'kg', 4.25, 1),
    (7, 'Zucchini', 'Black Beauty', 'Fresh green summer squash, versatile for cooking.', 'kg', 2.60, 1),
    (8, 'Broccoli', 'Calabrese', 'Compact green florets, rich in flavor.', 'head', 2.40, 1),
    (9, 'Kale', 'Curly Green', 'Hearty leafy greens for salads, soups, and sautés.', 'bunch', 2.15, 1),
    (10, 'Green Beans', 'Provider', 'Tender green beans, picked at peak freshness.', 'kg', 3.20, 1)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    variety = VALUES(variety),
    description = VALUES(description),
    unit = VALUES(unit),
    price = VALUES(price),
    active = VALUES(active);

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
ON DUPLICATE KEY UPDATE quantity = VALUES(quantity);

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
