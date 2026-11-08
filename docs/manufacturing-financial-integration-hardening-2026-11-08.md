# Kanozay ERP — Manufacturing inventory, WIP and COGS integration hardening

Date: 2026-11-08  
Branch: `development/kanozay-completion`  
Status: **Code changes committed; tests, migrations, runtime UAT and deployment deferred.**

## Existing posting framework (reviewed in this phase)

The development branch already contains:
- `manufacturing_cost_ledger_policies` to bind a specific original cost currency to the independent raw-material inventory asset, WIP asset, finished-goods inventory asset and COGS expense ledgers. Independent finance approval is required.
- `production_batch_cost_postings` with one WIP input reclassification and one finished inventory capitalization journal per released, fully sourced production batch.
- `production_invoice_cogs_postings` with one source-currency finished-goods asset credit / COGS debit journal per physically dispatched sales invoice line, within the same database transaction as sales invoicing.
- `production_return_cost_postings` with inventory asset recovery and COGS reversal only after separately authorized physical customer-return QC release.

Those are pre-existing source-level features on this development branch. Their existence must not be mistaken for fully reconciled production books or runtime acceptance.

## New development completed in this batch

### Financial source and posting integrity
- The batch WIP posting service now blocks *all* initial capitalization for a batch with an earlier standalone physical dispatch or customer invoice line, even if backdating the journal to a date before the sale would otherwise pass. Historic inventory and COGS must be repaired by an independently reviewed backfill procedure.
- For each consumed GRN FIFO source lot, the service now checks that the immutable source receipt quantity and cost equal the FIFO layer snapshot, that supplier matched invoice quantities equal the original quantity and that the sum of the matched invoice line values equals the original FIFO purchase cost.
- Each supplier matched invoice must have a posted, unreversed journal debit to the selected original-currency raw inventory asset ledger equal to the recorded matched invoice amount.
- Cost capitalization requires the independently approved overhead allocation to have existed on or before the requested original-currency accounting date.
- Production overhead submissions and approvals are blocked after any part of the batch has been dispatched **or** the batch has already been financially capitalized, preventing a frozen inventory cost from being altered without a controlled accounting amendment.

### Sales and returned-goods safeguards
- Disabled the previous standalone finished-goods dispatch controller action. Saleable stock now leaves through the sales invoice workflow, which writes the physical stock issue and original-currency COGS journal together. Internal warehouse transfers remain separate, and historical direct dispatch documents are not hidden.
- The finished-good screen now links to invoice-backed dispatch instead of offering a standalone posting dialog.
- COGS posting now verifies the linked source dispatch has one batch/product/quantity/date record matching its invoice line and has not been assigned to another invoice line.
- QC-approved customer return asset recovery verifies the original credit note's returned batch and package quantity and refuses recovery dated before the credit note.
- Existing direct-restock quantities are no longer counted twice if the same credit line also has a QC quarantine record. The finished goods history shows actual QC disposition and labels historical direct-restock exceptions.
- Duplicate invoice tokens return the original invoice only if customer, currency, invoice and due dates, source reference, sales revenue ledger, every physical dispatch token, warehouse, source batch, accepted order-line association, package quantity and unit price all match the original posted document.

### Financial reconciliation and month close
- Added a permission-gated **Manufacturing Inventory and COGS Reconciliation** report and full source-safe CSV export.
- The report independently lists QC-accepted units, posted physical warehouse receipts, physical dispatches, independently QC-restocked returns, posted costed sales and posted inventory-asset recoveries per batch.
- It compares physical remaining quantities to financially costed remaining quantities and derives remaining original-currency finished-goods asset cost: authorized capitalization less posted COGS plus independently recorded QC return recovery.
- The report validates source WIP and finished inventory journal status, reversal state and expected individual asset-ledger amounts, and checks each COGS/return journal debit and credit against its immutable source posting.
- It identifies unmatched or reversed journal sources, invoice/batch/quantity mismatches, missing initial capitalization, legacy direct-restock records, missing return-cost recovery, over-dispatch and negative financial inventory value.
- The report uses actual warehouse receipt movements for physical stock and does not count merely QC-approved, not-yet-released packages as warehouse assets.
- Monthly finance close now blocks unmatched standalone finished-goods dispatches and any corresponding unposted or reversed WIP, finished-inventory, COGS or QC-return recovery journals, in addition to the existing missing source posting checks.
- The financial reconciliation report is linked from the Manufacturing Cost Accounting screen and Reports menu; its navigation title is translated into Pashto and Dari.

## Remaining development and reconciliation before production handoff

- **Legacy backfill workflow:** Historic standalone dispatches, opening batch stock, invoices posted without COGS and directly restocked old customer credit notes require reviewed, documented correcting entries. The application blocks unsafe automatic backdating rather than inventing missing original cost evidence.
- **Landed costs and partial supplier returns:** Consumed material lots with unreconciled landed costs remain blocked from WIP capitalization. Supplier debit notes and original raw-material ledger need full amount and quantity reconciliation at each source purchase currency.
- **Production variances:** Rejected batches, physical waste, rework, loss/standard-versus-actual variances and period-end WIP must follow approved accounting policy, with explicit asset/expense recognition. A released batch snapshot cannot establish every factory allocation on its own.
- **Financial statements:** Trial balance, opening balances, stock subledger reconciliation, fiscal/tax policy, FX and profit calculations must be reconciled with the company's signed accounting setup.
- **Operational requirements:** Full Pashto/Dari translations, system-wide UI consistency, migration sequence verification, automated testing, MySQL compatibility, operational UAT and handoff ZIP are still pending.
- **Performance:** Reconciliation checks are source-document intensive and may need query/aggregation improvements for large production datasets.
- **Safety:** The code creates journal entries for new correctly sourced transactions only. Historical remediation is intentionally separate from online invoice posting, preventing an unsupported GL asset or duplicate revenue/cost recognition.

## Verification boundary

Per user instruction, **no migrations, automated tests, syntax lint, live DB processing, browser UAT, or production deployment were performed**. The above describes committed source changes and inspected code paths, not runtime-verified accounting functionality. Complete development and staged historical reconciliation are prerequisites for marking this production-ready.
