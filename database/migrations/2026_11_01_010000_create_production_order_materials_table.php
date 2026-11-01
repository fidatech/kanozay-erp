<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('production_order_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('line_number');
            $table->string('material_name');
            $table->string('unit', 32);
            $table->decimal('quantity_per_package', 18, 6);
            $table->decimal('required_quantity', 22, 6);
            $table->boolean('is_packaging')->default(false);
            $table->timestamps();
            $table->unique(['production_order_id', 'line_number']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('production_order_materials');
    }
};
