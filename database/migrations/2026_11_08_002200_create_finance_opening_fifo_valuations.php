<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up():void
    {
        Schema::create('finance_opening_fifo_valuations',function(Blueprint $t){
            $t->id();
            $t->foreignId('finance_historical_correction_case_id')
                ->unique('opening_fifo_one_case')
                ->constrained('finance_historical_correction_cases',indexName:'opening_fifo_case_fk')
                ->restrictOnDelete();
            $t->foreignId('inventory_cost_layer_id')
                ->unique('opening_fifo_one_layer')
                ->constrained('inventory_cost_layers',indexName:'opening_fifo_layer_fk')
                ->restrictOnDelete();
            $t->foreignId('currency_id')
                ->constrained('currencies',indexName:'opening_fifo_currency_fk')
                ->restrictOnDelete();
            $t->foreignId('inventory_ledger_id')
                ->constrained('ledger_accounts',indexName:'opening_fifo_asset_fk')
                ->restrictOnDelete();
            $t->foreignId('opening_equity_ledger_id')
                ->constrained('ledger_accounts',indexName:'opening_fifo_equity_fk')
                ->restrictOnDelete();
            $t->decimal('original_cost',20,4);
            $t->date('posting_date');
            $t->foreignId('journal_entry_id')->unique('opening_fifo_one_journal')
                ->constrained('journal_entries',indexName:'opening_fifo_journal_fk')
                ->restrictOnDelete();
            $t->string('evidence_reference',180);
            $t->text('reconciliation_notes');
            $t->foreignId('posted_by')
                ->constrained('users',indexName:'opening_fifo_poster_fk')
                ->restrictOnDelete();
            $t->timestamps();
        });
    }

    public function down():void
    {
        Schema::dropIfExists('finance_opening_fifo_valuations');
    }
};
