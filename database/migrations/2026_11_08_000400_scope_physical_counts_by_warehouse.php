<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_stock_counts', function (Blueprint $table) {
            // Null means the unassigned company stock pool. Previous counts
            // had company-wide snapshots, so preserve their original scope.
            $table->foreignId('company_warehouse_id')->nullable()
                ->constrained('company_warehouses')->restrictOnDelete();
            $table->string('count_scope', 24)->default('legacy_company');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_stock_counts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_warehouse_id');
            $table->dropColumn('count_scope');
        });
    }
};
