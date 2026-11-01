<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('employee_number', 32)->unique();
            $table->text('full_name');
            $table->text('father_name')->nullable();
            $table->string('gender', 32)->nullable();
            $table->string('position', 180)->nullable();
            $table->text('phone')->nullable();
            $table->text('national_id')->nullable();
            $table->text('taxpayer_id')->nullable();
            $table->decimal('monthly_salary', 20, 4);
            $table->foreignId('salary_currency_id')->constrained('currencies')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['is_active', 'employee_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_profiles');
    }
};
