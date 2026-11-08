# Kanozay ERP — Cross-module finished-goods cost reconciliation and language update

Date: 2026-11-08  
Branch: `development/kanozay-completion`  
Status: **Committed development source only. No migrations, tests, UAT or deployment.**

## Costing and GL integration boundary

### Source-linked dispatch cost exposure
- Added a permission-protected **Finished-Goods Dispatch Cost Reconciliation** page under Reports, with dispatch date filters, original-currency source cost grouping, a 30-line reviewed register and a full matching-source CSV export.
- Each dispatched quantity is associated with its original production batch, original quantity of QC-accepted packages, frozen FIFO material cost sources and independently approved overhead expense allocations.
- A source estimate is only displayed for a released batch with an accepted package quantity, fully valued FIFO consumption, one known original cost currency, no pending overhead, and a source expense evidenced as approved and posted by the dispatch date.
- Unknown/mixed-currency inputs, absent historical source lots, unvalued consumption, overhead approved after dispatch, expense journal reversals and missing approval evidence are shown as explicit blockers.
- Estimates are proportional to the original accepted batch packages. They are **gross original dispatch cost exposure**, not net COGS, sold-package profit, a per-customer margin, or a financial inventory journal.
- The report shows the exact original decimal source amounts. It does not convert currencies, invent cost of return, infer saleable condition or deduct customer credit notes from cost balances.
- The register and CSV can help finance reconcile missing WIP / finished inventory capitalization, but no COGS postings are created by the report.

### Safeguards before source cost changes
- Prevented approval of new batch overhead after a batch is rejected or any finished package has already been dispatched.
- Prevented new overhead submissions to already dispatched batches.
- Blocked dispatch when overhead for that batch is still pending independent review, preserving the previously reviewed source cost per accepted package.
- Added a warning to financial month-close readiness when any finished-goods dispatch lines occur in the month. This warning does not falsely assert every dispatch is unposted COGS. It requires a separate finance reconciliation of source cost, WIP, finished-goods inventory and COGS.
- These safeguards extend existing strict FIFO lot provenance and QC lot holds already implemented in the inventory and finished-goods release modules.

## Language coverage
- Added a Pashto/Dari title for the finished-goods dispatch cost review in the existing ERP menu.
- Added translations for the new dispatch cost screen's filters, report headings, source valuation warnings and state labels. The app retains English fallback for terms that are not translated.
- Financial values, original currency codes, product names, proof references and business-source notes are retained unchanged.

## Major remaining accounting dependencies

A production snapshot is **not itself a capitalized finished-goods inventory GL posting**. Safe COGS posting requires:

1. Confirm source purchase/GRN postings on inventory asset ledgers by original currency, including direct costs and landed costs, before raw-material consumption.
2. Post each verified raw-material FIFO issue as an appropriate warehouse inventory credit / WIP debit with the exact original source-cost layers, without double-posting purchase expense and WIP transfers.
3. Reconcile source-backed production labor and overhead expense, approved allocation, and their authorized reclassification to WIP; avoid claiming both source expense and asset capitalization as separate incremental costs.
4. Post QC-accepted finished goods from WIP to the appropriate finished-stock asset ledgers at a locked, complete approved cost, preserving original currencies and identifying rejected/wasted output.
5. Post a matched finished-goods asset credit and COGS debit on original stock dispatch; returned products need separate source-cost and QC review, not an automatic reversal of customer invoice cost.
6. Review GL accounts, fiscal rules, opening stock and signed source documents. An unsupported single-currency conversion or invented cost would distort financial statements.
7. Complete staged MySQL/SQLite tests, migrations, posted journal identity checks, batch-to-ledger reconciliation, credit returns and real-world UAT before production release.

The above steps are documented remaining development, **not completed financial postings**. This batch deliberately does not fabricate missing ledger entries or mark gross dispatch source cost as COGS.

## Verification

At user direction, source changes were committed on the development branch only. Automated tests, migrations, syntax lint, running-browser inspection, MySQL validation and deployment were not carried out. Production acceptance and final ZIP handoff remain outstanding.
