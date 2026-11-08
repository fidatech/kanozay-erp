# Kanozay ERP — Atomic historical batch capitalization with customer credit-note and QC-return recovery

Date: 2026-11-09  
Branch: `development/kanozay-completion`  
Status: **Source code committed. User-directed deferral remains in effect: no migrations, PHP lint, automated tests, SQLite/MySQL verification, UAT or deployment were run.**

## Supported source scenario

A QC-released production batch was physically dispatched to a genuine customer, but its original manufacturing FIFO/WIP/finished-inventory capitalization and the customer's original COGS were not posted. The customer subsequently received a genuine credit note and the company **already** accepted the same goods back into a named company warehouse with independent QC approval, but the restored original inventory asset / sales COGS reversal is also missing.

This batch integrates those movements into the previously developed independently approved `batch_uncapitalized` finance recovery. An approved original production batch may also contain documented promotional samples, damaged inventory and authorized internal consumption, provided every source line is independently classified and fully valued.

## Required immutable evidence

- Released production batch, source QC receipt, frozen original-currency material FIFO costs, independently approved supplier and overhead source ledgers, manufacturing source accounting period and genuine, independently reviewed correction case.
- Every original sale must have a true, posted and unreversed customer invoice and revenue/receivable journal matched to the physical dispatch's batch, finished item, quantity and original date. A separate signed non-sale expense classification remains required for every standalone non-customer dispatch.
- Every original **sales credit-note line** for the batch must have exactly one original independent QC return record, with the same original invoice line, batch, product and return quantity. Full credit-note totals must equal its line sum. The original credit note's posted revenue reversal must debit the same income GL used by the original posted invoice, and credit the original customer's receivable GL, both in the sale's own currency. Customer credits cannot exceed the genuine invoiced quantity.
- Every return must already have status **released**, with different credit/return registrar and QC inspector, signed QC document and decision notes, recorded QC date and a named released warehouse. Exactly **one** original warehouse `customer_return` movement must match the return ID, batch, package count, destination, QC date and reviewer. The historical correction is FINANCIAL ONLY; it will never create an extra physical restock.
- An already booked `production_return_cost_postings` entry, existing separate return-recovery proposal or independently open return correction case excludes that source return. Rejected, scrapped, still-quarantined, directly restocked or warehouse-unassigned legacy records remain blocked.

## Frozen source valuation and approval

1. A new migration adds `return_line_count` and `posted_return_lines` to `finance_historical_batch_capitalizations` and creates `finance_historical_batch_return_allocations`. Each QC return has a unique source assignment and independent evidence fingerprint, original credit-note and customer-sale line references, original manufacturing currency, QC date, original invoiced COGS amount, exact recoverable cost fraction and final posted cost/journal links.
2. The source validator computes the original-currency COGS of the genuine sale using the approved batch manufacturing projection. The return value is determined from this original COGS and exact QC returned packages. Earlier returns for that sale consume their original value; the last fully returned portion receives the exact outstanding 4-decimal rounding remainder. No customer sale price, invoice currency or estimated exchange rate is used as manufacturing cost.
3. The complete batch fingerprint binds original manufacturer cost, physical dispatches, verified sale and non-sale purposes, all signed QC/credit/warehouse records and their return cost allocations. Finance proposal, independent reviewer and separate executor re-read the same exact sources and compare to the stored approved amounts and fingerprints.
4. The finance proposer, reviewer and executor must differ from original historical case actors, original non-sale dispatch reviewers and all included original customer credit/QC return registrars/reviewers. Rejected, amended or changed source evidence cannot silently be reposted.
5. The original manufacturing capitalization date, each physical dispatch date and each original QC-return asset recovery date **must all be in open financial periods**. The QC date cannot predate the genuine customer invoice or original customer credit note or the source batch capitalization. Closed periods are not reopened or replaced with today's date.

## Atomic chronological accounting

Within a single shared-finance-gate database transaction, a separately approved executor:

1. Posts genuine source manufacturer raw/WIP and finished inventory journals in the approved original currency and date using the existing manufacturing capitalization service's protected historical authorization.
2. Processes original physical dispatches and QC releases **in source-date order**. Same-day source sales and independently signed non-sale dispatches are posted before same-day QC returns so the original COGS exists before any reversal.
3. For genuine customer sales, posts only missing original-date **Dr original manufacturing COGS / Cr finished-goods inventory**. Original customer revenue, trade receivable and invoices remain unchanged.
4. For signed non-sale purposes, uses the existing independently approved sample/loss/internal-use ledger **Dr authorized non-sale expense / Cr finished-goods inventory**, with no fabricated sale.
5. For an original independent QC-released physical customer return, uses the existing `FinishedGoodsCostPostingService::postReturnRecovery` with a **separate, temporary and scoped active-batch execution gate**. It posts **Dr finished-goods inventory / Cr original sale COGS** for the exact original source-cost fraction on its genuine QC release date. No second return movement, customer credit, refund or QC decision is created.
6. Verifies the generated `production_return_cost_postings` record, original sale cost ID, batch, currency, package count, source cost amount, posting date and GL reference against the immutable independent finance approval. Stores the corresponding source/journal links and marks the batch recovery fully posted. Every failure rolls back the entire manufacturing, sale, non-sale expense and customer return cost sequence.

## Reconciliation and limitations

- Existing `ManufacturingFinancialReconciliationService` already deducts approved source-cost return recoveries from net COGS and restores finished inventory value and packages; new return allocations use the same established `production_return_cost_postings` records. No second cost subledger is invented.
- `FinanceCloseReadinessService` validates all new batch QC return allocation source/journal links at the manufacturing capitalization close, and independently in the original QC journal month, comparing original COGS amount, source currency and quantity, original QC date, posted/reversal status and exact Dr inventory / Cr COGS GL split.
- Existing pure all-invoiced and mixed-sale/non-sale proposals created before this additional return register remain supported when no customer credit/return sources have been added. New plans always specify and lock the return count, even if zero.
- Still blocked: original material/overhead FIFO uncertainties, unsupported original credit documents, out-of-period/closed historical dates, original QC data without a matching warehouse restock, previously separately costed returns, direct-restock legacy credit notes, quarantined or scrapped stock, returns without independently signed source evidence and externally recorded manual GL adjustments with no document-level proof.
- Returns interleaved with later non-sale physical dispatches may require a separate source allocation/rate reconstruction because the legacy non-sale cost posting service refuses already-restored customer batches in some cases. The whole transaction fails closed rather than silently alter original inventory costs.
- Multiple source documents with inconsistent historical movement chronology cannot be automatically reordered into a different accounting period. An approved period-opening/adjustment and audited source-document amendment workflow remain future work.

**No production-level guarantees are implied; runtime and financial regression checks have been deliberately deferred.**
