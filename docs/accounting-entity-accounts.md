# Chart of Accounts and currency-specific entity accounts

Run `php artisan db:seed --class=ChartOfAccountsSeeder` after migrations. The seeder is repeatable, preserves existing account names and does not create balances or transactions. It creates manufacturing account groups and standard posting accounts. Account codes are globally unique.

`App\Services\Accounting\EntityAccountProvisioner::provision($type, $id, $name, $currencyId)` provisions the appropriate subaccounts and records an immutable entity/currency/purpose mapping in `entity_ledger_accounts`. Supported types: `customer`, `supplier`, `bank`, `sarafi`, `cashbox`, `shareholder`, `employee`. The requested currency must already exist and be active. Provisioning is idempotent and transactional; never use this service to create exchange-rate conversions or financial postings.

## Required integration when operational entity models are implemented

The project does not yet contain Customer, Supplier, Bank, Sarafi or Shareholder CRUD models/controllers. For each future model, call the service **after saving the entity**, within the same DB transaction as the entity creation, and once per configured currency. Alternatively, add the `ProvisionsLedgerAccounts` trait and implement `ledgerEntityType()` and `ledgerEntityName()`, then call `provisionLedgerAccountsForCurrency($currencyId)` after the model is saved. When a new currency is enabled for an existing entity, call it for that currency too. Do not create accounts for every currency unless the entity is enabled to transact in those currencies. Avoid posting directly to parent/group accounts.

Examples:

- Customer AFN/USD: receivable under `1121`, customer advance under `2120` for each currency.
- Supplier: payable under `2111`, supplier advance under `1131`.
- Bank: cash under `1112`; Sarafi: cash under `1113`; Cashbox: cash under `1111`.
- Shareholder: capital under `3110`, loan under `2220`, drawings under `3410`.
- Employee: salary payable under `2131`, advance under `1132`.

The mapping does not yet post journals or enforce currency exchange accounting. Account numbers are deterministic and unique per entity type, identifier, currency and purpose. Deleting operational entities should be restricted when associated with accounting records; deactivate instead. Future entity CRUD should also propagate safe display-name changes to linked account labels without altering codes or history.
