# Harvest deployment and recovery

Use this checklist for a production deployment or database recovery. The PHP app,
MySQL database, payment provider, and web server must be configured together.

## Beginner-friendly PHP hosting setup

1. Choose hosting that runs PHP 8.0 or newer with `mysqli`, `mysqlnd`, `openssl`,
   `fileinfo`, and MySQL or MariaDB. Enable `allow_url_fopen` only if you plan to use PayMongo.
   The PHP app is not the separate Vercel/Supabase app in `vercel-app/`.
2. Create an empty database and a dedicated database user in your hosting panel.
   Grant that user access only to this database. Do not use MySQL `root`.
3. Upload the PHP project to the hosting account. Keep the application files
   outside the document root when your host allows it. If Apache serves the
   project root, upload the included `.htaccess`; it blocks direct access to
   config/includes/tests, SQL, documentation, dotfiles, and local secret files.
   For Nginx or another server, configure equivalent deny rules and disable
   directory listing at the host; `.htaccess` is Apache-only.
4. For a new database, import `database.sql` using the host's database tool. The
   script names the database `vegetable_system`; if your host assigned another
   name, use a local copy adjusted for that database (or remove the `CREATE
   DATABASE` and `USE` lines when importing into a database already selected in
   the host's panel). Never re-import it over production data to apply changes.
   For an existing installation, take and test a backup before applying
   `database-upgrade.sql`.
5. Set `APP_ENV=production` and `DB_HOST`, `DB_NAME`, `DB_USER`, and
   `DB_PASSWORD` in the hosting environment settings. The app now refuses to
   connect in production if any database setting is missing. Do not paste these
   values into a public chat or commit them to Git.
6. Configure HTTPS and an HTTP-to-HTTPS redirect at the host. Set `APP_URL` to
   the exact public HTTPS base URL (include a project subfolder if used). Verify
   the session cookie is Secure on the deployed host.
7. For email verification and password recovery, set `EMAIL_MODE=smtp`,
   `SMTP_HOST`, `SMTP_PORT`, `SMTP_USERNAME`, `SMTP_PASSWORD`, and `EMAIL_FROM`.
   Gmail uses an App Password, not the normal account password. Port 587 uses
   STARTTLS; port 465 uses implicit TLS. The sender must match the Gmail SMTP
   username. Use `EMAIL_MODE=development` only on a private local development
   site; it displays verification links instead of sending email.
8. For online payments only, set `PAYMONGO_SECRET_KEY` and
   `PAYMONGO_WEBHOOK_SECRET`, enable OpenSSL and `allow_url_fopen`, then register
   `https://your-site/webhooks/paymongo.php` in PayMongo. Start with test
   credentials and confirm a signed test webhook before switching to live keys.
   Cash on Delivery does not require PayMongo.
9. Create a verified admin account, sign in, and test registration, verification,
   login/logout, role restrictions, produce/inventory updates, a COD order and
   collection, and reports. Test online payment and webhook handling separately
   with provider test credentials. Keep PHP error display off and check server
   logs for failures.

## Before deployment

- Use a supported PHP release with `mysqli`, `mysqlnd`, `openssl`, and `fileinfo` enabled.
- Create a dedicated MySQL database and a least-privilege app account. Do not use
  the MySQL root account for the website.
- Set `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` in the server environment.
  Keep payment and email credentials in protected server configuration, not in Git.
- Serve the app over HTTPS, redirect HTTP to HTTPS, and verify secure session cookies.
- Set the public HTTPS `app_url` and configure the PayMongo webhook secret. Test
  with provider test credentials before enabling live payments.
- Back up the database before applying `database-upgrade.sql`. Apply the upgrade
  once per environment, then confirm vegetable categories and existing orders.
- Run the PHP checks and perform a test-buyer order, COD status update, test
  payment, and signed webhook delivery before opening the site to customers.
- Keep PHP error display disabled in production and monitor web-server and PHP
  error logs. Restrict access to configuration and backup files.

## Create a database backup

Use a protected MySQL option file outside the web root and repository, for example:

```ini
[client]
host=127.0.0.1
user=harvest_backup
password=replace-with-a-secret
```

Restrict this file to the backup account and do not commit it. Create a
timestamped, access-controlled dump:

```powershell
mysqldump --defaults-extra-file="C:\secure\harvest-backup.cnf" --single-transaction --routines --triggers vegetable_system > "D:\HarvestBackups\vegetable_system-20261103-1200.sql"
```

`--single-transaction` provides a consistent snapshot for InnoDB tables. Store
the dump somewhere separate from the application host when possible, encrypt it
at rest, and define a retention policy that matches business and privacy needs.
Back up `assets/uploads/vegetables/` separately so uploaded catalog photos can
be restored alongside the database records that reference them.
Back up production secrets separately using the hosting provider's secret manager;
never put them in the SQL dump or source repository.

## Restore and verify

Restore into a new or explicitly selected database first, not over the live
database:

```powershell
mysql --defaults-extra-file="C:\secure\harvest-backup.cnf" -e "CREATE DATABASE vegetable_system_restore CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
& $env:ComSpec /c 'mysql --defaults-extra-file="C:\secure\harvest-backup.cnf" vegetable_system_restore < "D:\HarvestBackups\vegetable_system-20261103-1200.sql"'
```

If a migration is needed, back up first and apply `database-upgrade.sql` to the
restore database. Verify that users, produce, inventory, orders, sales, payment
history, and activity records are present and internally consistent. Sign in
with a test account, review reports, and perform a test checkout before directing
traffic to the restored site. Keep the original database and backup unchanged
until the restore has been verified and approved.

For a real incident, record the restore point and affected time range, coordinate
with the payment provider before retrying or refunding transactions, and retain
the original logs and database snapshot for investigation.

## Safe order-flow integration tests

The integration test writes temporary users, vegetables, inventory, orders, and
payment history, then rolls the transaction back. It refuses to run unless
`HARVEST_TEST_DB` names a dedicated database ending in `_test`. Create that test
database and import the project schema before running. `database.sql` selects
`vegetable_system` explicitly, so use a local copy with both database-name
occurrences changed; do not edit the committed schema file. For example:

```powershell
mysql --defaults-extra-file="C:\secure\harvest-backup.cnf" -e "CREATE DATABASE vegetable_system_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
$testSchema = Join-Path $env:TEMP "harvest-test-schema.sql"
$schema = [System.IO.File]::ReadAllText("database.sql").Replace("vegetable_system", "vegetable_system_test")
[System.IO.File]::WriteAllText($testSchema, $schema, [System.Text.UTF8Encoding]::new($false))
& $env:ComSpec /c "mysql --defaults-extra-file=`"C:\secure\harvest-backup.cnf`" < `"$testSchema`""
$env:HARVEST_TEST_DB = "vegetable_system_test"
php tests\order_flow_integration_test.php
Remove-Item Env:HARVEST_TEST_DB
Remove-Item $testSchema
```

Run the database-free tests with:

```powershell
php tests\checkout_calculation_test.php
php tests\payment_flow_test.php
php tests\security_session_test.php
```

Never point the integration test at production or a database containing data you
need to keep. Use a new, empty `_test` database for each schema setup; do not run
the schema-copy command against a test database containing data you need.
