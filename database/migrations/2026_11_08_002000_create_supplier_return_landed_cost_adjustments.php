<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up():void
    {
        Schema::create('purchase_return_landed_cost_adjustments',function(Blueprint $t){
            $t->id();
            $t->foreignId('purchase_return_record_id')->unique('pr_landed_one_adjustment')
                ->constrained('purchase_return_records',indexName:'pr_landed_return_fk')
                ->restrictOnDelete();
            $t->foreignId('currency_id')
                ->constrained('currencies',indexName:'pr_landed_currency_fk')->restrictOnDelete();
            $t->foreignId('inventory_ledger_id')
                ->constrained('ledger_accounts',indexName:'pr_landed_inventory_fk')->restrictOnDelete();
            $t->foreignId('expense_ledger_id')
                ->constrained('ledger_accounts',indexName:'pr_landed_expense_fk')->restrictOnDelete();
            $t->decimal('amount',20,4);
            $t->foreignId('journal_entry_id')->unique('pr_landed_unique_journal')
                ->constrained('journal_entries',indexName:'pr_landed_journal_fk')->restrictOnDelete();
            $t->string('approval_reference',180);
            $t->text('source_evidence');
            $t->foreignId('approved_by')
                ->constrained('users',indexName:'pr_landed_finance_reviewer_fk')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('purchase_return_landed_cost_adjustment_lines',function(Blueprint $t){
            $t->id();
            $t->foreignId('purchase_return_landed_cost_adjustment_id')
                ->constrained('purchase_return_landed_cost_adjustments',indexName:'pr_landed_detail_header_fk')
                ->restrictOnDelete();
            $t->foreignId('purchase_return_record_line_id')->unique('pr_landed_source_once')
                ->constrained('purchase_return_record_lines',indexName:'pr_landed_return_line_fk')
                ->restrictOnDelete();
            $t->decimal('quantity',20,6);
            $t->decimal('amount',20,4);
            $t->timestamps();
        });
    }

    public function down():void
    {
        Schema::dropIfExists('purchase_return_landed_cost_adjustment_lines');
        Schema::dropIfExists('purchase_return_landed_cost_adjustments');
    }
};