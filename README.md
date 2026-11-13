update public.profiles
set role = 'staff'
where lower(email) = lower('rjake611@gmail.com')
returning email, role;

select email, email_confirmed_at
from auth.users
where lower(email) = lower('user@example.com');

# G6 Harvest

A PHP and MySQL farm operations and produce shop project.

## Start here

This project uses PHP to build pages and handle form submissions. MySQL stores accounts, vegetables, plantings, harvests, inventory, and orders. The browser uses HTML for page content, CSS for appearance, and JavaScript for small interactions.

### Project map

- `index.php` is the public Harvest homepage, with live featured produce and database-backed farm statistics.
- `auth/` contains login, registration, logout, email verification, and password recovery pages.
- `buyer/` contains the buyer dashboard, produce shop, cart, checkout, and order pages.
- `modules/` contains farm staff pages for vegetables, plantings, harvests, inventory, quality checks, sales, reports, and activity.
- Admins can review filtered, paginated inventory movement history from the Inventory page, including who changed stock and before/after quantities.
- Vegetable categories are managed in the staff catalog and can be filtered in the buyer shop.
- `includes/auth.php` starts the session and loads shared code.
- `includes/functions.php` contains helpers shared by many pages, such as URL building, HTML escaping, form protection, and cart helpers.
- `includes/header.php` and `includes/footer.php` contain the shared page layout.
- `config/database.php` connects to MySQL. `config/email.php` reads email settings.
- `assets/css/style.css` controls appearance. `assets/js/script.js` handles application interactions. The public homepage's built `assets/vendor/` bundle uses GSAP, Swiper, and Lucide.
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
- Run the isolated role and checkout checks with `php tests\\authorization_test.php` and `php tests\\checkout_calculation_test.php`. Cart, payment, and session checks are available as `php tests\\cart_quantity_test.php`, `php tests\\payment_flow_test.php`, and `php tests\\security_session_test.php`.
- Run `tests\\order_flow_integration_test.php` only with `HARVEST_TEST_DB` set to a dedicated database ending in `_test`.
- To rebuild the public homepage UI libraries after changing `assets/js/ui-entry.js`, run `npm install` and `npm run build:ui` from the project root. Node/npm are only needed to rebuild these assets; the generated local bundle is served by PHP without a CDN.

## Requirements

- PHP with the `mysqli` and `mysqlnd` extensions enabled
- MySQL or MariaDB
- Apache or another web server that runs PHP

## Local setup

1. Put the project in your web server's document folder and start Apache and MySQL.
   Enable PHP's `fileinfo` extension, allow uploads up to 5 MB, and make
   `assets/uploads/vegetables/` writable by PHP to use catalog photos.
2. Import `database.sql` into MySQL for a new installation. This creates the tables
   and sample farm data, but no login accounts. For an existing installation, back
   up the database and run `database-upgrade.sql` to add farmer roles and vegetable photo paths.
3. Configure `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` in the server environment. The local defaults in `config/database.php` are for a typical XAMPP setup only.
4. To show a public contact email on the homepage, configure `CONTACT_EMAIL` with a monitored address. Phone, location, and testimonials are not shown unless real details are added to the application.
5. For local development only, optionally use `database-demo-users.sql`. It is a template with no usable default accounts:
   replace its email placeholders with unique development addresses and password placeholders with hashes generated locally by PHP `password_hash()` before importing. Never import that file into a public or production database.
6. Before public deployment, create an administrator account with a unique email and a password hash generated by PHP's `password_hash()`. Configure production secrets and verify the deployment checklist before allowing access.
7. To test from a phone, use the computer's local IP address while both devices are on the same Wi-Fi network.

To grant a verified account farmer permissions on an existing local database,
update only the intended account in MySQL:

```sql
UPDATE users SET role = 'farmer' WHERE email = 'verified-farmer@example.com';
```

Farmers can use farm-operation pages like staff, but cannot open the administrator-only activity log.

Use `database.sql` only to initialize a new, empty database. Do not re-import it
over an existing database: it includes sample data and setup/migration statements.
Its sample upserts no longer replace existing produce details or stock, but can
still add missing sample rows. To update an existing database, make a backup and
run `database-upgrade.sql`. The inventory movement table starts recording changes
after this migration; earlier stock movements cannot be reconstructed reliably.
Before public deployment, remove or rotate any demo
credentials already present in the database. Neither schema file creates login
accounts.

