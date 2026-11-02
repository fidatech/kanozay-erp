<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('fiscal_start_month')->nullable();
            $table->unsignedTinyInteger('fiscal_start_day')->nullable();
        });
        Schema::create('company_branches', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 180);
            $table->string('address', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('company_warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_branch_id')->constrained('company_branches')->restrictOnDelete();
            $table->string('code', 30)->unique();
            $table->string('name', 180);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_warehouses');
        Schema::dropIfExists('company_branches');
        Schema::table('company_settings', fn (Blueprint $table) => $table->dropColumn(['fiscal_start_month', 'fiscal_start_day']));
    }
};
