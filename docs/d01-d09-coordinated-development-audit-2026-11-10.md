# Kanozay ERP — D01–D09 coordinated development pass

Date: 2026-11-10  
Branch: `development/kanozay-completion`

## Scope and verified results

This source pass examined all nine requested development domains and committed additional source controls for D03, D04, D05, D06, D07, D08 and D09. The existing D01 and D02 implementation remains in source and was read, but those domains still require additional production acceptance and/or separately supported original-accounting scenarios.

**No claim of 100% implementation or accounting acceptance.** PHP syntax checks over 546 project PHP files passed at initial baseline; modified PHP files passed lint after the changes, Blade view compilation/clearing passed, and the Laravel application enumerated 367 routes after adding historical amendment routes. These are source and framework-boot checks. **Migrations, database posting, automated feature tests, real-company UAT and deployment remain unexecuted.**

| ID | Source work present or completed during pass | Outstanding acceptance/development |
|---|---|---|
| D01 | Existing invoice-level same-sale source COGS allocation already combines independently QC-released and scrapped return costs; separate QC credit identity and four-decimal rounding, with month-close source audit | Run genuine credit/return transaction fixtures; verify partially sold, same-day, multiple line, return after finance close and paid refund cases; production UAT |
| D02 | Existing source-backed FIFO: opening warehouse attributions, consumed adjustment expense GL, single-lot and 2–12-lot QC production WIP/finished/Cogs atomic historic recovery | One opening lot feeding multiple batches, several original issue dates, mixed GRN/overhead/waste, historical return/credit/non-sale and supplier return sources; real documents and chronology; database verification |
| D03 | **New:** `HistoricalSourceAmendmentService`: rejected/withdrawn cases get append-only, new source-fingerprinted cases with parent/replacement links and audit events; resolved cases may receive independently reviewed documentary full-source reversal-impact requests. Preserve both original pre-post fingerprint and current-source snapshot | Safe source-specific **execution** of linked full accounting reversal/reclassification, rejecting duplicate return/COGS/stock posting and handling closed periods; currently deliberately NO automatic GL reversal |
| D04 | Existing signed supplier debit/short credit/claim and landed-cost returns retained; **new:** original matched supplier invoice must precede physical return and contain enough source inventory debit and mapped supplier payable credit for authentic source return base cost | Full settlement/landed-charge claims, dispute/recovery/partial credit matrix and supplier-specific UAT; external invoice proof |
| D05 | Existing posted source WIP, overhead, waste and FG capitalization retained; **new:** finance-period posting lock and original-date open-period check before costing a batch, reducing closing race | Byproducts, packaging edge cases, mixed currencies, actual capacity and source reconciliation with all historical batches; full transaction tests |
| D06 | Existing laboratory/lot/quarantine/recall controls retained; **new:** unresolved major/critical source-lot or sample nonconformance immediately blocks material issue and associated finished batch QC/distribution, including after earlier approved QC sample | Recall contact closure, QC traceability, sample expiry exceptions, tested multi-warehouse and mixed-return scenarios; role/browser QA |
| D07 | Existing source bank matching, period balance and independent approval retained; **new:** statement line validates positive four-decimal amount; matching and independent final approval recheck real posted, unreversed, matching-date/currency bank GL debit/credit for exact transaction and direction | Bank CSV import/fees/outstanding transfers, bank statement timeliness and selected historic multi-currency opening account mapping; actual statement UAT |
| D08 | Existing accrued salary, itemized allowances/deductions, tax and payment GL retained; **new:** payroll creator and independent approver separate, original month-end open gate, unrelated entity-ledger prevention, valid unreversed accrual required before payment, and different cash/bank payout executor | Client-approved actual legal withholding/tax policy, partial/retrospective payroll/advances and employee bank integration; tests and approvals |
| D09 | Existing asset acquisition/straight-line depreciation/disposal and signed shareholder allocations retained; **new:** disposal recorder cannot post own retirement GL, original disposal period open, depreciation source journals must remain posted/unreversed/dated before retirement and original currency and proceeds valid | Original shareholder capital/beneficial ownership evidence, advance/capital distribution compliance, multi-currency asset disposals and actual gain/loss reconciliation UAT |

## Historical amendment design

The append-only `finance_historical_source_amendments` table links one immutable rejected/withdrawn parent to one new independently reviewed historical source case, while `finance_historical_source_reversal_requests` freezes the original source and current post-execution fingerprints, original accounting effects, requested replacement treatment, and independent reviewer. Both are documentary controls. A reversal **review approval is NOT a transaction posting**: no original raw, WIP, finished asset, customer COGS, supplier credit, GL or physical stock is rewritten. The correct full-source reversal executor remains D03 incomplete and would require independent period-gated transaction with all linked assets, liabilities and credits.

## Release requirements

Do not deploy the D03 migration/service or the other accounting changes to real production until isolated migrations, source/business checks, full Laravel tests, concurrency/fingerprint validation, real source evidence, period-close/rollback UAT and approvals succeed. Several source accounting operations require original client documents and prior manual GL verification that cannot be inferred automatically.

**Honest status as of source commit:** D01 source reviewed; D02–D09 partially developed, not fully verified or accepted.
