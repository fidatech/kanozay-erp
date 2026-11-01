<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('inventory_materials', function (Blueprint $table) {
            $table->decimal('reserved_quantity', 20, 6)->default(0);
        });
        Schema::table('production_order_materials', function (Blueprint $table) {
            $table->decimal('reserved_quantity', 22, 6)->default(0);
            $table->decimal('issued_quantity', 22, 6)->default(0);
        });
        Schema::table('production_orders', function (Blueprint $table) {
            $table->string('status', 20)->default('planned')->change();
        });
    }

    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->enum('status', ['planned', 'cancelled'])->default('planned')->change();
        });
        Schema::table('production_order_materials', function (Blueprint $table) {
            $table->dropColumn(['reserved_quantity', 'issued_quantity']);
        });
        Schema::table('inventory_materials', function (Blueprint $table) {
            $table->dropColumn('reserved_quantity');
        });
    }
};
