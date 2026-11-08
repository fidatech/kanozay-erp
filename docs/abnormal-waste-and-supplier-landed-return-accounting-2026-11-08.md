# Kanozay ERP — Abnormal FIFO production waste and supplier-return landed-cost adjustments

Date: 2026-11-08  
Branch: `development/kanozay-completion`  
Status: **Source committed; migrations, tests, syntax lint, application UAT, MySQL checks and deployment deferred.**

## 1. Abnormal production-loss GL recognition

Previously material waste was reviewed as a physical issued-material classification, but an abnormal or rework loss blocked warehouse release and capitalization because the system could not establish what fraction of actual FIFO cost should be recognized as a loss expense.

This batch creates `production_abnormal_waste_cost_reviews` and `production_abnormal_waste_cost_allocations`. Every abnormal classification must be linked to exact original `inventory_cost_consumptions` belonging to its `production_order_material` and recorded at the time of the original stock issue.

- The applicant enters a specific lost quantity for each eligible FIFO consumption. Their sum must equal the immutable recorded waste quantity, and no FIFO consumption quantity or cost may be claimed by overlapping abnormal-loss allocations.
- The attributed loss cost comes from each frozen original-currency FIFO consumption amount and its physical quantity. A final allocation for a fully consumed remainder receives the original source-cost remainder to preserve the underlying precision. No blended batch average, FX conversion or guessed replacement price is used.
- Only original GRN-backed, valued FIFO consumptions may be used. All linked source consumptions must use one original currency.
- The abnormal-loss review requires a third independent finance reviewer, different from the person who recorded the waste and the person who classified it. The reviewer chooses an active, unmapped non-COGS loss expense ledger, records signed source evidence and authorizes the exact lost quantity/cost allocation.
- A batch cannot capitalize or physically release an abnormal source loss without this specific approved FIFO cost attribution. Rework remains blocked until independently closed and a fresh QC sample has passed.
- The actual GL expense is posted **atomically inside the original manufacturing batch cost capitalization**, not when the physical waste is recorded or its source valuation is approved.
- The original WIP issue journal now **credits the original raw inventory ledger for the complete consumed material cost**, debits the authorized abnormal loss expense by its approved cost, and debits WIP with the remaining eligible material and separately approved production overhead. The follow-on finished-goods asset capitalization debits finished inventory and credits WIP for that *net eligible* amount.
- The batch cost posting preserves `material_amount` as gross FIFO source cost and adds `abnormal_waste_amount`; `total_amount` now means the net capitalized finished-goods asset amount. COGS unit amounts subsequently use the net capitalized batch value already stored in the posting.
- Abnormal loss expense accounts cannot also be source overhead expense accounts for the same batch. Expense accounts must be independently mapped and posted once.
- The batch cost readiness screen subtracts approved abnormal loss before estimating eligible cost per accepted package. The manufacturing GL reconciliation report and CSV show gross materials, abnormal loss expense, and remaining finished-goods value separately, and verify the underlying GL debit/credit relationship.
- The production-order screen provides eligible original FIFO consumption quantities, original-currency costs, account selection and evidence fields. The company-wide waste report distinguishes *source cost approved* from *expense journal posted*.
- Finance month-close treats abnormal loss as unresolved while its FIFO cost review or actual batch expense journal is missing, without automatically blocking already reviewed and posted losses.

The physical material issue and original immutable waste quantity are unchanged. Recording a loss never decrements raw inventory a second time.

## 2. Supplier returns involving capitalized landed costs

A supplier debit note reduces the *original base purchase payable*. It does not automatically reverse separate freight, customs, insurance or handling costs, which may have originated from another vendor. Removing a return lot with that cost still in the raw-inventory asset would overstate inventory.

This batch adds `purchase_return_landed_cost_adjustments` and `purchase_return_landed_cost_adjustment_lines` and a two-stage workflow:

