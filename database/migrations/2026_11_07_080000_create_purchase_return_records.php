<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void
    {
        Schema::create('purchase_return_records',function(Blueprint $table){
            $table->id();
            $table->string('number',42)->unique();
            $table->uuid('submission_token')->unique();
            $table->foreignId('goods_receipt_id')->constrained('goods_receipts')->restrictOnDelete();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->foreignId('company_warehouse_id')->nullable()->constrained('company_warehouses')->restrictOnDelete();
            $table->date('returned_on');
            $table->string('supplier_reference',120)->nullable();
            $table->string('status',30)->default('posted_pending_credit');
            $table->string('financial_status',40)->default('supplier_credit_pending');
            $table->text('reason');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['goods_receipt_id','returned_on']);
        });
        Schema::create('purchase_return_record_lines',function(Blueprint $table){
            $table->id();
            $table->foreignId('purchase_return_record_id')->constrained('purchase_return_records')->restrictOnDelete();
            $table->foreignId('goods_receipt_line_id')->constrained('goods_receipt_lines')->restrictOnDelete();
            $table->foreignId('inventory_material_id')->constrained('inventory_materials')->restrictOnDelete();
            $table->decimal('quantity',20,6);
            $table->decimal('unit_price_snapshot',20,4)->nullable();
            $table->decimal('supplier_credit_value_snapshot',20,10)->nullable();
            $table->timestamps();
            $table->unique(['purchase_return_record_id','goods_receipt_line_id'],'purchase_return_line_unique');
        });
    }
    public function down():void
    {
        Schema::dropIfExists('purchase_return_record_lines');
        Schema::dropIfExists('purchase_return_records');
    }
};