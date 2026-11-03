-- Development only. Do not import this file into a public or production database.
-- This template has no usable default accounts. Before importing it:
-- 1. Replace each placeholder email with a unique development email address.
-- 2. Replace each placeholder password value with a hash generated locally by PHP password_hash().
-- Never use a production password or commit real password hashes.
--
-- Import database.sql first. Re-running this file resets the configured development passwords.
USE vegetable_system;

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
