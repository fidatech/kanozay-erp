# Kanozay ERP development status — 7 November 2026

## Verification

This report describes the latest development branch of fidatech/kanozay-erp. A passing CI suite demonstrates that its covered code paths work in the configured CI environment. It does **not** demonstrate complete client acceptance, safe production deployment, MySQL UAT, browser/RTL print acceptance or approved source-workbook reconciliation.

The application uses Laravel 13, PHP 8.4 in CI and synthetic SQLite records for functional tests. The deployment package includes Composer dependencies, built Vite assets and source tests. It does not include .env, live data or credentials.

## Verified workflow code areas (not entire module acceptance)

1. Customer and supplier maintenance and statements with WhatsApp balance links.
2. Chart of accounts, posted journals, GL, balances, currencies and finance reporting.
3. Purchasing orders, goods receipt, cost layers, landed costs, physically posted supplier returns (credit reconciliation pending).
4. Raw materials, stock adjustments, physical stock count approvals, warehouse allocations and transfers, warehouse-specific GRN/issue selection.
5. Reorder rules, material requisitions, independent approval and conversion to draft purchase orders with explicit pricing.
6. Production recipes, production-order material reservation/issue, batches, QC and warehouse release.
7. Laboratory sample registrations, versioned specifications, immutable results, independent review and approved certificates; active nonapproved finished-batch samples block batch approval.
8. Quotations, sales orders, invoices, credit notes, delivery confirmations, customer credit exposure policy and reference price registers.
9. CRM registers for leads, opportunities, activities, follow-ups, complaints, contacts, segments and visits.
10. Payroll snapshot, manual adjustments, approvals, salary liability, payments and payslips; attendance, leave and advances.
11. Fixed asset register, custodians, depreciation policies and monthly journals, maintenance service, transfers and physical disposal snapshots.
12. Document management category registration, private upload/versioning, reviews, audit history, expiration and retention.
13. Data-backed operational reports, secure CSV exports and batch traceability.
14. Manufacturing equipment and time-slot schedules with collision checks, actual recorded downtime.

## Still blocked or incomplete

- Original production recipe workbooks contain unresolved formula errors; approval requires corrected sources from Kanozay.
- The 16-sheet legacy finance workbook and shareholder opening balances require reviewed mappings, currency attribution and reconciliation.
- A client-approved invoice sample, tax/discount policy, payroll deductions and payslip rules remain unavailable.
- Goods receipt posting and physical supplier returns currently leave related AP and supplier credit settlement requiring a distinct approved financial workflow.
- Manufacturing capacity, routing, labor overhead, byproduct costing, packaging specifications, lot-specific incoming QC quarantine and automated recall have incomplete end-to-end workflows.
- Fixed-asset disposal journals and historical acquisition reconciliation are not automated; posted disposals explicitly remain pending financial reconciliation.
- Many pages of erp_pages.php are intentionally still scaffolded and cannot be represented as fully implemented transactional modules.
- Full Dari/Pashto content and RTL verification, browser/UAT test passes, MySQL migration and production backup/restore drill remain outstanding.

## Release policy

**Current state: NO-GO for production.** Do not deploy to real Kanozay operations merely because CI passes. Use an isolated test database and reconcile all critical financial, stock and manufacturing records against approved real source files before a production release.

The tested ZIP is a deliverable of working development source, not proof that every company requirement is finished.
