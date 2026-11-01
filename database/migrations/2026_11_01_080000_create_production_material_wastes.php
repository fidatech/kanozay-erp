<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('production_material_wastes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_material_id')->constrained()->restrictOnDelete();
            $table->uuid('submission_token')->unique();
            $table->decimal('quantity', 22, 6);
            $table->text('reason');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_material_wastes');
    }
};
