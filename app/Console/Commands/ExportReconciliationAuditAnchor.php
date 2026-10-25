<?php
namespace App\Console\Commands;

use App\Models\ReconciliationCaseEvent;
use Illuminate\Console\Command;

class ExportReconciliationAuditAnchor extends Command {
 protected $signature='reconciliation:export-audit-anchor {path : Destination outside the application and its database backups}';
 protected $description='Export current reconciliation audit chain heads to an independently stored JSON anchor';
 public function handle():int {
  $path=$this->argument('path');
  if(file_exists($path)){$this->error('Refusing to overwrite an existing audit anchor.');return self::FAILURE;}
  $cases=[];
  foreach(ReconciliationCaseEvent::query()->orderBy('id')->cursor() as $event){
   if(!$event->event_hash){$this->error('Unsigned historical events exist; cannot establish a trusted anchor.');return self::FAILURE;}
   $cases[(string)$event->reconciliation_case_id]=$event->event_hash;
  }
  $payload=json_encode(['version'=>1,'exported_at'=>now()->toIso8601String(),'cases'=>$cases],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
  if(file_put_contents($path,$payload,LOCK_EX)===false){$this->error('Could not write anchor.');return self::FAILURE;}
  $this->info('Anchor exported. Transfer it to protected independent storage and verify with --anchor.');
  return self::SUCCESS;
 }
}
