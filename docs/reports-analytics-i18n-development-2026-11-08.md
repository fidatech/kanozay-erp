# Kanozay ERP — Reports, analytics and initial Pashto/Dari interface integration

Date: 2026-11-08  
Branch: `development/kanozay-completion`  
Status: **Source changes committed; full integration, UAT and deployment not yet performed.**

## Reports and analytics source changes

### Read-only operational overview
- The Reports & Analytics overview is now routed to an actual permission-protected reporting controller rather than a generic module landing page.
- Shows selected-period purchase-order counts, production-plan counts, QC-released batch counts, accepted released packages, posted GL journal count and customer delivery-confirmation event count.
- Shows **current** open quality nonconformance investigations and incoming lots under manually recorded QC holds. These two outstanding counts are expressly labeled as current snapshots, not as events dated within the selected period.
- Shows formal laboratory sample collection counts by source (incoming material and finished batches) alongside each sample's **current** approved, rejected or pending status.
- Shows separately the **face value of posted sales invoices** and **face value of issued sales credit notes**, each grouped by its original currency. Never adds different currencies or implies invoices equal collected cash, credits equal refunds, or the difference equals accounting revenue.
- The reporting SQL reads committed source records and adds no business or GL postings. Currency amounts use exact decimal arithmetic and source strings, not floating-point financial aggregation.
- Added period-filtered CSV for the operational overview. Formula-like user-controlled values are escaped and Unicode export uses UTF-8 BOM.

### Detailed reporting registers
- Added source-backed reporting pages for customer delivery notes (including actual customer delivery-proof tracking), customer credit notes, collected laboratory samples and quality nonconformance cases.
- Updated inventory report scope notes to acknowledge real per-warehouse material balances and warehouse-lot valuation registers; the existing material report remains a **current company-wide snapshot**, not an as-of-date warehouse stock valuation.
- Updated production material cost report to display ingredient, packaging and total material cost separately in the original currency. Historical category splits that were not captured remain explicitly **unknown**.
- Updated batch traceability report text to acknowledge existing supplier/GRN/FIFO source-lot forward and reverse traceability. Gross outbound package counts are not falsely described as net of customer returns.
- Distinguished old production-batch quick laboratory checks from separately collected formal QC samples and approved specification versions.
- Added context links from registers to warehouse stock/valuation, customer delivery and credit reports, formal laboratory samples, nonconformances, production-cost readiness and finance period closing.
- CSV report register exports already escaped spreadsheet formulas; they now also include an Excel-compatible UTF-8 BOM.
- Cash movement CSV now protects account names and currency values from spreadsheet formula injection without altering original source-currency money amounts.

### Reporting language support
- Existing project-wide `en` / `fa` / `ps` locale middleware and session-backed menu language picker are retained.
- Added a reporting translation layer with a conservative English fallback for missing terms.
- Added Pashto/Dari translations for reporting overview headings, filters, summary names, major source-record column headings, empty states, navigation titles, export buttons and contextual links.
- The dashboard now uses the same translations for primary metric headings and descriptions, date filters, greeting and activity controls.
- Original currency symbols, customer names, document IDs and source strings are not machine-translated or modified. Amount grouping avoids conversion through PHP floating-point numbers.

## Integration and reporting limits

- Current finance Profit & Loss, Balance Sheet and Cash Movement reports are existing code; this phase does **not** certify their statutory accuracy or invent FX or tax policy.
- Comprehensive COGS/finished-goods capitalization and physical stock accounting remain cross-module dependencies. No unreviewed valuation is shown as profit.
- A return credit does not prove customer cash refund; a delivery note does not post inventory again. The new reports retain those distinctions.
- Sample approval statuses and open QC holds are live statuses; exporting a historical date range does not recreate previous historical review states.
- Current translations concentrate on the dashboard and reporting module. Many transactional forms, document templates, errors, PDF printouts, imported source descriptions and finer RTL interactions remain English or require professional Afghan Dari/Pashto review.
- Several legacy records may lack reconciled GRN provenance, warehouse-lot locations, historical sample specification snapshots or acquisition journals. These remain open issues rather than being guessed.
- Aggregated report access is restricted to the existing reporting/administration read permissions; a detailed role and row-level confidentiality review is still necessary before external report sharing.
- CSV reports are source snapshots. They are not signed accounting certifications.
- Further work remains: full trial balance reconciliation, consolidated multi-branch reporting if explicitly approved, tax reports from signed company policies, date-scoped stock movement valuation, external documentation, report performance at production-scale datasets and complete translation of transactional workflow screens.

## Verification boundary

At the user's direction, no automated tests, migrations, database schema updates, running browser UAT, MySQL validation, deployment or final ZIP release were performed. Code commits demonstrate source development only. Report output, language rendering and workflow permissions still need staged end-to-end verification before accepting a production release.
