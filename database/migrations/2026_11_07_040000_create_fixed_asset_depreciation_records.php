<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fixed_asset_depreciation_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixed_asset_id')->unique()->constrained('fixed_assets')->restrictOnDelete();
            $table->unsignedSmallInteger('useful_life_months');
            $table->decimal('salvage_value', 20, 4);
            $table->date('start_month');
            $table->foreignId('expense_ledger_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->foreignId('accumulated_ledger_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('fixed_asset_depreciation_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->restrictOnDelete();
            $table->foreignId('policy_id')->constrained('fixed_asset_depreciation_policies')->restrictOnDelete();
            $table->date('period_month');
            $table->decimal('amount', 20, 4);
            $table->foreignId('journal_entry_id')->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('posted_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['fixed_asset_id','period_month'],'asset_depreciation_month_unique');
        });
    }
    public function down():void
    {
        Schema::dropIfExists('fixed_asset_depreciation_entries');
        Schema::dropIfExists('fixed_asset_depreciation_policies');
    }
};
