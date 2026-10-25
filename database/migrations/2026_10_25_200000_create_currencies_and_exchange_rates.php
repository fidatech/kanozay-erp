<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('currencies', function (Blueprint $table) {
            $table->id(); $table->char('code', 3)->unique(); $table->string('name', 100); $table->string('symbol', 12)->nullable();
            $table->unsignedTinyInteger('decimal_places')->default(2); $table->boolean('is_active')->default(true); $table->boolean('is_base')->default(false); $table->timestamps();
        });
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id(); $table->foreignId('from_currency_id')->constrained('currencies')->restrictOnDelete();
            $table->foreignId('to_currency_id')->constrained('currencies')->restrictOnDelete();
            $table->decimal('rate', 24, 12); $table->date('effective_date'); $table->string('source', 150)->nullable();
            $table->text('notes')->nullable(); $table->foreignId('created_by')->constrained('users')->restrictOnDelete(); $table->timestamps();
            $table->index(['from_currency_id','to_currency_id','effective_date'], 'exchange_pair_date_idx');
        });
    }
    public function down(): void { Schema::dropIfExists('exchange_rates'); Schema::dropIfExists('currencies'); }
};
