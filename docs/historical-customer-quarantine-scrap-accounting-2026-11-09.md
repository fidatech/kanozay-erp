# Kanozay ERP — Historical customer QC quarantine and signed scrap accounting

Date: 2026-11-09  
Branch: `development/kanozay-completion`  
State: **Development source committed only. No PHP lint, migrations, unit/integration tests, SQLite/MySQL UAT, browser QA or deployment have been performed by request.**

## Intent and accounting policy

Previously credited customer returns are not all inventory. A credit note reverses customer revenue/receivable in the customer **selling currency**, but does not independently prove a returned product is saleable or can be valued as a new asset. Accounting follows the recorded QC result:

- **Still quarantined:** The original goods remain a documentary physical QC hold and are **not saleable inventory**. No finished-stock asset, cost recovery, sales COGS reversal, additional loss expense or inventory package movement is created. The original posted customer credit remains unchanged. A later independent QC decision changes the source snapshot and must have a separately reviewed disposition.
- **Independently scrapped:** The QC officer separately authorizes physical scrapping/condemnation, with original date, signed QC document, explanation and physical warehouse evidence proving no return to saleable stock. The original sale COGS **remains an expense** unless an accountant independently authorizes reclassification into a dedicated returned/damaged-goods expense account. The approved original-factory-currency journal is `Dr approved scrapped-return loss expense / Cr original sales COGS`. This is a **category reclassification of an expense already recognized**, not a second charge to profit and loss or a credit to an inventory asset.

This batch adds `customer_return_disposition` to the Historical Financial Correction Center and persists its independent review in `finance_historical_customer_return_disposition_reviews`.

## Original-source evidence and case review

- Freeze the exact original `sales_return_quarantines.id`, original customer credit note/line, invoice/line, physical finished-goods dispatch, QC status/decision, original issued and credited packages, note's genuine revenue reversal, original source-manufacturing COGS ID/amount/currency when posted, and any recorded warehouse return movement.
- The original genuine invoice must be **posted**; customer receivable/transaction must be source-linked, with original posted unreversed revenue journal. The genuine posted customer credit note must debit the **same income ledger** as the original customer invoice, credit the same mapped customer receivable ledger, and have a sum of credit-note line values equal to its posted header. Credit notes cannot exceed original invoice sale quantities or use changed selling prices.
- Original physical sales dispatch must have **exactly one** batch/product/quantity/date-matching source line. A QC hold or scrap must have **no** separately documented saleable `customer_return` warehouse movement and no finished-stock cost recovery journal. Credit-note `restocked=true` is rejected because it is not independent QC evidence.
- A quarantine hold requires current QC status `quarantined` with no final decision date or QC officer. The signed source snapshot binds the genuine credit and physical source evidence without inventing a stock value. A dedicated finance proposer submits evidence and a different authorized reviewer independently approves **documentary hold only**, moving the plan to `held` and the case to `resolved`. **No GL journal** or financial asset amount is produced. A changed later QC result needs a new source-fingerprinted case.
- Scrapped returns require current QC status `scrapped`, a real independent QC decision maker different from the registrar, a signed reference and notes, and a valid original QC scrap date no earlier than customer credit/invoice. There must have been no physical saleable restock, no original inventory-cost recovery and no earlier scrap-loss reclassification for this same original returned portion.
- A scrapped return's original invoice COGS must **already exist** as a posted, unreversed original-currency `Dr COGS / Cr finished inventory asset` journal tied to the real manufacturing batch and approved ledger policy. A scrapped-return case **cannot capitalize a previously uncapitalized batch**, create missing invoice COGS, or assume estimated manufacturing values.

## Original-currency loss source valuation

