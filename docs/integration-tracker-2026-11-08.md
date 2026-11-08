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

## Warehouse-specific FIFO lot development

The Purchasing/Inventory development branch now contains per-warehouse FIFO lot positions, atomic lot-preserving transfers, audited manual attribution of historical positions, a warehouse FIFO valuation report, origin-lot supplier return consumption and production reservation checks against attributable QC-released lots. See [Warehouse FIFO Lot Integration](warehouse-fifo-lot-location-development-2026-11-08.md).

**Production caution:** Existing FIFO records were not assigned guessed locations. Historical stock with unknown lot provenance now requires explicit reconciliation before certain transfers, stock issues or production reservations can proceed. Historical inventory with no valuation layer is still a migration gap. This is a development change, not a tested rollout.

## Manufacturing and Production development update

Committed new draft-recipe editing, packaging line classification and issued-packaging checks, manufacturing equipment occupancy/downtime controls, more defensive approved-routing snapshots, production-plan idempotency, full ingredient/packaging source-lot forward and reverse traceability, split frozen batch material cost, actual-versus-planned issue variance reporting, and final source-QC-hold checks before finished-goods release.

[Manufacturing and Production development detail](manufacturing-production-development-2026-11-08.md) records the implemented code, the limitations, and the outstanding formulas, capacity, by-products, WIP/COGS journals and warehouse-specific reservations.

**Status remains integration incomplete.** No tests or migrations were run at user direction. Historical FIFO source records without verifiable consumption will require reconciliation before some older batches can be released.

## Laboratory and quality-control development

Added sample-time snapshots of approved QC specification versions, independent incoming FIFO lot holds/releases, formal finished-product QC requirements where specifications exist, downstream dispatch restrictions for newly held source-material lots, and source-linked nonconformance/root-cause/independent-closure records. The register now identifies downstream production batches consuming a held incoming lot.

[Laboratory and Quality Development](laboratory-quality-development-2026-11-08.md) documents current code and outstanding physical disposition, historical evidence, company quality policies and recall workflows.

**Still integration incomplete.** New database migrations, QA scenarios and MySQL/browser acceptance were not executed at user direction. The new strict hold gates depend on reviewed historical FIFO source data; they must be staged and reconciled before live activation.

## Sales and Delivery development update

Developed sales-order fulfillment summaries across posted invoice lines, delivery notes, proof-confirmed customer receipt and customer-credit returns. Added audited cancellation only for wholly unbilled linked sales orders. Delivery notes now reject credited returned quantities, require original invoice date or later, and protect in-transit documents from unsupported cancellation. Proof of delivery is required for all customer-received confirmations, while returned depot shipments retain source tracking without generating inventory entries.

Every **new** customer return credit enters QC quarantine and saleable stock can only be restored by a later independent QC disposition. The former immediate-restock form path was removed. Historical direct restocks remain labeled as legacy cases that require evidence review. Sales numeric forms normalize grouped and Pashto/Dari digits before normal validation.

[Sales and Delivery development detail](sales-delivery-development-2026-11-08.md) documents source changes and remaining finance/COGS, legacy-return, proof-of-receipt, tax/discount and customer-recall gaps.

**Status: advanced development, not full integration.** Migrations, automated testing, web UAT and deployment were deferred on user instruction; a committed feature is not represented as a production-verified function.

## HR, payroll and fixed-asset development update

Added optional verified hire/separation dates and protected employee offboarding, with employee payroll eligibility based on actual pay-period employment dates. Replaced the former one-click employee status toggle with evidence-backed employment-date correction. Payroll now requires a documented, current per-employee salary/attendance/leave/component review by an authorized HR and finance user before the salary accrual journal can be posted, and preserves append-only review history. Changes to reviewed evidence invalidate the review. Backdated attendance corrections and approved leave affecting already-posted payroll are blocked pending a controlled adjustment process.

Added finance-backed fixed-asset capitalization reconciliation linking each asset to a unique posted acquisition PPE debit without creating a second accounting entry. Depreciation posting now requires unreversed acquisition evidence and an eligible period. The asset register shows original-currency accumulated depreciation and book values, flags reversed or unmatched capitalizations, and connects disposal postings to the verified source acquisition where available.

