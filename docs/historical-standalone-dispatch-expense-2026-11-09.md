# Kanozay ERP — Source-backed historical standalone dispatch expenses

Date: 2026-11-09
Branch: `development/kanozay-completion`
Status: **Development source committed only. Database migrations, PHP syntax lint, automated tests, browser UAT, MySQL verification and deployment are deferred at user instruction.**

## Purpose and supported scenarios

The Historical Financial Correction Center already captures an independent, nonposting, immutable business-purpose classification of each original standalone finished-goods dispatch. This batch introduces an additional independently reviewed financial treatment for these strictly **non-sale** dispositions:

- `promotional_sample`: signed authorized product sample / advertising expense
- `production_loss`: independently evidenced damage or nonrecoverable finished inventory loss
- `internal_consumption`: documented approved internal use

`customer_sale` and `unresolved` **remain investigation only**. This service never invents a customer, sale, revenue, tax, invoice or receivable.

## Accounting constraints

1. The exact original dispatch lines, batch, finished good, warehouse and physical dispatch date must still match their historical case fingerprint; no invoice may link to that dispatch.
2. **Every** dispatch line must refer to a previously QC-released batch that was actually capitalized, in one independently approved **original currency**, **no later than its physical dispatch date**. A report-only indicative cost or an unsupported postdated capitalization cannot be booked.
3. The production batch's posted, unreversed manufacturing issue and finished-inventory capitalization journals must be source-consistent, including original-currency finished-asset debit and WIP credit equal to the full approved batch total.
4. The GL expense is derived from the original frozen and posted batch total multiplied by actual dispatched packages / QC-accepted packages. Its amount is calculated with 4-decimal journal precision. Prior sales COGS, approved non-sale dispatches and QC-approved customer return recoveries are accounted for. No batch cost or quantity may be consumed twice.
5. The finance proposer explicitly chooses a **distinct authorized expense ledger for every source dispatch line**. The ledger must be active, unmapped and of type expense; sales COGS, finished inventory, raw inventory and WIP accounts are prohibited as sample/loss/internal-use expenses. Evidence and proper expense classification require human finance authorization.
6. A different finance reviewer approves or rejects the proposal; a further independent executor rechecks the entire unchanged source, original cost, expense accounts, chronology and open accounting date. On posting, every line receives **Dr approved non-sale expense / Cr finished-goods inventory asset**, in its own original currency. All lines post atomically or the transaction rolls back.
7. The posting date is separately selected within an **open financial period**, not before the original physical dispatch or in the future. It is never backdated merely to force a closed month to accept new transactions. Original warehouse quantities, dispatch history and QC records remain unchanged.
8. One unique historical case, one unique signed disposition, one unique physical dispatch and unique source dispatch line/journal links guard against repeated application. Source journals cannot be independently reversed without a dedicated reviewed source correction.

## Downstream cross-module changes

- New table `finance_historical_dispatch_cost_settlements` records submission, independent finance approval and execution, with immutable original-disposition reference.
- New table `finance_historical_dispatch_cost_lines` records original physical line, approved production cost record, source currency, inventory and expense ledger IDs, exact quantity, approved source cost and posted journal.
- Existing customer COGS costing now includes posted historic non-sale dispatch quantities and expense amounts in available batch inventory, blocks trying to cost the same source dispatch as customer COGS and respects later non-sale posting chronology.
- Period-close readiness treats an original dispatch line as financially reconciled only when it has an invoice or an actual posted source-linked non-sale cost line. It additionally flags invalid or reversed journal links in the settlement posting month.
- The Historical Financial Correction Center presents source batch capitalizations, per-line expense mapping, proposal details, review forms and final posting controls.

## Outstanding restrictions

- **Uncapitalized legacy batches** cannot use this service. Existing manufacturing capitalization forbids retrospectively capitalizing a batch that already has physical dispatches. A separate audited, exact source-price historical inventory/GL capitalization path must precede any cost recognition for such batches; do not invent cost from an estimate.
- Original-currency sales revenue/COGS reconstruction for genuine historical customer sales is not covered. Sales must use independently supported customer and billing records.
- Rejected proposals cannot silently be overwritten. A separate audited amendment/resubmission path remains outstanding.
- Direct reversal is intentionally blocked. Reversing a settled physical dispatch expense requires a dedicated approved source correction, including cost and inventory reconciliation.
- Full migrations, static syntax verification, SQLite/MySQL tests, UAT and release packaging remain deferred.

**No claim of production acceptance is made.**
