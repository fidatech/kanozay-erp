<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::table('inventory_stock_movements',function(Blueprint $t){$t->foreignId('company_warehouse_id')->nullable()->constrained('company_warehouses')->restrictOnDelete();});
  Schema::table('goods_receipts',function(Blueprint $t){$t->foreignId('company_warehouse_id')->nullable()->constrained('company_warehouses')->restrictOnDelete();});
 }
 public function down():void {
  Schema::table('goods_receipts',fn(Blueprint $t)=>$t->dropConstrainedForeignId('company_warehouse_id'));
  Schema::table('inventory_stock_movements',fn(Blueprint $t)=>$t->dropConstrainedForeignId('company_warehouse_id'));
 }
};