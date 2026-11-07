<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
 public function up():void
 {
  Schema::create('operational_registers',function(Blueprint $t){
   $t->id();
   $t->string('workflow',72)->index();
   $t->string('number',40)->unique();
   $t->uuid('submission_token')->unique();
   $t->json('data');
   $t->string('status',24)->default('draft')->index();
   $t->text('review_note')->nullable();
   $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
   $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
   $t->timestamp('reviewed_at')->nullable();
   $t->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
   $t->timestamp('closed_at')->nullable();
   $t->timestamps();
   $t->index(['workflow','created_at'],'operational_workflow_created');
  });
  Schema::create('operational_register_events',function(Blueprint $t){
   $t->id();
   $t->foreignId('operational_register_id')->constrained('operational_registers','operational_event_record_fk')->restrictOnDelete();
   $t->string('action',30);
   $t->json('snapshot')->nullable();
   $t->text('note')->nullable();
   $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
   $t->timestamps();
   $t->index(['operational_register_id','created_at'],'operational_event_history');
  });
 }
 public function down():void
 {
  Schema::dropIfExists('operational_register_events');
  Schema::dropIfExists('operational_registers');
 }
};