[HR, Payroll and Fixed Assets development detail](hr-payroll-fixed-assets-development-2026-11-08.md) describes the changes and remaining employee rehire, salary amendment, opening-ledger and pooled asset-capitalization work.

**Status: advanced source development; integration incomplete.** No migrations, runtime tests, browser UAT or deployment were performed. Historical HR and PPE records may need documented reconciliation before payroll or depreciation operations can proceed.

## Shareholders and Financial Controls development update

Added original-currency, source-backed shareholder statements combining posted capital contributions, approved capital drawings and profit distributions, including sanitized full CSV export and per-currency activity totals. Posted shareholder capital and profit movements now create corresponding cashbook/bank transactions and cannot be independently reversed through the generic General Ledger reversal command.

Board distribution resolutions now require explicit, independently reviewed per-recipient entitlements, with append-only allocation history, both recipient and aggregate payout ceilings, confirmed retained earnings as of the payout date, and original-currency matching. Ownership % is no longer treated as implicit payout entitlement. Historical ownership can be reviewed as of a selected date using the recorded amendment history, with zero-interest exits and share-class percentage safeguards.

Finance month-close now has a source readiness report and checks for draft GL/payroll, unreconciled bank statement drafts, unposted asset disposals, and shareholder cash movements missing cashbook postings. It cannot close the current/incomplete month; it requires explicit acknowledgement of manually reviewed external source evidence. Independent reopening and closed-period posting restrictions remain.

[Shareholders and Financial Controls development detail](shareholders-financial-controls-development-2026-11-08.md) describes the changes and unresolved board evidence, old cashbook data, controlled reversal, treasury, statutory distribution and external reconciliation policies.

**Status: integration advanced, not production-verified.** New migrations, tests, MySQL schema and browser UAT were deliberately not run. Legacy approved resolutions without a documented allocation and old shareholder postings without cashbook movement need explicit reconciliation before these stricter controls are activated.

## Reports, Analytics and first-language translations

Replaced the generic Reports overview placeholder with a read-only, permission-controlled operations dashboard. Added original-currency posted invoice totals and customer credit-note values without treating them as cash or profit; purchase/production/delivery/journal counts; current nonconformance and manual incoming-lot hold counts; and formal laboratory sample status summaries.

Added four detailed source-backed report registers: customer delivery notes (using actual tracking proof), customer credit notes, formal quality samples, and nonconformance investigations. Corrected existing inventory, raw-material-plus-packaging costing, and batch-traceability scope descriptions to match their implemented source records. Added navigation to the underlying warehouse, manufacturing, QC, logistics and finance registers. Strengthened UTF-8 CSV exports and spreadsheet formula escaping.

Extended the existing `en`/`fa`/`ps` interface locale support for the dashboard, reporting overview, register filters, navigation labels and many report columns, with English fallback where translation remains incomplete. Original amounts retain exact source currencies and string-decimal formatting.

[Reports, Analytics and Language Development](reports-analytics-i18n-development-2026-11-08.md) documents current source status and the remaining complete-system translation, financial reconciliation and report UAT dependencies.

**Status: source development advanced, not production-ready.** No automated tests, migrations, runtime browser inspection, MySQL checks or deployment were performed. Reports describe recorded source documents; they are not independently certified financial statements.

## Finished-goods dispatch cost reconciliation and continued localization

Added a permission-gated, original-currency finished-goods dispatch cost reconciliation report with filters and full CSV export. Each gross dispatch line checks released batch status, QC-accepted quantity, complete frozen material FIFO value, one source currency, independent overhead approvals, and whether expense journals existed and had valid evidence as of the dispatch date. This is a **nonposting indicative source-cost report**, not COGS or booked finished-goods inventory. Invalid historical cost records are explicitly blocked from valuations rather than guessed.

Overhead cannot now be submitted/approved after its batch has been dispatched or rejected. Finished-goods dispatch is blocked while production overhead is pending review. Finance month-close readiness warns about dispatched finished-goods lines that need WIP, finished inventory and COGS GL reconciliation; it does not invent accounting postings.

Expanded Pashto/Dari report navigation and labels for the dispatch cost review while retaining fallback to English where full transactional translations are pending.

[Finished-goods dispatch cost reconciliation update](finished-goods-dispatch-cost-reconciliation-2026-11-08.md) documents the source-level implementation and the **unresolved** full acquisition-to-WIP-to-finished-stock-to-COGS posting chain, historic source-document reconciliation and accounting acceptance.

