<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_advance_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->uuid('submission_token')->unique();
            $table->foreignId('customer_id')->constrained('trade_parties')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->foreignId('destination_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->foreignId('advance_ledger_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->foreignId('money_transaction_id')->unique()->constrained('money_transactions')->restrictOnDelete();
            $table->date('received_on');
            $table->decimal('amount', 20, 4);
            $table->string('reference', 120);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['customer_id','currency_id','received_on'], 'customer_advance_customer_ccy_date');
        });
        Schema::create('customer_advance_applications', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->uuid('submission_token')->unique();
            $table->foreignId('customer_advance_receipt_id')
                ->constrained('customer_advance_receipts', indexName: 'customer_advance_source_fk')->restrictOnDelete();
            $table->foreignId('trade_document_id')->constrained('trade_documents')->restrictOnDelete();
            $table->foreignId('journal_entry_id')->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->date('applied_on');
            $table->decimal('amount', 20, 4);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['customer_advance_receipt_id','applied_on'], 'customer_advance_application_date');
        });
        Schema::table('trade_document_credits', function (Blueprint $table) {
            $table->foreignId('customer_advance_application_id')->nullable()->unique()
                ->constrained('customer_advance_applications', indexName: 'trade_customer_advance_fk')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('trade_document_credits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_advance_application_id');
        });
        Schema::dropIfExists('customer_advance_applications');
        Schema::dropIfExists('customer_advance_receipts');
    }
};
