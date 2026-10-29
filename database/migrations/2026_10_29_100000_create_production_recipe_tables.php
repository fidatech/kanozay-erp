<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('production_recipes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 180);
            $table->string('product_code', 80)->nullable();
            $table->enum('method', ['liquid_blending', 'powder_blending']);
            $table->decimal('package_weight_kg', 12, 4);
            $table->string('package_unit', 30);
            $table->string('cost_currency_code', 3)->nullable();
            $table->string('source_sheet', 120)->nullable();
            $table->string('source_reference', 180)->nullable();
            $table->enum('status', ['draft', 'approved', 'archived'])->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('production_recipe_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_recipe_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('line_number');
            $table->string('material_name', 180);
            $table->string('unit', 30);
            $table->decimal('quantity_per_package', 18, 6);
            $table->decimal('unit_cost', 18, 6)->nullable();
            $table->boolean('is_packaging')->default(false);
            $table->string('source_cell', 20)->nullable();
            $table->timestamps();
            $table->unique(['production_recipe_id', 'line_number']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('production_recipe_lines');
        Schema::dropIfExists('production_recipes');
    }
};
