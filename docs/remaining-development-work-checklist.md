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
| D02 | 📦 Consumed opening FIFO, unknown historical warehouse locations and missing original-cost valuation | 🟡 Partial | **New source implementation (2026-11-10):** signed original-source **2–12 independently approved opening FIFO lots → ONE actual QC-released batch** in a common authenticated original currency on ONE genuine material issue date. Real material/packaging issues reconcile exactly to the frozen unvalued batch, original ownership/each lot cost and remaining raw asset. A separate proposer, independent reviewer and final executor approve original-date posted GL for EACH opening inventory/equity lot, combined raw → WIP, QC finished inventory, and all actual posted customer-invoice COGS in ONE DB transaction. Added source fingerprints, unique lot/batch controls, posted immutable FIFO cost links, inventory valuation, independent finance-close audit, journal-reversal safeguards, manufacturing reconciliation, reporting and finance UI. **Still pending:** a lot feeding multiple batches; several original issue dates; mixed valued GRNs, currencies, overhead/waste and customer returns/non-sale dispatches; historical supplier returns; non-opening unknown warehouse location and manual GL source reconciliation. Source code only, no migrations, lint, tests or UAT. [Source/lot provenance](historical-fifo-consumption-warehouse-provenance-2026-11-09.md) · [Adjustment write-off GL](historical-consumed-opening-fifo-adjustment-cost-accounting-2026-11-09.md) · [Production WIP source review](historical-production-fifo-wip-source-preflight-2026-11-09.md) · [One-lot full accounting](historical-single-lot-production-fifo-atomic-recovery-2026-11-09.md) · [Multi-opening-lot full accounting](historical-multi-opening-fifo-atomic-cost-recovery-2026-11-10.md). |
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

Next source work in sequence: **Continue D02 with one authenticated opening FIFO lot used across MULTIPLE genuine production batches or multiple actual material issue dates**, including complete chronological raw/WIP/QC-finished/COGS posting in one source-authorized recovery. Only then add mixed prior valued GRNs, independently reviewed overhead/waste, signed non-sale/QC customer returns, historic supplier returns and non-opening inventory locations. The 2–12 lot single-batch path is GitHub source-committed but untested and must not be deployed as production-ready. D02 remains 🟡 partial. D03 follows after D02 development completion; all release and test tasks remain deferred by user request.
