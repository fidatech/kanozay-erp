<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_historical_fifo_adjustment_valuations',function(Blueprint $t){
            $t->id();
            $t->foreignId('inventory_cost_layer_id')->unique('fhfav_once_per_lot')
                ->constrained('inventory_cost_layers',indexName:'fhfav_layer_fk')->restrictOnDelete();
            $t->foreignId('finance_legacy_fifo_source_review_id')
                ->constrained('finance_legacy_fifo_source_reviews',indexName:'fhfav_review_fk')
                ->restrictOnDelete();
            $t->string('status',20)->default('submitted'); // submitted/approved/rejected/posted
            $t->foreignId('currency_id')->constrained('currencies',indexName:'fhfav_currency_fk')->restrictOnDelete();
            $t->foreignId('inventory_ledger_id')->constrained('ledger_accounts',indexName:'fhfav_asset_fk')->restrictOnDelete();
            $t->foreignId('opening_equity_ledger_id')->constrained('ledger_accounts',indexName:'fhfav_equity_fk')->restrictOnDelete();
            $t->foreignId('adjustment_loss_ledger_id')->constrained('ledger_accounts',indexName:'fhfav_loss_fk')->restrictOnDelete();
            $t->decimal('original_total_cost',20,4);
            $t->decimal('remaining_original_cost',20,4);
            $t->decimal('consumed_original_cost',20,4);
            $t->decimal('original_quantity',20,6);
            $t->decimal('remaining_quantity',20,6);
            $t->char('source_fingerprint',64);
            $t->string('original_cost_evidence_reference',180);
            $t->text('source_cost_reconciliation');
            $t->string('approval_reference',180)->nullable();
            $t->text('approval_notes')->nullable();
            $t->foreignId('submitted_by')->constrained('users',indexName:'fhfav_submit_fk')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users',indexName:'fhfav_approve_fk')->restrictOnDelete();
            $t->foreignId('executed_by')->nullable()->constrained('users',indexName:'fhfav_execute_fk')->restrictOnDelete();
            $t->foreignId('opening_journal_entry_id')->nullable()->unique('fhfav_opening_journal')
                ->constrained('journal_entries',indexName:'fhfav_opening_gl_fk')->restrictOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('executed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('finance_historical_fifo_adjustment_cost_lines',function(Blueprint $t){
            $t->id();
            $t->foreignId('finance_historical_fifo_adjustment_valuation_id')
                ->constrained('finance_historical_fifo_adjustment_valuations',indexName:'fhfac_valuation_fk')
                ->restrictOnDelete();
            $t->foreignId('inventory_cost_consumption_id')->unique('fhfac_once_per_consumption')
                ->constrained('inventory_cost_consumptions',indexName:'fhfac_consumed_fk')->restrictOnDelete();
            $t->foreignId('inventory_stock_movement_id')
                ->constrained('inventory_stock_movements',indexName:'fhfac_movement_fk')->restrictOnDelete();
            $t->date('original_issue_date');
            $t->decimal('quantity',20,6);
            $t->decimal('amount',20,4);
            $t->char('source_fingerprint',64);
            $t->foreignId('journal_entry_id')->nullable()->unique('fhfac_issue_journal')
                ->constrained('journal_entries',indexName:'fhfac_issue_gl_fk')->restrictOnDelete();
            $t->timestamps();
            $t->index(['finance_historical_fifo_adjustment_valuation_id','original_issue_date'],
                'fhfac_original_issue_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_historical_fifo_adjustment_cost_lines');
        Schema::dropIfExists('finance_historical_fifo_adjustment_valuations');
    }
};
