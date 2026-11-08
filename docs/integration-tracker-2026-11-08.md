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

## Customer, supplier and accounting implementation update

Implemented on the development branch after the tracker was created:

- Customer and supplier profile pages display per-currency balances derived from posted journals, recent related money movements, and receivable/payable documents with remaining amounts net of payments and credits.
- Account profile payment form supports customer receipts and supplier payments, with explicit currency and active cashbox/bank/sarafi selection.
- Optional invoice settlement is executed in the same database transaction as the money movement and balanced journal. A mismatched party, currency, document type, exceeded outstanding amount, or unavailable ledger aborts the transaction.
- Legacy cashbox-only quick forms remain supported. Account-originated payments redirect back to the customer/supplier profile.
- Existing customer and supplier edit forms now support active/inactive status, retaining historical account records.
- Manual trade documents cannot use an automatically mapped third-party ledger as their posting offset.

- Existing unallocated receipts or payments can be applied to outstanding invoices from the account profile without creating new journals, using the existing capped settlement allocation service.
- Active/inactive status is editable on customer and supplier profiles, with new-payment entry suppressed for inactive accounts.
- Manual receivable/payable charges now require UUID submission tokens; a nullable, unique column maintains compatibility with historical and source-generated trade documents.
- Manual trade document offset selection excludes other entities' protected mapped ledgers, and finance editors may access the form.
- Manual charges and settlement allocations accept grouped and localized numeric inputs before decimal validation.

**Development status:** these are committed implementation changes, not acceptance results. Historical legacy import, customer-approved finance policies, automated full cross-module reconciliation and final accounting UAT remain open.

## Additional customer, supplier and accounting development

Committed after the earlier progress update:

- Posted-journal validation protects ordinary trade-document settlements. Allocations require a matching-currency posted invoice journal and matching-currency posted payment journal.
- Journal posting now rejects entries that use a customer, supplier, employee or cash/bank/sarafi mapped ledger belonging to a different currency.
- Customer deposits have dedicated receipts, advance-liability postings, unapplied balances, invoice applications, immutable source history and capped cash/bank/sarafi refunds. Each application creates a debit to the advance liability and credit to the customer receivable. Each refund debits the advance liability and credits the refunding financial account.
- Customer deposits and their applications are kept out of ordinary receivable cash-allocation lists. Deposit applications are recorded as trade-document credits and included in outstanding receivable balances.
- Supplier advances newly posted after this change use the mapped supplier/currency-specific advance asset ledger, so the supplier statement reflects those prepayments. Historical advance postings using arbitrary unmapped asset ledgers are preserved and may require a reviewed legacy reconciliation.
- Supplier advance payment/application replay tokens require unchanged dates and references.
- Customer deposits and refunds are tracked by source, currency and token. Applications and refunds are capped and dated after earlier deposit activity.
- Independent reversal of customer advance-application journals is blocked; the original transaction cannot be changed by reversing a journal outside its document workflow.
- Supplier advance amounts accept localized digits and thousands grouping.

**Important development boundaries:** database migrations and newly authored pages/services have not been executed or validated in a UAT environment at user direction. These are committed code changes, not a declaration of runtime correctness, full phase acceptance or production readiness. Legacy data reconciliation, correction/reversal of financial source documents, bank statement matching, tax/policy sign-off and large-volume integration remain outstanding.

## Purchasing and inventory development update

Development work has moved to purchasing/inventory, with warehouse-scoped physical stock counts, editable/cancellable draft POs, receipt/return source controls, better GRN audit and QC visibility, customer/supplier payable snapshots and localized numeric entry committed.

See [Purchasing and Inventory Development Update](purchasing-inventory-development-2026-11-08.md) for implemented flows and unresolved cross-module development.

**Phase remains INTEGRATION INCOMPLETE.** Warehouse-specific FIFO lot attribution, complex supplier return credits across several invoices, independently invoiced landed costs and full manufacturing/GL reconciliation remain to be developed or reviewed. Testing was deferred at the user's direction; it is not represented as passing.

## Acceptance and release gates

1. Passing SQLite feature suite and isolated regression suite on the latest commit.
2. Passing clean MySQL migration and seed plus *transactional* MySQL tests, including idempotency, supplier returns, stock costing, payroll accruals and journal locks.
3. Client-approved and reconciled production recipe, legacy accounts, opening balances, payroll rules and invoice sample.
4. End-to-end browser exercises with distinct purchaser, storekeeper, accountant, manufacturing, QC, HR, auditor and administrator identities.
5. RTL translations and A4 document previews accepted in Chrome and Safari.
6. Verified backup, isolated restore and rollback/recovery rehearsal with documented sign-off.

No production deployment, `main` merge, or migration into a live accounting database is authorized merely from this tracker.
