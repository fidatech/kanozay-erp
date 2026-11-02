# Kanozay ERP UAT readiness report

**Decision: NO-GO for employee acceptance testing**
**Review date:** 2 November 2026
**Branch:** `development/kanozay-completion`
**Latest verified commit:** `602ca57f7cb3993c0513a5b9bc3ceb4837b0a6f3`

## Verification evidence

- GitHub Actions run `41458208435` passed: **262 tests passed, 1 skipped, 1,997 assertions**. The frontend build, Laravel test suite, clean SQLite migration, default role/permission seeding, reference chart seeding, and route registration steps completed successfully.
- The CI SQLite database is disposable and contains synthetic/reference data only. The client workbooks were not committed or imported into CI.
- The local workspace has Node.js and passed `npm run build` and `git diff --check`. It has no PHP or Composer, so Laravel commands were verified in CI.
- No authorized shared staging environment, human browser workflow run, print/PDF visual check, or MySQL UAT migration has been verified.

## Implemented workflow slices

- Procurement, GRN, raw-material stock movements, landed-cost allocation, material reservation/issue, production batches, QC decisions, and finished-goods release have code paths and feature tests.
- Inventory valuation uses cost layers where source costs exist. Unvalued or legacy balances remain visible rather than assigned invented prices.
- Quotations, sales-order conversion, invoice/batch dispatch, customer returns/credit notes, receivable journals and payment flows have partial integrations. The original invoice sample has not been found, and client print acceptance is pending.
- Payroll now has employee records and an optional department register, a guarded workbook import, monthly salary snapshots, reviewed payable adjustments, approval/accrual journals, individual cash/bank/sarafi payments and branded payslips. Attendance records and corrected status are audited; leave requests have single-use review decisions, and approved leave dates appear during payroll review. Salary advances can be issued against an explicitly selected receivable ledger and mapped cash account with duplicate-token protection; direct repayments are capped at the outstanding advance with linked journals. Payroll deduction rules remain incomplete. No automatic tax, leave or advance deduction formula is applied.
- Fixed assets now have a register, required source references, categories and assignment/return history. Depreciation schedules, disposal and asset accounting remain incomplete.
- The raw-material workbook preview classifies packaging, excludes on-way/ordered quantities from opening stock, detects duplicates and supports reviewed confirmation. The accounts workbook review lists its 16 legacy ledgers without posting historical transactions.

## Blocking decisions and acceptance gaps

1. The production workbook contains a calculated negative water quantity and a powder ingredient total above the stated package target. These recipes must not be approved until corrected source formulas are confirmed by Kanozay.
2. The 16-sheet accounts workbook requires approved account, date, currency, party and opening-balance mappings. The owner-investment movements also need approved classification and currency treatment.
3. Payroll source data has no stated salary currency or approved tax/transport deduction rule. Attendance, leave, advance and payroll scenarios need client validation before real payroll processing.
4. `01-Sample.xlsx` and the sample customer invoice have not been located in accessible project files. Invoice terminology/layout cannot be declared accepted from the present evidence.
5. End-to-end employee browser tests, visual A4/RTL print checks, clean MySQL UAT setup, human role-user exercise, backup/restore drill and client reconciliation against underlying transactions are outstanding.

## Decision rule

Keep the application in an isolated UAT database with synthetic records. Change the decision to GO only after the acceptance criteria in `docs/uat-checklist.md` are exercised on the target environment, source documents are reviewed, and critical data-integrity issues are closed. No production deployment is authorized.
