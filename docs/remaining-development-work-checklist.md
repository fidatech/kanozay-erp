# Kanozay ERP — Remaining development and release checklist

Branch: `development/kanozay-completion`  
Updated: 2026-11-09  
Release state: **NO-GO**. The checklist distinguishes source implementation from executed test evidence, business sign-off and deployment.

## Status legend

- ✅ **Source reviewed:** source implementation committed and its integration references checked. Does **not** mean PHP syntax/migrations/tests have run or it is safe for production.
- 🟡 **Partially implemented:** existing module/source functionality is present according to the integration tracker, with more integration work to complete.
- ⬜ **Pending:** substantial work, business evidence or release verification remains.
- ⏸️ **Explicitly deferred:** do not run until authorized by the user.

## Priority 1 — Source development

| ID | Task | Development status | Last checked / remaining |
|---|---|---|---|
| D01 | 🔄 Same customer invoice: mixed QC saleable releases and scrapped returned goods | ✅ Source reviewed | Single 4-decimal original COGS budget, deterministic original-date allocation, signed QC source fingerprint, cumulative posting checks, month-close checks, finance UI and eight **unexecuted** regression cases committed. [Implementation](historical-mixed-qc-return-cost-allocation-2026-11-09.md). |
| D02 | 📦 Consumed opening FIFO, unknown historical warehouse locations and missing original-cost valuation | 🟡 Partial | **Added this batch:** uniquely source-fingerprinted and independently approved ONE-original-lot / ONE-QC-released-batch / ONE-original-material-issue-date historical GL recovery; exact original opening asset/equity, source WIP, QC finished inventory and authentic already-invoiced customer COGS posted together under the original-period gate/one DB transaction, with full ledger source IDs and rollback on any failure. Existing immutable original `production_batch_costs` and consumed FIFO rows remain unchanged; linked posted WIP source cost now informs remaining FIFO valuation, warehouse provenance, future raw issues, manufacturing source validation, reporting, reversal protection and close-period audits. **Still pending:** multiple original raw lots, a lot shared across batches, multiple material issue dates, independently valued supplier inputs, source overhead/waste, mixed non-sale or customer-return inventory/COGS, historic supplier returns and non-opening unknown warehouse locations; no accounting source truth is assumed from external manual GL. Source-only review, no migrations/lint/tests/UAT executed. [Original provenance](historical-fifo-consumption-warehouse-provenance-2026-11-09.md) · [Restricted adjustment GL](historical-consumed-opening-fifo-adjustment-cost-accounting-2026-11-09.md) · [Production WIP source review](historical-production-fifo-wip-source-preflight-2026-11-09.md) · [Atomic single-lot production recovery](historical-single-lot-production-fifo-atomic-recovery-2026-11-09.md). |
| D03 | 🧾 Historical correction amendments, rejected-case resubmissions and original source-document reversals | ⬜ Pending | Immutable cases/approvals exist; no unrestricted reclassification or unreviewed journal reversal permitted. Build audited amendment path. |
| D04 | 💰 Complex supplier claims, recoverable landed costs and disputed/partial original credits | 🟡 Partial | Original-source supplier short-credit and nonrecoverable landed-cost cases exist; complex claims/settlements still require work. |
| D05 | 🏭 Manufacturing cost integration: packaging, approved overhead, capacity, byproducts and edge chronology | 🟡 Partial | Core source FIFO/WIP/COGS and waste controls exist; full manufacturing business rules/policies are not accepted. |
| D06 | 🧪 QC exceptions: source-lot rejections, quarantine, product recall and complete traceability | 🟡 Partial | Independent QC and returned-goods dispositions exist; complete recall/exception integration remains. |
| D07 | 🏦 Bank statements, multi-currency historical opening account mapping and full finance reconciliation | 🟡 Partial | Party/GL statements and source checks exist; real bank-feed reconciliation and legacy account sign-off remain. |
| D08 | 👥 Payroll deductions, bank/cash payroll payments and withholding/expense integration | 🟡 Partial | HR/payroll source modules exist; client-approved deductions and end-to-end accrual/payments pending. |
| D09 | 🏢 Fixed-asset acquisition/disposal and shareholder original ownership/capital controls | 🟡 Partial | Source accounting and governance exist; complete evidence/statement reconciliation and approval gaps remain. |
| D10 | 📄 Sales invoice format, credit/refund/tax handling and A4 document printing | 🟡 Partial | Sales, dispatch, invoices and credits exist; client template, tax rules and print/sign-off pending. |

