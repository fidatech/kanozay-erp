# Kanozay ERP — Mixed historical sales and non-sale batch cost recovery

Date: 2026-11-09
Branch: `development/kanozay-completion`
Status: **Development source committed. No database migrations, PHP syntax lint, automated tests, browser UAT, MySQL checks or deployment have been run.**

## Problem solved in source code

A released historical production batch may have been physically dispatched for more than one business purpose **before** its original manufacturing WIP and finished-inventory cost journals were posted. Some physical dispatches are genuine customer sales with already posted revenue/invoices. Others are authorized samples, damaged goods or internal consumption and must be recognized as expenses. The recovery must never treat samples or losses as fictitious sales, expense an actual invoice as a sample or consume the same batch value twice.

This implementation extends the independent historical `batch_uncapitalized` correction path to allow any combination of the following **fully documented** dispatch lines:

- **Customer sale** — exactly one genuine posted original-date customer invoice, original customer receivable and revenue GL; original batch, finished good, dispatch source and package quantities must match.
- **Promotional sample** — original `standalone_dispatch` case independently approved and a signed `finance_historical_dispatch_dispositions` classification of `promotional_sample`, with source documents and a separately approved sampling expense ledger.
- **Production loss** — same independent source case/classification for `production_loss`, source damage/loss documentation and an authorized inventory-loss expense ledger.
- **Internal consumption** — same independent source case/classification for `internal_consumption`, signed source-use documents and an authorized internal-use expense ledger.

A physical dispatch with no genuine invoice and no independently signed non-sale classification **cannot** be posted. Customer sales and unresolved purposes are excluded from non-sale expense recognition.

## Safeguards and accounting order

1. Each batch must be QC-released, have a single verified source currency, fully valued frozen FIFO materials, independent overhead and abnormal-waste reviews, valid raw-stock supplier/opening source journals, approved manufacturing GL ledger policy, a single QC receipt and no earlier manufacturing capitalization.
2. Every physical dispatch in that batch must be individually source-matched. The workflow blocks unknown purposes, duplicate physical/financial cost links, unmatched invoice lines, customer credit notes, QC return/restock events, incomplete source data and ambiguous shared dispatch lines.
3. Every original-date manufacturing posting and each sale/non-sale dispatch date must be in an **open accounting period**. The manufacturing source posting must be after the source QC receipt, supplier/overhead and FIFO material issues, but on or before the earliest physical dispatch. Future dates are rejected. The workflow cannot secretly post to a different period or assume a historical FX conversion.
4. A historical case requester, separate case reviewer, separate financial proposer, separate financial approver and separate final executor are required. Non-sale dispatches additionally have their own source requester and signed classification reviewer, who cannot perform the batch financial proposal, approval or execution.
5. The new `finance_historical_batch_dispatch_allocations` register records **every immutable original physical line**, genuine sales invoice OR signed non-sale disposition, purpose, original currency, accepted package quantity, independently source-priced 4-decimal allocated cost and explicit non-sale expense ledger. Unique line and invoice constraints prevent duplicate allocations.
6. At review, the signed batch and disposition fingerprints, original invoice/dispatch links, assigned expense accounts, source value and all line allocations must still match the initial proposal. Source cost and expense-account changes require an audited new review, not an in-place rewrite.
7. The final executor holds the shared financial-period posting gate. In **one DB transaction**, the existing strict `ManufacturingCostAccountingService` posts the original-currency raw-stock/WIP and finished-inventory source capitalization and the executor processes every physical dispatch in original date order. Customer invoices receive the missing COGS debit / finished-asset credit. Independently classified non-sale dispatches receive a debit to the independently selected purpose-specific expense / credit to the same original-currency finished inventory asset. If any amount, policy, ledger, date, stock quantity, asset balance or source proof fails, **every new journal and settlement in the batch rolls back**.
8. Each non-sale posting is written to the existing `finance_historical_dispatch_cost_settlements` and `finance_historical_dispatch_cost_lines` tables, so subsequent customer COGS, non-sale cost accounting and month-close readiness all reference the SAME original cost subledger. Original signed standalone cases are resolved with append-only events; original physical movements, stock quantities, customer invoices, sales revenue and receivables never change.
9. The manufacturing financial reconciliation now compares original physical dispatch packages with **sales COGS + non-sale expense packages**, net of independently approved customer QC return recoveries. It reports non-sale expenses in the finance screen and all-lines CSV separately from customer COGS and flags mismatched asset/expense journals.
10. Financial month-close checks verify every fully posted mixed-source batch line, genuine original sale link, signed expense settlement, original-currency journal, purpose, package quantity and authorized cost amount.

## Existing paths preserved

A previously created **all-invoiced** historical batch proposal without a mixed allocation register retains the original approved source fingerprint and pure invoice-Cogs backfill logic. New proposals use the full per-line allocation register, whether fully invoiced, fully signed non-sale or mixed.

Ordinary current manufacturing capitalization still forbids capitalization after dispatch without this independently approved, atomic historical cost recovery. Standalone non-sale expense accounting still requires a previously posted original batch capitalization. Generic GL reversal is blocked for the underlying WIP, capitalization, COGS and non-sale expense journals.

## Remaining work and restrictions

- Missing supplier GRN expense/asset documents, unknown FIFO unit costs, unsupported currencies, unreviewed overhead or QC evidence must be reconciled independently before batch posting. Financial approval cannot replace missing source records.
- Historical customer returns, credit notes and already costed lines require separately reviewed reverse-COGS or cost-restoration accounting and remain blocked.
- Historical periods that have already closed require a formally approved reopening or an accountant-approved prior-period correction framework. They are **not automatically reopened**.
- Rejected proposals remain uniquely archived; a formal audited amendment/resubmission workflow is outstanding.
- Accounting-period concurrency, tax impacts, report translations, SQLite/MySQL migration behavior, exact journal amounts and all edge cases must be verified in tests and UAT before any production accounting use. User-directed test/deployment deferral remains in effect.

**These are source-code changes, not runtime or production acceptance.**
