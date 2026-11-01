<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('goods_receipt_landed_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained()->restrictOnDelete();
            $table->string('component', 40);
            $table->string('currency_code', 3);
            $table->decimal('amount', 24, 2);
            $table->string('reference', 100)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['goods_receipt_id', 'component']);
        });

        Schema::create('goods_receipt_landed_cost_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_landed_cost_id')->constrained()->restrictOnDelete();
            $table->foreignId('goods_receipt_line_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 24, 2);
            $table->timestamps();
            $table->unique(['goods_receipt_landed_cost_id', 'goods_receipt_line_id'], 'grn_cost_line_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_landed_cost_allocations');
        Schema::dropIfExists('goods_receipt_landed_costs');
    }
};
