<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
 public function up():void {
  Schema::create('sales_delivery_tracking_events',function(Blueprint $t){
   $t->id();$t->uuid('submission_token')->unique();
   $t->foreignId('sales_delivery_note_id')->constrained('sales_delivery_notes',indexName:'delivery_tracking_note_fk')->restrictOnDelete();
   $t->string('status',24);
   $t->dateTime('occurred_at');$t->string('location',180);
   $t->string('proof_reference',180)->nullable();$t->string('receiver_name',150)->nullable();
   $t->text('notes')->nullable();
   $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
   $t->timestamps();$t->index(['sales_delivery_note_id','occurred_at'],'delivery_event_time_idx');
  });
 }
 public function down():void {Schema::dropIfExists('sales_delivery_tracking_events');}
};