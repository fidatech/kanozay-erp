<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_historical_customer_return_cost_recoveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('finance_historical_correction_case_id')
                ->unique('fhcr_one_case')
                ->constrained('finance_historical_correction_cases', indexName: 'fhcr_case_fk')
                ->restrictOnDelete();
            $table->foreignId('sales_return_quarantine_id')
                ->unique('fhcr_one_return')
                ->constrained('sales_return_quarantines', indexName: 'fhcr_return_fk')
                ->restrictOnDelete();
            $table->foreignId('production_invoice_cogs_posting_id')
                ->constrained('production_invoice_cogs_postings', indexName: 'fhcr_cogs_fk')
                ->restrictOnDelete();
            $table->foreignId('currency_id')
                ->constrained('currencies', indexName: 'fhcr_currency_fk')
                ->restrictOnDelete();
            $table->foreignId('finished_inventory_ledger_id')
                ->constrained('ledger_accounts', indexName: 'fhcr_asset_fk')
                ->restrictOnDelete();
            $table->foreignId('cogs_ledger_id')
                ->constrained('ledger_accounts', indexName: 'fhcr_expense_fk')
                ->restrictOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->unique('fhcr_one_journal')
                ->constrained('journal_entries', indexName: 'fhcr_journal_fk')
                ->restrictOnDelete();
            $table->string('status', 24)->default('submitted');
            $table->date('original_qc_date');
            $table->date('posting_date');
            $table->unsignedInteger('quantity_packages');
            $table->decimal('original_cogs_amount', 20, 4);
            $table->decimal('recoverable_original_cost', 20, 4);
            $table->char('source_fingerprint', 64);
            $table->string('source_evidence_reference', 180);
            $table->text('source_reconciliation_notes');
            $table->string('approval_reference', 180)->nullable();
            $table->text('approval_notes')->nullable();
            $table->foreignId('submitted_by')
                ->constrained('users', indexName: 'fhcr_submitter_fk')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()
                ->constrained('users', indexName: 'fhcr_approver_fk')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()
                ->constrained('users', indexName: 'fhcr_poster_fk')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_historical_customer_return_cost_recoveries');
    }
};
