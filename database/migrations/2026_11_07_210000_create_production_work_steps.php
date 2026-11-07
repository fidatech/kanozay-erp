<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
 public function up():void {
  Schema::create('production_work_steps',function(Blueprint $table){
   $table->id();
   $table->foreignId('production_order_id')->constrained('production_orders',indexName:'production_work_order_fk')->restrictOnDelete();
   $table->foreignId('routing_record_id')->constrained('operational_registers',indexName:'production_work_route_fk')->restrictOnDelete();
   $table->unsignedSmallInteger('step');
   $table->string('operation',180);
   $table->foreignId('manufacturing_equipment_id')->nullable()->constrained('manufacturing_equipment',indexName:'production_work_equipment_fk')->restrictOnDelete();
   $table->unsignedInteger('estimated_minutes')->nullable();
   $table->string('instructions',5000);
   $table->string('status',20)->default('pending');
   $table->timestamp('started_at')->nullable();
   $table->timestamp('completed_at')->nullable();
   $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
   $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
   $table->text('completion_notes')->nullable();
   $table->timestamps();
   $table->unique(['production_order_id','step'],'work_order_stage_unique');
   $table->unique(['production_order_id','routing_record_id'],'work_order_source_unique');
  });
 }
 public function down():void {Schema::dropIfExists('production_work_steps');}
};