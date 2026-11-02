<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sales_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->uuid('submission_token')->unique();
            $table->foreignId('customer_id')->constrained('trade_parties')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->string('currency_code', 3);
            $table->date('invoice_date');
            $table->date('due_date');
            $table->string('status', 16)->default('draft');
            $table->decimal('subtotal', 24, 4);
            $table->decimal('total', 24, 4);
            $table->foreignId('journal_entry_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('trade_document_id')->nullable()->unique()->constrained('trade_documents')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['customer_id', 'invoice_date']);
        });

        Schema::create('sales_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('finished_good_id')->constrained()->restrictOnDelete();
            $table->foreignId('production_batch_id')->constrained()->restrictOnDelete();
            $table->foreignId('dispatch_id')->unique()->constrained('finished_good_dispatches')->restrictOnDelete();
            $table->unsignedInteger('quantity_packages');
            $table->decimal('unit_price', 20, 4);
            $table->decimal('line_total', 24, 4);
            $table->timestamps();
            $table->unique(['sales_invoice_id', 'production_batch_id'], 'sales_invoice_batch_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_invoice_lines');
        Schema::dropIfExists('sales_invoices');
    }
};
