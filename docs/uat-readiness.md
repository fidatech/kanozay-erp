# Kanozay ERP UAT readiness report

**Decision: NO-GO for employee acceptance testing**  
**Review date:** 2 November 2026  
**Branch:** `development/kanozay-completion`  
**Starting commit inspected:** `1f6826a66c26e0bc4d6eebafc105ad88bcbf8da3`

## Evidence reviewed

- Repository source, migrations, controllers, services, Blade views, routes, seeders, tests, Composer and npm configuration, and GitHub Actions workflow.
- The branch contains 47 Laravel test files and a push/pull-request Laravel test workflow configured for PHP 8.4, SQLite tests, and a Vite build.
- The current execution workspace has no PHP, Composer, `vendor/`, or `node_modules/`. Consequently, `php artisan test` stopped with `php: command not found`; `npm run build` stopped with `vite: not found`. Neither result is a passing test or build.
- The repository status/check query returned no commit status entries. The available workflow-run query returned no runs for the inspected commit; it is limited to PR-triggered runs, so this does not prove that no push workflow ran.
- No authorized staging/UAT deployment was identified or created. No production deployment was attempted.

## Client workbook review

- The supplied liquid recipe sheet uses the water dose formula `SUM(F5:F17)-20`. Its listed non-water doses sum to 7.2996 kg, so the formula evaluates to **-12.7004 kg**. This is a source discrepancy; it has not been corrected or imported as an approved recipe.
- The supplied powder recipe's seven named ingredients total **25.11 kg** against a 25 kg bag. The sheet also contains a separate “Pro Cost” line; its role is unclear and must be confirmed. The formula remains a draft and is not approved for production.
- The raw materials/owner investment, account, and payroll workbooks contain client records. They were read locally for structure and anomaly review; none of their rows or personal data have been committed.
- Available brand files include the original multicolour and monochrome logo images and a one-page letterhead PDF. The multicolour original is now used on the ERP sign-in and header. The customer invoice sample was not available, so client-specific invoice reproduction remains blocked.

## Blocking issues for UAT

1. **Runtime verification:** install the project’s supported PHP/Composer dependencies in a controlled build environment, then run migrations, the complete Laravel suite, and frontend build. Fix every failure and attach actual CI evidence.
2. **Workflow coverage:** the current application contains functional accounting, customer/supplier, purchase order, GRN, raw-material stock, landed-cost, recipe approval, production planning, material reservation/issue, batch, and QC code. However, several business areas still route to generic module pages. Sales invoicing/dispatch/returns, payroll, shareholder transaction posting, fixed-asset depreciation, and complete management reports are not ready to validate end to end.
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

## Next implementation order

1. Restore a PHP 8.4 + Composer environment and run the Laravel tests, migrations, and Vite build.
2. Review/fix any failures, then validate the current purchase-to-GRN and manufacturing-to-QC flows on synthetic data.
3. Finish the agreed initial-release gaps in sales/invoices, payroll, shareholder ledger, fixed assets, and transaction-backed reports.
4. Obtain the missing invoice sample and client recipe approvals, then build validated import and print acceptance around those confirmed sources.
5. Deploy only to an isolated UAT target, prepare named test users, and run the employee UAT checklist.
