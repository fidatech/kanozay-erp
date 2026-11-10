<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up():void
    {
        Schema::create('finance_historical_fifo_production_recoveries',function(Blueprint $t){
            $t->id();
            $t->foreignId('finance_historical_fifo_production_cost_review_id')
                ->unique('fhfpr_only_once_approved_source')
                ->constrained('finance_historical_fifo_production_cost_reviews',indexName:'fhfpr_review_fk')
                ->restrictOnDelete();
            $t->foreignId('inventory_cost_layer_id')->unique('fhfpr_only_once_original_lot')
                ->constrained('inventory_cost_layers',indexName:'fhfpr_lot_fk')->restrictOnDelete();
            $t->foreignId('production_batch_id')->unique('fhfpr_one_original_batch')
                ->constrained('production_batches',indexName:'fhfpr_batch_fk')->restrictOnDelete();
            $t->foreignId('currency_id')->constrained('currencies',indexName:'fhfpr_currency_fk')->restrictOnDelete();
            $t->foreignId('opening_equity_ledger_id')->constrained('ledger_accounts',indexName:'fhfpr_equity_fk')
                ->restrictOnDelete();
            $t->date('original_opening_date');
            $t->date('original_issue_date');
            $t->date('original_finished_date');
            $t->decimal('original_lot_cost',20,4);
            $t->decimal('original_material_cost',20,4);
            $t->decimal('original_remaining_raw_cost',20,4);
            $t->unsignedInteger('source_issue_lines');
            $t->unsignedInteger('source_invoice_lines');
            $t->char('source_fingerprint',64);
            $t->string('status',24)->default('submitted'); // submitted/approved/rejected/posted
            $t->string('approval_reference',180);
            $t->text('reconciliation_notes');
            $t->string('review_reference',180)->nullable();
            $t->text('review_notes')->nullable();
            $t->foreignId('submitted_by')->constrained('users',indexName:'fhfpr_submit_fk')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users',indexName:'fhfpr_approve_fk')->restrictOnDelete();
            $t->foreignId('executed_by')->nullable()->constrained('users',indexName:'fhfpr_execute_fk')->restrictOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('executed_at')->nullable();
            $t->foreignId('opening_journal_id')->nullable()->unique('fhfpr_opening_journal_unique')
                ->constrained('journal_entries',indexName:'fhfpr_opening_gl_fk')->restrictOnDelete();
            $t->foreignId('wip_journal_id')->nullable()->unique('fhfpr_wip_journal_unique')
                ->constrained('journal_entries',indexName:'fhfpr_wip_gl_fk')->restrictOnDelete();
            $t->foreignId('finished_journal_id')->nullable()->unique('fhfpr_finished_journal_unique')
                ->constrained('journal_entries',indexName:'fhfpr_fg_gl_fk')->restrictOnDelete();
            $t->foreignId('production_batch_cost_posting_id')->nullable()->unique('fhfpr_batch_posting_unique')
                ->constrained('production_batch_cost_postings',indexName:'fhfpr_capitalized_fk')
                ->restrictOnDelete();
            $t->timestamps();
        });

        Schema::table('finance_historical_fifo_production_cost_lines',function(Blueprint $t){
            $t->foreignId('historical_wip_journal_id')->nullable()
                ->constrained('journal_entries',indexName:'fhfpl_wip_journal_fk')->restrictOnDelete();
        });
    }

    public function down():void
    {
        Schema::table('finance_historical_fifo_production_cost_lines',function(Blueprint $t){
            $t->dropForeign('fhfpl_wip_journal_fk');
            $t->dropColumn('historical_wip_journal_id');
        });
        Schema::dropIfExists('finance_historical_fifo_production_recoveries');
    }
};
