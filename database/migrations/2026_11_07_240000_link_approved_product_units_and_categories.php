<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::table('inventory_materials',function(Blueprint $table){
   $table->foreignId('catalog_unit_id')->nullable()->constrained('operational_registers',indexName:'raw_material_unit_fk')->restrictOnDelete();
   $table->foreignId('catalog_category_id')->nullable()->constrained('operational_registers',indexName:'raw_material_category_fk')->restrictOnDelete();
  });
  Schema::table('finished_goods',function(Blueprint $table){
   $table->foreignId('catalog_unit_id')->nullable()->constrained('operational_registers',indexName:'finished_good_unit_fk')->restrictOnDelete();
   $table->foreignId('catalog_category_id')->nullable()->constrained('operational_registers',indexName:'finished_good_category_fk')->restrictOnDelete();
  });
 }
 public function down():void {
  Schema::table('finished_goods',function(Blueprint $t){
   $t->dropConstrainedForeignId('catalog_category_id');$t->dropConstrainedForeignId('catalog_unit_id');
  });
  Schema::table('inventory_materials',function(Blueprint $t){
   $t->dropConstrainedForeignId('catalog_category_id');$t->dropConstrainedForeignId('catalog_unit_id');
  });
 }
};