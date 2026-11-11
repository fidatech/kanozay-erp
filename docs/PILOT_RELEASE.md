# Kanozay ERP — pilot release scope

Date: 2026-11-11 · Branch `development/kanozay-completion`

This release is suitable for a **supervised pilot**: Kanozay staff use it on a dedicated
server, with Fida Technologies support, while it runs alongside their current records.
It is **not** yet a final production release. The reasons are listed under "Before final sign-off".

## What is ready to use in the pilot

Each item below has automated tests that pass on MySQL and SQLite.

| Area | Ready |
|---|---|
| Access | Sign-in and sign-out, users, roles, permission checks on every page and form, sign-in audit log |
| Finance | Chart of accounts, journals with approval and reversal, general ledger, trial balance, balance sheet, profit and loss, financial period closing |
| Cash and bank | Cash, bank and Sarafi accounts, receipts, payments, transfers, currency exchange, bank statement import and reconciliation |
| Customers and suppliers | Accounts, statements, invoices and payables, settlements, customer deposits, supplier prepayments |
| Purchasing | Requests, purchase orders, goods receipts (partial and multiple), landed costs with shipment link, supplier invoice matching, purchase returns, debit notes |
| Inventory | Stock ledger, FIFO costing, warehouses and transfers, adjustments, stock counts, quality holds, valuation report, unit conversions |
| Production | Recipes with approval and versions, production orders, material reservation and issue, waste, batches, batch costing to finished goods |
| Quality | Laboratory tests, batch approval, quarantine and release |
| Sales | Quotations, orders, invoices, dispatch, delivery notes, credit limits, customer returns and refunds |
| HR and payroll | Employees, departments, attendance, leave, salary advances, payroll with approval and payment, payslips, tax policies and remittance, employee exit |
| Assets and owners | Fixed asset register, depreciation, disposal; shareholder contributions and withdrawals |

## Switched off in this release

Legacy accounting recovery postings (historical batch capitalization, legacy FIFO cost
recovery, historical dispatch cost, supplier short-credit, historical customer-return cost)
are disabled by `ERP_LEGACY_RECOVERY_POSTINGS=false`. They repair records made before the
current controls existed, a new installation has none, and they have no acceptance tests.
Do not enable them without dedicated testing.

## Known limits during the pilot

- **Language:** screens are in English. Menus translate to Dari and Pashto; forms, messages and reports do not yet.
- **PDF:** documents print through the browser's Print → Save as PDF. There is no server-side PDF export.
- **Document numbers:** purchase orders, goods receipts, production orders and some other documents take their number from an internal counter, not from the configurable numbering screen. Numbers are unique but may skip.
- **Reversals:** a posted payment or an approved payroll cannot yet be reversed from the screen. Correct with a journal entry approved by finance.
- **Shipments:** a freight or customs charge is entered per goods receipt; one charge cannot be split across several receipts automatically.
- **Units:** conversion applies when linking recipe requirements to stock. Purchase and sales lines use the stock unit.
- **Invoice layout:** not approved by Kanozay; the sample invoice was never supplied.
- **Not checked by anyone in a browser yet:** layout on phones, printing on A4, right-to-left display.

## Rules for the pilot

1. Use a dedicated pilot database. Do not load opening balances or real ledgers until the
   client decisions below are settled.
2. Keep the current books in parallel and compare results weekly.
3. Take a database backup every day (see `docs/INSTALLATION.md`).
4. Report every wrong figure with the document number; do not correct it by editing the database.

## Decisions needed from Kanozay

- Corrected formulas for the two recipes whose source workbook totals do not add up
- Approved chart of accounts and opening balances
- Classification and currency of owner investments; ownership percentages
- Salary currency, payroll tax and transport deduction rules
- Depreciation method, useful lives and residual values
- Sales tax rules and the invoice layout
- Default exchange-rate rule

## Before final sign-off

1. A person completes `docs/uat-checklist.md` in a browser on the pilot server, including printing and Dari/Pashto display.
2. The client decisions above are entered and reviewed.
3. Payment and payroll reversal are built and tested.
4. Dari and Pashto translations are completed and reviewed by a native speaker.
5. One full month is closed in the pilot and agrees with the parallel books.

Detailed evidence per module: `docs/COMPLETE_IMPLEMENTATION_TRACKER.md`.
