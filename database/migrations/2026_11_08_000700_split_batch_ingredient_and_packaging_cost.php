<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_batch_costs', function (Blueprint $table) {
            // Historical cost snapshots keep NULL because a split cannot
            // be established from a total without its original issue lines.
            $table->decimal('ingredient_cost',42,10)->nullable();
            $table->decimal('packaging_cost',42,10)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('production_batch_costs', function (Blueprint $table) {
            $table->dropColumn(['ingredient_cost','packaging_cost']);
        });
    }
};
