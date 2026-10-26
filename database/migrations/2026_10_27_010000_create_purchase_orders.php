<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('purchase_orders',function(Blueprint $t){
   $t->id();$t->string('number',60)->unique();$t->foreignId('supplier_id')->constrained('trade_parties')->restrictOnDelete();$t->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();$t->string('source',10);$t->date('order_date');$t->date('expected_date')->nullable();$t->string('status',20)->default('draft');$t->text('notes')->nullable();$t->foreignId('created_by')->constrained('users')->restrictOnDelete();$t->timestamps();
  });
  Schema::create('purchase_order_items',function(Blueprint $t){
   $t->id();$t->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();$t->string('description',255);$t->decimal('quantity',18,4);$t->decimal('unit_price',20,4);$t->timestamps();
  });
 }
 public function down():void {Schema::dropIfExists('purchase_order_items');Schema::dropIfExists('purchase_orders');}
};
