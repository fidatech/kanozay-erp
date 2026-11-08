# Kanozay ERP — Historical finance correction cases and independent COGS repair

Date: 2026-11-08  
Branch: `development/kanozay-completion`  
State: **Source development committed. No migrations, lint, automated tests, live UAT or deployment executed.**

## Why the workflow exists

Legacy physical dispatches, unreconciled finished inventory capitalization, unvalued FIFO opening stock, historic landed-cost allocations and partial supplier credits cannot be fixed by a generic balancing journal or by inventing an original source value. The old invoice COGS repair endpoint allowed a finance user to invoke the otherwise strict original-batch cost validation without a *separately recorded, independent review case*. These gaps are now tracked through source-backed correction cases.

## New historical correction case module

Created `finance_historical_correction_cases` and append-only `finance_historical_correction_events` with case ID, type, original record ID/reference and date, original currency ID where available, SHA-256 source-attribute fingerprint, evidence, requester, independent reviewer, approval/rejection decision and execution evidence.

Six types are supported:

1. Posted customer invoice missing original-currency COGS journals.
2. Released manufacturing batch without independently posted financial capitalization.
3. Historic finished-goods warehouse dispatch without a linked sales invoice.
4. Unvalued FIFO inventory lot requiring original opening, adjustment or GRN valuation evidence.
5. Posted GRN landed-cost component lacking original-currency inventory GL capitalization.
6. Supplier return with unresolved payable credit, landed cost or other historical return discrepancy.

The case service requires a real source record and stable source attributes. A database-unique `open_key` prevents duplicate unresolved cases for the same record and issue type. The original source fingerprint is checked again at review and at permitted execution. Approved source investigations remain unresolved until a supported correction method can actually be completed. Rejecting a case preserves its event history but allows a new properly evidenced request.

The requester cannot approve their own case. Reviews require an independent signed approval reference and evidence notes; submitting or approving a case **does not create an accounting journal, change physical stock or invent an original asset value**. If the source fingerprint changes, it cannot be approved. The independent reviewer may reject a stale submitted case, or the original requester may withdraw an unreviewed case; the reviewer who approved an unexecuted case may subsequently invalidate that approval. These actions preserve event history, free the source for a newly evidenced case, and create no stock or journal changes.

## Supported automatic correction: previously capitalized invoice missing COGS

The single automatic execution type is `invoice_missing_cogs`. Its case must be approved by a different authorized finance reviewer and executed by someone other than the requester.

Inside one database transaction, the executor:
- Locks the shared finance posting gate and requires the source month to be open.
- Revalidates the original invoice snapshot (customer, original currency, dates, amounts, revenue journal identity, physical dispatch and all original invoice lines).
- Requires the original sales revenue journal to remain posted and unreversed in the invoice currency, and checks the dated source revenue GL debit/credit totals plus linked customer receivable against the original posted invoice amount and customer.
- Invokes the pre-existing `FinishedGoodsCostPostingService::reconcilePostedInvoice`, which allows COGS only against an existing, approved original-cost-currency batch capitalization, original delivery date, original physical dispatch and permitted chronological cost-journal sequence.
- Checks **every** original invoice line for an unreversed posted original-currency COGS journal with the matching batch and number of dispatched packages.
- Verifies the recognized cost expense debit and the finished-stock asset credit match each invoice-line cost posting exactly. Cross-currency conversion is never assumed.
- Only after all verification passes: marks the case resolved, clears its unique open-key, records the newly posted cost-journal count and appends a signed resolution event. Any failure rolls back the entire correction transaction.

The previous direct invoice `/reconcile-cost` action is intentionally **nonposting** and redirects finance to the historical correction center. The invoice page now links to that center and explains the independent reviewer requirement. This closes the old approval bypass.

## Operational integration

- Created the Historical Financial Correction Center, accessible from Reports and Analytics and the accounting period-close and manufacturing reconciliation screens.
- Added a filtered case register, an explicit original-source submission form, detailed case and audit-event views, independent finance approval/rejection controls and a dedicated missing-COGS executor.
- The queue surfaces **up to 20 recent candidate identifiers per class**, including missing invoice COGS, uncapitalized finished batches, warehouse-only dispatches, unvalued FIFO layers, unposted landed costs and supplier returns awaiting financial reconciliation. These samples are not presented as a complete accounting audit.
- Month-close readiness blocks closing a month that has submitted or approved correction cases associated with original source documents dated within that month. This check supplements existing GL source and inventory warnings.
- Reports navigation title translated into Afghan Dari and Pashto. Transactional historical-correction forms remain English pending full UI localization.

## Still outstanding development

- **Historical uncapitalized, already-dispatched production:** requires verified opening inventory source, WIP/finished asset postings and matched COGS timing. A standalone backdated journal would create unsupported inventory balances.
- **Unvalued stock and opening balances:** original documentation, physical location, FIFO cost source and the signed opening asset journal must reconcile before source-cost attribution can be introduced.
- **Legacy supplier returns:** posted physical quantities with partially reversed payable or uncaptured freight/cost require individually evidenced expense, receivable or asset corrections. They are not automatically priced by this case workflow.
- **Recoverable landed freight/customs:** requires independent claims and settlement documents distinct from the current nonrecoverable supplier-return landed-cost expense.
- **Previous historical entries:** corrections to closed periods need independent reopening and a reviewed posting; this module does not automatically reopen months.
- **Safety/performance:** original-currency cost, source FIFO amounts, double-posting, warehouse provenance, permission boundaries and transaction concurrency still require actual staged source-data verification and tests before release.
- **Localization and deployment:** complete Pashto/Dari accounting forms, print/export translation, MySQL migration review, automated tests, UAT and ZIP delivery remain.

## Verification boundary

At the user's explicit request, tests, migrations, source syntax lint and deployment were **not** run. Commits are source development only, not evidence that the new schema exists in a running ERP or that the financial controls are production verified.
