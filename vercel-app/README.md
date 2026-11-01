# Harvest for Vercel

This is a separate, static HTML/CSS/JavaScript version of the Harvest app. The original PHP/MySQL project remains in the parent folder. This version uses Supabase Auth and PostgreSQL; Vercel Functions keep PayMongo credentials and privileged payment operations on the server.

The original responsive stylesheet and product images are copied into `assets/`. There is no sample or fake account/product data in this app. Import your existing database or create real catalog records after setting up the first administrator.
Do not copy the parent project's local email/payment configuration files into this folder. Put new secrets only in Vercel's server-side environment settings.

## What is included

- Buyer registration, email verification, login, password reset, and logout through Supabase Auth.
- Buyer, staff, and administrator roles. New registrations are buyers; roles are never chosen in the browser.
- Product catalog, product details, saved cart, checkout, order history, and receipts.
- Farm dashboard, vegetable catalog, plantings, harvests, inventory, quality inspections, sales, reports, and administrator activity log.
- Transactional PostgreSQL functions for inventory changes, harvest adjustments, checkout, order status, COD collection, and payment events.
- PayMongo GCash/card checkout, COD, cancellation/stock restoration rules, and a signed webhook at `/api/paymongo-webhook`.
- A MySQL import script that carries existing user accounts/roles and farm/order records into Supabase.

## Deploy to Vercel

### 1. Create Supabase project and schema

1. Create a Supabase project and keep its database credentials private.
2. In **SQL Editor**, run `supabase/migrations/001_initial_schema.sql`.
3. In **Authentication → URL Configuration**, set the Site URL to your Vercel deployment URL and allow its callback URL. Repeat with your production domain when you add one.
4. Configure Supabase Auth email delivery and enable email confirmation. Supabase sends verification and password-reset links; the PHP SMTP configuration is not used.

The schema intentionally starts empty. It does not insert example products or user accounts.

### 2. Install and deploy the converted folder

1. Push the repository to GitHub without adding any local configuration files or secrets.
2. Import it in Vercel and set **Root Directory** to `vercel-app`.
3. Use the Vite defaults, or set:
   - Install command: `npm install`
   - Build command: `npm run build`
   - Output directory: `dist`
4. Add the environment variables below to Vercel. Set them for Preview and Production as appropriate, then redeploy.

| Variable | Where it is used |
|---|---|
| `VITE_SUPABASE_URL` | Browser Supabase client; public project URL |
| `VITE_SUPABASE_ANON_KEY` | Browser Supabase client; public anon key, protected by database RLS |
| `SUPABASE_URL` | Vercel Functions and import script |
| `SUPABASE_ANON_KEY` | Vercel Functions, for requests carrying the signed-in user's token |
| `SUPABASE_SERVICE_ROLE_KEY` | Vercel Functions/import script only; never use a `VITE_` prefix |
| `PAYMONGO_SECRET_KEY` | Vercel Functions only |
| `PAYMONGO_WEBHOOK_SECRET` | Vercel webhook function only |
| `APP_URL` | Public HTTPS base URL, with no query string or credentials |

`VITE_SUPABASE_ANON_KEY` is expected to be visible in the compiled site. It is not a database password; PostgreSQL row-level security is what protects data. Never put the Supabase service-role key, MySQL password, or PayMongo secret in browser code.

### 3. Create the first administrator

After signing up and verifying the account you will use as administrator, run this statement in Supabase SQL Editor, replacing the email:

```sql
update public.profiles
set role = 'admin'
where lower(email) = lower('your-admin@example.com');
```

Confirm that exactly the intended account was updated. Only an administrator should assign staff roles; use SQL Editor for the initial administrator because the public registration form must not allow role selection.

### 4. Import existing MySQL data (optional)

The import script copies profiles, vegetables, plantings, harvests, inventory, orders, sales, payment history, quality inspections, and activity logs. It rewrites user foreign keys to the new Supabase profile IDs and preserves the other record IDs.

1. Back up the existing MySQL database.
2. If the source database is an older install, apply the original `database-upgrade.sql` first. The importer expects the current `orders` and `payment_history` tables.
3. Check that the source database does not contain development/demo accounts that should not be carried into production.
4. From PowerShell, open the converted folder, install the packages, then set the source and destination environment variables and run the importer:

```powershell
Set-Location 'C:\path\to\g6-harvest\vercel-app'
npm install
$env:SUPABASE_URL = 'https://your-project.supabase.co'
$env:SUPABASE_SERVICE_ROLE_KEY = 'your-service-role-key'
$env:MYSQL_HOST = '127.0.0.1'
$env:MYSQL_PORT = '3306'
$env:MYSQL_DATABASE = 'vegetable_system'
$env:MYSQL_USER = 'your-mysql-user'
$env:MYSQL_PASSWORD = 'your-mysql-password'
npm run import:mysql
```

The importer does **not** copy PHP password hashes or email-verification tokens into Supabase Auth. Imported account emails and roles are retained, but each existing user must use **Forgot password** to set a new Supabase password. Unverified accounts must complete Supabase email verification first. Do not delete the old database until the imported record counts and representative orders have been checked.

The import script is safe to rerun for the same source data: records are upserted by their IDs and users by their email/auth identity. Do not run imports from two different source databases into the same Supabase project.

### 5. Configure PayMongo

1. Add `PAYMONGO_SECRET_KEY` and `PAYMONGO_WEBHOOK_SECRET` to the Vercel project settings. Start with PayMongo test credentials.
2. Set `APP_URL` to the deployed HTTPS origin, for example `https://your-project.vercel.app`.
3. In PayMongo, register `https://your-project.vercel.app/api/paymongo-webhook` as the webhook URL. Subscribe to `checkout_session.payment.paid`, `checkout_session.payment.failed`, and `payment.refunded`.
4. Use only PayMongo's hosted checkout. The browser never receives the PayMongo secret key.

COD collection remains a staff action and is allowed only for an out-for-delivery or delivered COD order. A payment return page never marks an order paid; the signed webhook does. Failed online payments keep stock reserved until staff confirms cancellation. Online cancellation expires the PayMongo session before releasing stock.

## Local checks

- `npm test` runs checkout calculation and PayMongo signature/event parsing tests.
- `npm run build` builds the static site.
- For a local end-to-end run of Vercel Functions, install the Vercel CLI and use `vercel dev` with the environment variables set. A plain Vite dev server only serves the browser UI; it does not run `/api` functions.
- Live Auth, database, email, checkout, and webhook tests require your own Supabase and PayMongo test-project credentials. No credentials are included here.
