# Kanozay ERP

Kanozay ERP is a Laravel application for Kanozay Food Manufacturing Company. It is being completed for controlled user acceptance testing on an isolated database.

## Requirements

- PHP 8.4.1 or later (the committed `composer.lock` requires it; see the tracker's known limitations)
- Composer
- Node.js 22 and npm
- SQLite for local UAT; MySQL is supported by Laravel and is the planned local environment for client testing

## Local SQLite setup

Follow [Local SQLite UAT setup](docs/local-sqlite-uat.md) to install dependencies, configure an isolated SQLite database, migrate, create the first administrator, and sign in.

## Project status

This development branch is **blocked from UAT**. The evidence-based status of all 25 modules and the D01–D09 integration items, the defects fixed, and the open client decisions are in the [complete implementation tracker](docs/COMPLETE_IMPLEMENTATION_TRACKER.md). Use the [UAT checklist](docs/uat-checklist.md) only for workflows the tracker lists as tested.

## Deployment

Server installation, first administrator, backup and update steps are in the [installation guide](docs/INSTALLATION.md). What a pilot may and may not rely on is in the [pilot release scope](docs/PILOT_RELEASE.md).

## Development checks

```sh
composer install
npm ci
npm run build
php artisan test
```

CI builds the frontend, runs the Laravel suite on SQLite and on MySQL 8, verifies a clean installation on both, and uploads a tested source ZIP on pushes to `development/kanozay-completion`.

## Safety

- Never point a development or UAT environment at production.
- Do not commit `.env`, database dumps, client workbook rows, employee identifiers, passwords, or API credentials.
- Use synthetic data until Kanozay approves a client-file mapping and import preview.
- Do not merge to `main` or deploy to production without authorization.

See [development handoff](docs/handoff.md) for setup, backup and release instructions.
