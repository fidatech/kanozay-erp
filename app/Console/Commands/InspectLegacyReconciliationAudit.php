<?php
namespace App\Console\Commands;

use App\Models\ReconciliationCaseEvent;
use Illuminate\Console\Command;

class InspectLegacyReconciliationAudit extends Command {
 protected $signature='reconciliation:inspect-legacy-audit';
 protected $description='Inventory unsigned historical audit events without altering historical evidence';
 public function handle():int {
  $unsigned=ReconciliationCaseEvent::query()->whereNull('event_hash')
   ->selectRaw('reconciliation_case_id, COUNT(*) as events, MIN(id) as first_event, MAX(id) as last_event')
   ->groupBy('reconciliation_case_id')->orderBy('reconciliation_case_id')->get();
  $this->table(['Case ID','Unsigned events','First event','Last event'],
   $unsigned->map(fn($row)=>[$row->reconciliation_case_id,$row->events,$row->first_event,$row->last_event])->all());
  $this->line('Unsigned cases: '.$unsigned->count().'. No records were modified.');
  return $unsigned->isEmpty()?self::SUCCESS:self::FAILURE;
 }
}
