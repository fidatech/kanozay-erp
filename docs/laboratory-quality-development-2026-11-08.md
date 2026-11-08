# Kanozay ERP — Laboratory and quality-control development

Date: 8 November 2026  
Branch: `development/kanozay-completion`  
Status: **Source committed; migrations, browser/UAT and automated tests deferred at user's request.**

## Implemented in this batch

### Test standards and samples
- New laboratory samples freeze the IDs of the approved specification **versions active when the sample is collected**, stored in `quality_sample_required_specifications`. Later revisions do not rewrite the evidence needed for those new samples.
- Numeric observations and specification bounds accept localized Pashto/Dari digits and comma-separated values ahead of normal validation.
- Duplicate pending samples for the same source are rejected. A rejected sample can be retested with documented reason; a previously approved incoming sample requires an authorized manual lot hold before replacement collection.
- The collection date cannot predate the posted goods receipt or creation of a production batch.
- Independent review requires complete results from the assigned required specifications, rejects failing results, and prevents a sample's collector or tester from approving their own evidence.
- Sample details and certificates identify the specification version and source. Pre-existing historical samples that never froze their test requirements are visibly distinguished; the software does not claim to know their original test versions.

### Incoming raw materials
- Manual receipt-lot holds are tied to original FIFO cost layers and block inventory transfers, production issues and production reservations through the shared quality hold service.
- An authorized independent reviewer must clear the manual hold using separate evidence and a documented reason; the person who placed the hold cannot release it.
- A manual hold cannot be lifted while the latest required QC sample is pending or rejected. Removing the manual hold does not bypass automatic test requirements.
- The incoming-lot register shows current manual status, required sample disposition, traceable downstream batches and immutable hold/release evidence.
- Manual holds continue to protect a lot even when the previous laboratory sample was approved.

### Finished-goods safety
- Where the company has an approved finished-product QC specification, batch acceptance and warehouse release require the most recent independently approved formal sample to pass its required tests.
- This supplements the existing batch laboratory-check workflow instead of manufacturing a company-specific test requirement.
- Finished-product dispatch rechecks whether any traceable source ingredient or packaging FIFO lot became subject to a QC hold **after** warehouse release. Source-affected batches cannot dispatch until QC resolves the source restriction.
- Independent release of a held finished-good lot also checks current source-material holds and formal batch QC evidence.
- These restrictions do not automatically declare a recall. Existing downstream customer-recall controls remain separate.

### Nonconformance / corrective action
- Added source-linked nonconformance records (NCR) for a laboratory sample or incoming FIFO lot.
- Authorized staff can record severity, date, source evidence and deviation; an investigator records root cause and corrective action, and an independent QA reviewer verifies evidence and closes the record.
- Investigation and closure events remain in an append-only evidence register.
- NCR creation and closure do not automatically adjust stock, waive a failed laboratory test, or remove a physical QC hold. A separate documented quality decision is still necessary.

## Remaining development and source-data dependencies

- Historical samples lack frozen specification requirements. Accurate retrospective certificates require reviewed historical lab/source records; never retroactively assign new test specifications as though they were originally approved.
- Customer-specific microbiological and chemical tests, calibration rules, sampling acceptance plans, expiry policies and unit-specific tolerances must be confirmed from approved factory documents.
- Lot-level disposition workflow for scrap/rework and rejected materials, with independently authorized write-offs and accounting entries, remains to be integrated.
- Automated recall outreach and verified recovery quantities for already dispatched affected lots need a documented procedure. A source QC hold blocks *future* dispatch from traceable affected batches; it does not recall previously delivered products.
- Full laboratory operating performance, certification/export formats, instrument evidence, vendor quality scoring and historical stock migration remain outstanding.
- Source-to-batch checks depend on correctly reconciled FIFO consumptions, and existing legacy records may lack that provenance.

## Verification boundary

At the user's request, no automated tests, migrations, web UI trials, MySQL checks or deployment were performed during this batch. Code commits establish only that source changes were written to the development branch. They are not a guarantee of runtime correctness, validated company procedures or production acceptance. Applying the new migrations and reconciling historical inventory and laboratory history must precede enabling the stricter QC gates on a live database.
