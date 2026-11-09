# Kanozay ERP — Multiple historical opening FIFO lots, one production batch: source-linked atomic recovery

Date: 2026-11-10  
Branch: `development/kanozay-completion`  
**Acceptance:** GitHub source review only. No migrations, PHP lint/static analysis, automated/feature tests, SQL transactional runs, browser/UAT, deployment or merge were executed. This is not a finance sign-off or safe production release.

## Objective and scope

D02 previously had a restricted complete original-source GL executor for ONE original opening FIFO lot funding ONE genuine QC-released production batch. Many actual recipes consume more than one raw material or packaging lot. The new `HistoricalProductionFifoMultiRecoveryService` supports **2–12 independently approved original physical opening FIFO lots funding exactly one released manufacturing batch** under ONE genuine original currency and ONE original material issue date.

It rejects an opening lot used across multiple batches, an incomplete selection of original lots or any material from unapproved receipts, GRNs, mixed currency, unsupported source dates, overhead, abnormal/normal waste, customer returns, non-sale dispositions, competing manufacturer capitalization or previously posted source COGS. Batches with any one of those dependencies must await a separate authorized full-source executor; partial or assumed costs are not silently recognized.

## Source controls

- Every selected original source lot already has a genuine `opening / in` physical receipt, immutable prior FIFO issue lines, reconciled current positions/warehouse counts, original cost/currency evidence, independent provenance review and signed independently approved `finance_historical_fifo_production_cost_reviews` WIP cost budget.
- The source service re-runs each existing `verifiedApproved` review and source fingerprint, validates unique lot and review IDs, detects competing one-lot or multi-lot records, and requires one common, independently approved `manufacturing_cost_ledger_policies` currency and raw/WIP/FG/COGS ledger map.
- Every original production material `issue / out` and all its FIFO consumed rows must be present exactly once in the selected lots, matching source production-order material, real material ID, original movement quantity, physically issued date and `production_order_materials.issued_quantity`. All material and packaging inputs must be accounted for in source; the aggregate original quantity must equal the one frozen `production_batch_costs.unvalued_quantity`, with no pre-valued material amount.
- One actual QC-released batch, one actual physical accepted-finished-goods receipt, released package quantity, original source date, original production material issues and genuine batch identity must match. Original QC checks and production source details are included in the signed source fingerprint.
- Every physical customer dispatch must be uniquely tied to a real posted original-date invoice/line and verified customer receivable/revenue GL. Costs are booked in the ORIGINAL factory/source currency; sales revenue and receivables retain their actual separate selling currency. No FX rate is invented.
- The combined original opening cost `T` equals the sum of documented original source-lot totals `T_i`. Each lot's previously approved consumed source WIP share `W_i` and unconsumed raw asset balance `R_i` satisfy `T_i = W_i + R_i`. The full batch WIP `W = ΣW_i`, remaining source raw `R = ΣR_i`; require `T = W + R` exactly at four original-currency decimals. Authenticated WIP allocation is not re-priced from a newly assumed generic average.
- One signed common original opening-equity account is permitted only when independent evidence supports that ownership/account classification for ALL included opening lots. A source cost entered by an accountant still requires genuine external original documents and independent checking, including previously posted *manual* GL outside source-linked historical tables.

## Independent proposal / review / execution

Migration `2026_11_10_000100_create_historical_fifo_multi_lot_recoveries.php` creates:

- `finance_historical_fifo_multi_recoveries`: UNIQUE production batch, source fingerprint, original currency, true material issue/QC dates, original opening equity ledger, total cost, consumed WIP, remaining raw asset, genuine original issue and invoice counts, proposer, separate reviewer, separate executor, signed evidence and linked WIP/FG/manufacturing GL IDs.
- `finance_historical_fifo_multi_recovery_lots`: UNIQUE original FIFO opening layer and WIP source review, original opening date, signed 4-decimal original source value/WIP/remaining raw values, source signature and its own posted opening inventory/equity journal.

The finance review page `/inventory/legacy-fifo-reviews` shows approved original-source lots grouped by released batch and offers a selected-lot case form. Source and final finance proposers, their independent reviewers, all original receipt/issue actors and final executor are checked for segregation. Rejection creates NO GL journal, stock movement or source valuation.