## Orders, bulk discounts, and payments

Checkout stores one order summary in `orders` and keeps the existing `sales` rows as its vegetable line items. The server sums all quantities in the cart, including quantities with different catalog units, as requested. Orders with a combined quantity of 20 or more get 10% off the vegetable subtotal; the delivery fee is not discounted. The current delivery fee is a fixed ₱20 and can be changed in `includes/orders.php`.

Each checkout form also carries a one-time request token. The database uniquely associates that token with the buyer and stores a fingerprint of the submitted cart, address, and payment method. Repeated submissions of the same form return the existing order instead of creating another order or deducting stock again. The token is not reused for a newly loaded checkout. For an existing installation, run `database-upgrade.sql` to add the idempotency fields.

The checkout saves the delivery address with the order and supports:

- **Cash on Delivery:** starts as `Pending Payment`. Admins and staff move the order through the delivery statuses in Sales. Cash collection can be confirmed only for an out-for-delivery or delivered COD order.
- **Online Payment:** uses PayMongo's hosted checkout. GCash and card are requested; the merchant account must have the chosen methods enabled. The site never receives card numbers, security codes, PINs, or banking passwords. Only a signed PayMongo webhook can mark an online order paid.

A failed online payment keeps its inventory reserved until staff cancels the order. Returning from or cancelling the hosted checkout does not release stock.

Online checkout is hidden unless the secret key, webhook secret, public HTTPS application URL, and PHP OpenSSL support are available. For local setup, create `config/payment.local.php` (this file is ignored by Git) with:

```php
<?php
return [
    'paymongo_secret_key' => 'sk_test_...',
    'paymongo_webhook_secret' => 'whsk_...',
    'app_url' => 'https://your-public-https-url',
];
```

Use your PayMongo test keys first. The `app_url` is the public base URL of the application, including any project subfolder. In the PayMongo dashboard, register this webhook endpoint:

```text
https://your-public-https-url/webhooks/paymongo.php
```

Subscribe to `checkout_session.payment.paid` and, if your dashboard offers them, `checkout_session.payment.failed` and `payment.refunded`. Configure the secret generated for that webhook as `paymongo_webhook_secret`. Enable the PHP OpenSSL extension and `allow_url_fopen`; do not commit real credentials. For production, use HTTPS and live credentials. If the gateway cannot create a checkout session, the order transaction is rolled back and checkout reports the failure instead of claiming payment succeeded. A buyer's cancel redirect alone never changes payment status; it remains pending unless a verified provider event reports failure or payment.

### Test the ordering flow

1. Import `database.sql` for a new database, or back up an existing database and run `database-upgrade.sql`.
2. Sign in as a buyer, add produce, and open checkout. Confirm the subtotal, automatic discount, ₱20 fee, delivery address, and payment choices.
3. Place an order with a combined quantity below 20; verify the discount is ₱0. Try exactly 20 units across products; verify the 10% discount is based only on the vegetable subtotal.
4. Choose COD. As admin/staff, progress the order to Out for Delivery, confirm cash collection, then check that payment becomes Paid and the collection staff/date appear in order details.
5. Configure PayMongo test credentials and a publicly reachable HTTPS webhook. Choose Online Payment and complete a test GCash/card checkout. Confirm that the buyer return page remains pending until the signed webhook arrives, then verify the order becomes Paid. Cancel a test checkout and confirm it is not shown as paid.
6. Review Sales and Reports for original subtotal, discounts, final totals, payment method/status, discount history, and discounted vegetables.
7. On the buyer dashboard, test quick add, saving/removing favorites, reordering a
   past order with reduced stock, and reviewing an overstocked cart. Confirm
   checkout explains the combined-quantity discount threshold.
8. Run `php tests\\cart_quantity_test.php`, `php tests\\checkout_calculation_test.php`,
   `php tests\\payment_flow_test.php`, and `php -l` on the changed PHP files.

The database-backed order flow check requires a separate test database with the
project schema imported. Set `HARVEST_TEST_DB` to its name ending in `_test` before
running `php tests\\order_flow_integration_test.php`; the test validates stock
reservation, duplicate checkout protection, and payment-event handling, then
rolls back its data. Never use a production database for tests. See
[`DEPLOYMENT.md`](./DEPLOYMENT.md) for test setup and backup/restore guidance.

