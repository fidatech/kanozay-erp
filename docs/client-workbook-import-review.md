# Kanozay client workbook import review

This document records the source structure checked on 2 November 2026. It does not copy client transaction rows or employee personal information into the repository.

## Raw materials and owner investment workbook

`Raw Materials in Factory and Owner Investment.xls` has two worksheets: `Raw Materials Stock In Factory` and `Owner Investment`.

The raw-stock worksheet separates in-factory materials into labeled sections, including flavors, dried ingredients, preservatives, sweeteners, liquid and powder colors, vitamins, cans/bottles/caps, solvents, and `Materials ON Way or Ordered`. The import preview imports only numbered in-factory stock rows. It classifies the cans/bottles/caps section as packaging and excludes the on-way/ordered section. The source has quantities and USD price columns; an active USD currency and an opening-stock date are required to confirm import. Duplicate material/unit pairs, existing catalog matches, missing values, and unsupported decimal precision block confirmation.

The separate owner-investment worksheet is not imported by the raw-stock flow. Its rows need review and an approved split between contributions, withdrawals, operating expenses, and other movements before posting to shareholder or general-ledger accounts.

## Accounts workbook

`Accounts for Database.xls` contains 16 separate ledgers: three warehouse books, three currency expense books, AFN and USD receivables, daily production batches, two sarafi books, an asset book, AFN and USD payables, and two bank books. It does not provide a single unambiguous chart-of-accounts table.

Do not load these historical rows directly into posted journals. Before importing, confirm the target ERP account for each book, the source currency for each amount and balance, how party names match registered customers and suppliers, the date cut-off/opening balance treatment, and which rows represent inventory receipts versus adjustments. Reconcile sample totals with the client's ledger owner first.

## Production recipes workbook

The production workbook contains two formula sheets. The liquid recipe calculates a water quantity of approximately **-12.7004 kg**. The powder formula's listed ingredient quantities sum to **25.11 kg** for a stated 25 kg package. These source discrepancies remain unapproved; recipe preview warnings block import confirmation. Do not silently adjust either formula.

## Payroll workbook

The payroll workbook includes employee identifiers, contact details, job data, and monthly salary. It does not identify salary currency. Its tax/transport column is excluded from import, including any spreadsheet formula. Select a salary currency explicitly and approve payroll deduction policy before processing payroll.

## Missing acceptance references

The current repository includes a Kanozay logo asset and the client letterhead PDF is available. The sample customer invoice and `01-Sample.xlsx` have not been located in the accessible project files, so invoice layout matching and their data mapping cannot be verified yet.
