<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Nullable: historical GRNs retain an explicit 'not captured' state.
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->foreignId('valuation_currency_id')->nullable()->constrained('currencies')->restrictOnDelete();
            $table->string('valuation_currency_code', 3)->nullable();
            $table->decimal('fx_rate_snapshot', 24, 12)->nullable();
            $table->foreignId('fx_rate_source_id')->nullable()->constrained('exchange_rates')->restrictOnDelete();
            $table->date('fx_rate_effective_date')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->dropForeign(['valuation_currency_id']);
            $table->dropForeign(['fx_rate_source_id']);
            $table->dropColumn([
                'valuation_currency_id', 'valuation_currency_code', 'fx_rate_snapshot',
                'fx_rate_source_id', 'fx_rate_effective_date',
            ]);
        });
    }
};
