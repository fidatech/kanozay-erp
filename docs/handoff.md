# Kanozay ERP development handoff

## Confirmed scope
- Dedicated single-company, single-branch food manufacturing ERP (not multi-tenant).
- Multiple warehouses, cash accounts, banks, Sarafi counterparties, shareholders, manufacturing and laboratory workflows.
- UI must follow user-supplied Kanozay HTML template; do not replace with Breeze/Tailwind default design in final application.
- Local path: `/Users/mastaan/Code/kanozay-erp`.
- Repository: `https://github.com/fidatech/kanozay-erp`.
- Planned MySQL database: `kanozay-erp`; do not assume credentials or that it exists.

## Batch 01
- Laravel 13.38.0 installed with local .env; initial installer used SQLite.
- Laravel Breeze Blade scaffolding added for authentication; public registration routes removed.
- Existing auth UI is temporary scaffold and must be replaced with supplied HTML template.
- npm installation/build and test verification pending.
- No real employee or administrator credentials created.

## Next steps
1. Verify npm build, tests and MySQL connectivity; configure .env privately.
2. Obtain/mount original HTML template assets and integrate pixel-faithful login layout.
3. Build role/permission and user management using Spatie, with guarded admin bootstrap.
4. Add regression tests, then commit and push verified work.
