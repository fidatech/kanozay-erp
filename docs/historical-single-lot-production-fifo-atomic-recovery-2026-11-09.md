# Kanozay ERP — Historical one-lot / one-batch original FIFO atomic accounting recovery

Date: 2026-11-09  
Branch: `development/kanozay-completion`  
Verification: **GitHub source code only; migrations, PHP lint, automated tests, SQL transactional rehearsal, UAT and deployment have not been executed. This is NOT an accounting certification or production-ready release.**

## Financial issue

An independently evidenced original opening FIFO raw-material lot was issued years ago into a real production batch, but the original immutable `inventory_cost_consumptions` and frozen `production_batch_costs` recorded an unvalued source. Subsequent production may already have a genuine QC released finished-stock receipt and original physical customer dispatches/invoices. A one-sided expense or WIP journal would corrupt finished inventory and invoiced COGS. This development adds one conservative **atomic full-chain recovery** to the existing signed original-cost source-review workflow.

## Exact supported scope (deliberately narrow)

The `HistoricalProductionFifoRecoveryService` refuses to proceed unless ALL of the following are verified:

1. ONE approved original opening FIFO source lot, independently source-reviewed and independently original-price/currency authorized in `finance_historical_fifo_production_cost_reviews`.
2. ONE genuine QC-released production batch, with exactly ONE physical production finished-goods receipt and positive accepted packages; real material issues must precede this original finished/QC approval.
3. EVERY physical material movement for this production order must refer to the SAME opening FIFO source lot, must be an actual `production_order_material / issue / out` and have exactly one genuinely recorded FIFO consumption. No other supplier/receipt, packaging or extra batch-cost source may be omitted. All issues must have ONE authentic original material issue date.
4. The original single immutable `production_batch_costs` snapshot must show exactly the unvalued source quantity and no independently valued material component. The original batch must have **NO overhead, recorded production material waste or previously posted manufacturing cost/COGS**.
5. Genuine finished dispatches may exist, but EVERY dispatch must map uniquely and exactly to an authentic, posted customer invoice line on the SAME original physical dispatch date. Original revenue must have a genuine unreversed two-line `Dr mapped customer receivable / Cr income` posting in the CUSTOMER selling currency, matching the original receivable trade document and invoice total.
6. No customer credit, QC return/recovery, non-sale sample/loss/internal-use dispatch or competing historical manufacturing capitalization case. More complex mixed-dispatch/return reconstruction remains a different workflow and is not silently reduced to basic sale COGS.
7. The original purchase/ownership value and currency must be evidenced and human-verified; approved raw-material inventory, WIP, finished-goods and COGS ledgers must agree with the unchanged approved currency policy, and the opening equity account must be separately signed, non-party-mapped and not a manufacturing account.
8. All original receipt, issue, finished/QC and customer dispatch/invoice months must remain OPEN. Source documents must already exist, original dates cannot be newly invented and the historical source cannot have an unreviewed prior asset/WIP/COGS journal.

**Anything outside those conditions is blocked with no journals or physical changes.**

## Source and separate authorization

Migration `2026_11_09_000900_create_historical_fifo_production_recoveries.php` adds `finance_historical_fifo_production_recoveries`, unique to one original FIFO lot, one approved WIP cost-review and one physical production batch. It freezes the approved opening, production issue, finished-goods, actual sold invoice count, exact original currency, total lot value, consumed material WIP and current raw inventory remainder, original dates, selected equity GL and a SHA-256 fingerprint of original production/QC/customer revenue sources and policy.

A new original WIP journal reference on `finance_historical_fifo_production_cost_lines` binds each **immutable historic source consumption** to the full raw/WIP accounting chain; old original issue rows remain unchanged.

Separate historical plan proposer, independent reviewer and final executor must all differ from the original opening registrar, original production material issuers, consumption-provenance proposer and reviewer, and original WIP source cost proposer/approver. Signed approval is held separately from any financial posting.

The finance review screen at `/inventory/legacy-fifo-reviews` shows genuine lot/batch evidence, frozen WIP cost fractions, original dates, approved material/finished/cost amounts, original invoice count, independent review and the actual accounting journal IDs. There is no bypass for unsupported source batches.

