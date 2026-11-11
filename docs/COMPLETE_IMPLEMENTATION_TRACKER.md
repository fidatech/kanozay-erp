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
| 7 | `HistoricalOpeningStockValuationService` checked the warehouse of emptied lot positions | No opening stock lot could ever be valued once it had been allocated to a warehouse (found 2026-11-11 by the new test) | Empty positions skipped before the warehouse check |
| 6 | 28 tests were stale against hardened services (segregation of duties, source reconciliation, capitalization, submission tokens) | Suite could not act as a regression gate | Tests now drive the real approval paths and each gained a negative case; no assertion was removed without an equal or stricter replacement |

### Current CI evidence

See the "Verification log" at the end of this file for the run that verified the commit containing
this tracker. The numbers there are copied from the run output.

## Functional modules

Evidence column: feature tests named are in `tests/Feature` and pass on SQLite and MySQL unless
stated otherwise.

| ID | Module | Status | Verified evidence | Outstanding (development unless marked CLIENT) |
|---|---|---|---|---|
| M01 | Authentication, users, permissions | PARTIAL | `RouteAuthorizationSweep` requests all 168 parameterless routes as a guest (must redirect to login) and as a user with no permissions (must be refused); `Auth/AuthenticationAudit` proves sign-in, failed sign-in, lockout and sign-out are audited without secrets; plus `Auth/*`, `AdminAccess`, `AdministrationAccess`, `ModuleRoleSeeder`, `CreateUatUsers`, `Profile` | Routes with URL parameters are covered only by their own workflow tests. No per-role allowed/denied matrix for the seeded roles. Authorization is inline permission checks, not policies. Protection of the main administrator account not verified |
| M02 | Company configuration | PARTIAL | `CompanySetting`, `FinancialConfiguration` | About 20 document types (PO, GRN, production order, batch, delivery note, supplier invoice, debit note, purchase return, stock count, QC sample, NCR and others) derive their number from the row id and ignore the configurable `document_sequences`; prefixes there (`PROD-`) even differ from the code (`PRD-`). Letterhead/print preferences not verified |
| M03 | Currencies and exchange rates | PARTIAL | `CurrencyConfiguration`, `CurrencyExchange`, `GoodsReceiptFxSnapshot`; directional pair table with effective dates exists | Exchange-difference reporting and translated-vs-source balance presentation not verified. Buy/sell average rule: CLIENT confirmation |
| M04 | Chart of accounts and general ledger | PARTIAL | `LedgerAccount`, `JournalEntry`, `GeneralLedger`, `TrialBalance`, `BalanceSheet`, `ProfitAndLoss`, `FinancePeriodClosureWorkflow`, `ChartOfAccountsWorkbook` | Reconciliation invariants 4–12 of the master specification have no dedicated automated tests. Reference chart is not a client-approved mapping (CLIENT) |
| M05 | Customers and suppliers | PARTIAL | `TradeParty`, `TradePartyStatementPeriod`, `TradeDocument`, `TradeSettlement`, `UnifiedAccountDirectory` | WhatsApp balance message and PDF/Excel export not verified. UI review pending |
| M06 | Procurement and purchase orders | PARTIAL | `ProcurementRequestWorkflow`, `PurchaseOrder`, `PurchaseOrderInventoryLink` | PO amendment and cancellation flows not verified by tests. Supplier quotations are a generic register |
| M07 | Imports, shipments, landed costs | PARTIAL | `GoodsReceiptLandedCost` (allocation, capitalization, replay, segregation, and linking a charge to a documented shipment of the same order), `LandedCostAllocator` (unit) | The shipment itself is still a generic record. One charge cannot yet be split across several goods receipts of a shipment; it is recorded per receipt. Allocation basis is receipt value only (not configurable). Landed-cost revision flow untested |
| M08 | GRN, purchase returns, supplier claims | PARTIAL | `GoodsReceipt`, `GoodsReceiptCostSnapshot`, `MatchedSupplierInvoice`, `PurchaseReturnWorkflow`, `SupplierDebitNote` | Damage/shortage claim scenario D not covered end to end. `SupplierShortCreditSettlementService` has no tests |
| M09 | Warehouses and inventory | PARTIAL | `InventoryStockLedger`, `InventoryStockAdjustment`, `InventoryMovementHistory`, `PhysicalStockCount`, `WarehouseStockService`, `WarehousePostingIntegration`, `InventoryValuationReport`, `QualityMaterialHold` | Reservations are company-wide, not per warehouse (transfers are blocked while a reservation exists). Storage locations are a generic register. No concurrency test for simultaneous consumption. Expired/damaged stock handling not verified |
| M10 | Products, raw materials, units | PARTIAL | `UnitConversion`: explicit directional factors, item-specific override, audited deactivation, exact conversion of a production requirement into the stock unit with the recipe values kept, reservation in the stock unit; `InventoryMaterialPage`, `ApprovedProductCatalog` | Conversion is applied on production material links only, not yet on purchase order or sales lines. Units and categories are still generic registers not referenced by items. Barcode and separate purchase/sales units not implemented |
| M11 | Recipes and formulations | PARTIAL | `ProductionRecipe`, `ProductionRecipeWorkflow`, `ProductionRecipeApproval`, `ProductionRecipeImportPreview`, `ProductionOrderRecipeSnapshot` | CLIENT: source workbook has a negative water quantity and a formula total above its package target; these recipes must stay unapproved until Kanozay confirms corrected formulas |
| M12 | Production planning | PARTIAL | `ProductionOrderPlanning`, `ProductionMaterialAvailability` | Production plan/schedule pages route to operational controllers but have no dedicated tests. Shortage report not verified |
| M13 | Manufacturing and production costing | PARTIAL | `ProcurementToFinishedGoodsCostChain` drives GRN → supplier invoice → FIFO issue → batch → QC → release → the real `capitalize()` and reconciles raw material, WIP and finished goods to the GL; plus `ProductionMaterialIssue`, `ProductionMaterialWaste`, `ProductionCostReadiness`, `ManufacturingOperations` | Overhead allocation and abnormal-waste splits inside capitalization are untested. Labour cost not verified. Reservation is tested separately, not inside the chain |
| M14 | Laboratory and quality control | PARTIAL | `LaboratoryQualityWorkflow`, `ProductionBatchQc`, `QualityMaterialHold` | Retest, rework and recall closed-loop not verified. NCR blocking rule has no dedicated test |
| M15 | Finished goods | PARTIAL | `FinishedGoodDispatch`, `ProductionBatchQc` | Expiry handling, damaged quantities and FG-to-GL reconciliation not verified |
| M16 | Sales and quotations | PARTIAL | `SalesQuotationWorkflow`, `SalesOrderWorkflow`, `CustomerCreditPolicy` | Tax rules: CLIENT. Partial fulfilment edge cases not verified |
| M17 | Invoicing, dispatch, customer returns | PARTIAL | `SalesInvoiceWorkflow`, `SalesPaidRefundWorkflow`, `SalesDeliveryNote` | CLIENT: invoice sample `01-Sample.xlsx` was never available, so invoice layout/terminology is unapproved. A4 print/PDF not checked in a browser |
| M18 | Customer and supplier payments | PARTIAL | `PartyAdvanceWorkflow` (customer deposit receive/apply/refund; supplier prepayment pay/apply; replay, over-application, date and permission controls, GL balances), `QuickPartyTransaction`, `UnifiedQuickAccountTransaction`, `TradeSettlement`, `MoneyTransaction` | Reversal of a posted payment or advance is not implemented/verified. Supplier advance refund has no workflow. Multi-currency settlement not verified |
| M19 | Cash, banks, Sarafi | PARTIAL | `BankReconciliationWorkflow` (period, CSV import and re-import, matching, independent approval, immutability, staleness, permissions), `FinancialAccount`, `MoneyTransaction`, `CurrencyExchange`, `CashMovementReport` | Bank fees, timing differences and reconciling items have no workflow. A *changed* CSV with the same rows is treated as a new source and would add lines. Sarafi statements not verified |
| M20 | Shareholders and investment | PARTIAL | `ShareholderAccounting`, `ShareholderGovernance` | CLIENT: classification and currency treatment of the owner-investment workbook; ownership percentages. Distribution allocation service untested |
| M21 | Human resources | PARTIAL | `EmployeeExitService` (clearance, dates, draft payroll, single closure, payroll eligibility after separation), `EmployeeProfile`, `EmployeeDepartment`, `EmployeeAttendance`, `EmployeeLeaveWorkflow`, `EmployeeSalaryAdvance` | Exit is tested at service level, not through the operational-record screens. Contracts, shifts and overtime are generic registers. Unreturned-asset and outstanding-advance exit blocks untested. Attachments not verified |
| M22 | Payroll | PARTIAL | `PayrollTaxRemittanceWorkflow`, `PayrollPeriodWorkflow` (includes a repeated salary payment posting only once), `PayrollComponentWorkflow`, `PayrollTaxPolicyWorkflow` | Payroll reversal not found/verified. CLIENT: salary currency, tax and transport deduction rules (must stay inactive until approved) |
| M23 | Fixed assets | PARTIAL | `FixedAssetRegister`, `FixedAssetLifecycle`, `FixedAssetDepreciation`, `FixedAssetDisposalAccounting` | Scenario G not covered as one chain. CLIENT: useful lives, residual values, depreciation method |
| M24 | Reports and dashboard | PARTIAL | `OperationalReportCenter`, `Dashboard`, `InventoryValuationReport`, `TrialBalance`, `BalanceSheet`, `ProfitAndLoss`, `CashMovementReport` | AR/AP aging, exchange-difference, inventory aging, product profitability and several administrative reports not verified against the specification list. PDF export has no library (`composer.json` has no PDF package); Excel/CSV coverage not audited per report |
| M25 | Localization, documents, deployment | PARTIAL | Locale switch (`en`/`fa`/`ps`) and RTL attribute in the layout; `config/erp_interface_labels.php` translates menu labels | **No `lang/` directory.** Only 51 `__()` calls across 161 Blade files, so most screens, validation messages and reports are English only. Dari/Pashto font rendering in print not verified. `composer.lock` requires PHP ≥ 8.4.1 although `composer.json` says `^8.3` (see limitations) |

