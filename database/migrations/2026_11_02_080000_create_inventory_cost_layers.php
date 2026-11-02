<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inventory_cost_layers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_material_id')->constrained()->restrictOnDelete();
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');
            $table->decimal('original_quantity', 20, 6);
            $table->decimal('remaining_quantity', 20, 6);
            $table->decimal('original_cost', 42, 10)->nullable();
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->restrictOnDelete();
            $table->string('currency_code', 3)->nullable();
            $table->date('received_on');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['source_type', 'source_id'], 'inventory_cost_layer_source_unique');
            $table->index(['inventory_material_id', 'received_on', 'id'], 'inventory_cost_layer_fifo_index');
        });

        Schema::create('inventory_cost_consumptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_stock_movement_id')->constrained()->restrictOnDelete();
            $table->foreignId('inventory_cost_layer_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('quantity', 20, 6);
            $table->decimal('amount', 42, 10)->nullable();
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->restrictOnDelete();
            $table->string('currency_code', 3)->nullable();
            $table->boolean('is_valued')->default(false);
            $table->timestamps();
            $table->unique(['inventory_stock_movement_id', 'inventory_cost_layer_id'], 'inventory_cost_consumption_layer_unique');
            $table->index(['inventory_cost_layer_id', 'is_valued'], 'icc_layer_valued_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_cost_consumptions');
        Schema::dropIfExists('inventory_cost_layers');
    }
};
