<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_historical_batch_capitalizations', function (Blueprint $t) {
            // Nullable on earlier approved invoiced and mixed-source plans;
            // 0+ indicates a new, fully snapshotted return-inclusive plan.
            $t->unsignedInteger('return_line_count')->nullable();
            $t->unsignedInteger('posted_return_lines')->default(0);
        });
        Schema::create('finance_historical_batch_return_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('finance_historical_batch_capitalization_id')
                ->constrained('finance_historical_batch_capitalizations',
                    indexName: 'fhbr_batch_plan_fk')->restrictOnDelete();
            $t->foreignId('sales_return_quarantine_id')->unique('fhbr_once_per_return')
                ->constrained('sales_return_quarantines',
                    indexName: 'fhbr_qc_fk')->restrictOnDelete();
            $t->foreignId('sales_credit_note_line_id')->unique('fhbr_once_per_credit')
                ->constrained('sales_credit_note_lines',
                    indexName: 'fhbr_credit_fk')->restrictOnDelete();
            $t->foreignId('sales_invoice_line_id')
                ->constrained('sales_invoice_lines',
                    indexName: 'fhbr_invoice_fk')->restrictOnDelete();
            $t->foreignId('currency_id')->constrained('currencies',
                    indexName:'fhbr_currency_fk')->restrictOnDelete();
            $t->date('original_qc_date');
            $t->unsignedInteger('quantity_packages');
            $t->decimal('original_invoiced_cogs_amount',20,4);
            $t->decimal('original_return_cost_amount',20,4);
            $t->char('source_fingerprint',64);
            $t->foreignId('production_return_cost_posting_id')->nullable()
                ->unique('fhbr_recovery_once')
                ->constrained('production_return_cost_postings',
                    indexName:'fhbr_posted_cost_fk')->restrictOnDelete();
            $t->foreignId('journal_entry_id')->nullable()
                ->unique('fhbr_journal_once')
                ->constrained('journal_entries',
                    indexName:'fhbr_posted_journal_fk')->restrictOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_historical_batch_return_allocations');
        Schema::table('finance_historical_batch_capitalizations', function (Blueprint $t) {
            $t->dropColumn(['return_line_count','posted_return_lines']);
        });
    }
};
