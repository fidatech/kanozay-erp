# Kanozay ERP — Historical invoiced manufacturing capitalization and COGS recovery

Date: 2026-11-09
Branch: `development/kanozay-completion`
Status: **Development source committed only. Migrations, tests, PHP syntax lint, MySQL checks, browser UAT and deployment have not been performed.**

## Supported historical repair

This batch adds a restricted, independently approved **original-cost manufacturing backfill for QC-released production batches already physically dispatched to genuine, previously posted customers but not yet financially capitalized**. It requires ALL source dispatches for the batch to have exactly one posted original customer invoice with matching original dispatch date, finished product, batch and package quantity. There may be multiple original invoice/dispatch dates for one batch, but the batch must have no customer credit notes, quarantined returns, non-sale dispatch or earlier manufacturing/COGS source posting.

This is not a general retrospective adjustment. Missing customer invoices or uncertain original source costs remain blocked.

## Independent review and posting

1. A historical correction case of type `batch_uncapitalized` is submitted against the unchanged production batch. A different reviewer approves the original discrepancy, without creating any GL value.
2. A separate finance proposer selects an **original manufacturing accounting date** on or after the recorded QC finished-goods receipt and **on or before the earliest actual physical dispatch**. The date and every original customer invoice date must still belong to an **OPEN** accounting period.
3. The proposal binds the immutable batch/cost case fingerprint, approved manufacturing ledger policy, original source currency, frozen original material costs, reviewed overhead, independently allocated abnormal losses, QC receipt and **every linked posted invoice / source dispatch** to a new, uniquely keyed `finance_historical_batch_capitalizations` record. The original source cost preview is advisory pending full source GL validation.
4. A different authorized finance reviewer approves or rejects the exact proposed original-currency accounting value, date and source documentation. The requestor, original case reviewer and plan proposer cannot approve their own plan.
5. A separate executor re-reads ALL source and date evidence. Inside one financial-period-gated database transaction, the original `ManufacturingCostAccountingService` verifies original FIFO stock issue cost, original supplier asset postings, approved source expense/overhead, waste, QC and already-approved currency policy. It posts source WIP and finished inventory capitalization, then `FinishedGoodsCostPostingService` posts each missing original-currency COGS debit / finished-stock asset credit for the existing posted invoice lines, strictly in date order. If any source or journal fails, **every new manufacturing and sales cost journal rolls back**.
6. The original production batch, physical receipts, warehouse dispatches, sales invoice, customer receivable and sales revenue are left unchanged. Unique batch/case and linked journal references prevent repeated reconciliation. A resolved historical correction event records the signed evidence and the count of newly costed original invoices.

## Safeguards

- The ordinary `ManufacturingCostAccountingService::capitalize` path continues to **reject every already-dispatched or already-invoiced batch**. Its optional historical case authorization is accepted only with the approved dedicated recovery proposal and an independent executor.
- Existing costed original invoice lines and independently expensed non-sale physical dispatches are explicitly excluded. Frozen source-currency values are used without guessed FX or cost averages.
- Each original customer invoice requires an unreversed, posted, exact-date GL revenue journal and a same-customer/same-currency trade receivable with reconciled original invoice total. Separate COGS accounting cannot create a sale without customer/revenue evidence.
- The accounting date cannot be after the actual dispatch, before the QC receipt or supplier/FIFO source postings, or inside a closed month. No automatic period reopening or silent current-period replacement is allowed.
- Completed historical batch backfills are included in financial period-close validation. The reconciled batch must retain valid manufacturing GL and all matching original invoice COGS journals.
- A historical case containing a financial batch-backfill proposal cannot be withdrawn and orphan the reviewer or source evidence without an audited amendment.

## Still outstanding

- **Historical batches with standalone dispatches:** This version does not invent customer invoices and does not automatically classify sampling, damaged inventory or internal-use purposes. A separate approved batch capitalization + purpose-specific expense backfill remains to be developed.
- **Closed historical accounting months:** Original-period postings are blocked until properly authorized period reopening and reconciliation, or a separate accountant-approved current-period prior-period correction framework is implemented.
- **Historic customer returns and sales credits:** Prior credit notes, quarantines and stock-restock adjustments need original-source, reverse-COGS recovery and new-period treatment before being admitted.
- **Unverified original cost:** Consumed or unlocated opening FIFO lots, missing supplier GRN asset journals, unknown material costs, mixed currencies or overhead approved later than the original manufacturing date remain blocked.
- **Rejected proposals:** Editing or resubmitting a rejected source-cost proposal requires a dedicated audited amendment workflow. The unique source-batch record is intentionally not overwritten.
- **Quality assurance and deployment:** Full migrations, syntax lint, SQLite/MySQL tests, regression suite, period close/concurrency verification, UAT, full Pashto/Dari transactional labels and release packaging remain deferred at the user's direction.

**This change is source development, not acceptance for live accounting.**
