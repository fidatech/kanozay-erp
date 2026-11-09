# Kanozay ERP — Shared original invoice cost for mixed QC-released and QC-scrapped returns

Date: 2026-11-09  
Branch: \`development/kanozay-completion\`  
Development status: **Committed and source-reviewed; runtime unverified.**

## Completed development scope

A real, previously posted customer invoice for a source-valued manufacturing batch may contain several original customer credit-note lines. Some returned packages were independently QC-released into a recorded company warehouse, while others were separately condemned and QC-scrapped. The same *original invoice line* now supports both outcomes within the original \`batch_uncapitalized\` recovery workflow.

Previously, \`HistoricalBatchReturnSourceService\` and \`HistoricalBatchScrapSourceService\` each calculated original-currency fractions independently. When independently released and scrapped quantities together exhausted the original sale, both paths could seek the final rounding remainder. Mixed original invoice lines were therefore explicitly blocked.

This batch adds \`HistoricalBatchMixedQcAllocationService\`. Both independent source validators still authenticate their original customer sale, posted credit, credit author, independent QC decision, signed date and credit quantity. The released path requires the already existing exact destination-warehouse physical return, while the scrapped path requires a genuine condemnation and **no** saleable warehouse movement. Both paths return their source evidence and original manufacturing COGS projection before a **single joint invoice-level cost allocation**.

For every invoice line with both outcome types:

1. Bind exact original invoice-line ID, original manufacturing COGS, sold package quantity and every genuinely signed original credit/QC source. Reject duplicate QC or credit IDs and reject combined credited units greater than the source invoice's sold units.
2. Sort original credit/QC outcomes by actual QC date; on the same day, saleable returns are cost-restored before independently signed scraps, followed by original QC ID.
3. Allocate each original factory-currency fraction with Brick BigDecimal, at **4 decimal places**, using \`original invoice COGS × outcome packages ÷ original sold packages\` with HalfUp rounding. When the combined processed quantity exactly reaches the original sold quantity, assign the **single remaining original invoice COGS remainder** to that final source outcome. Reject zero, negative or overallocated fractions.
4. Re-fingerprint each mixed outcome against the entire sorted source event set, original sale COGS, its chronological position, prior approved quantity and cost, and the final allocated fraction. These re-fingerprinted amounts enter the existing immutable \`finance_historical_batch_return_allocations\` and \`finance_historical_batch_scrap_allocations\` review registers.
5. Leave every already-approved **unmixed** proposal's amount and source fingerprint unchanged.

## Independent execution and reconciliation

The independently authorized historical batch executor already posts original manufacturing capitalization and actual customer sale COGS before chronological QC returns/scraps in a shared financial-period-gated database transaction.

This batch strengthens both execution paths:

- \`FinishedGoodsCostPostingService::postReturnRecovery\` adds the quantities and exact original factory-currency expense amounts of **previously posted signed scraps** from the same invoice line, after verifying their original COGS ID, original GL date, account split, currency, unreversed journal and approved cost. It uses the total jointly consumed cost/quantity when restoring the next approved saleable QC return. It also compares the resulting amount with the independently reviewed \`finance_historical_batch_return_allocations\` record.
- \`HistoricalBatchCapitalizationService::postScrap\` adds **previously posted saleable original COGS restorations and signed scrapped cost reclassifications** from the same original invoice, verifies their chronological original currency and unreversed journals, recomputes the remaining 4-decimal source-cost fraction, and requires exact equality with the signed approval before posting a scrap loss journal.
- A released-to-stock return still posts \`Dr finished inventory / Cr original sale COGS\`. A condemned/scrapped return still posts \`Dr approved loss expense / Cr original sale COGS\`. Scrapping never creates an inventory asset; none of these workflows create original customer revenue, receivable, credit, refund, warehouse movement or QC decision.
- The source-verified approval, existing per-line cost subledgers, reversal protection, manufacturing reconciliation and original month-close audits continue to reference the exact same approved amounts and source journals. All mixed outcomes are atomic with the original WIP, finished-stock and sale COGS backfill; a source mismatch rolls back the entire proposed batch.

## Worked allocation (illustrative, not a production transaction)

Original invoice: **3 packages at 1.0000** original factory-currency total COGS. One genuine QC release, followed by scrapping the remaining two packages:

| Event | Packages | Signed original cost |
|---|---:|---:|
| Original genuine sale COGS | 3 | 1.0000 |
| Independently QC-released into existing warehouse | 1 | 0.3333 |
| Independently QC-scrapped with no stock restoration | 2 | 0.6667 |
| Total original cost allocated across customer credits | 3 | **1.0000** |

If scrapping occurred **before** saleable release, the signed allocations instead become 0.3333 to scrap and 0.6667 to the final release. If only two out of three packages were credited, each one-package portion remains 0.3333; 0.3334 stays associated with the customer's unreturned source sale. This prevents prematurely absorbing a rounding remainder.

## Prepared regression scenarios (not executed)

\`tests/Unit/HistoricalBatchMixedQcAllocationTest.php\` contains deferred Laravel/PHPUnit cases for:
- Full return/scrap shared budget and single remainder.
- Earlier scrap followed by warehouse release.
- Same-day release-before-scrap order.
- Partial returns retaining uncredited original sale cost.
- Combined credit quantity exceeding actual original sold quantity.
- Duplicate QC credit claimed as both released and scrapped.
- Changed original source invoice cost.
- Preservation of legacy unmixed source values and fingerprints.

## Still blocked or awaiting client evidence

- Original supplier FIFO material cost, overhead, manufacturing QC receipt, posted customer invoice/revenue and genuine customer credit must still exist and pass the original independent source validators. No unknown cost, invented FX or incomplete warehouse source may be approved.
- Still-quarantined or disputed returned goods cannot be guessed as saleable or scrapped. Closed original accounting months are not reopened. Unsupported direct restock, already costed original invoice/return, unapproved source changes and external manual GL without an authentic source link remain blocked.
- Separate *non-sale warehouse dispatch cost* interleaved with historic QC recoveries can require additional original chronology/accounting review; those unsafe paths remain fail-closed.
- No PHP syntax validation, migration, test execution, MySQL/SQLite data validation, finance-period concurrency tests, browser UAT or deployment was performed. All code is development source only; end-to-end accounting correctness is unconfirmed.

**Release gate:** Do not classify this as production-verified until the deferred regression suite, original-currency MySQL journal reconciliation, independent QC/finance actor UAT and full month-close checks pass.
