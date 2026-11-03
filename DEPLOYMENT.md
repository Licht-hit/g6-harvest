# Harvest deployment and recovery

Use this checklist for a production deployment or database recovery. The PHP app,
MySQL database, payment provider, and web server must be configured together.

## Before deployment

- Use a supported PHP release with `mysqli`, `mysqlnd`, and `openssl` enabled.
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
Back up production secrets separately using the hosting provider's secret manager;
never put them in the SQL dump or source repository.

## Restore and verify

Restore into a new or explicitly selected database first, not over the live
database:

```powershell
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
