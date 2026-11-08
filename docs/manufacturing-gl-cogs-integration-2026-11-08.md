# Kanozay ERP — Manufacturing-to-GL cost and COGS integration

Date: 2026-11-08  
Branch: `development/kanozay-completion`  
Status: **Source changes committed, NOT migrated, tested or deployed.**

## Why this development is necessary

The earlier physical flows recorded material FIFO issues, reviewed production-batch cost snapshots, released finished stock, posted customer invoice revenue and tracked customer credit-note returns. However, those flows did **not** consistently transfer historical cost from raw-material inventory through manufacturing WIP to the finished-goods asset, or from finished stock to the COGS expense ledger at sales dispatch.

Recording only the production batch's indicative cost without an offsetting GL journal could produce a balance sheet with overstated materials, missing finished-goods assets and incorrect gross profit. The implementation therefore uses source-backed original-currency bookkeeping and strictly blocks unsupported historic records.

## Source changes

### Reviewed, original-currency GL policy

- New `manufacturing_cost_ledger_policies` table stores one controlled mapping per factory costing currency for material/packaging inventory (asset), WIP (asset), finished-goods inventory (asset) and COGS (expense).
- All four must be distinct, active non-group ledgers, separate from entity-specific customer, supplier or cashbox mappings.
- A finance/manufacturing-authorized initiator proposes the original account mapping with a signed reference and explanatory notes. A **different** authorized reviewer must approve it. An approved mapping cannot be silently amended or replaced.
- The approved source currency is independent of a later customer's invoice currency. The system performs no FX conversion to calculate cost of sales.

### Original-source production capitalization

- New `production_batch_cost_postings` table links one QC-released production batch to its reviewed frozen cost, original source currency, accepted package count and two immutable posted journal references.
- The batch must have one material-cost currency, positive valued FIFO amounts, no unvalued movements and no still-submitted manufacturing overhead.
- Each original ingredient/packaging FIFO consumption must reconcile to the posted physical issue quantity and its frozen cost. Its incoming GRN source must be fully matched to independently **posted supplier invoice inventory asset debits** in the same currency and raw-material ledger.
- Original opening-stock lots or other unverified material source layers require a separate documented GL opening-value reconciliation; they are not treated as purchased stock without evidence.
- A FIFO source with unposted landed-cost allocations is blocked pending proper source expense/accounting reconciliation. The software does not invent customs, freight, tax, landed-cost or overhead journal entries.
- Only explicitly approved original-currency source-expense overhead allocations are considered, after verifying the source debit, original journal posting and review status. Their credited expense accounts are the original approved sources.
- The raw-material asset GL balance must cover the value being transferred as of the posting date. Cost capitalization cannot be dated before the physical finished-goods receipt, used source supplier invoice or reviewed overhead source, and cannot be dated after a historical sale already recorded for the batch.
- Current formal finished-product QC and upstream material lot holds are rechecked at capitalization.
- The first balanced journal debits WIP and credits the original raw-material inventory asset and reviewed overhead expense allocations. The second debits the finished-goods inventory asset and credits WIP, in the same original currency. Both are posted in a single transaction or neither is saved.
- The manufacturing accounting page records source details, posting dates, reviewer evidence, original source currency and both journal numbers.

### Original-currency sales COGS

- New `production_invoice_cogs_postings` links a unique sales invoice line to the previously capitalized production batch and its original-currency COGS journal.
- New customer invoices cannot dispatch uncapitalized finished goods, even if the goods have passed physical QC. Sales and COGS journals remain in one transaction; failure rolls back revenue and physical stock movements.
- Each original-currency COGS entry debits the approved COGS expense account and credits the finished-goods inventory asset.
- Cost is apportioned to dispatched packages using frozen, posted total batch value and the original accepted package count, with four-decimal journal precision. The final net dispatch receives the remaining rounding difference so the batch cannot be expensed beyond its posted value.
- Posted net sales are reduced only by independently released, previously cost-reversed returned package quantities. Historical non-saleable returns never increase saleable inventory cost automatically.
- A source-currency finished-goods GL balance is checked before every dispatched COGS journal. Backdated sales cannot be inserted before later posted cost movements for the same batch.
- Existing posted invoices can receive missing COGS only through a restricted finance action **if the original batch capitalization was already posted on or before the original invoice date**. Unsupported retrospective inventory costing is not fabricated.