## D01–D09 integration items

None of these has the executable acceptance tests the specification requires, so none can be
marked complete.

| ID | Item | Status | Verified evidence | Outstanding |
|---|---|---|---|---|
| D01 | Mixed QC-released and scrapped customer returns | PARTIAL | `HistoricalBatchMixedQcAllocation` (unit) proves the allocator; `SalesPaidRefundWorkflow` proves QC release restores stock and original COGS | No test of one return holding reusable, held, rejected and scrapped quantities together through HTTP, credit note and GL |
| D02 | Historical FIFO and warehouse reconciliation | PARTIAL | `HistoricalOpeningStockValuation`: an unvalued opening lot is valued once through a reviewed case, asset and equity agree, later FIFO issues carry the cost; lot moves in `WarehouseStockService` | The production FIFO recovery services (`HistoricalProductionFifoRecovery`, `...MultiRecovery`, `...SourceCostReview`, `HistoricalConsumedFifoAdjustmentValuation`, `HistoricalBatchCapitalization`, about 4,000 lines) are still **never executed by any test**. Backdated-transaction handling unproven |
| D03 | Historical amendments and atomic reversals | PARTIAL | `HistoricalOpeningStockValuation` covers case submit, duplicate block, independent review, reject, withdraw, refile with fresh evidence, and the reversal assessment with its segregation rules; journal reversal in `JournalEntry` | Reversal of a resolved correction is **documentary only**: an approved assessment posts nothing because no source-specific reversal executor exists. This is a missing feature, asserted as current behaviour in the test |
| D04 | Supplier claims and landed costs | PARTIAL | Landed-cost capitalization, debit notes and purchase returns tested | Short-credit settlement and landed-cost correction on return are untested |
| D05 | Manufacturing and production costing | PARTIAL | `ProcurementToFinishedGoodsCostChain`: batch cost reconciles raw material ledger = remaining FIFO value, WIP cleared to zero, finished goods = batch cost; replay and double-capitalization blocked; uninvoiced material and unreleased batches rejected | Overhead, abnormal waste, scrap and the COGS leg on dispatch are not in the same chain (COGS is tested on a fixture in `SalesInvoiceWorkflow`) |
| D06 | Laboratory and QC integration | PARTIAL | QC decisions gate stock in `ProductionBatchQc`, `QualityMaterialHold` | NCR auto-block, recall and rework untested |
| D07 | Banking and reconciliation | PARTIAL | `BankReconciliationWorkflow` passes on SQLite and MySQL: 3 tests covering import, duplicate protection, exact GL matching, segregation of duties, ledger disagreement and later staleness | Fees, timing differences, opening-balance evidence and period-close integration test still open |
| D08 | Payroll integration and reconciliation | PARTIAL | `PayrollTaxRemittanceWorkflow`: part payments, three-way segregation, never above the withheld amount, liability cleared to zero; duplicate salary payment blocked in `PayrollPeriodWorkflow`; accrual, components and payment tested elsewhere | Payroll reversal missing; employee statement not verified |
| D09 | Fixed assets and shareholder reconciliation | PARTIAL | Depreciation, disposal segregation and capital movements tested | Register-to-GL and shareholder-to-GL reconciliation reports untested |

