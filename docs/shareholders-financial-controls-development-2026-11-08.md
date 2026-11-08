# Kanozay ERP — Shareholders and Financial Controls development

Date: 2026-11-08  
Branch: `development/kanozay-completion`  
Status: **Source implemented, new migrations and runtime acceptance explicitly deferred.**

## Shareholder board governance

- Added `shareholder_distribution_allocations` and append-only adjustment events for each shareholder's documented amount authorized by a specific distribution resolution.
- Each allocation uses the resolution's original currency and must be positive, linked to an enabled recipient and recorded while the board resolution remains draft.
- Sum of recipient entitlements cannot exceed the distribution resolution ceiling. The resolution cannot be independently approved without at least one recipient allocation.
- The person who created the resolution cannot approve it; the latest person to enter or edit a recipient allocation cannot be its approving reviewer either.
- Approved allocations are frozen. Each payout must stay within both **its own recipient's remaining authorization** and the **whole resolution's remaining ceiling**.
- Previously approved resolutions lacking allocation evidence cannot newly pay out until a new documented, independently approved distribution allocation resolution is created. The app deliberately does not infer retroactive entitlements from ownership percentages.
- A retained-earnings check uses posted GL account 3200 in the same currency **dated on or before the actual payout date**, not profits posted afterward. A shared finance posting lock serializes checks with other GL posting and period closure.
- Distributions use the document sequence for unique journal identifiers and are recorded as cashbook/bank withdrawal MoneyTransactions alongside balanced journal entries.
- Idempotent reposting is allowed only when the resolution, recipient, payment source, date and amount exactly match the original token.

## Ownership

- Added read-only historical **as-of-date** ownership snapshots assembled from approved ownership amendment events.
- Class totals are shown by share class and currency-independent percent, with explicit warnings when a class is not fully allocated.
- Zero-percent amendments can retire a current interest while preserving original ownership history.
- Percentages from differently cased class labels are combined for cap checking (e.g., `Ordinary` versus `ordinary`), and class amendments cannot be entered with an effective date preceding an already-recorded later amendment.
- Capital deposits do not automatically set ownership percentages; distributions do not automatically use ownership percentages. Both require separate documented board authorization.

## Shareholder capital movements

- Contributions and drawings require both an authorized shareholder role and finance posting role, or an authorized administrator.
- Capital drawings require a documented source authorization reference and cannot exceed posted capital, net of earlier drawings, in that shareholder's original currency as of the transaction date. Already posted capital GL balances are used, without currency conversion or speculative profits.
- The shareholder subledger, GL journal and cashbook/bank MoneyTransaction now post atomically. A direct journal reversal of the shareholder's source financial document is prohibited without a future controlled correction document.
- Idempotency now checks the financial account, amount, currency, type, date, explanation, reference and actor, not merely the cash amount and date.
- Source amounts accept comma grouping and localized Pashto/Dari/Arabic digits before validated numeric precision.

## Shareholder statements

- Added a source-backed, combined original-currency statement covering contributed capital, capital drawings and board-approved profit distributions.
- Activity totals reflect the **selected date range** and explicitly distinguish net contribution/drawing activity from profit payouts; they do not claim to represent legal ownership, retained earnings, FX-converted consolidated capital, or outstanding dividend liabilities.
- The statement provides a 75-row recent activity preview and an export containing all matching posted entries. Old reversed journals are excluded; CSV fields beginning with spreadsheet formula characters are escaped.
- Existing paginated capital subledger history remains available separately.

## Financial month closing

- Added a finance month-close readiness screen with known posting blockers and manual-reconciliation warnings.
- Blocking checks include draft journal entries, draft payroll accruals, overlapping draft bank statement reconciliations, unposted physical fixed-asset disposals, and posted shareholder money movements missing a corresponding cashbook transaction.
- Missing full-month approved bank statement coverage and unmatched acquisition PPE source journals are warned about without inventing external statement data or forcing a guessed accounting entry.
- Only a **fully completed calendar month** may close.
- The finance closer must explicitly acknowledge reviewed source evidence, tax and stock exceptions, and bank reconciliation. The posting gate and blockers are checked again inside the finance-close transaction.
- Existing independent month-reopening and no-posting-in-closed-period safeguards remain.

## Outstanding development and source-document reconciliation

- Review and reconcile historical shareholder resolutions and original board documents, opening share register entries, capitalization dates, old cash movements, direct GL reversals and owner balances.
- A formal document-level correction workflow for capital contributions, owner drawings and profit payouts, including board cancellation/reapproval, is still required. Direct journal reversal remains prohibited.
- Profit entitlement policy across share classes, economic ownership rights, tax withholding and distribution tax treatment must be approved from company documents and applicable law; no rate or entitlement is invented.
- Verified cash sufficiency in every cashbox/bank/Sarafi account, formal treasury controls and source-backed bank balances still require full finance integration.
- Financial-period close does not certify documents never recorded in the ERP. External bank statements, inventory reconciliations, statutory tax filings and physical checks must be reviewed independently.
- The historical cap-table snapshot is a record of saved amendments; its completeness depends on the original signed ownership documents being entered. Early inconsistent historic ownership changes cannot be silently reconstructed.
- This is a source development milestone, not production sign-off.

## Verification boundary

As requested, **no migrations, automated tests, browser UAT, MySQL checks, `main` merge or deployment were performed**. These source changes, especially new financial-control blockers and historical-data guards, require staged migration, reconciliation of legacy records, and representative end-to-end role/accounting checks before production use.
