<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::table('reconciliation_cases',function(Blueprint $t){
   $t->decimal('approved_exposure',20,4)->nullable();
   $t->decimal('approved_ledger',20,4)->nullable();
   $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
   $t->timestamp('approved_at')->nullable();
  });
  Schema::create('reconciliation_case_events',function(Blueprint $t){
   $t->id();$t->foreignId('reconciliation_case_id')->constrained()->cascadeOnDelete();
   $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
   $t->string('action',40);$t->string('old_status',20)->nullable();$t->string('new_status',20);
   $t->text('notes')->nullable();$t->timestamps();
  });
 }
 public function down():void {
  Schema::dropIfExists('reconciliation_case_events');
  Schema::table('reconciliation_cases',function(Blueprint $t){
   $t->dropConstrainedForeignId('approved_by');
   $t->dropColumn(['approved_exposure','approved_ledger','approved_at']);
  });
 }
};