# Kanozay ERP — Historical uncapitalized manufacturing batch with independently QC-scrapped customer credits

Date: 2026-11-09  
Branch: `development/kanozay-completion`  
State: **Source code committed only. Migrations, PHP lint, automated tests, database/browser UAT and deployment remain deferred at the user's direction.**

## Purpose and scope

Recover historical original manufacturing capitalization for an already QC-released, physically dispatched batch containing genuine posted customer sales, independently classified non-sale warehouse movements, previously saleable QC-released customer credits and **genuinely scrapped, separately QC-condemned customer credits**. This extends the independently authorized `batch_uncapitalized` source workflow. All original manufacturing cost, source dispatch, customer credit and QC events must have recorded, authenticated historic dates and evidence.

**Scrapped customer returns never restore saleable stock or finished-goods inventory assets.** Once the genuine original customer sale COGS is posted, the documented returned/scrapped fraction can be presented as `Dr approved damaged-return loss expense / Cr original sale COGS`, in the same original factory-cost currency. This is only an expense-category reclassification of an already recognized cost; it does not add another total expense or create a stock movement, refund, sale, credit note, revenue or receivable.

## Source proof and independent review

- Original manufacturing source must pass the existing strict FIFO material, supplier stock, approved overhead, abnormal-waste, finished-goods QC, accepted-batch quantity and original single-currency checks. The manufacturing posting date must follow QC release and precede the first dispatched package, in an **OPEN** historic accounting month.
- Every physical dispatched batch line needs a unique genuine posted customer invoice or a separately approved and signed non-sale purpose with its own expense account. Original customer invoice revenue/receivable GL and corresponding credit-note income reversal must be genuinely posted and unreversed, in their own customer selling currency; no sale is created.
- Every customer credit note line for this batch needs one matching recorded QC case. Existing independently **released-to-stock** customer credits retain the prior source warehouse restock and original COGS asset recovery path. A QC status of **scrapped** is accepted only with a separate genuine, signed original QC condemnation date, independent approving officer (different from registrar), documentation/reference, exact batch, original sale/credit line and quantity, and **no** prior `customer_return` finished warehouse movement or inventory asset cost recovery.
- Still-quarantined, undocumented, directly restocked, manually valued, future dated, unposted or reversed source invoices and QC cases remain blocked. Source credits exceeding original sold packages, unsupported source currency and source GL mismatches fail closed. Previously approved separate scrap expense plans are excluded; a documented *former quarantine hold* is evidence only and does not itself recognize loss.
- The original customer sale must still be missing financial COGS before this workflow starts, and the manufacturing batch must have no previous capitalization. An existing original cost journal, previously recovered QC asset, or duplicate cost/expense allocation blocks this path.
- A customer invoice line that combines independently saleable QC-restored portions and independently scrapped portions is **intentionally blocked** until a combined invoice-level fraction and 4-decimal rounding method is signed and implemented; the two existing cost engines cannot safely allocate both types on that same invoice. Different sale lines within the same batch may have different independently signed QC dispositions.

## Frozen original-cost register

Migration `2026_11_09_000500_create_historical_batch_scrap_allocations.php` adds `scrap_line_count` and `posted_scrap_lines` to `finance_historical_batch_capitalizations` and creates `finance_historical_batch_scrap_allocations`, with uniquely keyed original QC and customer credit lines, genuine original sale line, original factory currency, package quantity, original QC scrap date, planned exact cost fraction, separate authorized loss expense ledger, immutable source fingerprint, original COGS posting reference and final loss journal.

`HistoricalBatchScrapSourceService` source-verifies original credit-note revenue/receivable, original physical sale, independent QC, no saleable restock, quantities and factory cost projected from the already reviewed manufacturing batch. 4-decimal original-cost fractions are allocated by actual sold packages and original batch COGS, with the exact remaining original cost assigned to the last fully scrapped portion. The chosen loss ledger must be an active, non-group, unmapped expense account, distinct from original sales COGS, raw/WIP and finished inventory ledgers.