## Required end-to-end scenarios (Part G)

| Scenario | State |
|---|---|
| A Procurement to inventory | Receipt, supplier invoice and stock/GL agreement chained in `ProcurementToFinishedGoodsCostChain`; landed cost and part-payment are in separate tests |
| B Manufacturing | Covered by `ProcurementToFinishedGoodsCostChain` except reservation (separate test) and overhead |
| C Sales | Mostly covered by `SalesInvoiceWorkflow` and `SalesPaidRefundWorkflow` on a capitalized-batch fixture |
| D Supplier claim | Not covered |
| E Payroll | Covered except attendance/leave-driven adjustments and payslip assertions in one chain |
| F Shareholders | Contribution and withdrawal covered; reconciliation statement not |
| G Fixed assets | Covered in separate tests, not one chain |
| H Historical reconciliation | Duplicate journal posting and period lock covered; backdated GRN, FIFO recalculation, concurrent consumption, bank re-import and duplicate salary payment not covered |
| I Permissions | `RouteAuthorizationSweep` covers guests and permissionless users on all parameterless routes; no per-role matrix yet |
| J Cross-database | Clean migration and seed on SQLite and MySQL verified; full suite on both — see verification log |
| K Browser and UI | Not performed. Requires human or browser-automation review |

## Known limitations

