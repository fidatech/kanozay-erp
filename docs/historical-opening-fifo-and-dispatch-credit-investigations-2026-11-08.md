# Kanozay ERP — Original opening FIFO valuation and legacy financial investigation

Date: 2026-11-08  
Development branch: `development/kanozay-completion`  
Release status: **Source committed only. No migrations, tests, lint, browser verification, MySQL execution or deployment.**

## 1. Source-evidenced opening inventory valuation

Previously, the Historical Financial Correction Center could investigate FIFO lots lacking original values, but there was no permitted financial posting path for an old physical opening lot. The FIFO and manufacturing services also excluded unverified opening stock from normal source-cost-backed journal capitalization.

This update adds `finance_opening_fifo_valuations` with a unique source FIFO layer ID, unique historical correction case ID and unique posted journal ID. Finance may recognize **a previously recorded, physically counted opening lot** only if all of the following conditions hold:

- The independent historical correction case is approved, its immutable original-source fingerprint still matches and the original requester is not the posting finance employee.
- The original stock recorder is not the employee posting the historical value.
- The source lot is a genuine `inventory_stock_movement` with `type=opening` and the same original material, quantity and physical entry date. Adjustment-in, supplier GRN and unknown source lots are **not** eligible for this path.
- Its `original_cost`, `currency_id` and currency code are empty, the complete original quantity remains in the lot and **no FIFO consumption from that lot has ever occurred**.
- The original stock remains in the material's current on-hand quantity, and the entire lot is fully assigned to active, counted warehouses. Per-location source quantity must be covered by physical warehouse balances, and the aggregated locations of **all** FIFO lots for the material may not exceed recorded warehouse quantities.
- Finance supplies the **documented original cost amount and original currency**. That amount is not computed from a guessed cost average or foreign exchange rate. A signed source reference and detailed notes certify that the amount is independently evidenced, the opening-equity account is authorized and that this same original lot has not already been valued through a manual GL entry. The application cannot independently prove a historical supplier valuation when those source documents are absent.
- The original-currency raw inventory asset ledger must match an independently approved manufacturing ledger policy. The offset must be a distinct, active, unmapped **equity** account.
- The posting date must be in an open financial period, be no earlier than the physical stock entry and be no later than the present date. It is kept separately from the original physical receipt date. No closed month is reopened by the workflow.

The system posts one balanced entry **Dr original-currency raw-material inventory / Cr approved opening equity**. Only after the journal is posted does it assign the same amount and currency to the existing original FIFO cost layer. It does **not** create a second physical receipt, change quantity or rewrite any immutable stock movement.

The source case becomes resolved with journal reference, original source fingerprint, independent review evidence and an append-only completion event. The recorded journal may not be reversed directly without a dedicated reviewed cost-layer correction. Later FIFO consumption also requires that the linked journal remains posted, unreversed, same-currency and dated before that material issue.

### Production accounting integration

The manufacturing WIP source validator now accepts FIFO `inventory_stock_movement` opening lots **only** with an independently resolved historical opening-stock case and matching asset/equity GL evidence. It verifies original quantity, original currency, journal status, approved raw inventory ledger and exact source amount before transferring that original cost into WIP.

Abnormal-production-waste allocation can also use a corrected opening FIFO consumption, provided the same source currency, original valuation journal and issue chronology match. Unverified opening lots, stock already issued before valuation and ordinary manual unvalued adjustments still cannot be silently treated as original-valued inventory.

The Raw-material Valuation screen includes a link to historical opening-stock source cases. Finance month-close detects invalid, reversed, re-dated or mismatched opening valuation journals.

## 2. Historical warehouse-only dispatch disposition

Created `finance_historical_dispatch_dispositions` with a unique source dispatch and correction-case link. A separate finance reviewer records the original signed business purpose:

- Customer sale (original customer/invoice reconciliation required)
- Promotional sampling
- Damaged/lost product
- Documented internal consumption
- Unresolved business purpose

