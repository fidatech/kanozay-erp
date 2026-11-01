<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inventory_materials', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 64)->unique();
            $table->string('name', 180);
            $table->string('unit', 30);
            $table->boolean('is_packaging')->default(false);
            $table->boolean('is_active')->default(true);
            $table->decimal('on_hand_quantity', 20, 6)->default(0);
            $table->timestamps();
        });

        Schema::create('inventory_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_material_id')->constrained('inventory_materials')->restrictOnDelete();
            $table->enum('type', ['opening', 'receipt', 'issue', 'adjustment_in', 'adjustment_out']);
            $table->enum('direction', ['in', 'out']);
            $table->decimal('quantity', 20, 6);
            $table->string('reference_type', 40);
            $table->unsignedBigInteger('reference_id');
            $table->date('occurred_on');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['reference_type', 'reference_id'], 'inventory_stock_reference_unique');
            $table->index(['inventory_material_id', 'occurred_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_stock_movements');
        Schema::dropIfExists('inventory_materials');
    }
};
