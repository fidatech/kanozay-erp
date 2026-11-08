<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inventory_cost_layer_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_cost_layer_id')->constrained('inventory_cost_layers')->restrictOnDelete();
            $table->foreignId('company_warehouse_id')->nullable()->constrained('company_warehouses')->restrictOnDelete();
            // "unassigned" and "wh:{id}" avoid the NULL uniqueness loophole.
            $table->string('location_key', 40);
            $table->decimal('quantity', 20, 6)->default(0);
            $table->timestamps();
            $table->unique(['inventory_cost_layer_id','location_key'], 'lot_unique_location');
            $table->index(['company_warehouse_id','inventory_cost_layer_id'], 'lot_location_warehouse');
        });
        Schema::create('inventory_warehouse_lot_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_warehouse_movement_id')->constrained('inventory_warehouse_movements', indexName: 'lot_transfer_movement_fk')->restrictOnDelete();
            $table->foreignId('inventory_cost_layer_id')->constrained('inventory_cost_layers', indexName: 'lot_transfer_layer_fk')->restrictOnDelete();
            $table->foreignId('source_warehouse_id')->nullable()->constrained('company_warehouses', indexName: 'lot_transfer_source_fk')->restrictOnDelete();
            $table->foreignId('destination_warehouse_id')->constrained('company_warehouses', indexName: 'lot_transfer_destination_fk')->restrictOnDelete();
            $table->decimal('quantity', 20, 6);
            $table->timestamps();
            $table->unique(['inventory_warehouse_movement_id','inventory_cost_layer_id'], 'lot_transfer_unique_layer');
        });
        Schema::create('inventory_legacy_lot_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_cost_layer_id')->constrained('inventory_cost_layers', indexName: 'legacy_lot_layer_fk')->restrictOnDelete();
            $table->foreignId('company_warehouse_id')->nullable()->constrained('company_warehouses', indexName: 'legacy_lot_warehouse_fk')->restrictOnDelete();
            $table->decimal('quantity', 20, 6);
            $table->string('reference', 160);
            $table->text('reason');
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_legacy_lot_assignments');
        Schema::dropIfExists('inventory_warehouse_lot_movements');
        Schema::dropIfExists('inventory_cost_layer_locations');
    }
};
