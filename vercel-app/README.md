3. Then run `supabase/migrations/002_roles_and_payment_safety.sql`.
# Harvest for Vercel

This is a separate, static HTML/CSS/JavaScript version of the Harvest app. The original PHP/MySQL project remains in the parent folder. This version uses Supabase Auth and PostgreSQL; Vercel Functions keep Xendit credentials and privileged payment operations on the server.

The original responsive stylesheet and product images are copied into `assets/`. There is no sample or fake account/product data in this app. Import your existing database or create real catalog records after setting up the first administrator.
Do not copy the parent project's local email/payment configuration files into this folder. Put new secrets only in Vercel's server-side environment settings.

## What is included

- Buyer registration, email verification, login, password reset, and logout through Supabase Auth.
- Buyer, staff, farmer, and administrator roles. Farmers have the same farm-operation permissions as staff; existing staff roles are retained. New registrations are buyers; roles are never chosen in the browser.
- Product catalog, product details, saved cart, checkout, order history, and receipts.
- Farm dashboard, vegetable catalog, plantings, harvests, inventory, quality inspections, sales, reports, and administrator activity log.
- Administrator dashboard with sales and harvest trends, inventory charts, stock alerts, top sellers, recent orders, inventory updates, and activity.
- Transactional PostgreSQL functions for inventory changes, harvest adjustments, checkout, order status, COD collection, and payment events.
- Xendit hosted checkout, COD, cancellation/stock restoration rules, and a verified webhook at `/api/xendit-webhook`. The PayMongo webhook remains available for orders created before the migration.
- A MySQL import script that carries existing user accounts/roles and farm/order records into Supabase.

## Deploy to Vercel

Follow these steps in order. Keep the original PHP project in the repository; Vercel will build only the `vercel-app` folder.

### 1. Create Supabase project and schema

1. Create a Supabase project and keep its database credentials private.
2. In **SQL Editor**, run `supabase/migrations/001_initial_schema.sql` exactly once. It creates the tables and app functions.
3. Then run `supabase/migrations/002_roles_and_payment_safety.sql`. It adds the farmer role and safer payment-event handling. It is safe to run again.
4. Run `supabase/migrations/003_public_catalog.sql` to let signed-out visitors read active vegetables on the landing page. Row-level security keeps inactive vegetables and inventory private.
5. Run `supabase/migrations/004_vegetable_category.sql` to add the product category field used by the catalog. It is safe to run on existing projects and defaults existing products to `Other`.
6. Run `supabase/migrations/005_seed_catalog.sql` to add the ten sample products to an empty catalog. Existing products with the same names are left unchanged; no inventory is added.
7. Run `supabase/migrations/007_fix_order_item_ambiguity.sql` to fix cart-item ambiguity in order checkout and call `pgcrypto` functions from Supabase's `extensions` schema. If you already ran an earlier copy of migration 007, run the updated file again; it safely replaces the checkout function.
8. Run `supabase/migrations/008_xendit_payments.sql` to enable Xendit for new orders while retaining PayMongo processing for existing orders.
    - If migration 001 finished successfully before, run only the later migrations that have not yet been applied, including migrations 004, 005, and 007.
   - If you see `relation "profiles" already exists`, stop. Do not run migration 001 again and do not delete the table.
   - Do not rerun migrations that have already been applied.
   - If you are unsure whether migration 001 finished, check that the core tables exist with this query:

     ```sql
     select table_name
     from information_schema.tables
     where table_schema = 'public'
       and table_name in (
         'profiles', 'vegetables', 'inventory', 'plantings', 'harvests',
         'orders', 'sales', 'payment_history', 'quality_inspections', 'activity_logs'
       )
     order by table_name;
     ```

     You should see all ten table names. If any are missing, do not delete existing data or blindly rerun migration 001; ask for help checking the incomplete setup first.
4. In **Authentication → URL Configuration**, set the Site URL to your Vercel deployment URL and add `https://your-deployment-domain.vercel.app/**` to Redirect URLs. This allows password-reset emails to open `/?page=reset-password`. Repeat with your production domain when you add one.
5. Configure Supabase Auth email delivery and enable email confirmation. Supabase sends verification and password-reset links; the PHP SMTP configuration is not used. From the login page, users can choose **Forgot password?**, enter their email, then use the email link to open the **Choose a new password** form and sign in with the replacement password.

The schema does not insert user accounts. Migration 005 adds the ten sample products shown in the original MySQL schema, but does not add inventory; set stock quantities from the farm dashboard before accepting orders.

### 2. Install and deploy the converted folder

