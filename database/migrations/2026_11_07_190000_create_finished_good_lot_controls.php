<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('finished_good_lot_controls',function(Blueprint $table){
   $table->id();
   $table->foreignId('production_batch_id')->unique()->constrained('production_batches',indexName:'fg_lot_control_batch_fk')->restrictOnDelete();
   $table->date('manufactured_on')->nullable();
   $table->date('expires_on')->nullable();
   $table->string('status',20)->default('active');
   $table->string('traceability_reference',160)->nullable();
   $table->text('risk_notes')->nullable();
   $table->foreignId('registered_by')->constrained('users')->restrictOnDelete();
   $table->foreignId('last_decided_by')->nullable()->constrained('users')->nullOnDelete();
   $table->timestamps();
   $table->index(['status','expires_on'],'fg_lot_status_expiry');
  });
  Schema::create('finished_good_lot_control_events',function(Blueprint $table){
   $table->id();
   $table->foreignId('finished_good_lot_control_id')->constrained('finished_good_lot_controls',indexName:'fg_lot_event_control_fk')->restrictOnDelete();
   $table->string('action',22);
   $table->string('previous_status',20)->nullable();
   $table->string('new_status',20);
   $table->text('reason');
   $table->string('authorization_ref',180);
   $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
   $table->timestamps();
   $table->index(['finished_good_lot_control_id','id'],'fg_lot_event_idx');
  });
 }
 public function down():void {
  Schema::dropIfExists('finished_good_lot_control_events');
  Schema::dropIfExists('finished_good_lot_controls');
 }
};