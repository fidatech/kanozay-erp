<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up():void
    {
        Schema::create('payroll_tax_remittances',function(Blueprint $t){
            $t->id();
            $t->uuid('submission_token')->unique();
            $t->foreignId('payroll_period_id')->constrained('payroll_periods',indexName:'ptr_period_fk')->restrictOnDelete();
            $t->foreignId('payroll_tax_policy_id')->constrained('payroll_tax_policies',indexName:'ptr_policy_fk')->restrictOnDelete();
            $t->foreignId('currency_id')->constrained('currencies',indexName:'ptr_currency_fk')->restrictOnDelete();
            $t->foreignId('liability_ledger_id')->constrained('ledger_accounts',indexName:'ptr_liability_fk')->restrictOnDelete();
            $t->foreignId('financial_account_id')->constrained('financial_accounts',indexName:'ptr_account_fk')->restrictOnDelete();
            $t->decimal('amount',20,4);
            $t->date('remit_on');
            $t->string('status',20)->default('submitted');
            $t->char('source_fingerprint',64);
            $t->decimal('source_total_withheld',20,4);
            $t->string('evidence_reference',180);
            $t->text('source_notes');
            $t->string('review_reference',180)->nullable();
            $t->text('review_notes')->nullable();
            $t->foreignId('requested_by')->constrained('users',indexName:'ptr_requested_fk')->restrictOnDelete();
            $t->foreignId('reviewed_by')->nullable()->constrained('users',indexName:'ptr_reviewed_fk')->restrictOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->foreignId('executed_by')->nullable()->constrained('users',indexName:'ptr_executed_fk')->restrictOnDelete();
            $t->timestamp('executed_at')->nullable();
            $t->foreignId('journal_entry_id')->nullable()->unique()
                ->constrained('journal_entries',indexName:'ptr_journal_fk')->restrictOnDelete();
            $t->foreignId('money_transaction_id')->nullable()->unique()
                ->constrained('money_transactions',indexName:'ptr_money_fk')->restrictOnDelete();
            $t->timestamps();
            $t->index(['payroll_period_id','payroll_tax_policy_id','status'],'ptr_period_policy_status');
            $t->index(['remit_on','status'],'ptr_due_status');
        });
    }
    public function down():void
    {
        Schema::dropIfExists('payroll_tax_remittances');
    }
};
