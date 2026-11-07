<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('finished_good_recall_contacts',function(Blueprint $table){
   $table->id();
   $table->foreignId('production_batch_id')->constrained('production_batches',indexName:'fg_recall_batch_fk')->restrictOnDelete();
   $table->foreignId('sales_invoice_line_id')->unique()->constrained('sales_invoice_lines',indexName:'fg_recall_invoice_line_fk')->restrictOnDelete();
   $table->string('status',24)->default('pending');
   $table->string('method',24)->nullable();
   $table->string('evidence_reference',180)->nullable();
   $table->text('notes')->nullable();
   $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
   $table->timestamp('contacted_at')->nullable();
   $table->timestamps();
   $table->index(['production_batch_id','status'],'fg_recall_status_idx');
  });
  Schema::create('finished_good_recall_contact_events',function(Blueprint $table){
   $table->id();
   $table->foreignId('finished_good_recall_contact_id')->constrained('finished_good_recall_contacts',indexName:'fg_recall_event_parent_fk')->restrictOnDelete();
   $table->string('status',24);
   $table->string('method',24)->nullable();
   $table->string('evidence_reference',180);
   $table->text('notes')->nullable();
   $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
   $table->timestamps();
  });
 }
 public function down():void {
  Schema::dropIfExists('finished_good_recall_contact_events');
  Schema::dropIfExists('finished_good_recall_contacts');
 }
};