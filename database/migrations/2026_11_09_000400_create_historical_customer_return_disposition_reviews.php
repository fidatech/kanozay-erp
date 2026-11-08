<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_historical_customer_return_disposition_reviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('finance_historical_correction_case_id')
                ->unique('fhcdr_one_case')
                ->constrained('finance_historical_correction_cases', indexName:'fhcdr_case_fk')
                ->restrictOnDelete();
            $t->foreignId('sales_return_quarantine_id')
                ->constrained('sales_return_quarantines', indexName:'fhcdr_qc_fk')
                ->restrictOnDelete();
            $t->foreignId('sales_credit_note_line_id')
                ->constrained('sales_credit_note_lines', indexName:'fhcdr_credit_fk')
                ->restrictOnDelete();
            $t->foreignId('sales_invoice_line_id')
                ->constrained('sales_invoice_lines', indexName:'fhcdr_sale_fk')
                ->restrictOnDelete();
            $t->foreignId('production_invoice_cogs_posting_id')->nullable()
                ->constrained('production_invoice_cogs_postings', indexName:'fhcdr_cogs_fk')
                ->restrictOnDelete();
            $t->foreignId('currency_id')->nullable()
                ->constrained('currencies', indexName:'fhcdr_currency_fk')
                ->restrictOnDelete();
            $t->foreignId('cogs_ledger_id')->nullable()
                ->constrained('ledger_accounts', indexName:'fhcdr_source_gl_fk')
                ->restrictOnDelete();
            $t->foreignId('loss_ledger_id')->nullable()
                ->constrained('ledger_accounts', indexName:'fhcdr_loss_gl_fk')
                ->restrictOnDelete();
            $t->foreignId('journal_entry_id')->nullable()->unique('fhcdr_one_journal')
                ->constrained('journal_entries', indexName:'fhcdr_journal_fk')
                ->restrictOnDelete();
            $t->string('treatment',32);
            $t->string('status',24)->default('submitted');
            $t->date('source_date');
            $t->date('posting_date')->nullable();
            $t->unsignedInteger('quantity_packages');
            $t->decimal('original_invoice_cogs',20,4)->nullable();
            $t->decimal('source_loss_amount',20,4)->nullable();
            $t->char('source_fingerprint',64);
            $t->string('evidence_reference',180);
            $t->text('reconciliation_notes');
            $t->string('approval_reference',180)->nullable();
            $t->text('approval_notes')->nullable();
            $t->foreignId('submitted_by')
                ->constrained('users', indexName:'fhcdr_submitter_fk')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()
                ->constrained('users', indexName:'fhcdr_approver_fk')->restrictOnDelete();
            $t->foreignId('executed_by')->nullable()
                ->constrained('users', indexName:'fhcdr_executor_fk')->restrictOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('executed_at')->nullable();
            $t->timestamps();

            // A held quarantine may subsequently become an independently
            // scrapped physical return. Its new, changed source needs a new
            // signed case, never an alteration of the former hold record.
            $t->unique(['sales_return_quarantine_id','treatment'],'fhcdr_once_per_disposition');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_historical_customer_return_disposition_reviews');
    }
};
