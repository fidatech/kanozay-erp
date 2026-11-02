<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fixed_asset_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 160);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->string('asset_tag', 64)->unique();
            $table->string('name', 180);
            $table->foreignId('fixed_asset_category_id')->constrained('fixed_asset_categories')->restrictOnDelete();
            $table->date('acquired_on');
            $table->decimal('acquisition_cost', 20, 4);
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->string('source_reference', 160);
            $table->string('status', 20)->default('active');
            $table->foreignId('custodian_id')->nullable()->constrained('employee_profiles')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['status', 'fixed_asset_category_id']);
        });

        Schema::create('fixed_asset_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->restrictOnDelete();
            $table->foreignId('employee_profile_id')->constrained('employee_profiles')->restrictOnDelete();
            $table->date('assigned_on');
            $table->date('returned_on')->nullable();
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['fixed_asset_id', 'returned_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_assignments');
        Schema::dropIfExists('fixed_assets');
        Schema::dropIfExists('fixed_asset_categories');
    }
};
