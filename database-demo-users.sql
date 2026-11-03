-- Development only. Do not import this file into a public or production database.
-- This template has no usable default accounts. Before importing it:
-- 1. Replace each placeholder email with a unique development email address.
-- 2. Replace each placeholder password value with a hash generated locally by PHP password_hash().
-- Never use a production password or commit real password hashes.
--
-- Import database.sql first. Re-running this file does not change existing accounts.
USE vegetable_system;

INSERT INTO users (name, email, password, role, email_verified_at) VALUES
   (
        'Administrator',
        'replace-admin-email@example.invalid',
        'REPLACE_WITH_ADMIN_PASSWORD_HASH',
        'admin',
        CURRENT_TIMESTAMP
    ),
    (
        'Farm Staff',
        'replace-staff-email@example.invalid',
        'REPLACE_WITH_STAFF_PASSWORD_HASH',
        'staff',
        CURRENT_TIMESTAMP
    ),
    (
        'Buyer',
        'replace-buyer-email@example.invalid',
        'REPLACE_WITH_BUYER_PASSWORD_HASH',
        'buyer',
        CURRENT_TIMESTAMP
    )
ON DUPLICATE KEY UPDATE
    email = VALUES(email);
