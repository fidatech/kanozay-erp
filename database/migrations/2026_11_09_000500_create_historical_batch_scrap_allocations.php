<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_historical_batch_capitalizations', function (Blueprint $table) {
            // Null identifies a legacy proposal created before scrap review.
            $table->unsignedInteger('scrap_line_count')->nullable();
            $table->unsignedInteger('posted_scrap_lines')->default(0);
        });

        Schema::create('finance_historical_batch_scrap_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('finance_historical_batch_capitalization_id')
                ->constrained('finance_historical_batch_capitalizations',indexName:'fhbs_batch_fk')
                ->restrictOnDelete();
            $table->foreignId('sales_return_quarantine_id')->unique('fhbs_original_qc_once')
                ->constrained('sales_return_quarantines',indexName:'fhbs_qc_fk')
                ->restrictOnDelete();
            $table->foreignId('sales_credit_note_line_id')->unique('fhbs_credit_once')
                ->constrained('sales_credit_note_lines',indexName:'fhbs_credit_fk')
                ->restrictOnDelete();
            $table->foreignId('sales_invoice_line_id')
                ->constrained('sales_invoice_lines',indexName:'fhbs_sale_fk')
                ->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies',indexName:'fhbs_currency_fk')
                ->restrictOnDelete();
            $table->foreignId('loss_expense_ledger_id')
                ->constrained('ledger_accounts',indexName:'fhbs_loss_fk')->restrictOnDelete();
            $table->date('original_qc_scrap_date');
            $table->unsignedInteger('quantity_packages');
            $table->decimal('original_invoice_cogs_amount',20,4);
            $table->decimal('scrap_reclass_amount',20,4);
            $table->char('source_fingerprint',64);
            $table->foreignId('source_cogs_posting_id')->nullable()
                ->constrained('production_invoice_cogs_postings',indexName:'fhbs_original_cogs_fk')
                ->restrictOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->unique('fhbs_one_gl')
                ->constrained('journal_entries',indexName:'fhbs_journal_fk')
                ->restrictOnDelete();
            $table->timestamps();
            $table->index('finance_historical_batch_capitalization_id','fhbs_plan_register');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_historical_batch_scrap_allocations');
        Schema::table('finance_historical_batch_capitalizations', function(Blueprint $table) {
            $table->dropColumn(['scrap_line_count','posted_scrap_lines']);
        });
    }
};
