# Hotel Order Platform

**OrderDesk** is a staff-first order desk for businesses supplying hotels. Hotel owners can order from their own catalogue, and staff can still enter orders received over WhatsApp. Staff prepare orders, issue printable invoices, and record money received outside the app. The app does not connect to WhatsApp or send messages.

Built with Laravel 12, MySQL 8.4, and Vue 3 + Vite.

## Run locally

Requirements: PHP 8.2+ with PDO MySQL, Composer, MySQL 8.4, and Node.js 24. Use an existing MySQL installation or start a local instance:

```sh
docker run --name orderdesk-mysql --rm -d \
  -p 127.0.0.1:3306:3306 \
  -e MYSQL_RANDOM_ROOT_PASSWORD=yes \
  -e MYSQL_DATABASE=orderdesk -e MYSQL_USER=orderdesk \
  -e MYSQL_PASSWORD='<choose-a-local-password>' \
  -v orderdesk-mysql:/var/lib/mysql mysql:8.4
```

In the project root:

```sh
npm ci
npm run build
cd backend
composer install
cp .env.example .env
php artisan key:generate
```

Update the `DB_*` fields in `backend/.env` to match your MySQL database and set `ADMIN_PASSWORD` privately (at least 12 characters and at most 72 UTF-8 bytes), then:

```sh
php artisan migrate --seed
php artisan serve
```

Open http://127.0.0.1:8000/admin and sign in as `admin@admin.com` using the password you set. The seeder does not reset an existing owner password or create sample records. Commands that run the seeder fail if `ADMIN_PASSWORD` is missing, blank, or too short. For later schema changes only, use `php artisan migrate` without `--seed`; it does not require the seed password. Use `php artisan staff:create <username>` for additional named staff; it prompts privately for a password. Staff accounts have equal access and changes are attributed to the signed-in user.

For frontend development, keep Laravel running on port 8000 and use `npm run dev` from the repo root. Optionally copy the **root** `.env.example` to `.env`, set the invoice seller details, and rebuild. Do not store secrets in frontend environment variables.

## Workflow

1. Add a hotel account and products. Set a base price in MYR for each sellable unit (for example piece, carton, or kg). Weight can use fractional quantities. Optionally record a manual stock count for each unit.
2. Set a hotel-specific price for a product and unit, if needed. Staff can also enter a one-off order price without affecting the hotel or catalog price.
3. Enter a WhatsApp order as **Open** or pick up an order submitted by the hotel owner. Adjust items and quantities as you prepare it. If something is short, restock manually or remove the item. Use **Cancelled** for orders that won't go ahead.
4. When quantities and prices are final, generate the invoice. The order moves to **Invoiced** and its invoice snapshot is locked. Print or save to PDF from the browser.
5. After the hotel pays, open the invoice and record the amount and payment date. Optionally add a reference and attach a JPG, PNG or WebP receipt image (up to 5 MiB). The invoice shows its payment history and outstanding balance. Multiple partial payments are supported; a payment cannot exceed the remaining balance.

## Hotel owner portal and transaction history

- **`/`**: Hotel owner sign-in. Staff create one owner login per hotel under **Hotels → Edit → Hotel owner access**. Share the chosen password privately; the app does not send credentials.
- **`/customer`**: Hotel owner workspace. An owner can see and order from the active catalogue at their hotel's prices, view orders and invoices for their hotel, and check their own transaction history. No internal stock counts, staff-only payment receipts, or other hotels' records are visible.
- **`/admin`**: Staff sign-in and workspace. Staff can manage all hotel accounts and orders, issue invoices, record payments, and view the transaction history on a hotel's account.

Each monthly history shows the opening balance, issued invoices, payments received, and closing balance. Invoice dates are shown in Malaysia time and payments use the date staff enter. Open orders are not amounts owed until invoiced.

This version does not include online payment collection, automatic stock deductions, supplier purchase orders, or tax/e-Invoice integrations. Configure and verify the printable invoice details before using it for live billing.

## Verify

Run `npm run build` from the root. Run `php artisan test` from `backend`; the default test setup uses in-memory SQLite. To test with MySQL, set `DB_CONNECTION=mysql` and point all `DB_*` settings to a **dedicated disposable test database**, never live data. CI checks the MySQL path.

For deployment, serve `backend/public` with HTTPS, disable debug, keep the app key and database credentials private, enable secure session cookies, and use durable database storage and backups.

Payment receipt images are private. Back up `backend/storage/app/private` (or its configured private storage volume) together with the database. Make the volume persistent in production, and set PHP `upload_max_filesize` to at least 5M and `post_max_size` and your reverse proxy limit high enough for the multipart request (for example 8M). Do not add a public symlink to the private receipt directory.

## Railway test deployment

Keep the app and a **new MySQL service** in the same Railway project. This test setup is separate from any existing database or business data. Import the repository root as the app service, using the root Dockerfile.

In the **app service's** Railway variables, set:

- `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` (a new Laravel key), `APP_URL` (the test app's HTTPS URL), `SESSION_SECURE_COOKIE=true`.
- `DB_CONNECTION=mysql` and the five `DB_*` connection variables as references to your MySQL service's private connection settings.
- `ADMIN_PASSWORD` for the first seed only. Use a unique test password; never commit it or use a live business password.
- Optional `VITE_INVOICE_SELLER_NAME` and `VITE_INVOICE_SELLER_ADDRESS` for demo invoice branding. These strings are public in the built frontend.

Configure the pre-deploy command to run `php artisan migrate --force` and the HTTP health check path to use `/up`. Mount a private Railway volume at `/app/backend/storage/app/private` for persistent receipt images. Do not expose the MySQL service publicly.

After the first successful deployment, open a shell in the app service and run `php artisan db:seed --force`. This creates only the owner login at `admin@admin.com`. Sign in at `/admin` and add fake hotel accounts and catalogue items to exercise the workflow. The hotel owner login is at `/`.
