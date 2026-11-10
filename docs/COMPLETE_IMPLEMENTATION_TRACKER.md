# Kanozay ERP — Complete implementation tracker

Branch: `development/kanozay-completion`
Audit date: 2026-11-10 (UTC)
Release decision: **BLOCKED FROM UAT** (see [Release decision](#release-decision))

This tracker records what the repository and GitHub Actions actually demonstrate. A module is
`COMPLETE` only when it is implemented **and** verified. Controllers, routes or tables on their
own do not count.

Status legend: `COMPLETE` · `PARTIAL` · `BLOCKED` (external decision needed) · `NOT STARTED` · `FAILED`.

## How this audit was verified

- All execution evidence comes from GitHub Actions (PHP 8.4, Node 22, SQLite and MySQL 8.4).
  The audit workspace could not install Composer packages (Packagist is not reachable from it),
  so nothing was executed locally apart from `php -l` syntax checks.
- No browser automation, print/PDF check, staging deployment or human UAT was performed.
- No client workbook was opened or imported; none is in the repository.

### State found at the start of the audit (commit `407b744`)

- The last ten CI runs on the branch had all failed. The suite had **33 failing tests**.
- Large parts of the code from 8–10 November were committed without ever being run
  (stated in `docs/remaining-development-work-checklist.md`).
- The MySQL test suite had never been run; CI only attempted a MySQL migration, and that step was
  never reached because the test step failed first.

### Defects found and fixed in this pass

| # | Defect | Effect | Fix |
|---|---|---|---|
| 1 | `@for($i=0;$i<1;$i)` in `hr/payroll/tax-policies.blade.php` never incremented | The payroll tax policy page consumed all memory for any user allowed to create policies; it also killed the CI runner | Loop corrected, regression assertion added |
| 2 | Eloquent wrote `date` casts as `Y-m-d 00:00:00` on SQLite, `Y-m-d` on MySQL | On SQLite, same-day landed-cost capitalization and QC return recovery were rejected as "predating" their own source document | `StoresDateOnlyColumns` trait on the 33 models with date casts; `DateOnlyStorageTest` |
| 3 | Eleven generated index/foreign-key names longer than 64 characters | Clean MySQL installation stopped at migration `2026_11_08_001500` (error 1059). The application could not be installed on its intended production database | Explicit short names in three migrations |
| 4 | Stock count placeholder number (40 chars) longer than its 32-char column | Creating a physical stock count failed on MySQL (error 1406) | Shorter unique placeholder |
| 5 | Quick party payment reported a wrong-currency account error on a field the form did not submit | Inline validation pointed at the wrong input | Error key follows the submitted field |
| 6 | 28 tests were stale against hardened services (segregation of duties, source reconciliation, capitalization, submission tokens) | Suite could not act as a regression gate | Tests now drive the real approval paths and each gained a negative case; no assertion was removed without an equal or stricter replacement |

### Current CI evidence

See the "Verification log" at the end of this file for the run that verified the commit containing
this tracker. The numbers there are copied from the run output.

## Functional modules

Evidence column: feature tests named are in `tests/Feature` and pass on SQLite and MySQL unless
stated otherwise.

| ID | Module | Status | Verified evidence | Outstanding (development unless marked CLIENT) |
|---|---|---|---|---|
| M01 | Authentication, users, permissions | PARTIAL | `Auth/*`, `AdminAccess`, `AdministrationAccess`, `ModuleRoleSeeder`, `CreateUatUsers`, `Profile`; public registration is disabled and tested | No `app/Policies`: authorization is inline permission checks per controller and has not been audited route by route. No login/failed-login audit listener found. No per-role allowed/denied matrix test (scenario I). Protection of the main administrator account not verified |
| M02 | Company configuration | PARTIAL | `CompanySetting`, `FinancialConfiguration` | About 20 document types (PO, GRN, production order, batch, delivery note, supplier invoice, debit note, purchase return, stock count, QC sample, NCR and others) derive their number from the row id and ignore the configurable `document_sequences`; prefixes there (`PROD-`) even differ from the code (`PRD-`). Letterhead/print preferences not verified |
| M03 | Currencies and exchange rates | PARTIAL | `CurrencyConfiguration`, `CurrencyExchange`, `GoodsReceiptFxSnapshot`; directional pair table with effective dates exists | Exchange-difference reporting and translated-vs-source balance presentation not verified. Buy/sell average rule: CLIENT confirmation |
| M04 | Chart of accounts and general ledger | PARTIAL | `LedgerAccount`, `JournalEntry`, `GeneralLedger`, `TrialBalance`, `BalanceSheet`, `ProfitAndLoss`, `FinancePeriodClosureWorkflow`, `ChartOfAccountsWorkbook` | Reconciliation invariants 4–12 of the master specification have no dedicated automated tests. Reference chart is not a client-approved mapping (CLIENT) |
| M05 | Customers and suppliers | PARTIAL | `TradeParty`, `TradePartyStatementPeriod`, `TradeDocument`, `TradeSettlement`, `UnifiedAccountDirectory` | WhatsApp balance message and PDF/Excel export not verified. UI review pending |
| M06 | Procurement and purchase orders | PARTIAL | `ProcurementRequestWorkflow`, `PurchaseOrder`, `PurchaseOrderInventoryLink` | PO amendment and cancellation flows not verified by tests. Supplier quotations are a generic register |
| M07 | Imports, shipments, landed costs | PARTIAL | `GoodsReceiptLandedCost`, `LandedCostAllocator` (unit) cover allocation, capitalization, replay and segregation of duties | **Shipments are a generic configurable register** (`config/operational_registers.php`) with no link to charges, landed-cost allocation or inventory value. Landed-cost revision flow untested |
| M08 | GRN, purchase returns, supplier claims | PARTIAL | `GoodsReceipt`, `GoodsReceiptCostSnapshot`, `MatchedSupplierInvoice`, `PurchaseReturnWorkflow`, `SupplierDebitNote` | Damage/shortage claim scenario D not covered end to end. `SupplierShortCreditSettlementService` has no tests |
| M09 | Warehouses and inventory | PARTIAL | `InventoryStockLedger`, `InventoryStockAdjustment`, `InventoryMovementHistory`, `PhysicalStockCount`, `WarehouseStockService`, `WarehousePostingIntegration`, `InventoryValuationReport`, `QualityMaterialHold` | Reservations are company-wide, not per warehouse (transfers are blocked while a reservation exists). Storage locations are a generic register. No concurrency test for simultaneous consumption. Expired/damaged stock handling not verified |
| M10 | Products, raw materials, units | PARTIAL | `InventoryMaterialPage`, `ApprovedProductCatalog` | **Unit conversion is NOT STARTED** (no conversion code or table). Units and categories are generic registers not referenced by materials or products. Barcode, purchase/stock/production/sales units not verified |
| M11 | Recipes and formulations | PARTIAL | `ProductionRecipe`, `ProductionRecipeWorkflow`, `ProductionRecipeApproval`, `ProductionRecipeImportPreview`, `ProductionOrderRecipeSnapshot` | CLIENT: source workbook has a negative water quantity and a formula total above its package target; these recipes must stay unapproved until Kanozay confirms corrected formulas |
| M12 | Production planning | PARTIAL | `ProductionOrderPlanning`, `ProductionMaterialAvailability` | Production plan/schedule pages route to operational controllers but have no dedicated tests. Shortage report not verified |
| M13 | Manufacturing and production costing | PARTIAL | `ProductionMaterialIssue`, `ProductionMaterialWaste`, `ProductionCostReadiness`, `ManufacturingOperations` | `ManufacturingCostAccountingService::capitalize()` is **not** exercised end to end: the sales tests use a fixture that inserts the cost posting directly (documented in `tests/Support/CapitalizesProductionBatches.php`). Scenario B is therefore not proven. Labour/overhead allocation untested |
| M14 | Laboratory and quality control | PARTIAL | `LaboratoryQualityWorkflow`, `ProductionBatchQc`, `QualityMaterialHold` | Retest, rework and recall closed-loop not verified. NCR blocking rule has no dedicated test |
| M15 | Finished goods | PARTIAL | `FinishedGoodDispatch`, `ProductionBatchQc` | Expiry handling, damaged quantities and FG-to-GL reconciliation not verified |
| M16 | Sales and quotations | PARTIAL | `SalesQuotationWorkflow`, `SalesOrderWorkflow`, `CustomerCreditPolicy` | Tax rules: CLIENT. Partial fulfilment edge cases not verified |
| M17 | Invoicing, dispatch, customer returns | PARTIAL | `SalesInvoiceWorkflow`, `SalesPaidRefundWorkflow`, `SalesDeliveryNote` | CLIENT: invoice sample `01-Sample.xlsx` was never available, so invoice layout/terminology is unapproved. A4 print/PDF not checked in a browser |
| M18 | Customer and supplier payments | PARTIAL | `QuickPartyTransaction`, `UnifiedQuickAccountTransaction`, `TradeSettlement`, `MoneyTransaction` | `CustomerAdvanceService` and `SupplierAdvanceService` have no feature tests. Payment reversal not verified |
| M19 | Cash, banks, Sarafi | PARTIAL | `FinancialAccount`, `MoneyTransaction`, `CurrencyExchange`, `CashMovementReport` | **Bank reconciliation (`BankReconciliationService`, controller, CSV import) has no automated test at all.** Statement re-import duplicate protection is unproven |
| M20 | Shareholders and investment | PARTIAL | `ShareholderAccounting`, `ShareholderGovernance` | CLIENT: classification and currency treatment of the owner-investment workbook; ownership percentages. Distribution allocation service untested |
| M21 | Human resources | PARTIAL | `EmployeeProfile`, `EmployeeDepartment`, `EmployeeAttendance`, `EmployeeLeaveWorkflow`, `EmployeeSalaryAdvance` | Contracts, shifts, overtime and exits pages are generic registers. `EmployeeExitService` untested. Attachments not verified |
| M22 | Payroll | PARTIAL | `PayrollPeriodWorkflow`, `PayrollComponentWorkflow`, `PayrollTaxPolicyWorkflow` | `PayrollTaxRemittanceService` has no tests. Payroll reversal not found/verified. CLIENT: salary currency, tax and transport deduction rules (must stay inactive until approved) |
| M23 | Fixed assets | PARTIAL | `FixedAssetRegister`, `FixedAssetLifecycle`, `FixedAssetDepreciation`, `FixedAssetDisposalAccounting` | Scenario G not covered as one chain. CLIENT: useful lives, residual values, depreciation method |
| M24 | Reports and dashboard | PARTIAL | `OperationalReportCenter`, `Dashboard`, `InventoryValuationReport`, `TrialBalance`, `BalanceSheet`, `ProfitAndLoss`, `CashMovementReport` | AR/AP aging, exchange-difference, inventory aging, product profitability and several administrative reports not verified against the specification list. PDF export has no library (`composer.json` has no PDF package); Excel/CSV coverage not audited per report |
| M25 | Localization, documents, deployment | PARTIAL | Locale switch (`en`/`fa`/`ps`) and RTL attribute in the layout; `config/erp_interface_labels.php` translates menu labels | **No `lang/` directory.** Only 51 `__()` calls across 161 Blade files, so most screens, validation messages and reports are English only. Dari/Pashto font rendering in print not verified. `composer.lock` requires PHP ≥ 8.4.1 although `composer.json` says `^8.3` (see limitations) |

## D01–D09 integration items

None of these has the executable acceptance tests the specification requires, so none can be
marked complete.

| ID | Item | Status | Verified evidence | Outstanding |
|---|---|---|---|---|
| D01 | Mixed QC-released and scrapped customer returns | PARTIAL | `HistoricalBatchMixedQcAllocation` (unit) proves the allocator; `SalesPaidRefundWorkflow` proves QC release restores stock and original COGS | No test of one return holding reusable, held, rejected and scrapped quantities together through HTTP, credit note and GL |
| D02 | Historical FIFO and warehouse reconciliation | PARTIAL | Lot location moves verified by `WarehouseStockService` | The historical FIFO recovery services (`HistoricalProductionFifo*`, `HistoricalOpeningStockValuation`, `HistoricalConsumedFifoAdjustmentValuation`) are **source only, never executed by any test**. Backdated-transaction handling unproven |
| D03 | Historical amendments and atomic reversals | PARTIAL | Journal reversal covered by `JournalEntry` tests | `HistoricalSourceAmendmentService` untested; by its own documentation there is no source-specific reversal executor yet |
| D04 | Supplier claims and landed costs | PARTIAL | Landed-cost capitalization, debit notes and purchase returns tested | Short-credit settlement and landed-cost correction on return are untested |
| D05 | Manufacturing and production costing | PARTIAL | Material issue, waste and cost readiness tested | Full raw → WIP → FG → COGS reconciliation per batch is not tested end to end (see M13) |
| D06 | Laboratory and QC integration | PARTIAL | QC decisions gate stock in `ProductionBatchQc`, `QualityMaterialHold` | NCR auto-block, recall and rework untested |
| D07 | Banking and reconciliation | PARTIAL | Code and UI committed 2026-11-10 | **Zero tests.** Not verified in any way |
| D08 | Payroll integration and reconciliation | PARTIAL | Accrual, components, tax policy and payment tested | Withholding remittance untested; reversal missing; employee statement not verified |
| D09 | Fixed assets and shareholder reconciliation | PARTIAL | Depreciation, disposal segregation and capital movements tested | Register-to-GL and shareholder-to-GL reconciliation reports untested |

## Required end-to-end scenarios (Part G)

| Scenario | State |
|---|---|
| A Procurement to inventory | Covered in pieces across several tests; not as one chained scenario |
| B Manufacturing | Not proven end to end (cost capitalization bypassed by fixture) |
| C Sales | Mostly covered by `SalesInvoiceWorkflow` and `SalesPaidRefundWorkflow` on a capitalized-batch fixture |
| D Supplier claim | Not covered |
| E Payroll | Covered except attendance/leave-driven adjustments and payslip assertions in one chain |
| F Shareholders | Contribution and withdrawal covered; reconciliation statement not |
| G Fixed assets | Covered in separate tests, not one chain |
| H Historical reconciliation | Duplicate journal posting and period lock covered; backdated GRN, FIFO recalculation, concurrent consumption, bank re-import and duplicate salary payment not covered |
| I Permissions | Spot checks only; no role matrix |
| J Cross-database | Clean migration and seed on SQLite and MySQL verified; full suite on both — see verification log |
| K Browser and UI | Not performed. Requires human or browser-automation review |

## Known limitations

1. **PHP version.** `composer.lock` pins Symfony 8.1, which needs PHP ≥ 8.4.1, while
   `composer.json` declares `^8.3`. The application cannot be installed on PHP 8.3 from the
   committed lock file. Either the server must run PHP 8.4, or the lock must be regenerated with
   `config.platform.php` set to 8.3 (needs Packagist access). Decision needed from Fida Technologies.
2. **Down migrations** have not been exercised on MySQL. Follow the forward-fix rule in
   `docs/handoff.md`.
3. Much service code is written as very long single-line methods, which makes review slow and
   hides defects (defect 1 above was one character). Reformatting with Pint is advisable but was
   not done here to keep diffs reviewable.

## Client decisions still required (not development defects)

- Corrected recipe formulas for the two flagged products.
- Account, currency, party and opening-balance mapping for the 16-sheet accounts workbook.
- Classification and currency of owner-investment movements; ownership percentages.
- Salary currency; payroll tax and transport deduction rules.
- Depreciation methods, useful lives and residual values.
- Sales tax rules and the customer invoice sample/layout.
- Default exchange-rate rule (average of buy and sell, or other).

## Release decision

**BLOCKED FROM UAT.**

Reasons, in order of weight:

1. D02, D03 and D07 contain posting code that no test has ever executed.
2. Manufacturing cost capitalization is not proven end to end.
3. Unit conversion is missing; shipments are not connected to costing.
4. Dari and Pashto interface coverage is minimal.
5. No browser, print or role-matrix verification has taken place.

Controlled UAT of the **tested** workflows on synthetic data becomes reasonable once items 1 and 2
have executable tests and a human has run the browser checklist in `docs/uat-checklist.md`.

## Verification log

| Date (UTC) | Commit | Run | Result |
|---|---|---|---|
| 2026-11-09 | `407b744` | 42705545856 | FAILED — SQLite: 33 failed, 368 passed, 1 skipped. MySQL steps not reached |
| 2026-11-10 | `59b1058` | first green run of this pass | PASSED — SQLite: 408 passed, 1 skipped, 3,176 assertions; frontend build; clean SQLite and MySQL install; tested ZIP artifact |
| 2026-11-10 | `f2fd3e2` | MySQL suite added | SQLite: 408 passed, 1 skipped (3,177 assertions). MySQL: 407 passed, 1 failed, 1 skipped — the failure was a test assuming row id 1 |
