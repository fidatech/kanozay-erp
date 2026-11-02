<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payroll_periods', function (Blueprint $table) {
            $table->id();
            $table->date('period_start');
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->string('status', 20)->default('draft');
            $table->foreignId('expense_ledger_id')->nullable()->constrained('ledger_accounts')->restrictOnDelete();
            $table->foreignId('payable_ledger_id')->nullable()->constrained('ledger_accounts')->restrictOnDelete();
            $table->foreignId('approval_journal_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['period_start', 'currency_id']);
        });

        Schema::create('payroll_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_period_id')->constrained('payroll_periods')->restrictOnDelete();
            $table->foreignId('employee_profile_id')->constrained('employee_profiles')->restrictOnDelete();
            $table->decimal('salary_snapshot', 20, 4);
            $table->decimal('payable_amount', 20, 4);
            $table->string('adjustment_reason', 500)->nullable();
            $table->foreignId('payment_transaction_id')->nullable()->unique()->constrained('money_transactions')->restrictOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['payroll_period_id', 'employee_profile_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_lines');
        Schema::dropIfExists('payroll_periods');
    }
};
