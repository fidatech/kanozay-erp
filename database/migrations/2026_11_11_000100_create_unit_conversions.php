<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row states: 1 from_unit = factor to_unit. Directions are explicit; the
        // reverse direction is a separate row and is never derived automatically.
        Schema::create('unit_conversions', function (Blueprint $table) {
            $table->id();
            $table->string('from_unit', 40);
            $table->string('to_unit', 40);
            $table->decimal('factor', 24, 10);
            // Null = applies to every item; set = only this stock item (e.g. carton -> kg).
            $table->foreignId('inventory_material_id')->nullable()
                ->constrained('inventory_materials', indexName: 'unit_conv_material_fk')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->constrained('users', indexName: 'unit_conv_creator_fk')->restrictOnDelete();
            $table->foreignId('deactivated_by')->nullable()->constrained('users', indexName: 'unit_conv_deactivator_fk')->nullOnDelete();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();
            $table->index(['from_unit', 'to_unit', 'is_active'], 'unit_conv_lookup_idx');
        });

        // A production requirement converted into the stock unit keeps what the recipe said.
        Schema::table('production_order_materials', function (Blueprint $table) {
            $table->string('source_unit', 40)->nullable();
            $table->decimal('source_quantity_per_package', 20, 6)->nullable();
            $table->decimal('source_required_quantity', 20, 6)->nullable();
            $table->decimal('unit_conversion_factor', 24, 10)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('production_order_materials', function (Blueprint $table) {
            $table->dropColumn(['source_unit', 'source_quantity_per_package', 'source_required_quantity', 'unit_conversion_factor']);
        });
        Schema::dropIfExists('unit_conversions');
    }
};