The historical case shows the physical batch, product, quantity, warehouse and any existing source-batch cost capitalization. This information is **evidence only**, not an automatic COGS amount or customer invoice.

The case's original dispatch fingerprint and lack of subsequently linked customer invoice are rechecked. A signed source document, rationale and outstanding account treatment are required. The original warehouse dispatch stays immutable, and the case **remains approved/open** after disposition review because no purpose-specific sales, expense or cost-correction journal has been posted.

No revenue, receivable, expense, inventory or COGS is invented by this classification. Existing linked independent evidence cannot be invalidated by merely withdrawing its parent historical correction case; a documented amendment workflow remains necessary.

## 3. Original-source supplier credit disputes

Created `finance_supplier_return_credit_disputes` for posted physical supplier returns with no supplier payable debit note. The case must already have independent finance approval and must still match the original immutable GRN return snapshot.

The reviewer enters the supplier's documented offered amount in the **original return currency**, with signed supplier correspondence. The system calculates:

`unresolved difference = sum(original returned purchase-price snapshot) - supplier offered credit`

The source amounts are derived only from immutable return-line snapshots. Offers equal to the original return value should follow the existing normal supplier debit-note service. A genuine partial credit, including a zero-credit refusal, may be documented as a claim pending, potential loss requiring independent authorization or unresolved evidence.

The dispute's original base value, offer and difference remain separate from capitalized freight/customs, taxes, unrelated suppliers and any FX conversions. **No payable reduction, legally enforceable receivable claim, cash receipt or loss expense is recognized** by the review. The historical case remains open until a purpose-specific authorized journal and settlement/adjustment workflow is developed.

## 4. Historical Financial Correction Center changes

- Added a dedicated, evidence-based execution form to approved, original opening FIFO cases. It records currency, exact cost, opening-equity account, open-period journal date, physical-count confirmation and signed source proof.
- Added read-only source batch costing and a signed, nonposting disposition to approved standalone dispatch cases.
- Added original supplier return-line amounts and a signed, nonposting short-credit dispute review to approved supplier-return cases.
- Historical correction cases remain unique per open source/type. After a related signed dispatch or supplier dispute classification is attached, the parent case cannot simply be withdrawn and orphan that evidence.
- The source review event history preserves all original case identifiers, reviewer decisions and original-source fingerprints.
- Existing historically capitalized invoices may still have missing original-currency COGS posted **only** through independent, case-approved, exact source validation.

## Still outstanding

1. **Consumed or unlocated opening stock:** This implementation intentionally refuses to reprice earlier FIFO issues. A separate source-wise retrospective stock/GL correction path is required for already consumed lots, unverified physical positions, or original costs with no credible evidence.
2. **Historic standalone physical dispatches:** Classification is complete as a documentation step. Source-priced sale COGS, sampled product expense, internal consumption or damaged stock expense require individually authorized, source-dated journal posting and matching operational documents. Do not treat them as customer sales without actual customer and revenue evidence.
3. **Supplier disputed/partial credits:** The source-price shortfall evidence is recorded, but the current ordinary debit note still requires a full matched purchase-price credit. Recognizing any legally enforceable supplier receivable or nonrecoverable loss requires approved account selection, claim evidence, consent or other accounting authorization and a separate original-currency journal.
4. **Existing manual opening GL:** A historical layer with earlier unlinked inventory-account posting cannot be safely recognized a second time. A signed finance review is required and the new source path has a mandatory explicit no-prior-valuation confirmation; the software cannot discover all manually documented off-system source allocations.
5. **Complete QA and release:** The new migrations and services have **not** been executed. Complete SQLite/MySQL migration compatibility, role and precision validation, journal posting unit/integration tests, period-close concurrency checks, physical stock UAT, multilingual transactional labels and handoff packaging later with user authorization.

## Verification boundary

This document records committed source, not a production acceptance statement. No migrations, automated tests, PHP lint, deployed browser runs or live accounting journals were performed in this phase. Unsupported historical costs stay unresolved instead of being fabricated.
