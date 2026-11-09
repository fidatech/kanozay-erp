# Kanozay ERP — Historical consumed opening FIFO production WIP original-source preflight

Date: 2026-11-09  
Branch: `development/kanozay-completion`  
Verification: **Source-only GitHub integration check. No migrations, PHP lint, automated tests, SQL transaction execution, browser/UAT or deployment.**

## Problem

An old physical opening FIFO lot can have immutable, originally **unvalued** raw material consumption records issued into real production orders, whose completed, quality-released batches may already have dispatched stock and customer invoices. A finance entry `Dr historical loss / Cr raw inventory` is inappropriate for normal production inputs. Raw/WIP/finished-goods/COGS accounting must match the same original production batch and original event dates, and the existing historical `production_batch_costs` snapshot is immutable.

The existing `HistoricalConsumedFifoAdjustmentValuationService` continues to permit monetary GL reconstruction only for verified `inventory_adjustment / adjustment_out` source issues. It specifically rejects production inputs.

## Implemented

New schema `2026_11_09_000800_create_historical_production_fifo_source_reviews.php` creates:

- `finance_historical_fifo_production_cost_reviews`: one immutable candidate per real opening FIFO layer, approved consumption provenance review, original source cost/currency, original opening/consumed/remaining quantities, source raw-inventory/WIP account policy, independently signed finance evidence and source fingerprint, status `submitted/approved/rejected`. **No journal, posted state, warehouse change or original-cost mutation is possible through this workflow.**
- `finance_historical_fifo_production_cost_lines`: one actual old FIFO consumption per source cost review with exact production-order material line, released production batch, original issue date, genuine issue quantity, four-decimal projected original WIP source-cost fraction and SHA-256 source fingerprint.

The `HistoricalProductionFifoSourceCostReviewService` provides independently segregated submit, approve/reject, snapshot integrity and finance-close audit. The finance UI at `/inventory/legacy-fifo-reviews` shows source-price evidence, each batch allocation and the downstream frozen material cost/unvalued quantity, customer dispatch/invoice and prior capitalization status.

## Source prerequisites

1. A real `opening/in` receipt, opening lot, actual FIFO quantities and original stock issue records must already pass `LegacyFifoSourceReviewService::snapshot`. A finance-consumption provenance reviewer must be independent of the original opening/production issue registrar. All original consumed rows must still have null source monetary amount/currency.
2. **Every** consumption on the lot must be a genuine `production_order_material` / physical `issue/out`; adjustment, supplier return or mixed-purpose source lots are blocked. The issue's referenced original material line, physical issued quantity, issued date, production order, single quality-released production batch and actual recorded finished-goods production receipt must reconcile. A released batch must have accepted packages, and material issues must occur before the real finished receipt.
3. One original immutable `production_batch_costs` snapshot must already exist and show genuine unvalued material quantity. No manufacturing capitalization or original invoice COGS may have been posted for any affected batch. Previously dispatched goods and genuine invoice lines are captured in the frozen source dependency fingerprint for later reconciliation, **not automatically booked**.
4. A competing original FIFO asset/equity capitalization, open duplicate correction or earlier historical consumed adjustment cost proposal blocks this production source review. Current remaining lot stock must be physically attributed to existing warehouse positions and verified counts; no phantom opening quantity is created.
5. Independent reviewers supply a source-document-backed **original** opening lot total cost and currency. This is a human-attested fact, not something the ERP can verify merely from an entered number. The current approved manufacturing ledger policy identifies the true raw-inventory and WIP accounts for that currency.
6. For original total cost `T`, original quantity `Q`, genuine current remaining `R` and genuine already consumed production `C`: `Q = R + C`, `remaining_original_cost = round4(T × R / Q)`, `consumed_production_cost = T − remaining_original_cost`. Each dated original production issue receives a four-decimal share proportional to its physical quantity; the final issue receives the sole remaining original-cost rounding difference. All shares must be positive and sum exactly to consumed source cost.
7. The finance proposer is different from the original opening-stock registrar, original production material issuers and consumed-source provenance requester/reviewer. A further distinct finance approver reviews the exact frozen original issue, batch dependency, approved currency/ledger, original purchase/ownership evidence and proposed WIP shares. Changed source data fails approval.

## Financial close and deliberate safeguards

- The new finance-close check inspects source-review integrity for months containing either the opening lot receipt or its genuine original production issues. **Submitted and independently approved WIP-source proposals remain accounting close blockers** until genuine original-currency raw/WIP/finished-goods/invoiced COGS journals are recognized by a *separate jointly authorized executor*. Existing unvalued-consumption close blockers remain in effect.
- There is no posting action, no draft GL journal, no ability to change frozen `production_batch_costs`, no physical warehouse movement, no second invoice/customer credit, no COGS insertion, and no source FIFO amount mutation.
- A signed source cost is **not proof of historical price** without genuine external opening/ownership or supplier documentation. A human review of earlier manual GL, opening balances and period cutoff remains essential.

## Still needed to finish D02

Build an **atomic chronological original-source recovery executor** that can accept independently approved source-cost budgets from multiple opening FIFO layers for each real production order; reconcile all contemporaneous valued goods-receipt/material issues, independently approved production waste and overhead; validate original production batch costs without mutating them; post real opening inventory/equity, dated production raw-inventory/WIP and finished inventory/COGS (and historical non-sale/credit returns as applicable) on original open accounting dates, with full source IDs, exactly-once protection, segregation, period locking and month-close reconciliation. When an already-posted/manual journal or closed period exists, fail closed and require independently audited source correction.

**Status:** D02 partial. This delivery adds authenticated original production issue/WIP allocation **review**, not an executable accounting correction. The next source development step must integrate the above full financial sequence before anyone can post.
