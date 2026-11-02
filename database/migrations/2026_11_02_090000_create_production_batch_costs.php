<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('production_batch_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_batch_id')->constrained()->restrictOnDelete();
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->restrictOnDelete();
            $table->string('currency_code', 3)->nullable();
            $table->decimal('material_cost', 42, 10)->nullable();
            $table->decimal('consumed_quantity', 22, 6)->default(0);
            $table->decimal('unvalued_quantity', 22, 6)->default(0);
            $table->timestamps();
            $table->unique(['production_batch_id', 'currency_code'], 'production_batch_cost_currency_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_batch_costs');
    }
};
