# Kanozay ERP — Landed-cost GL capitalization and production waste/rework financial controls

Date: 2026-11-08  
Development branch: `development/kanozay-completion`  
Status: **Source committed. No migration, automated test, lint, running-application verification or deployment.**

## 1. Original-currency GRN landed costs and financial journals

Previously, GRN freight/customs/handling allocations increased recorded FIFO lot cost but did not capitalize that expense into the matching raw-material inventory GL asset account. This made it unsafe to debit WIP for such lot costs: the source inventory GL debit was not independently verified.

This batch adds:
- `goods_receipt_landed_cost_postings`, one posting per original immutable freight/customs/insurance/transport/handling component. Each stores the exact source expense journal line, cost-currency inventory asset ledger, expense ledger, amount, posting journal, original accounting date and reviewer evidence.
- `GoodsReceiptLandedCostPostingService`, which verifies an original posted unreversed source expense debit **equal to the complete cost component**, original purchase currency and approved manufacturing raw-inventory ledger. One expense line is consumed by only one such landed-cost posting. An expense line already reserved for production overhead is prohibited.
- Independent financial review: the person who originally recorded the GRN cost allocation cannot approve its GL capitalization. An exact signed approval reference and evidence notes are required.
- GRN line allocations must add up to their original component amount and belong to the source receipt. All allocated FIFO layers must exist with the correct currency, and none may have been consumed already. Historical consumed allocations require a separate reviewed adjustment, not retroactive automatic capitalization.
- One atomic balanced original-currency accounting journal: **debit raw inventory asset, credit original expense**. A second physical stock receipt, supplier invoice or currency conversion is never created.
- A finance-facing approval form on the purchase-order receipt page, restricted to matching posted source-expense lines of the same currency and amount, displaying the resultant inventory-capitalization journal and original review reference.
- FIFO issuing/returns now require posted, unreversed landed-cost capitalization before consuming a receipt layer containing an allocated cost. The manufacturing WIP source validator additionally requires the original-cost ledger to match the independently approved raw-inventory account before incorporating the landed-cost value.
- Generic GL reversal is prohibited for a matched landed-cost source expense or its inventory reclassification. The same source expense cannot later be allocated again to production overhead.
- Finance month-close blocks GRN costs recorded in the period without a corresponding landed-cost inventory journal and warns if an existing source GL reclassification is unposted or reversed.

**Conservative supplier-return treatment:** The existing supplier debit-note workflow reverses only the invoiced base purchase value. If a source lot includes separately capitalized freight/customs, a supplier return now stops and requests a separately authorized landed-cost return credit/write-off. Automatically returning the raw quantity would otherwise leave an unsupported landed-cost asset behind.

## 2. Source-based production material waste and rework

Previously, material loss was an immutable **physical classification of already-issued material**, but did not have independent approval of the financial treatment. The raw material issue had already reduced stock, so simply posting another issue would double-count the same loss.

This batch adds:
- `production_waste_financial_reviews`, with one recorded independent decision for each source waste event: `normal_process_loss`, `abnormal_loss`, or `rework_required`.
- An independent finance reviewer and signed policy reference, plus factual notes. The initial waste recorder cannot approve the classification. These reviews post **no second stock movement and no speculative GL expense**.
- Normal process loss may remain within the accepted batch's original source material cost only after the company-approved loss policy and evidence have been documented.
- Abnormal material loss and unresolved rework block released-finished-goods capitalization. In particular, these quantities cannot silently inflate saleable finished-goods value. A separately approved FIFO lot-cost allocation and abnormal loss expense journal remain to be developed.
- Physical warehouse release additionally blocks unreviewed, abnormal and unresolved-rework classifications. Batch cost-readiness reports present distinct, human-readable outstanding warnings.
- `production_waste_financial_review_events` creates append-only history for original classifications and independently approved rework resolutions.
- Rework completion requires a **second reviewer**, separate from the original waste recorder and first rework reviewer, evidence of completed work, QC reinspection reference, approved normal-loss policy and finance authorization. Rework can be resolved only before QC approval or downstream cost posting.
- After rework, final QC approval requires a new formally approved laboratory sample created after the rework approval, collected on or after physical completion. Earlier QC evidence cannot silently approve repaired output. The original material issue/waste quantity is never edited.
- A new permission-gated **Production Waste Financial Review** register groups source quantities, production order, batch status, loss reason, finance classification and signed evidence; its full matching CSV preserves the source quantities without inventing currency or costs.
- Initial Pashto/Dari translations cover the waste exception reporting filters, labels and navigation. Complete transactional localization remains outstanding.
- The manufacturing batch-to-GL reconciliation report also flags historical batches with unreviewed abnormal or rework material losses.
- Finance close blocks unreviewed, abnormal or unresolved-rework waste recorded in the month until signed source treatment is reconciled.

## 3. Remaining financial development — must not be marked complete

1. **Supplier-return landed-cost credit/write-off:** for a purchased FIFO lot with freight, customs and other capitalized costs, implement original-currency cost allocation for the returned quantity, independently approved recovery versus non-recoverable expense, and correct asset/expense journals alongside the supplier credit.
2. **Abnormal waste source valuation and accounting:** require the exact FIFO lot consumption associated with each wasted physical quantity. A blended allocation across many source lots without traceable provenance cannot be described as exact loss cost. Once verified, independently approve an abnormal loss expense ledger and a corresponding WIP/finished-inventory cost adjustment. Normal losses under a signed policy may be included in the accepted output cost.
3. **Historic corrections:** standalone physical dispatches, legacy raw inventory and opening balances, consumed landed costs without source capitalization, partially posted old purchases and previous COGS omissions need explicit source-document correction workflows and audit events. They cannot be repaired by an unapproved backdated journal.
4. **Production exceptions:** rejected entire batches, disposal of rejected finished packages, rework material reissues, rejected-stock impairment, full overhead allocation, fiscal period-end WIP valuation and full supplier return expense treatment.
5. **Integrations and language:** final financial trial balance, cost-to-batch trace, balance-sheet stock valuation, complete user-role checks, complete Pashto/Dari transactional forms, HTML/PDF printouts and final UI improvements.
6. **Release:** only after the user reauthorizes testing: apply migrations in a copy of source data, check SQLite and MySQL financial precision/unique constraints, run financial integration cases, perform role/warehouse/QC UAT, then prepare the final ZIP and deployment package.

## Verification and scope

This batch adds actual repository source code, routes, migrations and UI; **none of the migrations or code paths were executed**. The source is not a verified production release. Unreconciled old records will intentionally fail the new accounting gates until signed source evidence is reconciled in staging. No costs, tax rates, supplier credits, stock quantities or financial profits were invented.
