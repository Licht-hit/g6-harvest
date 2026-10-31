# G6 Harvest

A PHP and MySQL farm operations and produce shop project.

## Start here

This project uses PHP to build pages and handle form submissions. MySQL stores accounts, vegetables, plantings, harvests, inventory, and orders. The browser uses HTML for page content, CSS for appearance, and JavaScript for small interactions.

### Project map

- `index.php` sends a signed-in user to the correct starting page.
- `auth/` contains login, registration, logout, and email verification pages.
- `buyer/` contains the produce shop, cart, checkout, and order pages.
- `modules/` contains farm staff pages for vegetables, plantings, harvests, inventory, quality checks, sales, reports, and activity.
- `includes/auth.php` starts the session and loads shared code.
- `includes/functions.php` contains helpers shared by many pages, such as URL building, HTML escaping, form protection, and cart helpers.
- `includes/header.php` and `includes/footer.php` contain the shared page layout.
- `config/database.php` connects to MySQL. `config/email.php` reads email settings.
- `assets/css/style.css` controls appearance. `assets/js/script.js` handles small browser interactions.
- `database.sql` creates the database tables and sample data. `database-upgrade.sql` updates an existing database.

### How a PHP page works

Most pages follow the same steps:

1. Load `includes/auth.php`. It starts the session and loads the database and shared helpers.
2. Check the user's role when the page requires a signed-in user.
3. If a form was submitted, check its CSRF token, validate the input, then use a prepared database query.
4. Load the records the page needs.
5. Set `$pageTitle`, include the shared header, display the page's HTML, then include the shared footer.

When showing database or user-provided text in HTML, use `h($value)` to escape it. When putting user input into a database query, use `$db->prepare()` and pass values to `execute()` instead of joining the input into the SQL string.

### Editing and checking the project

- Keep PHP files as `.php`: the server needs to run PHP for login, database access, and form actions.
- Keep indentation at four spaces in PHP, HTML, CSS, JavaScript, and SQL. `.editorconfig` describes these shared formatting rules for compatible editors.
- After editing PHP, check its syntax with `php -l path\\to\\file.php`.
- Run the application on a PHP web server with the required MySQLi and MySQL extensions. A syntax check alone does not test database behavior.

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

## Shop product pictures

The shop maps image files in `assets/images/` to vegetable names in `buyer/index.php`. All ten sample vegetables have a matching product image: available photos are used for Carrot, Cucumber, Bell Pepper, Broccoli, and Green Beans, and original SVG illustrations are used for Tomato, Lettuce, Spinach, Zucchini, and Kale. Product images use the same fixed-size card area. To add or replace an image, copy it into `assets/images/` and set that vegetable's `file` in `$productPictures`. Use a simple filename without spaces when adding new images.

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