1. **PHP version.** `composer.lock` pins Symfony 8.1, which needs PHP ≥ 8.4.1. Fida Technologies
   confirmed on 2026-11-10 that the server runs PHP 8.4, so 8.4 is the supported runtime.
   `composer.json` still says `^8.3`; aligning it needs a lock refresh with Packagist access.
2. **Down migrations** have not been exercised on MySQL. Follow the forward-fix rule in
   `docs/handoff.md`.
3. Much service code is written as very long single-line methods, which makes review slow and
   hides defects (defect 1 above was one character). Reformatting with Pint is advisable but was
   not done here to keep diffs reviewable.

### Scope note on the historical recovery services

The untested historical production FIFO recovery, dispatch-cost settlement and supplier
short-credit services exist to repair records created *before* the current posting controls
were added. A fresh Kanozay installation has no such records, and the normal purchase,
production and sales paths do not call them. They remain a risk only if legacy transactions
are imported. They should stay disabled in UAT unless that import is planned.

### Blocked in the audit workspace

- **PDF export and `composer.json` PHP constraint:** both need a Composer lock refresh, and the
  workspace cannot reach Packagist. Browser print is the only PDF path today.
- **Concurrent stock consumption:** cannot be exercised in single-process PHPUnit; row locks are
  present in the code but unproven under real concurrency.

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

1. No browser, print, RTL or role-by-role verification has taken place.
2. Payment reversal and payroll reversal are missing; a resolved historical correction cannot
   actually be reversed (D03).
3. Historical production FIFO recovery code (D02) has never been executed by a test; see the
   scope note.
4. Dari and Pashto interface coverage is minimal.
5. No browser, print or role-matrix verification has taken place.

The automated gates set earlier for controlled UAT (bank reconciliation and the manufacturing
cost chain having executable tests) are now met. What still stands between this branch and
**controlled UAT of the tested workflows on synthetic data** is a staging installation on PHP 8.4
and MySQL and one human pass through `docs/uat-checklist.md` in a browser, including print and
RTL. Until someone has opened the application and done that, the decision stays blocked.

## Verification log

| Date (UTC) | Commit | Run | Result |
|---|---|---|---|
| 2026-11-09 | `407b744` | 42705545856 | FAILED — SQLite: 33 failed, 368 passed, 1 skipped. MySQL steps not reached |
| 2026-11-10 | `59b1058` | first green run of this pass | PASSED — SQLite: 408 passed, 1 skipped, 3,176 assertions; frontend build; clean SQLite and MySQL install; tested ZIP artifact |
| 2026-11-10 | `f2fd3e2` | MySQL suite added | SQLite: 408 passed, 1 skipped (3,177 assertions). MySQL: 407 passed, 1 failed, 1 skipped — the failure was a test assuming row id 1 |
| 2026-11-10 | `500f282` | 42897987456 | PASSED — SQLite: 408 passed, 1 skipped, 3,178 assertions. MySQL 8.4: 408 passed, 1 skipped, 3,178 assertions. Frontend build, compiled Blade lint, clean SQLite and MySQL install, tested ZIP artifact `kanozay-erp-tested-zip`. The skipped test needs the original client workbook, which is not in the repository |
| 2026-11-10 | `8550c2d` | 42901331678 | PASSED — SQLite and MySQL 8.4: 414 passed, 1 skipped, 3,322 assertions each. Adds D07 bank reconciliation and D05 cost-chain acceptance tests |
| 2026-11-11 | `1d5c126` | 42965452631 | PASSED — SQLite and MySQL 8.4: 422 passed, 1 skipped, 3,606 assertions each. Adds advances, payroll tax remittance, historical correction/opening valuation/amendment and employee exit tests; one defect fixed |
| 2026-11-11 | `ac0c0db` | 42968327384 | PASSED — SQLite and MySQL 8.4: 429 passed, 1 skipped, 3,757 assertions each. Adds unit conversion, shipment link for landed costs, sign-in audit and the route authorization sweep |
