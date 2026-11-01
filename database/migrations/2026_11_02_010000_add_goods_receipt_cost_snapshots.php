<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Nullable to preserve legacy posted GRNs without inventing historical costs.
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->foreignId('cost_currency_id')->nullable()->constrained('currencies')->restrictOnDelete();
            $table->string('cost_currency_code', 3)->nullable();
        });

        Schema::table('goods_receipt_lines', function (Blueprint $table) {
            $table->decimal('unit_price_snapshot', 20, 4)->nullable();
            // Receipt quantity (6 decimals) x PO price (4 decimals) is exact at scale 10.
            $table->decimal('line_subtotal_snapshot', 42, 10)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipt_lines', function (Blueprint $table) {
            $table->dropColumn(['unit_price_snapshot', 'line_subtotal_snapshot']);
        });
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->dropForeign(['cost_currency_id']);
            $table->dropColumn(['cost_currency_id', 'cost_currency_code']);
        });
    }
};
