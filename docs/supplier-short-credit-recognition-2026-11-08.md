# Source-matched supplier short-credit settlement (development only)

The historical supplier-return financial correction now has a three-step accounting workflow:

1. A finance proposer chooses the **original posted, line-matched supplier invoice**, a source-document reference, original-currency posting date, and a distinct claim-asset or authorized-loss-expense ledger. Signed evidence must support the selected recognition treatment. This only submits a proposal.
2. A separate authorized reviewer approves or rejects the amount, original evidence, selected accounts and intended treatment. No journal is created at review.
3. An independently authorized executor re-checks immutable GRN return line quantities, original purchase-price snapshots, the supplier's signed offered credit, posted unreversed invoice journals, payable residual and accounting period; only then can the original-currency correction post.

The posted GL is **Dr supplier payable (actual accepted credit), Dr approved claim asset or authorized nonrecoverable loss (documented difference), Cr original matched supplier inventory asset (full return base purchase value)**. For zero-credit supplier refusals, the payable debit and trade-document credit entry are omitted.

The workflow links its posting to a unique historical case, dispute, physical return and journal, adds a partial actual supplier payable credit where applicable, records an append-only case-resolution event, and blocks independent reversal of that system journal. It does not move physical stock, infer foreign exchange or assume a supplier debt is legally enforceable without documentary evidence. Unmatched legacy supplier payables, unsupported pricing and unresolved disputes cannot use this posting pathway.

**Unfinished:** historical standalone dispatch purpose-specific GL posting; consumed/uncertain opening FIFO cost corrections; amendments to rejected settlement proposals; complete cross-module reconciliation; Pashto/Dari transaction translations; final UAT and release checks. The implementation has been committed as source only; migrations, lint, tests, browser UAT and deployment remain deferred at user instruction.
