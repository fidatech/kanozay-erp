<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_salary_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_profile_id')->constrained('employee_profiles')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->decimal('amount', 20, 4);
            $table->date('issued_on');
            $table->foreignId('receivable_ledger_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->foreignId('money_transaction_id')->unique()->constrained('money_transactions')->restrictOnDelete();
            $table->uuid('submission_token')->unique();
            $table->string('reason', 500);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['employee_profile_id', 'issued_on']);
        });
    }
    public function down(): void { Schema::dropIfExists('employee_salary_advances'); }
};
