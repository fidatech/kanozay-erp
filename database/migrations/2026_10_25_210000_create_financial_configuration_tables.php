<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('financial_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('base_currency_id')->nullable()->constrained('currencies')->restrictOnDelete();
            $table->string('fiscal_year_start',5)->default('01-01');
            $table->boolean('allow_negative_stock')->default(false);
            $table->boolean('require_payment_approval')->default(true);
            $table->boolean('require_purchase_approval')->default(true);
            $table->string('purchase_mode',15)->default('both');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id(); $table->string('document_type',50)->unique(); $table->string('prefix',20);
            $table->unsignedBigInteger('next_number')->default(1); $table->unsignedTinyInteger('padding')->default(5);
            $table->boolean('is_active')->default(true); $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('document_sequences'); Schema::dropIfExists('financial_settings'); }
};
