<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            // Historical orders remain NULL: do not invent snapshots for old plans.
            $table->string('recipe_name_snapshot', 180)->nullable();
            $table->string('product_code_snapshot', 80)->nullable();
            $table->decimal('package_weight_kg_snapshot', 12, 4)->nullable();
            $table->string('package_unit_snapshot', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropColumn([
                'recipe_name_snapshot', 'product_code_snapshot',
                'package_weight_kg_snapshot', 'package_unit_snapshot',
            ]);
        });
    }
};
