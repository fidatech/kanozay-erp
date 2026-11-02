<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_advance_repayments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_salary_advance_id')->constrained('employee_salary_advances')->restrictOnDelete();
            $table->decimal('amount', 20, 4);
            $table->date('received_on');
            $table->foreignId('money_transaction_id')->unique()->constrained('money_transactions')->restrictOnDelete();
            $table->uuid('submission_token')->unique();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['employee_salary_advance_id', 'received_on']);
        });
    }
    public function down(): void { Schema::dropIfExists('employee_advance_repayments'); }
};