## Atomic original-source GL sequence

Under one database transaction and shared original-period posting lock:

1. Original opening receipt: `Dr raw material inventory / Cr authenticated opening equity` in the original FIFO source currency for total opening lot value.
2. Original physical production issue date: `Dr approved production WIP / Cr raw inventory` for the exact amount historically consumed by this approved production batch.
3. Original QC release date, after physical accepted finished receipt: `Dr finished-goods inventory / Cr production WIP` for the exact same material source amount. The batch has no eligible overhead or abnormal waste in this executor.
4. Create ONE `production_batch_cost_postings` source link, using the genuine batch accepted packages, exact original-currency WIP/finished journal IDs, zero unsupported waste/overhead, and the independent approval reference. This avoids mutating original frozen `production_batch_costs` snapshots.
5. For every genuine posted customer invoice/physical dispatch in chronological order, call the existing guarded `FinishedGoodsCostPostingService::postInvoiceLine` to post the original-date `Dr sale COGS / Cr finished inventory` and source-linked `production_invoice_cogs_postings`. Existing source invoice revenue, receivable, selling currency and physical dispatch are untouched.
6. Only after ALL financial journal checks succeed, record original FIFO lot value/currency, attach the same historically posted WIP journal to every original source consumption allocation, mark the original WIP source review as posted, and mark the separate full-chain recovery posted with actual journal IDs, executor, date and manufacturing posting ID. Failure at any step rolls back all new journals/cost valuation entries and approvals to the previous unchanged state.

The final historical remaining raw-material FIFO value is `original authentic source lot cost - posted original historical WIP issue cost`. `InventoryCostService` and `LegacyFifoSourceReviewService` recognize that source-linked issued WIP amount while **preserving original consumed issue rows**. Future raw FIFO issues and conventional manufacturing capitalization are permitted only if the complete historic recovery journal chain remains valid, in the same original currency and before those new source dates.

## Independent audit, reversal and original month close

- Original opening, actual WIP and QC finished inventory GL must each have exactly two posted, unreversed original-currency, original-date balancing ledger lines.
- The `HistoricalProductionFifoRecoveryService::auditPosted` independently validates the genuine source opening lot, unique production batch/accepted receipt, original WIP issue references and value split, separate actors, manufacturing posting and every original customer invoice COGS GL against the approved policy. It is READ ONLY; source source-review snapshots created before the authorized valuation are not replayed after the source lot becomes genuinely valued.
- `FinanceCloseReadinessService` audits the full chain in opening, material issue, finished production and affected original invoice months. The earlier historical unvalued-cost issue blocker is removed only where an original physical issue is linked to a genuinely POSTED source-WIP journal and posted complete recovery chain. Pending submitted/approved recovery remains a blocker.
- Generic source journal reversal blocks the original opening/equity, original WIP and QC finished capitalization journals individually; existing manufacturing/COGS reversal protection remains.
- `ProductionCostReadinessService` can display a batch as source-backed historically GL-recovered even though its **original immutable** batch-cost snapshot still truthfully shows unvalued original source lines; the displayed financial value comes from the independently posted original-cost recovery.

## Still outstanding in D02

- MULTIPLE original opening FIFO lots feeding one batch, one lot feeding several batches, material issues on different original dates, mixed valued supplier/GRN inputs, and proper cross-lot 4-decimal cost rounding.
- Independently sourced overhead, normal/abnormal material waste, partly realized finished goods, non-sale dispatch expense, original customer credit/return/QC reclassification combinations and their original accounting chronology.
- Source-specific historical supplier returns, source-GRN or adjustment lots with unknown historical locations, older manually posted GL not discoverable from source-linked tables and legitimately closed original accounting periods. These require independent accounting and source-document approval, not a manual date change or guessed historic cost.
- This is still source-only development. **No migrations, PHP lint, automated tests, database transactional checks, financial UAT, role/UAT, or deployment were run.** D02 remains **🟡 Partial**, not completed or production-certified.
