<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sales_credit_notes', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->uuid('submission_token')->unique();
            $table->foreignId('sales_invoice_id')->constrained('sales_invoices')->restrictOnDelete();
            $table->foreignId('trade_document_id')->constrained('trade_documents')->restrictOnDelete();
            $table->foreignId('journal_entry_id')->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->date('credit_date');
            $table->string('reason', 255);
            $table->string('currency_code', 3);
            $table->decimal('total', 24, 4);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['sales_invoice_id', 'credit_date']);
        });
        Schema::create('sales_credit_note_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_credit_note_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_invoice_line_id')->constrained('sales_invoice_lines')->restrictOnDelete();
            $table->foreignId('finished_good_id')->constrained('finished_goods')->restrictOnDelete();
            $table->foreignId('production_batch_id')->constrained('production_batches')->restrictOnDelete();
            $table->unsignedInteger('quantity_packages');
            $table->decimal('unit_price', 20, 4);
            $table->decimal('line_total', 24, 4);
            $table->boolean('restocked')->default(false);
            $table->timestamps();
            $table->unique(['sales_credit_note_id', 'sales_invoice_line_id'], 'sales_credit_line_invoice_line_unique');
        });
        Schema::create('trade_document_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_document_id')->constrained('trade_documents')->restrictOnDelete();
            $table->foreignId('sales_credit_note_id')->unique()->constrained()->restrictOnDelete();
            $table->date('credit_date');
            $table->decimal('amount', 24, 4);
            $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('trade_document_credits');
        Schema::dropIfExists('sales_credit_note_lines');
        Schema::dropIfExists('sales_credit_notes');
    }
};
