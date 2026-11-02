<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sales_quotations', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->uuid('submission_token')->unique();
            $table->foreignId('customer_id')->constrained('trade_parties')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->string('currency_code', 3);
            $table->date('quotation_date');
            $table->date('valid_until');
            $table->string('status', 16)->default('issued');
            $table->decimal('subtotal', 24, 4);
            $table->decimal('total', 24, 4);
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['customer_id', 'quotation_date']);
        });

        Schema::create('sales_quotation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_quotation_id')->constrained()->restrictOnDelete();
            $table->string('description', 255);
            $table->unsignedInteger('quantity');
            $table->string('unit', 40);
            $table->decimal('unit_price', 20, 4);
            $table->decimal('line_total', 24, 4);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_quotation_lines');
        Schema::dropIfExists('sales_quotations');
    }
};
