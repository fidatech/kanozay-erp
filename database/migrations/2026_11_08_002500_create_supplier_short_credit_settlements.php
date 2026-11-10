<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('finance_supplier_short_credit_settlements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('finance_historical_correction_case_id')->unique('short_credit_case_uq')->constrained('finance_historical_correction_cases',indexName:'short_credit_case_fk')->restrictOnDelete();
            $t->foreignId('finance_supplier_return_credit_dispute_id')->unique('short_credit_dispute_uq')->constrained('finance_supplier_return_credit_disputes',indexName:'short_credit_dispute_fk')->restrictOnDelete();
            $t->foreignId('purchase_return_record_id')->unique('short_credit_return_uq')->constrained('purchase_return_records',indexName:'short_credit_return_fk')->restrictOnDelete();
            $t->foreignId('trade_document_id')->constrained('trade_documents',indexName:'short_credit_document_fk')->restrictOnDelete();
            $t->foreignId('currency_id')->constrained('currencies',indexName:'short_credit_currency_fk')->restrictOnDelete();
            $t->foreignId('inventory_ledger_id')->constrained('ledger_accounts',indexName:'short_credit_inventory_fk')->restrictOnDelete();
            $t->foreignId('payable_ledger_id')->constrained('ledger_accounts',indexName:'short_credit_payable_fk')->restrictOnDelete();
            $t->foreignId('difference_ledger_id')->constrained('ledger_accounts',indexName:'short_credit_difference_fk')->restrictOnDelete();
            $t->foreignId('journal_entry_id')->nullable()->unique('short_credit_journal_uq')->constrained('journal_entries',indexName:'short_credit_journal_fk')->restrictOnDelete();
            $t->string('treatment',35); $t->string('status',25)->default('submitted');
            $t->date('posting_date');
            $t->decimal('source_base_return_value',20,4);
            $t->decimal('supplier_offered_credit',20,4);
            $t->decimal('unresolved_difference',20,4);
            $t->string('settlement_reference',180); $t->text('recognition_evidence');
            $t->string('approval_reference',180)->nullable(); $t->text('approval_notes')->nullable();
            $t->foreignId('submitted_by')->constrained('users',indexName:'short_credit_submitter_fk')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users',indexName:'short_credit_approver_fk')->restrictOnDelete();
            $t->foreignId('posted_by')->nullable()->constrained('users',indexName:'short_credit_executor_fk')->restrictOnDelete();
            $t->timestamp('approved_at')->nullable(); $t->timestamp('posted_at')->nullable();
            $t->timestamps();
        });
        Schema::table('trade_document_credits',function(Blueprint $t){
            $t->foreignId('supplier_short_credit_settlement_id')->nullable()->unique('trade_short_credit_uq')
                ->constrained('finance_supplier_short_credit_settlements',indexName:'trade_short_credit_fk')->restrictOnDelete();
        });
    }
    public function down(): void {
        Schema::table('trade_document_credits',function(Blueprint $t){
            $t->dropForeign('trade_short_credit_fk');
            $t->dropUnique('trade_short_credit_uq');
            $t->dropColumn('supplier_short_credit_settlement_id');
        });
        Schema::dropIfExists('finance_supplier_short_credit_settlements');
    }
};