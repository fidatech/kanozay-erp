# Local SQLite UAT setup

This guide prepares a personal, isolated Kanozay ERP database on a developer computer. It does not deploy a shared environment or import client data. Use synthetic records only.

## Requirements

- PHP 8.3+ with the extensions in `composer.json` and the CI workflow, including SQLite/PDO SQLite, mbstring, intl, GD, and zip
- Composer
- Node.js 22 and npm

## Configure SQLite

From the repository root:

```sh
composer install
npm ci
cp .env.example .env
touch database/database.sqlite
php artisan key:generate
```

Edit `.env` and set:

```dotenv
APP_NAME="Kanozay ERP - UAT"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000
DB_CONNECTION=sqlite
DB_DATABASE=/absolute/path/to/repository/database/database.sqlite
```

On Windows PowerShell, create the database file with:

```powershell
New-Item -ItemType File -Force database/database.sqlite
```

Use the full local path in `DB_DATABASE` (for example `C:/work/kanozay-erp/database/database.sqlite`). Keep this database outside production and do not commit it.

## Migrate, seed and sign in

```sh
npm run build
php artisan migrate --force
php artisan db:seed --force
php artisan kanozay:admin
php artisan serve --host=127.0.0.1 --port=8000
```

Open `http://127.0.0.1:8000` and use the administrator credentials just created. The admin command prompts for the password; do not save it in Git or this guide.

The default seeder installs reference permissions and role templates only. It does not seed a user, a chart of accounts, or sample transactions. The chart of accounts in `ChartOfAccountsSeeder` is a reference structure, not a verified import of Kanozay's 16-sheet account workbook. Review and approve its use before running it:

```sh
php artisan db:seed --class=ChartOfAccountsSeeder --force
```

The UAT checklist identifies workflows that still need a chart of accounts or other client approval. Do not use production customer, supplier, employee, opening-stock, or investment rows.

## Run automated checks

```sh
php artisan test
npm run build
```

The development branch CI runs both checks, then installs a separate clean SQLite file with migrations, default role seeding and the reference chart seeder. Passing CI is not evidence of a deployed environment, browser testing, client data approval, MySQL migration or print acceptance.

## Move from SQLite to a separate MySQL UAT database

Changing `DB_CONNECTION` does not convert or copy SQLite records. Create a separate empty MySQL database, configure its host/port/name/user/password in a private `.env`, then run migrations and seeders against that MySQL database. Never run these instructions against production.

For a clean MySQL UAT database, first verify `DB_CONNECTION=mysql` and the target database name, then:

```sh
php artisan config:clear
php artisan migrate --force
php artisan db:seed --force
php artisan kanozay:admin
```

Create synthetic test users and records in the MySQL UAT environment, or prepare a reviewed migration/import process. Do not copy the SQLite database file and assume it is MySQL data.

## Backup and recovery

Before a migration/import test, stop the local server and copy `database/database.sqlite` to a dated file stored outside the repository. Keep a separate backup before any import preview confirmation.

To restore, stop the application and replace the local SQLite file from the backup. Prefer restoring to a new disposable file first, then point `DB_DATABASE` at it and verify sign-in, migrations, and sample records. Never restore a UAT backup over production.
