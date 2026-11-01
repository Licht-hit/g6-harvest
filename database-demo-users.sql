-- Development only. Do not import this file into a public or production database.
-- Sample login accounts:
-- Administrator: admin@example.com / admin123
-- Farm staff:    staff@example.com / staff123
-- Buyer:         buyer@example.com / buyer123
--
-- Import database.sql first. Re-running this file resets these sample passwords.
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
