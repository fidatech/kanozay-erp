# Kanozay ERP — Historical opening FIFO consumption and warehouse provenance review

Date: 2026-11-09  
Branch: `development/kanozay-completion`  
Verification status: **GitHub source only. No migrations, PHP lint, automated tests, browser UAT, accounting period-close execution or deployment.**

## Problem and source boundaries

Historical raw material FIFO opening receipts can include (a) **already consumed** units with immutable unvalued issue rows and missing original cost and (b) genuine **remaining stock** recorded as warehouse-unassigned or missing a FIFO lot position. These are different facts and must not be conflated.

- An old issued quantity is not physically available: its original receipt and immutable stock issues require documentary source reconciliation. No inventory quantity, FIFO issue amount, material inventory asset or manufacturing WIP expense can be created merely because an accountant types a price.
- A remaining FIFO quantity can be attributed to an existing warehouse only where a separate signed physical count already supports that location. This assignment changes the **lot position ledger**, not global on-hand, warehouse physical stock, original cost, customer/supplier documents or the General Ledger.

## Implementation

Added migration `2026_11_09_000600_create_legacy_fifo_source_reviews.php`, `LegacyFifoSourceReviewService` and `LegacyFifoSourceReviewController`, routed at `/inventory/legacy-fifo-reviews`. The source-frozen `finance_legacy_fifo_source_reviews` stores source lot/material, review type, opening/used/remaining quantities, location and count reference, evidence, SHA-256 source fingerprint, two separate actors, signed decision, audit timestamps and a link to an existing-lot-location assignment if approved.

### Evidence validation

1. The lot must be a real, dated `inventory_stock_movement` **opening/in** receipt, with matching material and original quantity, original stock registrar and source date.
2. Each original FIFO consumption must be an immutable material-matched, appropriately dated **out** movement, with legitimate source reference, true quantity and no inconsistent valuation/currency state.
3. All FIFO consumed quantities for each original outward stock movement must total exactly the original physical movement. Total consumed plus still remaining must equal the original opening-lot quantity. No arbitrary partial movement cost is silently assigned.
4. The source snapshot freezes original opening, material, immutable cost/issue rows, all lot warehouse positions, global material on-hand and actual warehouse balances. Review approval re-reads and compares the exact fingerprint. Changed physical count, FIFO issue, original cost, warehouse allocation or source date invalidates the pending review.
5. Only the original **unvalued** consumed movements can receive documentary `consumed_provenance` approval. A verified monetary original cost still needs the separate authorized finance accounting source path: approval does **not** change `inventory_cost_consumptions.amount`, `inventory_cost_layers.original_cost`, production cost or GL.
6. Source-backed **remaining** stock may receive `locate_remaining` approval even when the original FIFO lot already has an authentic source valuation; any prior approved opening-value journal must remain posted, source-matched and unreversed. Neither valued nor unvalued costs are repriced by location review.

### Independent warehouse attribution

- Source proposer and final reviewer must be different people and both differ from the original opening-stock registrar.
- Destination warehouse must be active, physically counted and consistent with a source receipt already naming a warehouse. This action cannot fabricate a historical transfer to a different warehouse.
- The quantity must be a genuine part of the current original remaining FIFO lot that is **not already physically attributed**, including existing `unassigned` lots. Sum of lot locations cannot exceed remaining FIFO quantity.
- The review executor checks that all already attributed lots for the material at the destination plus the proposed quantity do not exceed the actual `inventory_warehouse_balances.quantity` for that material/warehouse. Global material on-hand must be consistent.
- The verified change moves/allocates only `inventory_cost_layer_locations`: decrement existing `unassigned` attribution if present, add the exact approved quantity to the destination lot position and record `inventory_legacy_lot_assignments` with independent reviewer identity and signed evidence. It **never writes any** `inventory_stock_movements`, physical warehouse balance, cost consumption or GL journal.
- All validation, original source/warehouse locks, review status, warehouse attribution, history record and audit log commit in one DB transaction. A stale signed proposal or source mismatch fails with no changes.
- The older direct `InventoryLotLocationService::assignLegacy` execution path now explicitly rejects calls. The warehouse stock form only **submits** source evidence, requiring the independent reviewer to approve on the dedicated review page. Both unpositioned and existing legacy `unassigned` lots appear.

### Financial close and remaining limits

- `FinanceCloseReadinessService` now blocks monthly financial close when historical opening FIFO **consumed** issue movements in that month have unresolved authentic original cost or source-currency valuation. Documentary provenance review **does not** remove that blocker.
- Finance close checks independent reviewer, actor and quantity links for approved physical location attributions.
- **Not yet implemented:** source-priced retroactive consumption/WIP/COGS GL recognition, genuine source purchase/owner equity valuation, original-currency expense allocation for production and nonproduction consumption, historical issue accounting date adjustments, proof against older manually posted journals, unknown receipt provenance and arbitrary non-opening GRN/adjustment lot location remapping. These need their own original-document policies and independent business sign-off.
- Original source data, account balances, production batches, consumed cost rows and any already posted journals remain unchanged by this review flow.

### Development evidence

GitHub service, migration, route, controller, warehouse UI and close-check integration references were reviewed. This is **partial D02 development**, not certification of FIFO accounting correctness. Migration execution, PHP syntax lint, unit/integration tests, DB transactions, warehouse concurrency and role-based browser review are deliberately deferred at the user's earlier request. No production release is authorized.
