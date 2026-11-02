<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('finished_good_dispatches', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->uuid('submission_token')->unique();
            $table->date('dispatch_date');
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('finished_good_dispatch_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('finished_good_dispatch_id')->constrained()->restrictOnDelete();
            $table->foreignId('finished_good_id')->constrained()->restrictOnDelete();
            $table->foreignId('production_batch_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity_packages');
            $table->timestamps();
            $table->unique(['finished_good_dispatch_id', 'production_batch_id'], 'fg_dispatch_batch_unique');
            $table->index(['production_batch_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finished_good_dispatch_lines');
        Schema::dropIfExists('finished_good_dispatches');
    }
};
