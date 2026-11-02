# Kanozay ERP development handoff

## Project and branch

- Client: Kanozay Foods Manufacturing Company
- Repository: `fidatech/kanozay-erp`
- Working branch: `development/kanozay-completion`
- Application: Laravel 13, PHP `^8.3`, MySQL-compatible; single company and branch, with multiple warehouses
- Current handoff decision: **NO-GO for employee UAT** until the blocking items in [uat-readiness.md](uat-readiness.md) are resolved

## Safe environment setup

For developer-machine SQLite setup, see [local-sqlite-uat.md](local-sqlite-uat.md). The clean MySQL procedure is also documented there; SQLite records are not automatically copied when changing database drivers.

Use a new, isolated UAT database. Do not point these commands at production or import the source workbooks directly into a live database.

1. Install the PHP version and extensions required by `composer.json` and `.github/workflows/laravel-tests.yml`.
2. Install dependencies with `composer install` and `npm ci`.
3. Copy `.env.example` to `.env`, set a private `APP_KEY`, set `APP_ENV=production`, `APP_DEBUG=false`, and configure only the isolated UAT database credentials. Do not commit `.env`.
4. Build assets with `npm run build`.
5. Run `php artisan migrate --force` and `php artisan db:seed --force`. The default seeder creates reference roles, scoped role permission templates, and permissions; it does not create a login, chart-of-accounts entries, or synthetic transactions. Role access is verified in CI, but review each role before giving it to an employee.
6. Run `php artisan kanozay:admin` interactively to create the first administrator. The command refuses to run once any user exists and asks for the password without placing it in shell history.
7. Create named test users with minimum permissions through the user-management UI, or use `php artisan kanozay:uat-users --confirm-isolated` on a disposable local database for eight synthetic role users with one-time random credentials. Use synthetic names and addresses until the client explicitly approves importing production employee/customer records.
8. Verify `/up`, sign-in, roles, and the UAT checks in [uat-readiness.md](uat-readiness.md) before inviting employees.

These are setup instructions, not evidence that a deployment or UAT environment currently exists.

## Client source files

Files available in the current task include:

- Production types and recipe formula workbook (`.xlsx`)
- Raw materials in factory and owner investment workbook (`.xls`)
- Accounts for database workbook (`.xls`)
- Payroll workbook (`.xlsx`)
- Company letterhead PDF
- Original multicolour and monochrome Kanozay logo images

The production recipe importer stores workbook previews server-side, reports nonpositive quantities and formula weight mismatches, and blocks confirmation while warnings remain. Do not change the source formulas or approve either flagged formula without written client confirmation. The detailed flags are recorded in [uat-readiness.md](uat-readiness.md).

The customer invoice sample, `01-Sample.xlsx`, and documented recipe approvals were not available among the task attachments. Search of the connected Library located a proposal, an agreement draft, and dashboard designs, but did not locate an invoice sample by the expected name. No invoice layout or client-specific financial assumption should be inferred from those missing files.

The uploaded payroll and account workbooks contain confidential personal and financial records. They must remain outside Git. The payroll field layout has informed the HR employee register; no client employee rows have been committed or imported. The tax/transport formulas need client confirmation before any payroll calculation. Use validated previews and explicit confirmation before any future import. Keep `APP_KEY` private and backed up because encrypted employee fields cannot be read if that key is lost.

## Current implementation boundary

The development branch includes guarded inventory workbook import, accounts workbook review, purchasing/manufacturing/quality workflows, partial sales and shareholder integration, payroll accrual/payment/payslips and guarded salary advance issuance and direct repayment, and a fixed-asset register with assignments. The reference chart is not a client-approved mapping. The source production formulas and owner-investment ledger require decisions described in [client-workbook-import-review.md](client-workbook-import-review.md).

GitHub Actions run `41458208435` verified commit `602ca57f7cb3993c0513a5b9bc3ceb4837b0a6f3`: 262 passed, 1 skipped, 1,997 assertions. CI also built the frontend and installed a clean SQLite database with role and chart seeders. This does not verify a shared staging environment, browser print layout, client data reconciliation or MySQL setup. See [uat-readiness.md](uat-readiness.md) for the NO-GO decision and current blockers.

## Backup and recovery

- Take a database backup before each UAT migration or import. Store it outside the web root and restrict access to the client and authorized operators.
- Keep application releases and database backups separately. Do not commit dumps, uploaded workbooks, `.env`, or credentials.
- For MySQL, use an approved account with dump privileges and a protected output destination; for example: `mysqldump --single-transaction --routines --triggers kanozay_uat > /secure-backups/kanozay_uat-YYYYMMDD-HHMMSS.sql`.
- Verify a backup by restoring it to a separate disposable database and checking tables and a sample of records before relying on it.
- Do not run `migrate:rollback`, `migrate:fresh`, or destructive restore commands against production. Prefer a tested forward fix. Restore only into a separately isolated database, then switch the UAT application after validation.

## Release rule

This branch is for development and UAT preparation. Do not merge it into `main` or deploy it to production under this handoff. Publish a client UAT release only after migrations, tests, document previews, access controls, and the end-to-end scenarios in [uat-readiness.md](uat-readiness.md) have been run successfully on a clean isolated environment.
