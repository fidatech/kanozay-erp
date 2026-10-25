<?php
namespace App\Models\Concerns;
use App\Services\Accounting\EntityAccountProvisioner;
/** Opt-in for future customer/supplier/bank/sarafi/shareholder models. */
trait ProvisionsLedgerAccounts {
 public function provisionLedgerAccountsForCurrency(int $currencyId):array {
  if(!$this->exists)throw new \LogicException('Save the entity before provisioning its ledger accounts.');
  return app(EntityAccountProvisioner::class)->provision($this->ledgerEntityType(),(int)$this->getKey(),$this->ledgerEntityName(),$currencyId);
 }
 abstract protected function ledgerEntityType():string;
 abstract protected function ledgerEntityName():string;
}
