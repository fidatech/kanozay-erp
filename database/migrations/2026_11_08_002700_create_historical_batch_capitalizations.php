<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_historical_batch_capitalizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('finance_historical_correction_case_id')
                ->unique('fhbc_one_case')
                ->constrained('finance_historical_correction_cases', indexName: 'fhbc_case_fk')->restrictOnDelete();
            $table->foreignId('production_batch_id')->unique('fhbc_one_batch')
                ->constrained('production_batches', indexName: 'fhbc_batch_fk')->restrictOnDelete();
            $table->foreignId('currency_id')
                ->constrained('currencies', indexName: 'fhbc_currency_fk')->restrictOnDelete();
            $table->foreignId('production_batch_cost_posting_id')->nullable()->unique('fhbc_one_posting')
                ->constrained('production_batch_cost_postings', indexName: 'fhbc_posting_fk')->restrictOnDelete();
            $table->string('status', 24)->default('submitted');
            $table->date('accounting_date');
            $table->decimal('expected_original_cost', 20, 4);
            $table->unsignedInteger('accepted_packages');
            $table->unsignedInteger('dispatched_packages');
            $table->unsignedInteger('invoice_line_count');
            $table->char('source_fingerprint', 64);
            $table->string('source_authorization_reference', 180);
            $table->text('source_reconciliation_notes');
            $table->string('approval_reference', 180)->nullable();
            $table->text('approval_notes')->nullable();
            $table->unsignedInteger('posted_invoice_cogs_lines')->default(0);
            $table->foreignId('submitted_by')
                ->constrained('users', indexName: 'fhbc_submitter_fk')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()
                ->constrained('users', indexName: 'fhbc_approver_fk')->restrictOnDelete();
            $table->foreignId('executed_by')->nullable()
                ->constrained('users', indexName: 'fhbc_executor_fk')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_historical_batch_capitalizations');
    }
};