**Status: cost evidence and review advanced; full finished-goods GL capitalization/COGS development outstanding.** No automated tests, migrations, browser UAT, source syntax lint, MySQL verification or deployment performed at user's direction.

## Manufacturing GL, invoice COGS and physical-stock reconciliation hardening

The development branch already contains approved original-currency manufacturing ledger policies and atomic WIP → finished-goods capitalization, sales invoice COGS journals, and independently QC-released customer return asset recoveries. This batch strengthened that source code without introducing a second accounting engine.

Prevented legacy physical dispatch from being retroactively capitalized without its missing historic COGS; disabled standalone finished-goods stock dispatch outside the invoiced COGS workflow; froze approved overhead after batch capitalization/dispatch; checked actual supplier invoice GL asset debit and exact original FIFO purchase cost values; verified each COGS posting against source batch/quantity/date/warehouse dispatch; and required source-matching credit notes for returned cost recovery. Replayed sales invoice tokens now require identical original financial and dispatch payloads.

Added the **Manufacturing Inventory and COGS Reconciliation** report and CSV, comparing actual warehouse receipts, physical dispatches, costed sales, QC-stock recovery, original-currency WIP/finished-stock/COGS journal states and remaining financially capitalized stock value. It flags source record mismatches and never infers missing historical value. Finance month close now blocks unlinked warehouse dispatches and reversed/unposted manufacturing cost journals in addition to missing inventory source postings. Navigation includes the new report with Dari/Pashto titles.

[Manufacturing Financial Integration Hardening](manufacturing-financial-integration-hardening-2026-11-08.md) documents implemented controls and remaining historical remediation, landed costs, rejected/reworked batch variance accounting, full translation and deployment dependencies.

**Status: substantial GL source development, not production-verifiable completion.** No migrations, automated tests, lint, real-browser UAT or MySQL deployment were performed on user instruction.

## Manufacturing, finished-goods assets and COGS financial integration

Implemented a controlled original-currency manufacturing cost ledger policy: independently approved raw inventory asset, WIP asset, finished-goods asset and COGS expense accounts. Physical production cost snapshots alone remain advisory until finance verifies each consumed FIFO material's original GRN, posted supplier inventory debit and current QC disposition.

For a one-currency, fully verified released batch, the finance workflow can now post two balanced, source-linked journals: debit WIP/credit raw material and approved original overhead expense, then debit finished goods/credit WIP. GL asset sufficiency, source journal dates, unvalued or opening FIFO quantities, mixed source currencies, unreconciled landed costs and missing supplier billing block unsupported capitalization.

New sales invoices now atomically post an original-currency COGS journal for each physically dispatched finished-good batch. The batch must already be capitalized; a GL or COGS failure rolls back the invoice and stock. Per-line COGS journals and cost currency are visible to authorized finance/reporting users. Independent release of a quarantined return now posts finished inventory asset recovery and reverses that original COGS; scrap and held returns do not restore an asset. Finance may reconcile missing COGS on historic invoices only if original posted capitalization already existed by the invoice date.

Added cost accounting navigation and forms, protected COGS/asset journals against standalone GL reversal, froze overhead after capitalization, showed posted-vs-advisory manufacturing readiness and extended monthly financial close checks for missing WIP, finished inventory, COGS and return recovery postings. Corrected posted journal balance checks to compare **each currency independently**.

[Manufacturing-to-GL Cost and COGS Integration](manufacturing-gl-cogs-integration-2026-11-08.md) details the implemented financial workflow and outstanding historic inventory, mixed-currency, landed costs, business-policy and corrections dependencies.

**Status: source integration advanced; migration and runtime verification still deferred.** Costing policies and historic source evidence need signed factory approval. Live deployment must not be attempted until staging migrations, original-cost audit and controlled acceptance are complete.

## Landed-cost GL journals and waste/rework accounting controls

Added an independently approved original-purchase-currency landed-cost capitalization path: each immutable GRN freight/customs/handling component must match a unique, unreversed posted expense debit, a signed finance approval and the approved raw-inventory GL ledger. The resulting balanced journal debits inventory and credits original expense without a second stock receipt or FX conversion. FIFO lot issues and WIP capitalization now demand this source GL evidence for a landed-cost lot. Source expense lines cannot fund both landed cost and production overhead, and their source/reclassification journals cannot be reversed independently. The purchase-order receipt page offers finance the expense-match action and displays posted status. Supplier returns of such lots remain blocked until a dedicated asset write-off or recovered landed-cost credit exists; regular supplier debit notes cover only invoiced base purchase value.

