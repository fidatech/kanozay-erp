<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('sales_return_quarantines',function(Blueprint $t){
   $t->id();
   $t->foreignId('sales_credit_note_line_id')->unique()
    ->constrained('sales_credit_note_lines',indexName:'return_quarantine_note_line_fk')->restrictOnDelete();
   $t->foreignId('production_batch_id')->constrained('production_batches')->restrictOnDelete();
   $t->foreignId('received_warehouse_id')->nullable()
    ->constrained('company_warehouses',indexName:'return_quarantine_received_warehouse_fk')->restrictOnDelete();
   $t->foreignId('released_warehouse_id')->nullable()
    ->constrained('company_warehouses',indexName:'return_quarantine_released_warehouse_fk')->restrictOnDelete();
   $t->unsignedInteger('quantity_packages');
   $t->string('status',24)->default('quarantined')->index();
   $t->foreignId('registered_by')->constrained('users')->restrictOnDelete();
   $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
   $t->date('decided_on')->nullable();$t->string('quality_reference',160)->nullable();
   $t->text('decision_notes')->nullable();
   $t->timestamps();
  });
 }
 public function down():void {Schema::dropIfExists('sales_return_quarantines');}
};