<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_invoice_lines', function (Blueprint $table) {
            $table->foreignId('sales_order_line_id')->nullable()->after('sales_invoice_id')->constrained('sales_order_lines')->restrictOnDelete();
            $table->index('sales_order_line_id');
        });
    }
    public function down(): void
    {
        Schema::table('sales_invoice_lines', fn (Blueprint $table) => $table->dropConstrainedForeignId('sales_order_line_id'));
    }
};
