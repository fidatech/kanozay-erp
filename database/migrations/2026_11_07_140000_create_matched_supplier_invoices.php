<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void {
        Schema::create('matched_supplier_invoices',function(Blueprint $table){
            $table->id();
            $table->string('number',42)->unique();
            $table->uuid('submission_token')->unique();
            $table->foreignId('supplier_id')->constrained('trade_parties')->restrictOnDelete();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->foreignId('goods_receipt_id')->constrained('goods_receipts')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->string('supplier_invoice_number',100);
            $table->date('invoice_date');$table->date('due_date');
            $table->decimal('total',20,4);
            $table->string('status',20)->default('posted');
            $table->foreignId('inventory_ledger_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->foreignId('trade_document_id')->unique()->constrained('trade_documents')->restrictOnDelete();
            $table->foreignId('journal_entry_id')->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['supplier_id','supplier_invoice_number'],'supplier_invoice_reference_unique');
            $table->index(['goods_receipt_id','invoice_date']);
        });
        Schema::create('matched_supplier_invoice_lines',function(Blueprint $table){
            $table->id();
            $table->foreignId('matched_supplier_invoice_id')->constrained('matched_supplier_invoices')->restrictOnDelete();
            $table->foreignId('goods_receipt_line_id')->constrained('goods_receipt_lines')->restrictOnDelete();
            $table->foreignId('inventory_material_id')->constrained('inventory_materials')->restrictOnDelete();
            $table->decimal('quantity',20,6);
            $table->decimal('unit_price_snapshot',20,4);
            $table->decimal('line_value',20,10);
            $table->timestamps();
            $table->unique(['matched_supplier_invoice_id','goods_receipt_line_id'],'supplier_invoice_receipt_line_unique');
            $table->index('goods_receipt_line_id');
        });
    }
    public function down():void {
        Schema::dropIfExists('matched_supplier_invoice_lines');
        Schema::dropIfExists('matched_supplier_invoices');
    }
};