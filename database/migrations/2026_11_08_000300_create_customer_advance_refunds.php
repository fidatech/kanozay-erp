<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_advance_refunds', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->uuid('submission_token')->unique();
            $table->foreignId('customer_advance_receipt_id')
                ->constrained('customer_advance_receipts', indexName: 'customer_advance_refund_source_fk')
                ->restrictOnDelete();
            $table->foreignId('source_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->foreignId('money_transaction_id')->unique()->constrained('money_transactions')->restrictOnDelete();
            $table->foreignId('journal_entry_id')->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->date('refunded_on');
            $table->decimal('amount', 20, 4);
            $table->string('reference', 120);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['customer_advance_receipt_id','refunded_on'], 'customer_advance_refunds_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_advance_refunds');
    }
};
