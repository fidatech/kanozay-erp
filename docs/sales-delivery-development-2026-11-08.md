# Kanozay ERP — Sales and Delivery development update

Date: 2026-11-08  
Branch: `development/kanozay-completion`  
Status: **Development source committed; full integration and UAT still outstanding.**

## Implemented in this phase

### Customer orders and fulfillment
- Added a read-only fulfillment calculation per accepted sales order and line, comparing ordered packages against posted invoice packages, customer-credit return quantities, active delivery-note quantities and proof-confirmed deliveries.
- Returns are displayed separately: a credit note does not automatically re-open the original order or erase historical invoicing.
- The sales-order register and details display quantities remaining to invoice and delivery progress. Existing immutable accepted quotation prices and quantity snapshots remain unchanged.
- Added a reason-backed, uniquely tokenized, audited cancellation workflow for confirmed orders with **no invoice lines linked to any of their order lines**. Order model immutability remains the default; the controlled service records the only status transition.
- Once any sales-invoice line is linked, ordinary cancellation is blocked and staff must use the reviewed returns/adjustments process.
- A direct invoice created without associating an order line cannot be automatically linked back to that order. Staff must reconcile those legacy/manual cases independently.

### Customer returns and quarantine
- **Every new customer credit-note return is quarantined**. Removed the invoice-form checkbox that previously permitted immediate saleable restocking.
- A credit note still reverses its revenue and updates unpaid customer receivable, with any paid refund handled by an independently authorized finance posting. The credit itself does not create saleable finished-goods stock.
- Returned goods enter the existing quarantine register and require the separate QC-recovery decision by an independent authorized person before their quantity can be restored to available finished-goods inventory.
- Posted credit-note details now display the actual current quarantine disposition through the linked QC record.
- Older returns previously recorded as direct restocks are identified explicitly as **legacy exceptions requiring historical QC evidence**. The application does not invent an old independent QC approval.

### Delivery documentation and proof
- Sales delivery-note creation subtracts already credited customer-return quantities as well as noncancelled notes. A note may not precede the original posted invoice date.
- A duplicate delivery-note submission token can only replay the identical source invoice, quantities, date, driver, vehicle, address and reference. Changed requests are rejected.
- Delivery confirmation now always requires receiver name, proof reference and checkpoint/location. Confirmation records a source-linked immutable logistics milestone.
- A documented returned-to-depot milestone marks its delivery note as returned, without restoring invoiced inventory or reversing a sales journal.
- Delivery notes with loading/transit history may not be casually cancelled. Returned notes with an authenticated return event can be closed with an auditable cancellation reason, allowing revised logistics documentation; original stock and finance postings remain untouched.
- Older delivery notes with a terminal tracking return still marked `issued` can be closed using their retained return evidence.
- Posted invoice details display linked delivery notes, customer-received packages, returned shipment packages and cancelled logistics notes. Each label distinguishes warehouse dispatch from actual customer proof of delivery.

### Numeric entry
- Quotation, invoice, customer return and delivery-note controllers now normalize comma-separated values and Arabic/Pashto/Dari digits before the existing numeric/precision/range validation rules.
- Monetary and package-quantity inputs on the Sales forms use text fields with numeric keyboards to accept these formats. This does not change tax or invoice-price policy.

## Important remaining work

- **Financial costing:** WIP, finished-goods inventory capitalization, COGS at dispatch, cost-of-return reconciliation and currency conversion for costing require an approved ledger design and posting integration.
- **Tax/discount policy:** No invented GST, BRT, withholding, promotion or discount rates have been added. Final rules must come from Kanozay's approved business setup.
- **Legacy data:** Historical directly-restocked credit notes, manually issued finished-goods balances and unlinked orders need documented review.
- **Delivery operations:** Proof is currently an audited reference and receiver identifier, not a secure signed document upload. Carrier acceptance, partial refusal and physical depot intake reconciliation require an approved workflow.
- **Customer returns:** A return credit does not prove an item was physically received. Additional proof-of-receipt documents and lot-level recovery reconciliation are still required for full control.
- **Quality impact:** The upstream FIFO material hold now blocks future traceable finished-goods dispatch, but previously delivered products require separate controlled recall and customer-contact actions.
- **Administration:** Permissions and stage UI require end-to-end UAT on both SQLite and deployment MySQL, with RTL language review.

## Verification and release boundary

At the user's direction, tests, new database migrations, MySQL schema changes, browser UAT and production deployment were **not executed**. These are committed source modifications, not proof of runtime correctness or readiness for customer handover. Do not enable them on live stock/accounting data before a staged migration, historical reconciliation and formal operational acceptance.
