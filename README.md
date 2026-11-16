# Hotel Order Platform

**OrderDesk** is a staff-first order desk for businesses supplying hotels. Hotels order on WhatsApp as usual; your team enters orders, prepares items and issues printable invoices. The app does not connect to WhatsApp or send messages.

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

Update the `DB_*` fields in `backend/.env` to match your MySQL database and set `ORDERDESK_ADMIN_PASSWORD` privately (at least 12 characters and at most 72 UTF-8 bytes), then:

```sh
php artisan migrate --seed
php artisan serve
```

Open http://127.0.0.1:8000 and sign in as `admin@admin.com` using the password you set. The seeder does not reset an existing owner password or create sample records. Once the account exists, you can remove `ORDERDESK_ADMIN_PASSWORD` from the local environment. Use `php artisan staff:create <username>` for additional named staff; it prompts privately for a password. Staff accounts have equal access and changes are attributed to the signed-in user.

For frontend development, keep Laravel running on port 8000 and use `npm run dev` from the repo root. Optionally copy the **root** `.env.example` to `.env`, set the invoice seller details, and rebuild. Do not store secrets in frontend environment variables.

## Workflow

1. Add a hotel account and products. Set a base price in MYR for each sellable unit (for example piece, carton, or kg). Weight can use fractional quantities. Optionally record a manual stock count for each unit.
2. Set a hotel-specific price for a product and unit, if needed. Staff can also enter a one-off order price without affecting the hotel or catalog price.
3. Enter a WhatsApp order as **Open**. Adjust items and quantities as you prepare it. If something is short, restock manually or remove the item. Use **Cancelled** for orders that won't go ahead.
4. When quantities and prices are final, generate the invoice. The order moves to **Invoiced** and its invoice snapshot is locked. Print or save to PDF from the browser.

This version does not include a hotel self-service portal, payments, monthly statements, automatic stock deductions, supplier purchase orders, or tax/e-Invoice integrations. Configure and verify the printable invoice details before using it for live billing.

## Verify

Run `npm run build` from the root. Run `php artisan test` from `backend`; the default test setup uses in-memory SQLite. To test with MySQL, set `DB_CONNECTION=mysql` and point all `DB_*` settings to a **dedicated disposable test database**, never live data. CI checks the MySQL path.

For deployment, serve `backend/public` with HTTPS, disable debug, keep the app key and database credentials private, enable secure session cookies, and use durable database storage and backups.
