# Kanozay ERP — Manufacturing and Production development

Date: 2026-11-08  
Branch: `development/kanozay-completion`  
Status: **Source code committed; development and integration still in progress.**

## Implemented in this development batch

### Recipe and packaging workflow
- Draft production recipes can be edited in a single database transaction. All draft lines can be adjusted or replaced; approved recipe records and existing production plans are locked against modification.
- Creation and editing include a first-class *packaging versus ingredient* flag for each recipe line.
- Ingredient quantities, unit costs and package weight accept grouped and localized Pashto/Dari decimal digits while preserving database precision constraints.
- Production batches cannot report more packages than the actual issued quantities of packaging materials permit under the frozen approved recipe.
- Production-order material rows now display actual issue versus planned quantity variances without inventing client-specific tolerance thresholds.

### Production plans and equipment routing
- Production plan creation has a nullable unique submission token for compatibility with old records. The current form issues one and rejects duplicate submissions.
- Approved routing snapshots now reject invalid or missing stage numbers/operations, inactive equipment, unsupported estimates and oversized instructions instead of silently omitting malformed stages.
- Work-step start locks the manufacturing equipment record and blocks concurrent operation by another active step.
- Recorded equipment downtime blocks starting an overlapping routed step. Existing calibration results that are failed or expired still block operation; no unsupported universal calibration requirement is invented where a company policy has not been provided.
- Actual elapsed time between start and completion is shown alongside the planned duration. It is **not** a validated payroll or labor-hour record.

### Material traceability and cost
- A batch traceability page connects actual material and packaging issue quantities to source FIFO layers, original GRNs, purchase orders and suppliers where known, issue warehouses, current QC results, and finished-goods sales/dispatch summaries.
- A reverse source-lot traceability page identifies production orders and batches that consumed a selected raw or packaging material lot.
- Source lots currently under a QC hold display an explicit warning that already released downstream batches require a quality/recall review. This does **not** automatically recall any batch.
- Frozen `production_batch_costs` now preserve separate `ingredient_cost` and `packaging_cost` values for new snapshots, while retaining total `material_cost` for existing reports. Older snapshots retain NULL category splits because inventing historical values is not acceptable.
- A QC-approved batch cannot be physically released if any original material source lot is now held, missing, or a posted issue quantity does not match the immutable FIFO consumption records. A historical issue without verified source provenance must first be reconciled.

### Audit history
- Added activity records covering production-plan creation, material reservation, actual material issue, cancellation, batch submission, laboratory test entry, QC acceptance/rejection, and warehouse release.
- Existing finished-good release and cost snapshot workflows remain transactional. No extra stock adjustment, journal entry or currency conversion is created by traceability reports.

## Remaining manufacturing development and dependencies

- Customer-approved recipe workbook corrections and exact raw-material/packaging unit conversions; do not approve incorrect recipes from assumptions.
- Plant equipment schedules, throughput/capacity calculations, actual labor time records and machine-load planning grounded in approved units and rates.
- By-products, formal rework, material variance approval rules, rejected package disposal and material recovery procedures.
- Warehouse-specific production reservations (global reservations are currently used), source-lot quarantine disposition, and end-to-end controlled recall actions.
- Detailed payroll and production overhead allocations to GL, WIP-to-finished-goods valuation and COGS capitalization.
- Full production, accounting, QC and sales integration, stakeholder UAT and live data migration.

## Deployment and verification

At the user's request, **no automated tests, database migrations, browser UAT or production deployment were performed** during this batch. Code commits prove only that changes were written to the development branch. They do not prove that all workflows execute correctly or that the manufacturing module is ready for production. Changes that block release for missing FIFO provenance must be evaluated against reviewed legacy stock history before activation.
