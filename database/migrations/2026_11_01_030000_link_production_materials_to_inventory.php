<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('production_order_materials', function (Blueprint $table) {
            $table->foreignId('inventory_material_id')->nullable()
                ->constrained('inventory_materials')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('production_order_materials', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inventory_material_id');
        });
    }
};
