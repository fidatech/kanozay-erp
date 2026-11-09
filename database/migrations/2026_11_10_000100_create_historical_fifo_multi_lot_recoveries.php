<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_historical_fifo_multi_recoveries',function(Blueprint $t){
            $t->id();
            $t->foreignId('production_batch_id')->unique('fhfmr_unique_batch')
                ->constrained('production_batches',indexName:'fhfmr_batch_fk')->restrictOnDelete();
            $t->foreignId('currency_id')
                ->constrained('currencies',indexName:'fhfmr_currency_fk')->restrictOnDelete();
            $t->foreignId('opening_equity_ledger_id')
                ->constrained('ledger_accounts',indexName:'fhfmr_equity_fk')->restrictOnDelete();
            $t->date('original_issue_date');
            $t->date('original_finished_date');
            $t->decimal('original_opening_total',20,4);
            $t->decimal('source_material_total',20,4);
            $t->decimal('remaining_original_raw_total',20,4);
            $t->unsignedSmallInteger('source_lot_count');
            $t->unsignedSmallInteger('original_issue_line_count');
            $t->unsignedSmallInteger('genuine_invoice_line_count');
            $t->char('source_fingerprint',64);
            $t->string('status',24)->default('submitted'); // submitted/approved/rejected/posted
            $t->string('approval_reference',180);
            $t->text('reconciliation_notes');
            $t->string('review_reference',180)->nullable();
            $t->text('review_notes')->nullable();
            $t->foreignId('submitted_by')
                ->constrained('users',indexName:'fhfmr_submit_fk')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()
                ->constrained('users',indexName:'fhfmr_approved_fk')->restrictOnDelete();
            $t->foreignId('executed_by')->nullable()
                ->constrained('users',indexName:'fhfmr_executed_fk')->restrictOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('executed_at')->nullable();
            $t->foreignId('wip_journal_id')->nullable()->unique('fhfmr_wip_unique')
                ->constrained('journal_entries',indexName:'fhfmr_wip_gl_fk')->restrictOnDelete();
            $t->foreignId('finished_journal_id')->nullable()->unique('fhfmr_finished_unique')
                ->constrained('journal_entries',indexName:'fhfmr_finished_gl_fk')->restrictOnDelete();
            $t->foreignId('production_batch_cost_posting_id')->nullable()->unique('fhfmr_posting_unique')
                ->constrained('production_batch_cost_postings',indexName:'fhfmr_post_fk')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('finance_historical_fifo_multi_recovery_lots',function(Blueprint $t){
            $t->id();
            $t->foreignId('finance_historical_fifo_multi_recovery_id')
                ->constrained('finance_historical_fifo_multi_recoveries',indexName:'fhfmrl_recovery_fk')
                ->restrictOnDelete();
            $t->foreignId('finance_historical_fifo_production_cost_review_id')
                ->unique('fhfmrl_unique_source_review')
                ->constrained('finance_historical_fifo_production_cost_reviews',indexName:'fhfmrl_review_fk')
                ->restrictOnDelete();
            $t->foreignId('inventory_cost_layer_id')->unique('fhfmrl_unique_original_lot')
                ->constrained('inventory_cost_layers',indexName:'fhfmrl_lot_fk')->restrictOnDelete();
            $t->date('original_opening_date');
            $t->decimal('original_source_total',20,4);
            $t->decimal('consumed_wip_cost',20,4);
            $t->decimal('remaining_raw_cost',20,4);
            $t->char('source_fingerprint',64);
            $t->foreignId('opening_journal_id')->nullable()->unique('fhfmrl_opening_gl_unique')
                ->constrained('journal_entries',indexName:'fhfmrl_opening_gl_fk')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['finance_historical_fifo_multi_recovery_id','inventory_cost_layer_id'],
                'fhfmrl_per_case_lot_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_historical_fifo_multi_recovery_lots');
        Schema::dropIfExists('finance_historical_fifo_multi_recoveries');
    }
};
