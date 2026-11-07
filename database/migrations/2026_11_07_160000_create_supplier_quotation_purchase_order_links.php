<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('supplier_quotation_conversions',function(Blueprint $table){
   $table->id();
   $table->foreignId('operational_register_id')->unique()
    ->constrained('operational_registers',indexName:'supplier_quote_record_fk')->restrictOnDelete();
   $table->foreignId('purchase_order_id')->unique()
    ->constrained('purchase_orders',indexName:'supplier_quote_purchase_fk')->restrictOnDelete();
   $table->uuid('submission_token')->unique();
   $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
   $table->timestamps();
  });
 }
 public function down():void {Schema::dropIfExists('supplier_quotation_conversions');}
};