The case requester, original case reviewer, finance proposer, independent approver and distinct executor cannot overlap each source credit-note author, original QC registrar or independent QC decision maker. The independent financial approval freezes **every** signed scrap, its factory currency/cost amount, original date and dedicated account; any later change invalidates the source evidence and prevents posting.

## Atomic original-date financial reconstruction

One original-period-gated database transaction rebuilds the sequence:

1. Original source-validated `Dr WIP / Cr material inventory` and `Dr finished inventory / Cr WIP` manufacturing cost capitalization, using the existing protected historical override.
2. All authentic original customer invoice `Dr original sale COGS / Cr finished inventory` and independently signed non-sale `Dr expense / Cr finished inventory` on their actual original dispatch dates.
3. Existing independently QC-released/customer-restocked `Dr finished inventory / Cr COGS` on genuine original saleable QC dates (when permitted by existing source rules).
4. For each signed original QC scrap, after its genuine customer sale COGS exists, `Dr independently authorized damaged-returns loss expense / Cr original factory sale COGS` for the exact original source COGS fraction, on its original independent QC scrap date. **No finished-stock asset journal or warehouse movement is made for scrap.**
5. Per-line source IDs, original invoice COGS reference, immutable approved QC amount, GL status/date/currency and final journal are bound in `finance_historical_batch_scrap_allocations`. Any failed source, account, quantity, original period, GL, or signed evidence check rolls back the entire manufacturing, genuine sale, non-sale expense, saleable QC recovery and scrapped QC-loss transaction.

Events on the same day prioritize original physical dispatch/sale COGS, then saleable QC recovery, then signed QC scrapping. Original credit notes and invoice revenue/receivable data are untouched. The generic GL reversal route prohibits reversing source-linked scrap journals independently of their historical case.

## Independent reporting and period close

The Historical Finance Correction Center shows every signed original QC scrap, original customer credit and decision date, a separate expense account selector, independently reviewed cost and final journal register. Manufacturing inventory/COGS reconciliation and CSV now combine independently posted standalone scrapped-customer COGS reclassifications with this **historical batch** scrap register, distinguishing gross original sale COGS, separate QC scrap-loss expense, physically saleable QC return recovery, net sale COGS and actual remaining stock. Scrap does not add to saleable batch packages or asset valuation.

Finance period close verifies scrapped-customer cost journal, exact signed QC package/credit/source sale, valid original manufacturing COGS, original factory currency, independent loss ledger, correct two-line `Dr loss / Cr original COGS`, no saleable restock and no GL reversal. Audits run for both the manufacturing capitalization month and the original QC scrap month. Posted batch scrap losses no longer appear in the warning for genuine QC scrapping with no independent expense classification.

## Deliberately pending

- Customer invoice lines containing both saleable-restored and scrapped QC portions require a new jointly approved original-cost allocation and rounding method. They are blocked to avoid duplicate source COGS consumption.
- Original customer credit lines still in `quarantined` state cannot be assumed scrapped; documentary QC hold has no new asset or loss recognition. Full independent QC disposition remains necessary.
- Previously posted source COGS or manufacturing capitalization, missing original FIFO/supplier GL, unverifiable QC disposal, manual unlinked historic cost adjustments and closed original accounting periods require separately approved source correction or formal period reopening.
- Genuine inventory asset value of scrapped goods is **never** restored. Existing customer refunds, paid claims, tax, insurance, salvage and disposal compliance are not inferred from the credit note.
- Rejected proposals, source amendments, conflicting interleaved saleable QC and standalone non-sale chronology, concurrency and all edge cases require dedicated controls and tests.
- No claim of production readiness: migrations, lint, regression tests, SQL database verification, UI UAT, period close/GL accounting certification and deployment were not performed.

**This commit is source-code development only, not a verified or deployable accounting release.**
