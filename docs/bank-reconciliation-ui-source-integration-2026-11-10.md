# Kanozay ERP — D07 bank statement source reconciliation UI

Date: 2026-11-10  
Branch: `development/kanozay-completion`  
Deployment status: source committed, **not** migrated, database-tested or UAT-approved.

## Delivered

- `/finance/bank-reconciliations`: authorized bank-statement register, bank source/currency selection, original period start/end and original statement opening/closing figures.
- `/finance/bank-reconciliations/{period}`: original source statement details, exact currency, account information, transaction list, matched/unmatched source journals and independent finance approval form.
- Individually typed bank statement lines must have genuine posting date, positive signed bank amount and correct bank deposit/withdrawal direction.
- Import `date,direction,amount,reference,description` CSV with max 2 MB and 2,000 rows per file. Original source dates, correct number of columns, amount format, date bounds, identity and required fields are validated before insertion, all imported atomically. Same uploaded source file is recognized by stable deterministic UUID5 from period, file SHA256 and row position. CSV **never** automatically creates a ledger transaction or FX conversion.
- Proposed transaction matches are filtered in UI by same original bank account, currency, date, amount and deposit/withdrawal direction. The original `BankReconciliationService` independently rechecks exact posted unreversed GL source bank debit/credit, amount, mapped bank ledger account and date, ensuring no duplicate matched money transactions.
- Period approval requires independently authorized reviewer other than creator, and rechecks all bank statement movements and posted money transactions, original source-currency balance, statement opening + deposits - withdrawals = statement closing and original bank ledger as of period end. Approved sources are immutable.
- Finance sidebar now exposes the page. `FinanceCloseReadinessService` calls a read-only `BankReconciliationService::auditApproved` on every approved statement overlapping the closing month; a previously signed but now inconsistent bank GL becomes a month-close **blocker**.

## Operational sequence

1. Confirm a real active `bank` financial account with correct source currency and mapped, active bank cash ledger already exists.
2. Open a source period and provide original bank statement opening/closing and document number. Period overlaps with another bank statement are rejected by the service.
3. Import a bank-issued CSV or enter actual statement lines. Use identical source format for retrying an import; never fabricate a transaction just to match a statement.
4. Match each statement row to one authentic posted ERP money transaction, and ensure every posted bank transaction for the period is represented.
5. Independently approve the period only when every source journal agrees, and original bank/account GL balance equals the signed statement balance.
6. Review affected month in Finance Period Close. Approved reconciliations are re-audited. A bank with **no** approved full-month statement still produces an existing manual-review warning, and lack of authentic source evidence cannot be presented as signed reconciliation.

## Intentionally blocked or pending

Bank fees/interest, timed cheques, outstanding deposits, unreconciled external bank transfers, historical opening bank valuation and changed source-document data require verified accounting documents and independently controlled timing/accrual or original-period GL. There is no automatic netting, guessing or generic bank journal. The source workflow should **not** be promoted as final financial certification until migrations, integrated MySQL/SQLite posting, large CSV/idempotency tests, role access tests, original bank source files and bank closing balance UAT pass.

## Verification performed

- PHP 8.4 syntax check on the new bank controller and changed bank services.
- Laravel Blade view compile and clear succeeded.
- Laravel enumerates all seven new bank reconciliation routes.
- Ramsey UUID5 availability was confirmed and the unsupported Laravel Str helper replaced.
- Git whitespace verification passed.

**Not performed:** migrations, database write/rollback exercises, feature tests or actual statement import and approval on a bank account.
