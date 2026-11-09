# Kanozay ERP — Source-documented consumed opening FIFO adjustment accounting

Date: 2026-11-09  
Branch: `development/kanozay-completion`  
Release status: **NO-GO; development source only. No migrations, PHP lint, unit/feature tests, MySQL/SQLite, browser UAT or deployment performed.**

## Narrow original-source cost recovery

This batch extends D02 beyond documentary consumed-opening FIFO provenance to a **restricted original-currency financial correction** for an opening FIFO lot with one or more original **inventory-adjustment write-offs**. Every old source consumption must have been recorded as a genuine `inventory_adjustment` / `adjustment_out`, tied to a real immutable stock-adjustment record with exact material, warehouse, quantity, date, recorded author, and documented adjustment reason. An independently signed `consumed_provenance` review and original opening receipt are required before finance may propose values.

An **original source document must supply the monetary price/currency/ownership**. The ERP does not independently know that an entered cost is historically correct, and requires a human to cite, review and approve the evidence. Unverified purchase prices, fabricated ownership, guessed FX or a duplicate already-booked manual original-cost journal must not enter this workflow.

The conservative executor **rejects any lot containing production material issues, supplier returns or other issue categories**. They may require original WIP, cost of finished goods, COGS, supplier payable or a documented separate type of loss; this executor has no authority to treat them as simple stock adjustment write-offs.

## Schema and independent review

- Migration `2026_11_09_000700_create_historical_consumed_fifo_adjustment_valuations.php` creates `finance_historical_fifo_adjustment_valuations` (one signed original opening lot plan) and `finance_historical_fifo_adjustment_cost_lines` (each immutable old FIFO consumption exactly once, actual issue date, frozen source fingerprint, rounded monetary cost and posted journal).
- The existing independently approved `finance_legacy_fifo_source_reviews` source is reread and fingerprinted. Finance source cost proposer, independent cost approver and separate final executor must all be distinct from opening-stock registrar, original adjustment authors, source provenance requester and source provenance reviewer.
- Finance proposer records the true original opening-lot total cost, original factory/purchase currency, separately approved manufacturer raw-material inventory account, original opening equity account, and active non-COGS/non-inventory physical adjustment-loss expense account.
- An independently authorized finance reviewer verifies the same snapshot, original externally documented purchase/opening value, all historical issue source documents, original amounts, ledger mappings and no previously recognized manual asset/loss GL. Rejection creates no journal or stock value. Rejected sources are immutable, pending an independently approved amendment process (D03).
- Current remaining source stock must be wholly attributable to existing verified warehouse positions with matching physical counts, or the original lot must be fully consumed.

## Four-decimal original source allocation

Let original source quantity be `Q`, historically already consumed adjusted quantity `C`, current original-lot remaining quantity `R` and authenticated original cost `T` in one source currency. Require `Q = C + R`.

The frozen, independent original cost allocation uses:

- `remaining_value = round_4(T × R / Q)` when `R > 0`, else zero;
- `consumed_loss = T − remaining_value`;
- Original adjustment issues sorted by actual issue date, movement ID and consumption ID are allocated their source share at 4 decimal places; the last original issue receives the exact unused `consumed_loss` rounding remainder.

All source issue cost shares must be positive, within remaining original budget and sum **exactly** to `consumed_loss`; `remaining_value + consumed_loss = T`. The amounts and selected ledger IDs are frozen in the approved finance plan. No monetary amounts on old FIFO consumptions are modified, because those physical records are immutable.

## Atomic original-date GL

In one database transaction, after independent final authorization:

1. Acquire the finance-period posting gate and verify **every** original receipt and issue accounting month is OPEN.
2. Post the authentic original opening receipt date journal **Dr approved raw-material inventory / Cr original opening equity** for `T`.
3. For each genuine past physical adjustment write-off, post **Dr separately approved stock-adjustment loss expense / Cr the same raw-material inventory** for the exact original source allocated cost on its original movement date.
4. After every source journal posts, bind its original-consumption record ID and journal ID in the independent financial cost-line register. Change only the original FIFO layer's previously null `original_cost`, `currency_id` and `currency_code` to the separately approved source cost/currency.
5. Mark the approved plan posted; any invalid source quantity/date/cost, account, period, posted journal or quantity allocation rolls back all journals and the cost-layer change together.

The existing historical opening FIFO method for **unconsumed** lots remains a separate workflow; its restrictions are preserved. A consumed lot can never use that old unconsumed-only valuation path.

`InventoryCostService::remainingLayerCost()` now subtracts **posted, unreversed historical source adjustment costs** from the original layer's total before pricing any current remaining FIFO issues. This preserves the correct existing raw-material asset amount without overwriting historic `inventory_cost_consumptions` rows. The source provenance validator recognizes old immutable issue rows with separately authenticated and posted historical cost links, so later physically counted warehouse reviews continue to work.

## Audits and safeguards

- Direct standalone reversal of either the original opening/equity journal or any individual historical adjustment-loss journal is prohibited.
- Finance close audits the complete allocation for a posted plan whenever its original receipt or issue falls within the month: correct original-cost asset/equity debit/credit; signed distinct actors; factory currency; all genuine dated physical `adjustment_out` source movements; matching quantity; exactly two posted, unreversed GL lines per journal; and exact original budget split between historical consumed loss and remaining original inventory asset.
- Earlier unvalued original FIFO consumption still blocks month close until it is correctly accounted for with either a conventional valued consumption or this exact signed original issue journal allocation. A documentary provenance review alone never removes the accounting blocker.
- The whole process is source-protected and rejects unapproved accounts, duplicate original-lot cost proposals, missing source issue reasons, unlocated remaining physical units and closed original accounting dates.

## Remaining D02 scope, and why it is NOT complete

- **Production material consumption:** Must reconcile original source material asset, production-order WIP, batch manufacturing cost, finished goods, already invoiced COGS and period chronology. These old immutable production source issues cannot be treated as generic expense and remain blocked.
- **Supplier returns and non-adjustment movements:** Need source invoices, original landed freight and payable/credit entries with safe reversals and original dates.
- **Old manual GL and opening ownership:** No automated procedure can confirm all external manual/cross-period accounting records; signed external cost/ownership and no-duplicate-journal verification remains mandatory.
- **Non-opening GRN/stock adjustments with missing locations:** This review handles physical original opening FIFO sources only.
- **Period closure, source amendments, rejected proposals and accounting reclassifications:** These require separately approved authorization, and reopen/reversal via existing controls, never an arbitrary corrected date or one-sided expense.

The code was reviewed through GitHub file and call-reference checks only. Nothing was executed. Source links and implementation are **not** proof of accepted real-client accounting results or production readiness.
