# Kanozay ERP integration tracker — 8 November 2026

Branch: `development/kanozay-completion`. Release state: **NO-GO**.

Status definitions:
- **Core developed**: functional implementation exists and has partial automated coverage; not equivalent to client acceptance.
- **Integration incomplete**: more cross-module work, validation, or acceptance is needed.
- **Blocked**: requires client evidence or operational access.
- **Verified**: only after named acceptance criteria and linked test evidence pass.

| Phase | Current status | Evidence and outstanding requirements |
|---|---|---|
| Users, roles and permissions | Core developed; client UAT pending | Scoped role seeding and permission tests exist. Verify representative users in real browser sessions, separation of duties and approval paths. |
| Customers, suppliers and accounting | Integration incomplete | Parties, balances, GL, statements and settlements exist. GRN-matched supplier invoices and AP journals exist; expanded financial safety tests added. Verify supplier return/debit-note/payment and reconciliation scenarios end to end. Legacy account mapping remains client-blocked. |
| Purchasing and inventory | Integration incomplete | PO, GRN, landed costs, stock movement, valuation, warehouse controls and returns exist. Validate real cost/FX conversions, partial invoicing, returns after billing, FIFO valuation and locked-period constraints against MySQL records. |
| Manufacturing and production | Integration incomplete | Recipes, order issue, batches, waste and basic scheduling exist. Correct source formulas; verify overhead, packaging, capacity, byproducts and finished-goods cost linkage. |
| Laboratory and quality control | Integration incomplete | Versioned specifications, samples, independent review and holds exist. Verify source-lot quarantine, manufacturing release, rejection, customer returns and recall traceability. |
| Sales and delivery | Integration incomplete | Quotations, orders, invoices, dispatch, returns and credit notes exist. Obtain approved invoice template and policies; verify returns/refunds, batches, A/R, taxes and A4 printing. |
| CRM and documents | Core developed; client UAT pending | CRM registers and private document version/review workflows exist. Verify employee permissions, notifications and retention against client procedures. |
| HR, payroll and fixed assets | Integration incomplete | Attendance, payroll, advances, withholding policy and lifecycle accounting code exists. Obtain approved payroll deductions; verify payments, tax liabilities, depreciation, disposals and original acquisition evidence. |
| Shareholders and financial controls | Integration incomplete | Resolutions, distributions, audit trail and period closing exist. Client must reconcile opening capital and ownership entries by currency; validate approvals and lock-period behavior. |
| Reports, translations and deployment | Incomplete / blocked | Operational reports and exports have automated tests. Full Dari/Pashto, RTL, print acceptance, transactional MySQL UAT, migration rehearsal, backups, restore drill and live data reconciliation are pending. |

## Development work on 8 November

- Hardened `MatchedSupplierInvoiceService`: an idempotent submission token can only replay if GRN, reference, invoice and due dates, currency-derived total, inventory ledger and all positive receipt-line quantities match the posted invoice.
- Added feature tests for supplier invoice posting and journal totals, identical retries, overbilling protection, erroneous invoice-total rollback, duplicate supplier reference and altered submission payloads.
- Added an early isolated supplier invoice test step in GitHub CI to obtain actionable failure feedback before the full suite.
- Full test and MySQL transactional acceptance must be verified from the actual GitHub Actions results; do not assume passing status from a successful commit.

## Acceptance and release gates

1. Passing SQLite feature suite and isolated regression suite on the latest commit.
2. Passing clean MySQL migration and seed plus *transactional* MySQL tests, including idempotency, supplier returns, stock costing, payroll accruals and journal locks.
3. Client-approved and reconciled production recipe, legacy accounts, opening balances, payroll rules and invoice sample.
4. End-to-end browser exercises with distinct purchaser, storekeeper, accountant, manufacturing, QC, HR, auditor and administrator identities.
5. RTL translations and A4 document previews accepted in Chrome and Safari.
6. Verified backup, isolated restore and rollback/recovery rehearsal with documented sign-off.

No production deployment, `main` merge, or migration into a live accounting database is authorized merely from this tracker.
