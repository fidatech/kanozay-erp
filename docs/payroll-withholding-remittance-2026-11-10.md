# D08 — Source-backed employee withholding remittances

**Date:** 2026-11-10  
**Branch:** \`development/kanozay-completion\`  
**Status:** Source-implemented, not migrated or UAT-approved. Do not deploy to real financial data without acceptance.

## Workflow

The original payroll tax policy is independently approved and applied to every employee in an approved payroll period; the resulting deduction components credit a dedicated original-currency withholding payable through the posted month-end payroll accrual.

The new \`PayrollTaxRemittanceService\` creates the next operational stage:

1. **Submit:** Choose one actual approved/paid payroll period, its approved policy, an active same-currency bank/cashbook, a positive payable amount, original payment date and genuine source evidence. Rebuild every employee tax application and its matching immutable deduction component; validate the original posted and unreversed salary journal's withholding credit. Freeze a SHA-256 source signature. Hold all submitted, approved and posted partial remittances against the original accrued tax liability to prevent duplicate requests. Submission creates NO GL or bank payment.
2. **Independently review:** Reject self-approval by the remittance proposer, original payroll preparer/approver, or policy approver. Recheck the live employee tax applications, policy, original GL and all reserved partial payments; record signed approval/reference or rejection. Still no journal or money transaction.
3. **Execute by a different actor:** Lock finance posting and payroll source period, reconstruct the signed source, verify actual paid date within an open accounting month, and ensure both outstanding tax payable and actual bank/cash GL balances cover the original-currency payment. Post exactly **Dr withholding liability / Cr bank or cash asset**, and record an ordinary bank-matchable payment transaction in ONE database transaction. All source postings roll back if anything fails. Original employee salaries, original tax accrual, source policy and tax rates remain untouched.
4. **Bank reconciliation:** The posted remittance money transaction can be matched by the new D07 source bank statement screen in the original account currency; it is a normal journal-backed bank withdrawal and cannot be generically reversed independent of source transaction controls.

## Delivered implementation

- Migration: \`database/migrations/2026_11_10_000400_create_payroll_tax_remittances.php\` with idempotency token, period/policy/source currency/liability/bank amount, source fingerprint, evidence, individual proposer/reviewer/executor, linked payment GL and cashbook transaction.
- Service: \`app/Services/PayrollTaxRemittanceService.php\`.
- Controller and finance register: \`app/Http/Controllers/PayrollTaxRemittanceController.php\` and \`resources/views/finance/payroll-tax-remittances.blade.php\`.
- Four authenticated Finance routes: \`finance.payroll-tax-remittances.index\`, \`.store\`, \`.review\`, \`.post\`.
- Sidebar access in \`resources/views/layouts/kanozay.blade.php\`.

## Explicit limitations

This application does **not** assert Afghan statutory salary tax rates, current legal eligibility, the real treasury beneficiary or legally mandated remittance due dates. The company must enter a current authoritative policy and independently sign government payment instructions. The workflow does not implement disputed tax refunds, tax overpayment recovery, payroll reversal, multi-currency tax settlements, prior-year source adjustments or bank-rejected transfer reversals.

**Verification:** GitHub file and route source cross-reference inspection confirms the related source files, functions, route names and view forms exist. This does not establish runtime correctness. PHP lint, view compilation, migrations, feature tests, full source accounting UAT and real bank acceptance remain necessary, and GitHub Actions still reports unrelated/pre-existing overall suite failures. Do not claim a 100% tested payroll release.
