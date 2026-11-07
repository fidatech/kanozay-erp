<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
 public function up():void {
  Schema::create('finance_posting_gates',function(Blueprint $table){
   $table->id();$table->timestamps();
  });
  DB::table('finance_posting_gates')->insert(['id'=>1,'created_at'=>now(),'updated_at'=>now()]);
  Schema::create('finance_period_closures',function(Blueprint $table){
   $table->id();$table->date('period_start')->unique();
   $table->string('status',24)->default('closed');
   $table->unsignedInteger('journal_count');
   $table->decimal('debit_snapshot',24,4);$table->decimal('credit_snapshot',24,4);
   $table->string('authorization_reference',160);
   $table->text('closing_note');
   $table->foreignId('closed_by')->constrained('users')->restrictOnDelete();
   $table->timestamp('closed_at');
   $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
   $table->timestamp('reopened_at')->nullable();
   $table->string('reopening_reference',160)->nullable();
   $table->text('reopening_note')->nullable();
   $table->timestamps();
  });
  Schema::create('finance_period_closure_events',function(Blueprint $table){
   $table->id();$table->foreignId('finance_period_closure_id')
    ->constrained('finance_period_closures',indexName:'period_closure_event_fk')->restrictOnDelete();
   $table->string('action',20);
   $table->string('reference',160);
   $table->text('notes');
   $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
   $table->timestamps();
  });
 }
 public function down():void {
  Schema::dropIfExists('finance_period_closure_events');
  Schema::dropIfExists('finance_period_closures');
  Schema::dropIfExists('finance_posting_gates');
 }
};