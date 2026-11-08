# Kanozay ERP — HR, payroll and fixed-asset development

Date: 2026-11-08  
Branch: `development/kanozay-completion`  
Status: **Source changes committed; migrations, automated tests, browser UAT and deployment deferred at user's request.**

## HR and employment effective dates

- Added nullable `employee_profiles.hired_on` and `separated_on` to preserve verified employment eligibility without inventing legacy hire dates.
- New employee forms support a verified hire date. Existing employees may have a hire date corrected only with documentary reference and explanation; existing approved payroll blocks unsupported retroactive correction.
- Removed the previous one-click active/inactive status toggle: it bypassed employee exit clearance, company asset returns and salary-advance reconciliation.
- Approved offboarding records now persist their actual last working date after verifying outstanding employee advances, unreturned fixed assets and draft payroll. Offboarding refuses dates that conflict with later posted payroll.
- Payroll drafts now include employees eligible by month and verified hire/separation dates. Employees who joined or left mid-month still require manual payroll review; full-month salaries are *not* automatically prorated.
- Inactive imported employees with no known separation date are not silently inferred as eligible for historical payroll; active imported employees without a known hire date remain visibly unverified for reviewer judgment.

## Payroll source evidence and approval gate

- Added `payroll_line_reviews` and an append-only `payroll_line_review_events` history, recording evidence reference, reviewer, explanation, and a fingerprint of the salary, employment dates, attendance, leave statuses and component deductions/allowances.
- Every draft payroll line has a review form and a current, pending or stale state. An adjusted salary amount, changed attendance/leave record, altered employee dates or new payroll component invalidates the prior review.
- Payroll approval requires fresh evidence for **every included employee**. A checked approval checkbox alone no longer authorizes salary accrual.
- Review documents human verification. This does *not* automatically impose workday counts, tax policy, absence deductions or a legal salary-proration rate.
- Attendance recording/corrections against an approved employee pay period are blocked to prevent changing the source facts behind an already-posted accrual.
- Backdated leave approval crossing a posted employee payroll period is blocked until a separate, finance-reviewed adjustment workflow exists.
- Salary payments may not precede their original posted payroll-accrual journal date.
- Payroll components and fixed-asset monetary input now accept localized Pashto/Dari/Arabic digits and comma grouping ahead of the existing precision validators.

## Fixed-asset acquisition and depreciation accounting

- Added `fixed_asset_capitalizations`, linking each asset to one **specific and uniquely used posted acquisition journal debit**. Finance must document original source reference and currency, exact acquisition amount, PPE asset ledger and evidence.
- The reconciliation is strictly read/link only: it posts **no duplicate asset acquisition journal**.
- Active assets lacking this evidence are visible as 'needs GL matching' and cannot post new depreciation.
- Depreciation additionally refuses periods dated before the linked acquisition journal. Existing chronological/month-end/useful-life checks remain in place.
- Asset depreciation register now reports original currency, acquisition cost, accumulated posted depreciation, calculated remaining book value and financial capitalization state. Reversed GL sources and over-depreciated records display exception warnings.
- If a reviewed capitalization link exists, disposal posting is required to match its acquisition journal, PPE ledger and exact acquisition debit; pre-existing disposal accounting remains subject to its existing historical matching checks.
- Asset service and disposal amounts share the existing localized-decimal parser before validation.

## Outstanding development and reconciliation

- **Historical records:** Imported staff often have unknown hire dates, and asset registrations often lack a uniquely matched PPE journal debit. Their original contractual and financial evidence must be reviewed before live use.
- **Payroll amendments:** Approved payroll corrections, leave back-pay, final settlement, paid/unpaid leave policy, overtime rules and approved tax/transport component calculations require a company-agreed financial workflow. The new gate prevents silent edits; it does not fabricate an adjusting journal.
- **HR:** Employee rehire/reactivation, effective-dated salary revisions, automatic accrual calculations, shift-to-payroll mappings and full employee document/contract controls need further integration.
- **Assets:** Bulk capitalizations or pooled PPE journal entries require a reviewed allocation procedure when one journal debit covers several asset records. Depreciation and disposal recognition still require staged reconciliation of older values.
- **Finance:** Historical cash/bank ledger maps, payroll tax withholdings, employee advances, asset capitalization and fixed-asset book values must be reconciled with the actual company's approved opening balances.
- **Operations:** UAT is needed for offboarding history, payroll fingerprint invalidation, accounting journals, backdated records, and multi-currency asset reporting.

## Release boundary

No automated tests, database migrations, MySQL schema updates, browser UAT or production deployment were executed. These changes represent written development source, not a verified production release. The payroll-review and capitalization guards will block some historic records until their documented source evidence is reconciled in staging.