For an existing installation, run `database-upgrade.sql` after taking a backup
to add farmer roles, vegetable photo paths, produce categories, and the buyer
favorites table. The migration assigns sensible categories to the included
sample vegetables and leaves other produce in “Other.”

Buyer favorites are saved per account. New installations create the
`buyer_favorites` table through `database.sql`; existing installations must run
`database-upgrade.sql` before using favorites or the updated buyer dashboard.

## Shop product pictures

The `product_picture()` helper in `includes/functions.php` maps vegetable names to image files in `assets/images/`. The shop and product details page use the same mapping and show a small vegetable icon if an image is unavailable.

To add or replace an image, copy it into `assets/images/` and update the matching entry in `product_picture()`.

## Database code

`config/database.php` creates a MySQLi connection and sets UTF-8 encoding. The small `MySQLiDatabase` and `MySQLiStatement` helpers keep the page code straightforward; prepared statements bind values through MySQLi. Use `$db->prepare()` for statements that include user input.

## Main features

- Buyer, staff, farmer, and administrator roles with server-side access checks;
  farmers share farm-operation permissions with staff
- Registration, login, logout, password hashing, session protection, email verification, and one-time password reset links
- Vegetable catalog, plantings, harvests, and quality inspection records
- Produce categories, staff-uploaded vegetable photos, live shop search,
  availability filtering, and price sorting
- Edit and archive actions for vegetables, growing plantings, and harvests; historical records are retained
- Checkout updates inventory in a database transaction and buyers can only view their own orders
- Buyer pages support English/Filipino language switching and refresh
  order/payment statuses while open
- Dashboard summaries, searchable vegetable records, filtered reports, and a print-friendly report view
- Administrator-only activity log for account and farm operations
- CSRF tokens, input validation, prepared database statements, and escaped HTML output

## Production security settings

The shared PHP bootstrap applies a restrictive Content Security Policy and baseline browser security headers. It also starts cookie-only, strict-mode sessions with HttpOnly and SameSite=Lax cookies. Session cookies are marked Secure when PHP detects a direct HTTPS request. Set `APP_ENV=production` to enable HSTS on HTTPS responses.

Terminate TLS at the web server or trusted reverse proxy and redirect all HTTP traffic to HTTPS there. Do not rely on an untrusted forwarded-protocol header to detect HTTPS. When TLS terminates at a proxy, configure the web server/PHP environment so PHP can reliably detect the HTTPS request before enabling online checkout; never mark session cookies secure based on arbitrary client-supplied headers. Confirm HTTPS detection and cookie attributes using the deployed web server, because PHP CLI settings do not necessarily match Apache/PHP-FPM.

The session is browser-lifetime and logout destroys it. Configure web-server/PHP error display off in production, keep secrets out of the web root where possible, use a least-privilege MySQL account, and restrict file and backup permissions. Those hosting settings cannot be verified or changed by this repository.

## Data structure used

Checkout in `buyer/checkout.php` builds the `$byId` associative array using each vegetable ID as a key. This is a hash-table lookup used to quickly match each cart item with its current price and stock.

- Building the lookup for `n` products takes O(n) time and O(n) additional space.
- Looking up a product by ID takes O(1) time on average; a hash table can take O(n) in the worst case.

This structure is used in checkout validation and total calculation, rather than being included only as a demonstration.

## Email and SMS

Email verification and password recovery support configured SMTP delivery and expiring, single-use links. For local development, set `EMAIL_MODE=development` (or `mode => 'development'` in the ignored `config/email.local.php`) to display the generated links in the browser instead of sending email. For SMTP, configure the sender credentials and the public `APP_URL` so links point to the deployed application. Existing databases must run `database-upgrade.sql` to create the password-reset token table. SMS notifications are optional in the project requirements and are not needed for the core workflows.

## Vercel version

The separate `vercel-app/` folder contains a static HTML/CSS/JavaScript version for Vercel, using Supabase for authentication and PostgreSQL. The PHP project above remains the original local application. See `vercel-app/README.md` for deployment, environment variables, PayMongo setup, and the optional MySQL data import. Existing account passwords must be reset after import.
