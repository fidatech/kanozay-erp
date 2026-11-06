# Operational report registers

The Reports & Analytics workspace now connects ten operational registers to real application records:

- Production orders; stock as currently held; purchases; sales invoices; laboratory checks
- Journal register; payroll runs; frozen raw-material batch costs; recorded GRN landed-cost items; batch release/dispatch trace

All ten support permission checks, pagination, context-appropriate search, date ranges where meaningful, source-record links and a filtered CSV export. Blank registers show no fabricated data.

Three additional report navigation entries delegate to the existing posted-journal-based Profit and Loss, Balance Sheet and Cash/Bank/Sarafi movement reports, including their original filters and CSV exports.

**Financial boundaries:** All amounts stay in their recorded currency. The invoice register is not a cash receipt, the purchase register is not a supplier payable balance, the GRN landed-cost register is not a posted expense and batch raw-material cost is not full production costing. Unvalued materials remain unvalued. Cash/bank/sarafi movements are not categorized as a full cash-flow statement.

**Traceability boundaries:** The batch register links production order, finished-good SKU, QC status, accepted/rejected package counts and gross dispatched quantity. It does not yet identify the supplier source lot of every consumed raw material. Customer returns are not deducted from the gross dispatch figure.

**Inventory boundaries:** The stock register uses the existing company-wide on-hand and reservation ledger. Warehouse-specific tracking and historical stock-by-date are not supported by that ledger, so the snapshot deliberately has no date filter.

CSV exports prefix text fields that could be interpreted as spreadsheet formulas with an apostrophe; do not open exports in software that changes financial decimal precision. For an auditable currency-specific general ledger, use the Finance module's existing journal and trial-balance screens.

These pages improve operational visibility. They do not establish readiness for production use, receipt printing acceptance, payroll tax rules, approved historical imports or company-specific QA signoff.