- Sale COGS is the independent source, **never customer selling price or guessed FX**. The reclassified fraction equals actual returned/scrapped package count multiplied by original sold invoice COGS divided by originally invoiced packages, to 4 decimal places with the existing BigDecimal rounding policy.
- Earlier approved original-currency `production_return_cost_postings` (independently QC-released goods) and posted scrapped-return reclassifications for the same invoice are validated and reserved before another portion can be expensed or returned to stock. The last fully credited portion takes the exact remaining 4-decimal original sale COGS value. Returned plus scrapped packages and their total source-cost value cannot exceed the original sold inventory.
- Each earlier source journal must remain posted, unreversed, correctly mapped and chronologically consistent. Any source fingerprint, prior recognized fraction or authorized ledger change invalidates the signed financial proposal.
- Original period for a scrap loss journal is its signed QC scrap date, and the period must be OPEN. The shared period gate prevents closing during a posted reclassification. Original COGS must precede the scrap date. Closed historical periods are **not reopened automatically**.

## Independent authorization and posting

1. Source discrepancy requester and an independent case reviewer approve the original QC/customer credit/physical records without posting a journal.
2. Separate finance proposer submits exact treatment, source evidence and the approved loss ledger (only for genuinely scrapped goods). The loss account must be active, an expense posting account, different from sales COGS/WIP/finished/raw asset ledgers and not mapped to customer or supplier accounts.
3. A separate finance reviewer re-reads the frozen original customer/COGS and QC records and approves or rejects the exact proposed original-currency amount/ledger and date. The proposer/reviewer must be different from the original case actors, the customer credit-note author and original QC registrar/decider.
4. **Quarantine:** Independent reviewer records `held` with the case resolved as documentary evidence only; there is no posting action.
5. **Scrap:** A third, independent authorized finance executor holds the period-posting gate, repeats every source/account/cost verification and posts a balanced `Dr separately authorized damaged-returns loss expense / Cr original invoice's sale COGS`, in the original factory currency and actual open QC scrap month. The journal and source case resolve **in one DB transaction**; any failed check rolls back every new entry.
6. Unique source-case and source-quarantine+treatment constraints prevent repeated hold/scrap expense proposals without audited amendments. Generic standalone GL reversal of posted reclassification journals is prohibited.
7. Original customer credit, refunds, sales revenue, receivables, original invoice COGS journal, QC status/decision and physical warehouse amounts are unchanged.

## Reports, month close and limitations

- QC return register now distinguishes a documented quarantine hold, pending independent scrap decision and posted loss reclassification with exact original-currency journal reference.
- Manufacturing financial reconciliation and CSV now show original **gross sale COGS**, returned-asset COGS recovery, separately **reclassified scrapped-return loss**, and **net sales COGS** after those source credits. Scrapped packages are tracked for expense presentation but never added to saleable physical packages or asset value.
- Finance period closing independently validates every posted scrap loss's real source invoice line/COGS, independently QC-signed original quantity, credit record, posting date, currency, amount, original cost policy, exactly two correct GL journal lines and absence of phantom stock movements. Independently reviewed documentary holds must have **no** monetary journal or cost asset. The close warns (does not automatically expense) about QC-scrapped returns still in genuine original sales COGS.
- **Uncapitalized batches and missing original sale COGS:** Scrap reclassification is blocked until a separate controlled source WIP/finished-inventory and real invoice COGS recovery exists. Interleaving scrap with historically uncapitalized batch reconstruction requires an independently verified chronological executor, still pending.
- **QC quarantine:** No saleable return, inventory capitalization, loss expense or COGS adjustment is permitted before independent final QC disposition.
- **Customer credit without genuine physical returned goods, changed original source price/credit journal, unknown FIFO costs, shared/inconsistent physical dispatches, closed months, manually booked unlinked historic loss and previously separately recognized returned cost** remain blocked or require a different audited correction.
- Rejected source proposals and retrospective edits require a separately authorized amendment procedure. This workflow does not infer legal tax treatment, destruction certificates, insurance recoveries, supplier claims or customer refunds.

**This is source-development progress only. Accounting correctness and runtime behavior remain unverified until authorized migrations, tests and financial UAT.**
