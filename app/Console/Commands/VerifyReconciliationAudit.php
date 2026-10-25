<?php
namespace App\Console\Commands;

use App\Models\ReconciliationCaseEvent;
use Illuminate\Console\Command;

class VerifyReconciliationAudit extends Command {
 protected $signature='reconciliation:verify-audit {--case= : Restrict to a case ID} {--anchor= : Verify against an externally stored anchor JSON file}';
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
  if($this->option('anchor')){
   $path=$this->option('anchor');
   if(!is_file($path)||!is_readable($path)){
    $this->error('Audit anchor file is missing or unreadable.');return self::FAILURE;
   }
   try {$anchor=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);}
   catch(\JsonException $exception){$this->error('Invalid audit anchor JSON.');return self::FAILURE;}
   if(!is_array($anchor)||!isset($anchor['cases'])||!is_array($anchor['cases'])){
    $this->error('Invalid audit anchor format.');return self::FAILURE;
   }
   foreach($anchor['cases'] as $caseId=>$expected){
    $actual=$previous[$caseId]??null;
    if(!is_string($expected)||!is_string($actual)||!hash_equals($expected,$actual)){
     $invalid++;$this->error("Audit anchor mismatch for case {$caseId}");
    }
   }
  }
  $this->line("Checked {$checked} events; {$invalid} invalid.");
  return $invalid===0?self::SUCCESS:self::FAILURE;
 }
}
