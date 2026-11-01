# Kanozay ERP UAT readiness report

**Decision: NO-GO for employee acceptance testing**  
**Review date:** 1 November 2026
**Branch:** `development/kanozay-completion`  
**Latest verified commit:** `f93086fa5ff2ae4a64a03adaec3e179f677315af`

## Evidence reviewed

- Repository source, migrations, controllers, services, Blade views, routes, seeders, tests, Composer and npm configuration, and GitHub Actions workflow.
- The branch contains 47 Laravel test files and a push/pull-request Laravel test workflow configured for PHP 8.4, SQLite tests, and a Vite build.
- The current execution workspace has Node.js but no PHP, Composer, or `vendor/`. `npm ci --no-audit --no-fund` completed and `npm run build` passed locally on the current development branch. Laravel tests and migrations cannot run here because `php` is unavailable.
- GitHub Actions run **#95** for commit `f93086fa5ff2ae4a64a03adaec3e179f677315af` completed successfully. The workflow reports **204 passed, 1 skipped, 1593 assertions**, and the frontend asset build completed successfully.
- No authorized staging/UAT deployment was identified or created. No production deployment was attempted.

## Client workbook review

- The supplied liquid recipe sheet uses the water dose formula `SUM(F5:F17)-20`. Its listed non-water doses sum to 7.2996 kg, so the formula evaluates to **-12.7004 kg**. This is a source discrepancy; it has not been corrected or imported as an approved recipe.
- The supplied powder recipe's seven named ingredients total **25.11 kg** against a 25 kg bag. The sheet also contains a separate “Pro Cost” line; its role is unclear and must be confirmed. The formula remains a draft and is not approved for production.
- The raw materials/owner investment, account, and payroll workbooks contain client records. They were read locally for structure and anomaly review; none of their rows or personal data have been committed.
- The payroll workbook contains employee number, name, father’s name, gender, position, phone, national ID, taxpayer ID, monthly salary, and a tax/transport deduction column with spreadsheet formulas. The employee import preview excludes the deduction column, detects row errors and duplicate employee numbers, requires a selected salary currency because the workbook does not identify one, and requires a second upload plus confirmation before writing records. No client employee data has been imported. Confirm the deduction meaning and payroll treatment with Kanozay before calculation or posting.
- Available brand files include the original multicolour and monochrome logo images and a one-page letterhead PDF. The multicolour original is now used on the ERP sign-in and header. The customer invoice sample was not available, so client-specific invoice reproduction remains blocked.

## Blocking issues for UAT

1. **Runtime verification:** PHP is unavailable in this local workspace, so local migrations and tests were not run. CI run #95 verifies commit `f93086fa5ff2ae4a64a03adaec3e179f677315af`; every later backend change requires a fresh successful run.
2. **Workflow coverage:** the current application contains functional accounting, customer/supplier, purchase order, GRN, raw-material stock, landed-cost, recipe approval, production planning, material reservation/issue, batch, and QC code. The HR employee register and confirmed import flow are implemented, but payroll periods, attendance, payroll calculation, approval, payment, and journals are not implemented. Sales invoicing/dispatch/returns, shareholder transaction posting, fixed-asset depreciation, and complete management reports are not ready to validate end to end.
3. **Invoice acceptance:** obtain the client’s actual customer invoice sample and confirm required fields, currency/number formats, and approval/signature layout before implementing the print/PDF template.
4. **Recipe approval:** obtain written Kanozay confirmation of the two formula discrepancies before importing or approving those recipes.
5. **UAT data:** there are no synthetic scenario seeders or preconfigured test users in the repository. Create synthetic users and test transactions only after the deployment is isolated. The default database seeder was changed to create roles/permissions without a predictable default account.
6. **Localization and print validation:** interface language selection exists, but full Dari/Pashto translations, RTL behavior, Jalali date display, and branded A4/RTL document rendering have not been verified.
7. **Staging, backup, and rollback:** a staging environment is not available in this task context. No migration rehearsal, restore drill, or UAT rollback rehearsal has been run.

## Minimum evidence required to change to GO

- Fresh isolated UAT database accepts all migrations and seeders.
- Full Laravel test suite and Vite build pass in CI.
- Authorized roles can complete and are blocked from disallowed actions.
- Purchase → approved order → partial/full GRN → landed cost → stock and payable reconciliation passes with no duplicate posting or over-receipt.
- Approved recipe → production plan → reservation → issue → batch → QC release/rejection → finished-goods stock passes with correct quantities and no release of failed batches.
- Sales invoice, dispatch, return, customer payment and ledger reconcile; invoice matches the client sample after review.
- Payroll calculation and posting match a client-approved scenario; no unapproved statutory rules are assumed.
- Journals balance, reports reconcile to transaction records, and the backup can be restored to a separate database.
- Browser checks pass for desktop/mobile, login and permissions, modals, Select2 workflows, English/Dari/Pashto direction, and print/PDF output.

## Change made during this review

- Replaced the fabricated sign-in mark and generic header leaf with the supplied original multicolour Kanozay logo.
- Removed the default seeder's predictable `test@example.com` login. The database seeder now installs reference roles and permissions only; first-admin creation remains interactive through `php artisan kanozay:admin`.
- Added a regression test for the no-default-user seeding rule. It is **not executed** because PHP and Composer are unavailable in this workspace.
- Corrected landed-cost rounding enum compatibility and expanded the GRN landed-cost tests for proportional allocation, reconciliation, duplicate component protection, and permissions. The branch CI passed on run #74.
- Follow-on changes now use `brick/math` decimal operations for journal posting, trade settlement, currency exchange validation, general ledger running balances, aging, reconciliation, purchase totals, and finance amount display. Purchase and money transaction inputs enforce the database's four-decimal precision. Recipe workbook and approval weight checks also use decimal arithmetic. Regression tests were added for decimal journal balancing, settlement limits, currency conversion rounding, ledger totals, and input precision. These commits are **pending CI verification**; the available GitHub workflow-run connector only returns pull-request-triggered runs and no status is exposed for these branch pushes.
- Commit `28cf1110e256affa1c7a192b86f9ed5e25942854` uses exact decimal allocation totals in sales and purchase overview overdue counts, formats amounts with the shared finance component, and tests fully paid versus partially settled overdue receivables. CI run #92 passed.
- Commit `f93086fa5ff2ae4a64a03adaec3e179f677315af` adds a permission-gated employee register and a two-step payroll workbook import preview. Personal names, phone, national ID, and taxpayer ID are encrypted using `APP_KEY`. The source deduction column is excluded, salary currency is explicitly selected, duplicate employee numbers block import, and preview files are not retained. CI run #95 passed. Payroll periods, attendance, payroll calculation, approval, payment, and journal posting remain incomplete.

## Next implementation order

1. Wait for CI to verify the exact-decimal accounting changes, then continue removing floating-point arithmetic from financial posting paths.
2. Validate migrations and the current purchase-to-GRN and manufacturing-to-QC flows on a clean SQLite test database and synthetic data.
3. Finish the agreed initial-release gaps in sales/invoices, payroll, shareholder ledger, fixed assets, and transaction-backed reports.
4. Obtain the missing invoice sample and client recipe approvals, then build validated import and print acceptance around those confirmed sources.
5. Deploy only to an isolated UAT target, prepare named test users, and run the employee UAT checklist.