## Priority 2 — Interface and reporting

| ID | Task | Development status | Last checked / remaining |
|---|---|---|---|
| U01 | 🎨 Consistent ERP UI/UX, tables, modals and forms | 🟡 Partial | Cross-module screen-by-screen audit pending. |
| U02 | 🌐 Complete Dari and Pashto application and transactional translations | 🟡 Partial | Existing translations not complete; verify all strings. |
| U03 | ↔️ RTL layouts, forms, tables, modals and multilingual inputs | 🟡 Partial | Source RTL/localized numeral support exists in several areas; full acceptance pending. |
| U04 | 📊 Manufacturing, inventory, payroll, GL, AP/AR and management reports | 🟡 Partial | Operational reports exist; reconciliation coverage and real-client data acceptance remain. |
| U05 | 🖨️ Printable invoice/statement/voucher/report A4 layouts | ⬜ Pending | Chrome/Safari print and client-approved A4 sample required. |
| U06 | 🔔 CRM/document approvals, notifications, audit retention and role scope | 🟡 Partial | CRM and document core developed; client procedures and permission UAT pending. |

## Priority 3 — Acceptance and release (deferred)

| ID | Task | Verification status | Required evidence |
|---|---|---|---|
| R01 | 🧩 Execute clean migrations and restore migration rehearsal | ⏸️ Deferred | Clean database, zero failures, rollback/restore evidence. |
| R02 | 🧹 PHP syntax, static analysis, code style and route/view inspection | ⏸️ Deferred | Actual PHP/tool execution on the latest commit. |
| R03 | 🧪 Laravel feature, unit, integration and regression tests | ⏸️ Deferred | Successful suite including new mixed QC cost cases, idempotency, concurrency and journal locks. |
| R04 | 🗄️ MySQL source-currency transactional and GL/COGS validation | ⏸️ Deferred | Original stock/cost/FX/journals reconciled with real test data and closed-period blocking. |
| R05 | 🔐 Independent role-based browser, finance and QC UAT | ⏸️ Deferred | Signed purchaser, warehouse, QC, accountant, approver, auditor and admin scenarios. |
| R06 | 📦 Client-approved recipes, historical opening balances, parties and finance policies | ⬜ Awaiting client evidence | Reconciled source data and authorized sign-off. |
| R07 | 💾 Backup, isolated restore, rollback, deployment and recovery rehearsal | ⏸️ Deferred | Verified recovery procedure and backup checks. |
| R08 | 🚀 Final production package, live migration and deployment authorization | ⏸️ Deferred | Release checklist approval after R01–R07. |

## Verification note

On the 9 November development batch, GitHub source paths and the new pure allocator/guard/regression-test integration were checked. **Tests were written, but not run.** Source-code presence and commit success are not evidence of runtime correctness or completed client UAT. All prior modules remain at the integration tracker status until they receive their own evidence-backed checks.

Next source work in sequence: **Continue D02 with MULTIPLE independently approved original FIFO lots feeding one real released batch, and safe cross-lot chronological manufacturing-cost allocation**, followed by separately reviewed multi-date material WIP sources, overhead/waste, historical non-sale and QC customer returns. Keep unsupported cases blocked until their full original GL costs are signed and source-complete. This batch implements only one genuine lot/one batch/one original issue date; its financial posting path is source-committed but not run or tested. D02 remains 🟡 partial. Then proceed to D03. All acceptance/release gates remain deferred by request.
