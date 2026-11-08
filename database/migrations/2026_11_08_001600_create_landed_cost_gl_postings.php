<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up():void
    {
        Schema::create('goods_receipt_landed_cost_postings',function(Blueprint $table){
            $table->id();
            $table->foreignId('goods_receipt_landed_cost_id')->unique('grlc_gl_one_component')
                ->constrained('goods_receipt_landed_costs',indexName:'grlc_gl_component_fk')
                ->restrictOnDelete();
            $table->foreignId('source_expense_journal_line_id')->unique('grlc_gl_one_source_debit')
                ->constrained('journal_lines',indexName:'grlc_gl_expense_line_fk')
                ->restrictOnDelete();
            $table->foreignId('inventory_ledger_id')
                ->constrained('ledger_accounts',indexName:'grlc_gl_inventory_fk')
                ->restrictOnDelete();
            $table->foreignId('expense_ledger_id')
                ->constrained('ledger_accounts',indexName:'grlc_gl_expense_fk')
                ->restrictOnDelete();
            $table->foreignId('currency_id')
                ->constrained('currencies',indexName:'grlc_gl_currency_fk')
                ->restrictOnDelete();
            $table->decimal('amount',20,4);
            $table->date('accounting_date');
            $table->foreignId('journal_entry_id')->unique('grlc_gl_one_journal')
                ->constrained('journal_entries',indexName:'grlc_gl_journal_fk')
                ->restrictOnDelete();
            $table->string('approval_reference',180);
            $table->text('evidence_notes');
            $table->foreignId('approved_by')->constrained('users',indexName:'grlc_gl_reviewer_fk')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down():void
    {
        Schema::dropIfExists('goods_receipt_landed_cost_postings');
    }
};