Added independent production material waste financial classification (normal process loss under signed policy, abnormal loss or rework pending). Abnormal losses and unreviewed/rework cases cannot be silently included in accepted finished-stock capitalization. Completed rework requires a separate authorized finance reviewer, append-only event history, physical completion and approved policy evidence, followed by a newly approved QC sample. The original material issue is never deducted twice. A company-wide waste/rework review report and safe CSV display missing source decisions and current evidence; relevant reporting navigation and filters are translated into Pashto and Dari. Cost-readiness, manufacturing GL reconciliation and month-close now identify these exceptions.

[Landed-cost and Waste Financial Controls](landed-cost-and-waste-financial-controls-2026-11-08.md) documents implemented source and outstanding abnormal waste expense reclassification, historic landed-cost supplier-return corrections, legacy asset/COGS reconciliation and final translation.

**Status: cross-module source controls advanced; source-currency abnormal loss expense and landed-cost supplier-return journals incomplete.** Tests, migrations, syntax lint, browser UAT, MySQL validation and deployment remain intentionally deferred.

## Abnormal production loss and supplier landed-return GL posting

Implemented exact original-currency abnormal waste cost approvals against immutable FIFO consumption records. Independent finance approval now documents source-lot waste quantity, source cost and a distinct non-COGS expense ledger. Manufacturing batch capitalization credits full raw material, debits the approved abnormal-loss expense and debits WIP with only the remaining eligible material plus overhead. Net eligible cost becomes the finished-goods asset and subsequent source of invoice COGS. Source values are preserved separately in `production_batch_cost_postings.abnormal_waste_amount`; report registers distinguish approved-only cost from expense actually posted and compare physical amounts with GL debits. Warehouse release is permitted only after independently reviewed FIFO attribution; unresolved/rework losses remain blocked. Month-close also distinguishes approved-and-posted abnormal expenses from unresolved ones.

Supplier returns of GRN lots containing freight, customs or other capitalized landed costs now use a two-stage flow. The request records original source quantities but leaves stock intact until an independent finance reviewer approves a nonrecoverable original-currency landed-cost expense write-off against the exact inventory asset ledger. The one journal and all physical lot returns post atomically. The original base supplier payable remains governed by the separate posted debit-note workflow. Cancelled pending requests release their proposed quantities without GL or stock moves. Finance close blocks unapproved or reversed landed-return journals; the return document shows approval status and original write-off reference.

[Abnormal Waste and Supplier Landed-Return Accounting](abnormal-waste-and-supplier-landed-return-accounting-2026-11-08.md) documents new source tables, explicit source-cost and reviewer requirements, deferred historic corrections and recoverable supplier/freight claims.

The ordinary supplier debit-note workflow now insists on the full source-priced return base value, since crediting less would leave removed material value in the inventory GL asset. A disputed or partial credit requires a separate approved loss/receivable correction. The landed-cost write-off verifies that the original-currency asset balance can support its credit, and cancelled return tokens cannot be replayed.

**Development status: abnormal FIFO loss and nonrecoverable landed-cost supplier-return code committed, not runtime verified.** Formal recoverable landed-cost claims, legacy source corrections, complete multilingual transactional UI and production release verification remain. User requested no tests, migrations, syntax lint, MySQL/UAT or deployment at this stage.

## Acceptance and release gates

1. Passing SQLite feature suite and isolated regression suite on the latest commit.
2. Passing clean MySQL migration and seed plus *transactional* MySQL tests, including idempotency, supplier returns, stock costing, payroll accruals and journal locks.
3. Client-approved and reconciled production recipe, legacy accounts, opening balances, payroll rules and invoice sample.
4. End-to-end browser exercises with distinct purchaser, storekeeper, accountant, manufacturing, QC, HR, auditor and administrator identities.
5. RTL translations and A4 document previews accepted in Chrome and Safari.
6. Verified backup, isolated restore and rollback/recovery rehearsal with documented sign-off.

No production deployment, `main` merge, or migration into a live accounting database is authorized merely from this tracker.
