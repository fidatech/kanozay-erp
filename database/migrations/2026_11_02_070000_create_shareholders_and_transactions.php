<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shareholders', function (Blueprint $table) {
            $table->id();
            $table->string('name', 180);
            $table->string('code', 40)->nullable()->unique();
            $table->string('phone', 80)->nullable();
            $table->string('email', 180)->nullable();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('shareholder_currencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shareholder_id')->constrained()->cascadeOnDelete();
            $table->foreignId('currency_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['shareholder_id', 'currency_id']);
        });

        Schema::create('shareholder_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('number', 50)->unique();
            $table->string('idempotency_key', 36)->unique();
            $table->foreignId('shareholder_id')->constrained()->restrictOnDelete();
            $table->enum('type', ['contribution', 'withdrawal']);
            $table->date('transaction_date');
            $table->foreignId('currency_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_account_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 20, 4);
            $table->string('reference', 120)->nullable();
            $table->text('description');
            $table->foreignId('journal_entry_id')->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['shareholder_id', 'transaction_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shareholder_transactions');
        Schema::dropIfExists('shareholder_currencies');
        Schema::dropIfExists('shareholders');
    }
};
