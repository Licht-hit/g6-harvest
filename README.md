# G6 Harvest

A PHP and MySQL farm operations and produce shop project.

## Requirements

- PHP with the `mysqli` and `mysqlnd` extensions enabled
- MySQL or MariaDB
- Apache or another web server that runs PHP

## Local setup

1. Put the project in your web server's document folder and start Apache and MySQL.
2. Import `database.sql` into MySQL for a new installation.
3. Configure `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` in the server environment. The local defaults in `config/database.php` are for a typical XAMPP setup only.
4. Open the project in a browser and sign in with one of the sample accounts shown in `database.sql`. Change sample passwords before using the application outside a local demo.
5. To test from a phone, use the computer's local IP address while both devices are on the same Wi-Fi network.

If upgrading an existing database, back it up and run `database-upgrade.sql`. Importing the complete `database.sql` again also reapplies the sample data.

## Database code

`config/database.php` creates a MySQLi connection and sets UTF-8 encoding. The small `MySQLiDatabase` and `MySQLiStatement` helpers keep the page code straightforward; prepared statements bind values through MySQLi. Use `$db->prepare()` for statements that include user input.

## Main features

- Buyer, staff, and administrator roles with server-side access checks
- Registration, login, logout, password hashing, session protection, and email verification
- Vegetable catalog, plantings, harvests, and quality inspection records
- Edit and archive actions for vegetables, growing plantings, and harvests; historical records are retained
- Checkout updates inventory in a database transaction and buyers can only view their own orders
- Dashboard summaries, searchable vegetable records, filtered reports, and a print-friendly report view
- Administrator-only activity log for account and farm operations
- CSRF tokens, input validation, prepared database statements, and escaped HTML output

## Data structure used

Checkout in `buyer/checkout.php` builds the `$byId` associative array using each vegetable ID as a key. This is a hash-table lookup used to quickly match each cart item with its current price and stock.

- Building the lookup for `n` products takes O(n) time and O(n) additional space.
- Looking up a product by ID takes O(1) time on average; a hash table can take O(n) in the worst case.

This structure is used in checkout validation and total calculation, rather than being included only as a demonstration.

## Email and SMS

Email verification supports a configured SMTP sender and expiring one-time links. SMS notifications are optional in the project requirements and are not needed for the core workflows.
