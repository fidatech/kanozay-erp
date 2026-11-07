<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
 public function up():void {
  Schema::create('finished_good_warehouse_stocks',function(Blueprint $table){
   $table->id();
   $table->foreignId('production_batch_id')->constrained('production_batches',indexName:'fg_wh_batch_fk')->restrictOnDelete();
   $table->foreignId('company_warehouse_id')->constrained('company_warehouses',indexName:'fg_wh_location_fk')->restrictOnDelete();
   $table->unsignedBigInteger('quantity_packages')->default(0);
   $table->timestamps();
   $table->unique(['production_batch_id','company_warehouse_id'],'fg_warehouse_batch_unique');
  });
  Schema::create('finished_good_warehouse_movements',function(Blueprint $table){
   $table->id();
   $table->foreignId('production_batch_id')->constrained('production_batches',indexName:'fg_wh_move_batch_fk')->restrictOnDelete();
   $table->foreignId('source_warehouse_id')->nullable()->constrained('company_warehouses',indexName:'fg_wh_move_source_fk')->restrictOnDelete();
   $table->foreignId('destination_warehouse_id')->nullable()->constrained('company_warehouses',indexName:'fg_wh_move_dest_fk')->restrictOnDelete();
   $table->string('type',22);
   $table->unsignedBigInteger('quantity_packages');
   $table->string('reference',160)->nullable();
   $table->date('occurred_on');
   $table->uuid('submission_token')->unique();
   $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
   $table->timestamps();
   $table->index(['production_batch_id','occurred_on'],'fg_wh_move_history');
  });
  Schema::table('finished_good_movements',function(Blueprint $table){
   $table->foreignId('company_warehouse_id')->nullable()->constrained('company_warehouses',indexName:'fg_move_warehouse_fk')->restrictOnDelete();
  });
  Schema::table('finished_good_dispatch_lines',function(Blueprint $table){
   $table->foreignId('company_warehouse_id')->nullable()->constrained('company_warehouses',indexName:'fg_dispatch_warehouse_fk')->restrictOnDelete();
  });
  Schema::table('sales_credit_note_lines',function(Blueprint $table){
   $table->foreignId('company_warehouse_id')->nullable()->constrained('company_warehouses',indexName:'fg_credit_warehouse_fk')->restrictOnDelete();
  });
 }
 public function down():void {
  Schema::table('sales_credit_note_lines',fn(Blueprint $table)=>$table->dropConstrainedForeignId('company_warehouse_id'));
  Schema::table('finished_good_dispatch_lines',fn(Blueprint $table)=>$table->dropConstrainedForeignId('company_warehouse_id'));
  Schema::table('finished_good_movements',fn(Blueprint $table)=>$table->dropConstrainedForeignId('company_warehouse_id'));
  Schema::dropIfExists('finished_good_warehouse_movements');
  Schema::dropIfExists('finished_good_warehouse_stocks');
 }
};