### Customer returns and original COGS recovery

- New `production_return_cost_postings` links a unique QC-return quarantine decision to the source sales-invoice COGS line and its immutable inventory recovery journal.
- A customer credit note does not immediately recognize saleable inventory cost. An independent reviewer must release the physical returned product to stock.
- At release, the new journal debits the original-currency finished-goods asset and credits the original COGS account for the physically returned packages, preserving the original source cost basis.
- The journal and warehouse stock release are atomic. An unreconciled historical sale without original COGS cannot be released to saleable stock until finance reviews and corrects the missing cost evidence.
- Scrapped or still-held returns do not restore finished inventory assets. Return recovery cannot predate its customer credit or follow a backdated valuation sequence inconsistent with later posted costs.
- Credit-note, warehouse and independent QC evidence remain separately traceable; the return line records one cost-recovery journal at most.

### Financial controls and visibility

- Generic GL reversal is blocked for source-linked manufacturing WIP/finished assets, sales COGS and QC-return cost journals. These require separate approved physical and financial correction documents.
- Manufacturing overhead may not be submitted or approved after its batch has been financially capitalized.
- The invoice page shows restricted finance-only source currency, per-line COGS, GL references and unreconciled legacy entries.
- The customer-return quarantine page shows restricted finance-only asset recovery and missing legacy COGS evidence.
- The production cost-readiness screen differentiates advisory cost snapshots from actually posted finished-goods assets. The Manufacturing menu includes the accounting workflow, with Dari and Pashto menu labels.
- Month-close checks flag unfinished physical finished-goods capitalization, posted invoices lacking COGS, saleable returns lacking inventory recovery, detached legacy dispatches, invalid cost journals, pending overhead and per-currency journal imbalances.

## Remaining work and business decisions

- **Policy approval required:** Kanozay must confirm the correct income/revenue transfer date, COGS recognition at dispatch versus customer acceptance, treatment of spoilage/scrap, depreciation, labor and factory overhead allocation, applicable taxes and exchange-rate policy. Code does not invent these choices.
- **Opening and landed-cost reconciliation:** Old inventory value, opening material lots, GRNs containing freight/customs allocations and pooled supplier expense postings cannot be automatically matched to posted GL assets without historical evidence and a designated correction workflow.
- **Multi-currency cost:** Batches with mixed source currencies need an approved FX capitalization policy and an auditable rate snapshot before they can be placed in a unified finished inventory and COGS ledger. The new workflow blocks them rather than using today's market rate.
- **Historic invoice exceptions:** A previously dispatched batch with absent or later-dated capitalization cannot simply be backdated into closed periods. It needs independently reviewed opening adjustments, source reconstruction or a documented prior-period correcting entry.
- **Cost audit:** Full manufacturing wastage/scrap, quality yield, waste-value treatment, recipe variance, precision/rounding policy and historical customer-return corrections remain to be integrated with the approved factory business rules.
- **Access review:** Cost amounts are finance/reporting restricted on invoices and QC-return registers, but the full implementation requires a comprehensive transaction-by-transaction role and confidentiality review.
- **Performance and GL reconciliation:** Running cost-ledger valuations and backdated source joins require measured query performance, both SQLite and MySQL schema compatibility and independent verification against sample cost ledgers.
- **Translations:** Manufacturing cost-accounting navigation is translated into Dari and Pashto; its newer detailed accounting forms still need reviewed translations and RTL alignment.

## Release boundary

At the user's request, **no migrations, automated tests, browser UAT, MySQL data changes, deployment, main branch merge or final ZIP release were performed**. The source code was committed to the development branch. It must be migrated to staging and validated with actual signed production cost books, accounting balances, roles, inventory history and the approved company costing policy before activating new automatic COGS posting on live sales.
