# Kanozay ERP — installation and operations guide

For a staging or pilot server. Verified by CI: clean install, migrations and seeders on
MySQL 8.4 with PHP 8.4. Web-server configuration below is standard Laravel practice and has
not been exercised by CI.

## 1. Server requirements

- PHP **8.4.1 or later** with extensions: `pdo_mysql`, `mbstring`, `intl`, `gd`, `zip`, `bcmath` (or `gmp`), `fileinfo`, `openssl`, `ctype`, `json`, `tokenizer`, `xml`
- MySQL **8.0 or later** (8.4 is what CI uses), InnoDB, `utf8mb4`
- Composer 2
- Node.js 22 and npm (build step only; not needed at runtime)
- Nginx or Apache serving the `public/` directory only, HTTPS enabled

## 2. Database

```sql
CREATE DATABASE kanozay_erp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'kanozay'@'localhost' IDENTIFIED BY '<strong password>';
GRANT ALL PRIVILEGES ON kanozay_erp.* TO 'kanozay'@'localhost';
```

Keep MySQL strict mode on (the default). The application relies on it.

## 3. Install

```sh
git clone https://github.com/fidatech/kanozay-erp.git && cd kanozay-erp
git checkout development/kanozay-completion      # or the approved release tag
composer install --no-dev --optimize-autoloader
npm ci && npm run build
cp .env.example .env
php artisan key:generate
```

Edit `.env`:

```dotenv
APP_NAME="Kanozay ERP"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://erp.example.af
APP_TIMEZONE=Asia/Kabul        # set before the first transaction; do not change afterwards

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=kanozay_erp
DB_USERNAME=kanozay
DB_PASSWORD=<strong password>

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=database
LOG_LEVEL=warning

# Leave off. See docs/PILOT_RELEASE.md.
ERP_LEGACY_RECOVERY_POSTINGS=false
```

Then:

```sh
php artisan migrate --force
php artisan db:seed --force                        # roles and permissions only; no users
php artisan kanozay:admin                          # interactive; creates the first Super Administrator
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

`php artisan kanozay:admin` works only while no user exists and never takes a password on
the command line. Do **not** run `kanozay:uat-users` on a server that holds real data.

### Chart of accounts

`php artisan db:seed --class=ChartOfAccountsSeeder --force` installs a reference chart. It is
**not** a Kanozay-approved chart. Use it for pilot and training; for live books, load the
approved chart through Finance → Chart of accounts before any transaction is posted.

## 4. File permissions

The web-server user needs write access to `storage/` and `bootstrap/cache/` only. Uploaded
documents are kept in `storage/app/private` and are served through permission-checked
routes, never directly.

## 5. Background work

No scheduled task or queue worker is required by the current release. Notifications are
stored in the database.

## 6. After installation

1. Sign in as the administrator and complete Administration → Company settings (name, logo,
   base currency, warehouses).
2. Add currencies and exchange rates, then cash, bank and Sarafi accounts.
3. Create users and assign roles. Posting, review and payment steps require **different
   people**; plan at least a preparer, an approver and a cashier for finance and payroll.
4. Approve the manufacturing cost ledger policy before the first production batch is costed.
5. Define unit conversions (Inventory → Warehouse stock → Unit conversions) if recipes use
   units different from stock units.

## 7. Backup and restore

Back up **both** the database and uploaded files, daily at minimum, and keep copies off the server.

```sh
mysqldump --single-transaction --routines --triggers kanozay_erp | gzip > kanozay_$(date +%F).sql.gz
tar czf kanozay_files_$(date +%F).tar.gz storage/app/private
```

Restore:

```sh
php artisan down
gunzip < kanozay_YYYY-MM-DD.sql.gz | mysql kanozay_erp
tar xzf kanozay_files_YYYY-MM-DD.tar.gz
php artisan config:cache && php artisan up
```

Test a restore on a separate machine before relying on the backups. Keep `.env` (it holds
`APP_KEY`) in a secure place: without the same key, encrypted fields cannot be read.

## 8. Updating

```sh
php artisan down
# take a database backup first
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan up
```

Never run `migrate:fresh`, `migrate:refresh` or `db:wipe` on a server with real data. Fix
problems with a new forward migration, not by rolling back.

## 9. Health check

`GET /up` returns 200 when the application boots. `storage/logs/laravel.log` holds errors.
