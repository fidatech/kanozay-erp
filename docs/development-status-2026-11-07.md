# Kanozay ERP development status — 7 November 2026

## Verification

This report describes the latest development branch of fidatech/kanozay-erp. A passing CI suite demonstrates that its covered code paths work in the configured CI environment. It does **not** demonstrate complete client acceptance, safe production deployment, full MySQL UAT, browser/RTL print acceptance or approved source-workbook reconciliation.

The application uses Laravel 13, PHP 8.4 in CI, synthetic SQLite records for feature tests, and a clean MySQL 8.4 migration + seed + route smoke test. This smoke test is not a full MySQL transactional UAT. The deployment package includes Composer dependencies, built Vite assets and source tests. It does not include .env, live data or credentials.

## Verified workflow code areas (not entire module acceptance)

1. Customer and supplier maintenance and statements with WhatsApp balance links.
2. Chart of accounts, posted journals, GL, balances, currencies and finance reporting.
3. Purchasing orders, goods receipt, cost layers, landed costs, physically posted supplier returns, supplier-confirmed debit notes with source-cost and payable caps, and audit-linked GL settlement credits.
4. Raw materials, stock adjustments, physical stock count approvals, warehouse allocations and transfers, warehouse-specific GRN/issue selection.
5. Reorder rules, material requisitions, independent approval and conversion to draft purchase orders with explicit pricing.
6. Production recipes, production-order material reservation/issue, batches, QC and warehouse release, plus receipt-lot incoming-material QC holds and source-lot cost consumption for supplier returns.
7. Laboratory sample registrations, versioned specifications, immutable results, independent review and approved certificates; active nonapproved finished-batch samples block batch approval.
8. Quotations, sales orders, invoices, credit notes, delivery confirmations, customer credit exposure policy and reference price registers.
9. CRM registers for leads, opportunities, activities, follow-ups, complaints, contacts, segments and visits.
10. Payroll snapshot, manual adjustments, approvals, salary liability, payments and payslips; attendance, leave and advances.
11. Fixed asset register, custodians, depreciation policies and monthly journals, maintenance service, transfers and physical disposal snapshots and posted disposal journals requiring acquisition proof, original source amount and approved accounts.
12. Document management category registration, private upload/versioning, reviews, audit history, expiration and retention.
13. Data-backed operational reports, secure CSV exports and batch traceability.
14. Manufacturing equipment and time-slot schedules with collision checks, actual recorded downtime.
15. Shareholder ownership and board resolution review, with approved retained-earnings-capped distributions recorded in their source currency.
16. The CI pipeline now validates clean MySQL 8.4 migrations and seeders as well as the full SQLite test suite.

## Still blocked or incomplete

- Original production recipe workbooks contain unresolved formula errors; approval requires corrected sources from Kanozay.
- The 16-sheet legacy finance workbook and shareholder opening balances require reviewed mappings, currency attribution and reconciliation.
- A client-approved invoice sample, statutory tax/discount policy and official payroll deduction/payslip rules remain unavailable. Reviewed manual allowances/deductions are supported but cannot establish those rules for the client.
- Supplier debit notes now post GL credits when an original payable and supplier-approved credit exist. GRNs do not automatically create purchase payable invoices; original supplier invoices still require matched, reviewed recognition.
- Manufacturing capacity, routing, labor overhead, byproduct costing, packaging specifications, full warehouse-based lot quarantine and automated recall have incomplete end-to-end workflows. Incoming QC holds now protect cost layers with approved specifications; wider lot lifecycle controls are not complete.
- Fixed-asset disposal now supports a reconciled GL posting against the verified original acquisition journal. Legacy assets lacking original acquisition proof remain blocked pending documented reconciliation.
- Many pages of erp_pages.php are intentionally still scaffolded and cannot be represented as fully implemented transactional modules.
- Full Dari/Pashto content and RTL verification, browser/UAT test passes, full MySQL transactional testing and production backup/restore drill remain outstanding.

## Release policy

**Current state: NO-GO for production.** Do not deploy to real Kanozay operations merely because CI passes. Use an isolated test database and reconcile all critical financial, stock and manufacturing records against approved real source files before a production release.

The tested ZIP is a deliverable of working development source, not proof that every company requirement is finished.