All signed source details are frozen on submission, independently reconstructed and compared again on approval and immediately before posting; source changes/duplicate recovery plans fail without financial changes.

## Complete atomic original-date GL for restricted batch

After a separate independent final authorization, inside ONE DB transaction and finance period lock:

1. For EACH genuine original source-lot opening date: `Dr approved raw-material asset / Cr independently documented opening equity` for that lot's genuine original amount `T_i`.
2. For the ONE genuine recorded production material issue date: `Dr approved production WIP / Cr raw-material asset` for the exact combined consumed original FIFO costs `W`.
3. On actual QC finished release date: `Dr finished-goods inventory / Cr WIP` for `W`. The batch is verified to have no approved overhead or waste in this restricted path.
4. Create one source-linked `production_batch_cost_postings` record, with original source currency, true accepted package quantity, posted WIP and QC-finished journal IDs, zero excluded overhead and waste, and signed approval reference.
5. For every ACTUAL customer sale line in original dispatch-date order, use the existing guarded `FinishedGoodsCostPostingService::postInvoiceLine`: `Dr customer sale COGS / Cr finished inventory`, original sale/dispatch date, factory cost currency, original invoice amount/revenue unchanged. Allocate by genuine accepted packages, with the final original sold batch remainder assigned using the existing 4-decimal COGS rules.
6. Only AFTER all journals/COGS succeed, assign the previously missing original lot cost/currency, link every immutable historical FIFO consumption's separately recognized WIP source journal, mark each signed original WIP review financially posted, and mark the coordinated case fully posted with actual GL IDs. Original physical stock, source consumption costs, source `production_batch_costs` snapshots, customer revenue, customer credits and product quantities remain untouched.
7. If ANY step fails, the database rolls back the entire proposed journal/cost/asset chain. This cannot create only one opening or partially capitalize the batch.

All original lot receipt, issue, QC finished, sale dispatch and invoice months MUST be OPEN. An exception cannot be resolved by assigning a new convenient accounting date.

## Subsequent finance controls

- `LegacyFifoSourceReviewService`, `InventoryCostService` and `ManufacturingCostAccountingService` now recognize either independently posted single-lot or multi-lot original WIP source links. Future real raw consumption subtracts prior posted historical source WIP from original lot valuation and validates that the full historical account chain is valid before using that source.
- `HistoricalProductionFifoSourceCostReviewService::audit` routes posted source reviews to the correct single/multi-lot post-audit. `HistoricalProductionFifoMultiRecoveryService::auditPosted` verifies source opening lot quantities/material/currency, per-lot journal, combined WIP, QC finished, physical issues and frozen unvalued quantity, posted source journals, independent actor roles, real customer invoice revenue and chronological exact COGS allocation. It flags changed customer credits, QC returns, overhead/waste and duplicate or unmatched original physical movements.
- `JournalPostingService` blocks standalone reversal of any opening lot/equity, combined WIP or finished journal. The existing COGS reversal guard remains in force.
- `FinanceCloseReadinessService` checks original opening months of ALL lots, original WIP and QC finished dates, and original customer invoice months; pending cases cannot be ignored. A previously unvalued old FIFO issue is no longer a close blocker only if it is linked to a true independently POSTED single-lot or multi-lot complete source chain. `ManufacturingFinancialReconciliationService` and `ProductionCostReadinessService` expose the posted historic source status while preserving the frozen original manufacturing cost snapshot.

## D02 remains partial — next scope

This implementation **does not** support: a source FIFO lot feeding several released batches, multiple original material issue dates per batch, mixed original factory currencies or independently valued supplier GRN/purchase layers, signed overhead/waste/abnormal loss costs, mixed sample/internal/damaged dispatch, return/credit QC and revised invoices, preexisting manual GL, historical supplier returns or non-opening unknown warehouse locations. Those are separate source accounting cases requiring independent policies, date and GL evidence. Do not downgrade finance blockers or create fake source values to force a batch to appear complete.

**Verification limitation:** GitHub source and integration reference inspection was performed, not PHP compilation or actual financial exercise. Deferred migrations, role tests, journal rounding/balance tests, concurrency and MySQL UAT must pass before any accounting posting may be enabled on a real company database.
