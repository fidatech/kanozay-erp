<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->uuid('submission_token')->unique();
            $table->foreignId('sales_quotation_id')->unique()->constrained('sales_quotations')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('trade_parties')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->string('currency_code', 3);
            $table->date('order_date');
            $table->string('status', 16)->default('confirmed');
            $table->decimal('total', 24, 4);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['customer_id', 'order_date']);
        });
        Schema::create('sales_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_quotation_line_id')->constrained('sales_quotation_lines')->restrictOnDelete();
            $table->string('description', 255);
            $table->unsignedInteger('quantity');
            $table->string('unit', 40);
            $table->decimal('unit_price', 20, 4);
            $table->decimal('line_total', 24, 4);
            $table->timestamps();
            $table->unique(['sales_order_id', 'sales_quotation_line_id'], 'sales_order_quote_line_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_lines');
        Schema::dropIfExists('sales_orders');
    }
};
