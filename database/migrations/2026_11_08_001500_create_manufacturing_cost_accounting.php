<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up():void
    {
        Schema::create('manufacturing_cost_ledger_policies',function(Blueprint $t){
            $t->id();
            $t->foreignId('currency_id')->unique()->constrained('currencies')->restrictOnDelete();
            $t->foreignId('material_inventory_ledger_id')->constrained('ledger_accounts')->restrictOnDelete();
            $t->foreignId('work_in_progress_ledger_id')->constrained('ledger_accounts')->restrictOnDelete();
            $t->foreignId('finished_inventory_ledger_id')->constrained('ledger_accounts')->restrictOnDelete();
            $t->foreignId('cogs_ledger_id')->constrained('ledger_accounts')->restrictOnDelete();
            $t->string('status',20)->default('draft');
            $t->string('authorization_reference',180);
            $t->text('notes');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
        });

        Schema::create('production_batch_cost_postings',function(Blueprint $t){
            $t->id();
            $t->foreignId('production_batch_id')->unique()->constrained('production_batches')->restrictOnDelete();
            $t->foreignId('manufacturing_cost_ledger_policy_id')->constrained('manufacturing_cost_ledger_policies',indexName:'prod_cost_policy_fk')->restrictOnDelete();
            $t->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $t->unsignedInteger('accepted_packages');
            $t->decimal('material_amount',20,4);
            $t->decimal('overhead_amount',20,4);
            $t->decimal('total_amount',20,4);
            $t->date('accounting_date');
            $t->foreignId('issue_journal_id')->unique()->constrained('journal_entries')->restrictOnDelete();
            $t->foreignId('capitalization_journal_id')->unique()->constrained('journal_entries')->restrictOnDelete();
            $t->string('approval_reference',180);
            $t->text('reconciliation_notes');
            $t->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });

        Schema::create('production_invoice_cogs_postings',function(Blueprint $t){
            $t->id();
            $t->foreignId('sales_invoice_line_id')->unique()->constrained('sales_invoice_lines',indexName:'prod_cogs_invoice_line_fk')->restrictOnDelete();
            $t->foreignId('production_batch_cost_posting_id')->constrained('production_batch_cost_postings',indexName:'prod_cogs_batch_cost_fk')->restrictOnDelete();
            $t->unsignedInteger('quantity_packages');
            $t->decimal('amount',20,4);
            $t->foreignId('journal_entry_id')->unique()->constrained('journal_entries')->restrictOnDelete();
            $t->timestamps();
        });

        Schema::create('production_return_cost_postings',function(Blueprint $t){
            $t->id();
            $t->foreignId('sales_return_quarantine_id')->unique()->constrained('sales_return_quarantines',indexName:'prod_return_quarantine_fk')->restrictOnDelete();
            $t->foreignId('production_invoice_cogs_posting_id')->constrained('production_invoice_cogs_postings',indexName:'prod_return_origin_fk')->restrictOnDelete();
            $t->unsignedInteger('quantity_packages');
            $t->decimal('amount',20,4);
            $t->foreignId('journal_entry_id')->unique()->constrained('journal_entries')->restrictOnDelete();
            $t->timestamps();
        });
    }

    public function down():void
    {
        Schema::dropIfExists('production_return_cost_postings');
        Schema::dropIfExists('production_invoice_cogs_postings');
        Schema::dropIfExists('production_batch_cost_postings');
        Schema::dropIfExists('manufacturing_cost_ledger_policies');
    }
};
