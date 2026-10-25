<?php
namespace App\Console\Commands;

use App\Models\ReconciliationCaseEvent;
use Illuminate\Console\Command;

class VerifyReconciliationAudit extends Command {
 protected $signature='reconciliation:verify-audit {--case= : Restrict to a case ID}';
 protected $description='Verify reconciliation event HMAC chain integrity (cannot detect deletion of the final event without an external anchor)';
 public function handle():int {
  $query=ReconciliationCaseEvent::query()->orderBy('reconciliation_case_id')->orderBy('id');
  if($this->option('case')) $query->where('reconciliation_case_id',$this->option('case'));
  $previous=[];$checked=0;$invalid=0;
  foreach($query->cursor() as $event){
   $case=$event->reconciliation_case_id;
   $expectedPrevious=$previous[$case]??null;
   $valid=$event->event_hash !== null
     && hash_equals((string)$expectedPrevious,(string)$event->previous_hash)
     && hash_equals(ReconciliationCaseEvent::signature($event,$event->previous_hash),$event->event_hash);
   if(!$valid){$invalid++;$this->error("Invalid or unsigned audit event #{$event->id} (case {$case})");}
   $previous[$case]=$event->event_hash;$checked++;
  }
  $this->line("Checked {$checked} events; {$invalid} invalid.");
  return $invalid===0?self::SUCCESS:self::FAILURE;
 }
}
