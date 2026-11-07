<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('manufacturing_equipment',function(Blueprint $t) {
   $t->id();$t->string('code',40)->unique();$t->string('name',180);
   $t->foreignId('fixed_asset_id')->nullable()->unique()->constrained('fixed_assets')->restrictOnDelete();
   $t->decimal('capacity_per_hour',20,4)->nullable();$t->string('capacity_unit',30)->nullable();
   $t->boolean('is_active')->default(true);
   $t->foreignId('created_by')->constrained('users')->restrictOnDelete();$t->timestamps();
  });
  Schema::create('manufacturing_schedules',function(Blueprint $t){
   $t->id();$t->foreignId('production_order_id')->unique()->constrained('production_orders')->restrictOnDelete();
   $t->foreignId('manufacturing_equipment_id')->constrained('manufacturing_equipment')->restrictOnDelete();
   $t->dateTime('starts_at');$t->dateTime('ends_at');$t->string('status',20)->default('planned');
   $t->text('notes')->nullable();
   $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
   $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
   $t->timestamps();$t->index(['manufacturing_equipment_id','starts_at','ends_at'],'manufacturing_schedule_equipment');
  });
  Schema::create('manufacturing_downtime_entries',function(Blueprint $t) {
   $t->id();$t->uuid('submission_token')->unique();
   $t->foreignId('manufacturing_equipment_id');$t->foreign('manufacturing_equipment_id','manuf_downtime_equipment_fk')->references('id')->on('manufacturing_equipment')->restrictOnDelete();
   $t->foreignId('production_order_id')->nullable()->constrained('production_orders')->restrictOnDelete();
   $t->dateTime('started_at');$t->dateTime('ended_at');
   $t->unsignedInteger('duration_minutes');$t->string('cause',40);
   $t->text('description');$t->foreignId('created_by')->constrained('users')->restrictOnDelete();
   $t->timestamps();$t->index(['manufacturing_equipment_id','started_at'],'equipment_downtime_history');
  });
 }
 public function down():void {
  Schema::dropIfExists('manufacturing_downtime_entries');
  Schema::dropIfExists('manufacturing_schedules');
  Schema::dropIfExists('manufacturing_equipment');
 }
};