# Warehouse FIFO lot location integration — development record

Date: 2026-11-08  
Branch: `development/kanozay-completion`  
Status: **source implemented, migrations and runtime UAT deliberately deferred**

## Implemented

- Created `inventory_cost_layer_locations` with one unique position per FIFO cost layer and warehouse/unassigned pool. The explicit `location_key` prevents duplicate unassigned rows on SQLite and MySQL.
- Created immutable `inventory_warehouse_lot_movements` linking each warehouse allocation/transfer to the actual contributing original FIFO layers.
- Created an audited `inventory_legacy_lot_assignments` register requiring a documented physical/count reference and reason, with combined inventory and finance authorization or administration authorization.
- New posted GRN lines, opening stocks and positive inventory adjustments create warehouse-specific lot positions. Positive adjustments without a verified purchase cost remain unvalued.
- Warehouse allocations and transfers update lot locations and aggregate warehouse balances in the same database transaction. FIFO original quantity, original cost, source receipt and currency are unchanged by transfers.
- Transfer picks only physically positioned and quality-released FIFO lots. Global production reservations still block warehouse transfers until cleared because production reservations are not yet warehouse-specific.
- Production issues and inventory reductions consume only FIFO layers with the chosen location; supplier returns consume the exact original receipt lot at the actual returned-from warehouse. A mismatched lot/source location rejects the complete stock movement.
- Production reservation now requires sufficient quality-released, location-attributed FIFO quantity.
- Returns and issues dated before their source FIFO receipt are refused.
- Warehouse register reports global versus warehouse physical balances, verified FIFO quantity, unreconciled quantity, and source lots for every new warehouse movement.
- Paginated location valuation reports remaining FIFO value by warehouse, source layer and original currency, with explicit unreconciled and over-attributed positions. Values of different currencies are never added together.

## Historical data and transition

- Existing FIFO layers are **not automatically assigned to current warehouse balances**. Their historical transfers and issues might differ from original receipt locations. Staff must inspect counts, source GRNs and warehouse movements and explicitly attribute each remaining lot via Warehouse Stock.
- Legacy layers with no positional evidence, over-attributed positions and insufficient source-lot quantities **block affected issues, transfers and production reservations** instead of being silently consumed or revalued.
- Legacy physical stock without **any** FIFO layer requires a separate approved opening-stock provenance reconciliation. This batch does not create a fictional source cost or receipt date.
- Legacy warehouse movements already posted before the new lot-ledger feature are labelled historical/unverified; they are not retroactively given invented FIFO lot allocations.
- Unvalued positive adjustments are tracked as unvalued FIFO layers so location movement remains traceable, without assuming a price.

## Outstanding development before full purchasing/manufacturing sign-off

- A reviewed workflow for legacy physical stock that predates even FIFO cost-layer tracking, including quantity/cost evidence and original period handling.
- Warehouse-specific production reservations and QC quarantine transfer routing, including authorization for disposal/return of held lots.
- Material batch/serial traceability and documented recall workflow through production batches and finished goods.
- Formal financial postings for separately invoiced landed-cost suppliers, including review of tax and discounts.
- Complex return credits across multiple matched supplier invoices.
- Full source-ledger reconciliation and client-specific approval of legacy opening values.

## Verification boundary

No automated tests, MySQL migrations, browser sessions or production deployment were executed at the user's request. These commits represent development artifacts, not confirmed runnable functionality or approval for production use. Apply the new migrations and reconcile legacy stock in a dedicated UAT environment before enabling the stricter FIFO controls on a live database.
