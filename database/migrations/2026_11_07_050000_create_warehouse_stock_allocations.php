<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up():void
    {
        Schema::create('inventory_warehouse_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_material_id')->constrained('inventory_materials')->restrictOnDelete();
            $table->foreignId('company_warehouse_id')->constrained('company_warehouses')->restrictOnDelete();
            $table->decimal('quantity',20,6)->default(0);
            $table->timestamps();
            $table->unique(['inventory_material_id','company_warehouse_id'],'material_warehouse_unique');
        });

        Schema::create('inventory_warehouse_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_material_id')->constrained('inventory_materials')->restrictOnDelete();
            $table->foreignId('source_warehouse_id')->nullable()->constrained('company_warehouses')->restrictOnDelete();
            $table->foreignId('destination_warehouse_id')->constrained('company_warehouses')->restrictOnDelete();
            $table->enum('type',['initial_allocation','transfer']);
            $table->decimal('quantity',20,6);
            $table->date('occurred_on');
            $table->string('reason',500);
            $table->uuid('idempotency_key')->unique();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['inventory_material_id','occurred_on'],'warehouse_material_day');
        });
    }
    public function down():void
    {
        Schema::dropIfExists('inventory_warehouse_movements');
        Schema::dropIfExists('inventory_warehouse_balances');
    }
};