1. Push the repository to GitHub without adding any local configuration files or secrets.
2. In Vercel, choose **Add New → Project**, then import this GitHub repository.
3. Set **Root Directory** to `vercel-app`.
4. Use these build settings:
   - Install command: `npm install`
   - Build command: `npm run build`
   - Output directory: `dist`
5. Add the environment variables below to Vercel. Select **Production** and **Preview** for each variable. Leave **Development** unselected unless you are using Vercel locally.

| Key to enter in Vercel | Value to paste |
|---|---|
| `VITE_SUPABASE_URL` | Supabase **Project URL** from **Project Settings → API** |
| `VITE_SUPABASE_ANON_KEY` | Supabase **anon/public** or **publishable** key from **Project Settings → API** |
| `SUPABASE_URL` | The same Supabase **Project URL** |
| `SUPABASE_ANON_KEY` | The same Supabase **anon/public** or **publishable** key |
| `SUPABASE_SERVICE_ROLE_KEY` | Supabase **service_role secret** key. Keep it private; do not add `VITE_` to this name. |
| `APP_URL` | Your deployed Vercel address, for example `https://your-project.vercel.app` |
| `VITE_CONTACT_EMAIL` (optional) | Public email address to show in the homepage Contact section. |

Add these only if you are turning on online payments:

| Key to enter in Vercel | Value to paste |
|---|---|
| `XENDIT_SECRET_KEY` | Xendit secret API key. Start with a test key. |
| `XENDIT_WEBHOOK_TOKEN` | The callback verification token configured for your Xendit webhook. |

To find your Supabase values, open your project dashboard, then **Project Settings → API**. Copy the Project URL and the anon/public key. Do not paste the service-role key into either `VITE_` variable. After saving or changing environment variables in Vercel, redeploy so the new values are included.

`VITE_SUPABASE_ANON_KEY` is expected to be visible in the compiled site. It is not a database password; PostgreSQL row-level security is what protects data. Never put the Supabase service-role key, MySQL password, or Xendit secret in browser code.

### 3. Create the first administrator

After signing up and verifying the account you will use as administrator, run this statement in Supabase SQL Editor, replacing the email:

```sql
update public.profiles
set role = 'admin'
where lower(email) = lower('your-admin@example.com');
```

Confirm that exactly the intended account was updated. The public registration form never allows role selection. Use the SQL Editor for the initial administrator, then have an administrator manage role assignments.

To give a verified farm account the farmer role, run this statement with that account's email:

```sql
update public.profiles
set role = 'farmer'
where lower(email) = lower('your-farmer@example.com');
```

Confirm that exactly the intended account was updated. Farmers and existing staff accounts can manage farm operations; only administrators can view the activity log.

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

### 5. Configure Xendit

1. Activate the payment channels you want to offer (such as GCash or Maya) in your Xendit account, then add `XENDIT_SECRET_KEY` and `XENDIT_WEBHOOK_TOKEN` to Vercel. Start with Xendit test credentials.
2. Set `APP_URL` to the deployed HTTPS origin, for example `https://your-project.vercel.app`.
3. In Xendit, configure `https://your-project.vercel.app/api/xendit-webhook` as the callback URL and enter the same callback verification token in `XENDIT_WEBHOOK_TOKEN`. Enable `payment_session.completed`, `payment_session.expired`, `refund.succeeded`, and `refund.failed` callbacks.
4. The app uses Xendit's hosted payment session; the browser never receives the Xendit secret key. Payment channels available to buyers depend on your account's activation and eligibility.
5. Keep the existing PayMongo webhook and credentials configured until all PayMongo orders created before the migration have been completed, cancelled, or otherwise reconciled.

COD collection remains a farm-staff action and is allowed only for an out-for-delivery or delivered COD order. A payment return page never marks an order paid; the verified webhook does. Failed online payments keep stock reserved until farm staff confirms cancellation. Online cancellation cancels the payment session before releasing stock. If a valid payment event arrives after cancellation, checkout expiry, or another payment, the order is not silently changed; the event is recorded for administrator review.

## Local checks

- `npm test` checks checkout calculations, Xendit and legacy PayMongo webhook parsing, and role permissions.
- `npm run build` builds the static site.
- For a local end-to-end run of Vercel Functions, install the Vercel CLI and use `vercel dev` with the environment variables set. A plain Vite dev server only serves the browser UI; it does not run `/api` functions.
- Live Auth, database, email, checkout, and webhook tests require your own Supabase and Xendit test-project credentials. No credentials are included here.

If a protected action reports that the session expired, confirm the Supabase project is active and `SUPABASE_URL` and `VITE_SUPABASE_URL` refer to the same project. The server checks these URLs and records Supabase token-validation errors in Vercel function logs. Redeploy after changing Vercel variables. The app attempts to refresh an expired access token once; if Supabase rejects the refresh token, sign in again.