1. When a requested GRN return contains any original landed-cost component, the return record and source quantities are saved as `pending_landed_cost_review`; **no stock is removed**, no supplier payable is credited, and no return GL journal is posted.
2. A separate finance reviewer who did not request the physical return selects an active, non-COGS expense ledger and supplies signed evidence. The original capitalized landed-cost components must already have been posted in the exact purchase currency and approved raw-inventory account by the physical return date.
3. The system derives the returned original-currency landed-cost share for each GRN lot. Partial returns use cumulative source-lot quantities and previously posted return loss amounts to preserve rounding. Missing historical landed-cost write-offs, mismatched currencies or source quantities block the new return rather than being inferred.
4. Finance verifies that the as-of-date original-currency raw inventory GL asset balance covers the returned landed-cost value, then atomically posts one journal **debit nonrecoverable landed-cost loss expense / credit original raw-inventory asset** for the total returned landed-cost share, records each source return-line amount and **only then** posts all physical stock returns. The FIFO source consumes the exact original lot and validates the independently posted, unreversed journal and returned quantity.
5. Upon success, the return becomes `posted_pending_credit`. The existing supplier debit-note workflow remains responsible for the original *base purchase-value* supplier payable reduction, with a separate approval. That ordinary debit note now requires the full source-priced returned base value; a disputed/partial supplier credit must use a distinct, evidence-backed short-credit expense or receivable process. The returned landed amount is not added to the supplier credit or assumed recoverable.
6. While the request is pending, authorized procurement/inventory staff may cancel it with a recorded reason. Cancellation does not post inventory or GL changes, and the unused requested quantity is released for future return requests. A cancelled submission token cannot be replayed as a posted return.
7. The return document and the return register now distinguish pending physical stock, posted physical return, posted supplier credit and cancelled request states. The return document displays the landed-cost GL reference and independent approval evidence.
8. Finance month-close blocks unfinished requests and invalid/reversed returned-landed-cost journals. The GL generic reversal service forbids reversal of these journals independently from the physical source record.

The ordinary supplier-return flow without landed costs retains its existing physical stock posting behavior.

## 3. Remaining financial corrections and integration work

- **Recoverable landed charges and partial base supplier credits:** This completed flow explicitly treats the returned landed cost as a *nonrecoverable* expense. If the company expects reimbursement from the supplier, transporter or insurer, a separately evidenced receivable/claim and recovery workflow is still required. Supplier base debit notes cannot be used to fabricate that recovery.
- **Historical returns and opening stock:** Any already-posted supplier return with landed-cost FIFO assets but no original matching write-off remains blocked from additional returns of the same lot until historic source evidence is independently reconciled.
- **Legacy adjustments:** Standalone finished-goods dispatches, missing historical COGS, unknown stock opening values, consumed landed-cost layers without valid source GL, old multi-currency cost combinations and previous supplier debit notes still require a formal documented correction process.
- **Abnormal loss exceptions:** Quantities, source costs and original-currency expense ledgers are explicit for newly reviewed abnormal issues. Corrections to already-capitalized batches, retrospective rework, rejected full batches, impaired/WIP stock and external write-off authorizations remain separate procedures.
- **Source protection:** A full production-scale database and role review is needed to ensure every historical source record can satisfy the stricter immutable lot and GL reconciliation gates. Costs with absent evidence cannot be repaired by simply making a backdated journal.
- **Language and UI:** New English approval controls are source implemented; full Afghan Dari/Pashto transactional translation, detailed amount formatting and review of right-to-left interactions remain pending.
- **Release:** The new migrations and all related original-currency finance workflows are unexecuted. Test in a staging copy and reconcile historical balances before enabling on MySQL or production.

## Verification boundary

Per user instructions, this is development **only**. No migrations were executed; no automated tests, PHP syntax lint, browser UAT, historical backfill, journal post, live inventory movement or deployment was performed. Production readiness and final ZIP acceptance remain pending.
