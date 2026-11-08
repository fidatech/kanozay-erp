# Kanozay ERP — Historical customer credit-note and QC return original-cost recovery

Date: 2026-11-09  
Branch: `development/kanozay-completion`  
State: **Development source committed. No migrations, PHP lint, automated tests, database/financial UAT or deployment executed.**

## Business scenario

A genuine, posted customer **sales credit note** may have placed previously invoiced stock into quarantine. An independent QC inspector subsequently approved its return to saleable stock. The exact original warehouse movement exists, so physical on-hand inventory was already increased. However, the associated factory **Dr finished-goods inventory / Cr original sales COGS** journal was missing, causing the original-currency inventory asset and sales gross margin to disagree with recorded physical stock.

This workflow is exclusively for **previously QC-released and already physically restocked returns** with a real, posted original invoice and original-currency COGS journal.

## Independent source review

1. Add the source-correction type `released_return_missing_cost` in the Historical Financial Correction Center. Source ID is the **existing `sales_return_quarantines.id`**, not an arbitrary refund amount or a manually entered quantity. Recent released returns without `production_return_cost_postings` are sampled in the review screen.
2. Freeze a source fingerprint containing the original QC return/approval and signed date, the exact sales credit-note line and posted credit-note journal, original invoice and invoice line, related batch/source currency, and the existing exact warehouse `customer_return` movement. An independent historical case reviewer must approve source evidence before any financial plan is allowed.
3. Only QC status `released` is eligible. Credit-note registration and physical QC must have **different actors**. A nonempty signed QC reference, independent approving employee, definite original QC date and **real named released warehouse** are required. The original warehouse ledger must have **exactly one** matching customer return movement, same batch, credited packages, warehouse, QC date, reference and deciding employee. Unassigned legacy stock with no independent warehouse movement stays blocked.
4. The original customer invoice, receivable account and customer credit note must remain genuinely posted, unreversed, same customer/trade document, and consistent original sales currency and original unit price/quantity. The original credit journal must credit the same customer's receivable, not an invented unrelated liability. Customer credits cannot exceed original sold quantity.
5. The source manufacturing batch must be QC released and financially capitalized; the original invoice COGS journal must be previously posted, unreversed and agree exactly with the original **Dr COGS / Cr finished inventory asset** in its original factory cost currency. The customer invoice selling currency may differ, but **no FX conversion** is performed for inventory cost. The source FIFO/manufacturing policy must still be approved.
6. Calculate the exact recoverable fraction from the original posted invoice-line COGS and the actual QC-released returned packages, subtracting all earlier individually posted return recoveries. The final fully returned portion receives the exact remaining source COGS rounding remainder; no COGS, physical quantity or asset balance can be recovered twice. Prior return journals and their original QC dates must be valid, posted and unreversed.
7. Historical chronology is mandatory. The final recovery journal date equals the **original QC release date**, which must be in an **OPEN** accounting month and no earlier than original invoice, credit note or COGS posting. The service rejects an already posted later batch COGS/non-sale cost transaction or later recovery requiring a separate chronology adjustment. Closed months cannot be reopened automatically.

## Authorization and posting

- A separate finance proposer records original restock/credit/source-cost evidence and submits a unique `finance_historical_customer_return_cost_recoveries` plan. The plan records original case/return/COGS source IDs, source fingerprint, original QC/posting date, quantity, original COGS, selected policy currency, exact recoverable amount, source inventory and COGS accounts, evidence, actor and timestamps.
- A different authorized finance reviewer approves or rejects **that exact frozen source and amount**. A further independent finance executor rechecks the complete source, original GL, original credit, QC and warehouse movement. Those finance actors must all differ from the historical case requester/reviewer, original credit-note registrar and QC approving employee.
- The executor sets a temporary `posting` state inside the database transaction. Only then may the existing `FinishedGoodsCostPostingService::postReturnRecovery` accept the already `released` historical QC record. Normal QC release still requires status `quarantined`. The temporary state is committed only together with the source-linked journal and resolved case.
- The journal posts **Dr originally authorized finished-goods inventory / Cr original source invoice COGS**, both in the original production cost currency and exact original QC release date. It reuses the `production_return_cost_postings` subledger, the same as ordinary QC release, to reconcile manufacturing quantities and money.
- **No new warehouse movement, product on-hand increment, quality decision, customer sales credit, customer refund, revenue reversal or payment** is created by this source correction. An atomic failure rolls back the new journal, subledger and finance status.
- Unique case/quarantine/journal constraints, approved source fingerprints, independent actor separation and the generic GL direct-reversal prohibition prevent simple duplicate booking. A resolved case event and activity record keep the signed reviewer history.

## Integrity reporting

- The sales return quarantine register directly links released cases with missing factory COGS restoration to the historical correction center.
- Financial closing already blocks QC `released` movements in a month that lack their corresponding original-currency inventory/COGS restoration. Additional historic-recovery checks verify their posted source journal, date, currency, original QC quantity, case status and exact asset debit/COGS credit.
- Manufacturing financial reconciliation continues to read the same `production_return_cost_postings` source records, so restored original packages and source-cost values are accounted for without introducing a parallel cost model.

## Deliberately unsupported

- **Uncapitalized batches / missing original source invoice COGS:** these must complete a separately audited source manufacturing and sales recovery before a historical return can be valued.
- **Previously scrapped or still quarantined goods:** scrapped goods never restore saleable inventory; still-quarantined goods must use normal independent QC release and original-Cogs recognition.
- **Unassigned legacy warehouse restocks with no matching source movement, direct-restocked legacy credit-note lines and unsupported physical adjustments:** cannot assume asset quantities from a credit document.
- **Credits, refunds or price adjustments without physical returned stock:** sales credit is a financial reduction, not automatic inventory restock.
- **Closed original accounting periods or cost journals posted after the historical QC date:** require a separately authorized prior-period/cutoff correction. Do not silently use an unrelated current accounting date.
- **Earlier generic manual GL entries for the same return:** signed finance confirmation is required; no automated proof of all possible legacy manual postings is claimed.
- **Rejected proposals and amendments:** preserved as source-linked evidence; an independently reviewed amendment/resubmission workflow is still outstanding.
- **Production readiness:** migrations, PHP syntax verification, integration tests, concurrent period close, SQLite/MySQL, UI and full regression tests are deliberately deferred at user instruction.

**No production accounting correctness or deployment is claimed.**
