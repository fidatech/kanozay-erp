<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_historical_dispatch_cost_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('finance_historical_correction_case_id')
                ->unique('hfd_cost_case_once')
                ->constrained('finance_historical_correction_cases', indexName: 'hfd_cost_case_fk')->restrictOnDelete();
            $table->foreignId('finance_historical_dispatch_disposition_id')
                ->unique('hfd_cost_disposition_once')
                ->constrained('finance_historical_dispatch_dispositions', indexName: 'hfd_cost_disposition_fk')->restrictOnDelete();
            $table->foreignId('finished_good_dispatch_id')
                ->unique('hfd_cost_dispatch_once')
                ->constrained('finished_good_dispatches', indexName: 'hfd_cost_dispatch_fk')->restrictOnDelete();
            $table->string('business_purpose', 40);
            $table->string('status', 24)->default('submitted');
            $table->date('posting_date');
            $table->string('source_authorization_reference', 180);
            $table->text('expense_recognition_evidence');
            $table->string('approval_reference', 180)->nullable();
            $table->text('approval_notes')->nullable();
            $table->foreignId('submitted_by')->constrained('users', indexName: 'hfd_cost_submitter_fk')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users', indexName: 'hfd_cost_approver_fk')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users', indexName: 'hfd_cost_executor_fk')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('finance_historical_dispatch_cost_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('finance_historical_dispatch_cost_settlement_id')
                ->constrained('finance_historical_dispatch_cost_settlements', indexName: 'hfd_line_settlement_fk')->restrictOnDelete();
            $table->foreignId('finished_good_dispatch_line_id')
                ->unique('hfd_cost_one_physical_line')
                ->constrained('finished_good_dispatch_lines', indexName: 'hfd_line_dispatch_fk')->restrictOnDelete();
            $table->foreignId('production_batch_cost_posting_id')
                ->constrained('production_batch_cost_postings', indexName: 'hfd_line_batch_cost_fk')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies', indexName: 'hfd_line_currency_fk')->restrictOnDelete();
            $table->foreignId('finished_inventory_ledger_id')
                ->constrained('ledger_accounts', indexName: 'hfd_line_inventory_fk')->restrictOnDelete();
            $table->foreignId('expense_ledger_id')
                ->constrained('ledger_accounts', indexName: 'hfd_line_expense_fk')->restrictOnDelete();
            $table->unsignedInteger('quantity_packages');
            $table->decimal('original_batch_total', 20, 4);
            $table->decimal('source_cost_amount', 20, 4);
            $table->foreignId('journal_entry_id')->nullable()->unique('hfd_line_one_journal')
                ->constrained('journal_entries', indexName: 'hfd_line_journal_fk')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_historical_dispatch_cost_lines');
        Schema::dropIfExists('finance_historical_dispatch_cost_settlements');
    }
};
