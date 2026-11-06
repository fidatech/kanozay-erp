<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_stock_counts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->uuid('submission_token')->unique();
            $table->date('counted_on');
            $table->string('status', 16)->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->index(['counted_on', 'status']);
        });

        Schema::create('inventory_stock_count_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_stock_count_id')->constrained()->restrictOnDelete();
            $table->foreignId('inventory_material_id')->constrained()->restrictOnDelete();
            $table->decimal('expected_quantity', 20, 6);
            $table->decimal('counted_quantity', 20, 6);
            $table->uuid('idempotency_key')->unique();
            $table->foreignId('inventory_stock_adjustment_id')->nullable()
                ->constrained('inventory_stock_adjustments')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['inventory_stock_count_id', 'inventory_material_id'], 'stock_count_material_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_stock_count_lines');
        Schema::dropIfExists('inventory_stock_counts');
    }
};
