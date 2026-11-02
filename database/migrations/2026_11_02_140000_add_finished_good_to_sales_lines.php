<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_quotation_lines', function (Blueprint $table) {
            $table->foreignId('finished_good_id')->nullable()->after('sales_quotation_id')->constrained('finished_goods')->restrictOnDelete();
        });
        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->foreignId('finished_good_id')->nullable()->after('sales_quotation_line_id')->constrained('finished_goods')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_order_lines', fn (Blueprint $table) => $table->dropConstrainedForeignId('finished_good_id'));
        Schema::table('sales_quotation_lines', fn (Blueprint $table) => $table->dropConstrainedForeignId('finished_good_id'));
    }
};
