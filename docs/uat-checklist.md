# Kanozay ERP UAT checklist

**Branch:** `development/kanozay-completion`  
**Readiness:** NO-GO for full employee acceptance testing. Run only on an isolated UAT database with synthetic records.

## Before the session

- [ ] Create a new isolated database. Never point UAT at production.
- [ ] Install Composer/npm dependencies, configure a private `APP_KEY`, build assets, run migrations, and seed reference roles/permissions as described in [handoff.md](handoff.md).
- [ ] Create the first administrator with `php artisan kanozay:admin`.
- [ ] Create named synthetic test users, or on an isolated local UAT database run `php artisan kanozay:uat-users --confirm-isolated`. Verify each assigned role and allowed/denied actions in Administration → Roles before the test session.
- [ ] Use synthetic names and email addresses only. The UAT command generates unique random passwords and prints them once; do not add credentials to Git, logs or this checklist.
- [ ] Record the starting database backup and confirm that it can be restored into a separate disposable database.
- [ ] Do not import source customer, supplier, employee, account, raw-stock, or investment rows during this session. Use synthetic fixtures only.
- [ ] Record the current commit, test run, browser/device, user role, and observed result for each case below.

## Access and setup

| ID | Scenario | Expected result | Status |
|---|---|---|---|
| UAT-AUTH-01 | Open a protected page without signing in | Redirect to sign-in | Ready to exercise |
| UAT-AUTH-02 | Sign in as the initial administrator | Dashboard opens and the user can reach authorized administration pages | Ready to exercise |
| UAT-AUTH-03 | Sign in as Finance Manager, Inventory Officer, and Viewer | Each can open its scoped pages; write actions require the matching create/update grants; unrelated pages/actions are denied | Ready to exercise; broader controller coverage remains partial |
| UAT-SET-01 | Switch English, Dari, and Pashto | Page language changes; record direction/layout defects | Partial; RTL and full translations remain unverified |

## Purchasing and raw materials

The default database seeder does not seed the reference chart of accounts. Customer/supplier creation with currencies provisions ledger accounts and therefore requires an approved chart mapping or a clearly marked synthetic chart in the isolated UAT database. Do not represent the reference chart as the client's confirmed account workbook.

| ID | Scenario | Expected result | Status |
|---|---|---|---|
| UAT-PUR-01 | Create a synthetic purchase order with a synthetic supplier | Order and line totals are saved and visible; a view-only role cannot create it | Ready to exercise; supplier ledger provisioning requires an approved chart of accounts |
| UAT-PUR-02 | Receive part of the open order | GRN records only received quantities; outstanding quantity remains | Ready to exercise |
| UAT-PUR-03 | Try receiving above the open quantity or submit the same receipt twice | Validation rejects the excess/duplicate and stock is unchanged | Ready to exercise |
| UAT-PUR-04 | Add freight/customs/handling landed-cost items | Allocation totals reconcile to entered costs; review per-item unit costs | Ready to exercise |
| UAT-INV-01 | Record a synthetic stock adjustment with a reason | Immutable ledger movement and current balance agree | Ready to exercise |
| UAT-INV-02 | Reduce stock below reservations or below zero | Request is rejected and the prior balance is unchanged | Ready to exercise |
| UAT-INV-03 | Preview the client raw-stock workbook, including packaging and on-order sections | Duplicate/invalid rows block confirmation; ordered stock remains out of on-hand balance; confirm only reviewed in-factory rows | Ready for synthetic exercise; client opening date and cost reconciliation pending |

## Manufacturing and laboratory

| ID | Scenario | Expected result | Status |
|---|---|---|---|
| UAT-MFG-01 | Preview the supplied recipe workbook | Errors, warnings, duplicate formulas, and weight discrepancies appear before import | Ready to exercise with review copy |
| UAT-MFG-02 | Inspect liquid and powder source discrepancies | Liquid water is `-12.7004 kg`; powder named ingredients total about `25.11 kg` against a `25 kg` target; do not approve either formula without written client confirmation | Blocked by client decision |
| UAT-MFG-03 | Create a production plan from an approved recipe | The order snapshots the approved formula/version and shows requirements/shortages | Ready after a client-approved recipe exists |
| UAT-MFG-04 | Reserve and issue materials, then record a batch | Stock ledger reflects reservations and consumption; duplicate posting is prevented | Ready to exercise |
| UAT-QC-01 | Record passing and failing lab checks | A failed/unreleased batch remains unavailable for sale; release requires approval | Ready to exercise |
| UAT-FG-01 | Review production receipt, released quantity, invoice dispatch and customer return | Batch history and available packages reconcile; failed/unreleased stock cannot dispatch | Ready for synthetic exercise; transfer and valuation remain partial |

