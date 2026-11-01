# Kanozay ERP

Kanozay ERP is a Laravel application for Kanozay Food Manufacturing Company. It is being completed for controlled user acceptance testing on an isolated database.

## Requirements

- PHP 8.3 or later (CI currently uses PHP 8.4)
- Composer
- Node.js 22 and npm
- SQLite for local UAT; MySQL is supported by Laravel and is the planned local environment for client testing

## Local SQLite setup

Follow [Local SQLite UAT setup](docs/local-sqlite-uat.md) to install dependencies, configure an isolated SQLite database, migrate, create the first administrator, and sign in.

## Project status

This development branch is **not approved for full employee UAT**. Sales invoice/dispatch, payroll calculation and posting, shareholder posting, fixed assets, full client data mapping, and visual invoice/RTL acceptance remain incomplete. Read the evidence and blockers in [UAT readiness](docs/uat-readiness.md), and use the [UAT checklist](docs/uat-checklist.md) only for workflows marked ready.

## Development checks

```sh
composer install
npm ci
npm run build
php artisan test
```

CI runs the Laravel suite using SQLite and builds the frontend on pushes to `development/kanozay-completion`.

## Safety

- Never point a development or UAT environment at production.
- Do not commit `.env`, database dumps, client workbook rows, employee identifiers, passwords, or API credentials.
- Use synthetic data until Kanozay approves a client-file mapping and import preview.
- Do not merge to `main` or deploy to production without authorization.

See [development handoff](docs/handoff.md) for setup, backup and release instructions.