## Finance and reports

Use one currency per test and verify its journal lines. Never add amounts in different currencies.

| ID | Scenario | Expected result | Status |
|---|---|---|---|
| UAT-FIN-01 | Post a balanced journal, then view the general ledger | Posted lines appear; draft lines do not; decimal running balance is exact | Ready to exercise |
| UAT-FIN-02 | View the trial balance at a selected date | Only posted lines through that date appear; debit/credit totals reconcile separately by currency | Ready to exercise |
| UAT-FIN-03 | View Profit and Loss for a date range | Income/expense totals and net result agree with underlying posted journal lines per currency | Ready to exercise |
| UAT-FIN-04 | View Balance Sheet at a selected date | Fiscal-year start follows financial settings; difference is visible and must be resolved before acceptance | Ready to exercise with reconciliation |
| UAT-FIN-05 | View cash/bank/sarafi movements | Opening + posted inflows - posted outflows = closing balance for each account/currency | Ready to exercise |
| UAT-FIN-06 | Export the three statements and movement report to CSV | Filtered records and decimal values match the on-screen report | Ready to exercise |
| UAT-FIN-07 | Reconcile the supplied 16-sheet accounts workbook | Map each sheet to accounts, dates, currencies, parties, and source documents; approve opening balances before import | Blocked by mapping/approval |

## HR and other blocked workflows

| ID | Scenario | Expected result | Status |
|---|---|---|---|
| UAT-HR-01 | Preview a synthetic payroll-format workbook | Errors/duplicates are shown; salary currency must be selected; no source rows are written until confirmation | Ready to exercise with synthetic file |
| UAT-HR-02 | Create a synthetic monthly payroll draft, adjust an amount with a reason, approve, pay and print a payslip | Salary snapshot is retained; accrual and payment journals balance; repeat payment does not duplicate; payslip matches line and payment | Ready for synthetic exercise; no approved tax/attendance calculation |
| UAT-HR-03 | Reconcile attendance, leave, advances and deductions against client policy | Approved rules produce the agreed net salary and employee statement | Blocked by approved deduction policy and payroll reconciliation; attendance, leave and direct advances have partial workflows |
| UAT-SALES-01 | Create quotation/order/invoice, dispatch stock, receive payment, print invoice | Reconcile stock, receivable, payment and journal; compare printed document to the client sample | Ready for synthetic flow; original invoice sample and visual print approval missing |
| UAT-SH-01 | Record synthetic shareholder contributions/withdrawals and reconcile investment statement | Journal and shareholder account reconcile in the selected currency | Partial workflow; source owner-investment mapping and business rules unapproved |
| UAT-ASSET-01 | Register a synthetic asset with source reference, assign and return it | Unique tag, cost currency and custodian history remain consistent; no journal is fabricated | Ready for synthetic register exercise |
| UAT-ASSET-02 | Run approved depreciation and disposal | Reconcile schedule, net value, proceeds and journals against approved policy | Blocked by policy and unfinished depreciation/disposal workflows |
| UAT-DASH-01 | View dashboard as each test role and change date range | Counts reflect accessible records only; no revenue/profit/inventory value appears without supporting workflows | Ready to exercise |

## Record a result

For each case, record: tester, date/time, role, database snapshot ID, app commit, steps, expected result, actual result, pass/fail/blocker, and a redacted screenshot or reference number. Never include passwords, real employee identifiers, or client workbook rows in screenshots or Git.

A failed check against real business rules is a client decision or defect, not an instruction to alter source data. Preserve the UAT database before repair and use the documented backup/restore procedure.
