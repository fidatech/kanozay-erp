<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('finished_goods', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 64)->unique();
            $table->string('name', 180);
            $table->string('package_unit', 40);
            $table->decimal('package_weight_kg', 12, 3);
            $table->unsignedBigInteger('on_hand_packages')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('production_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('finished_good_id')->constrained()->restrictOnDelete();
            $table->string('batch_number', 64)->unique();
            $table->unsignedInteger('reported_packages');
            $table->unsignedInteger('accepted_packages')->default(0);
            $table->unsignedInteger('rejected_packages')->default(0);
            $table->string('status', 24)->default('pending_qc');
            $table->text('notes')->nullable();
            $table->text('qc_notes')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
        });

        Schema::create('production_batch_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_batch_id')->constrained()->cascadeOnDelete();
            $table->string('test_name', 120);
            $table->text('specification')->nullable();
            $table->text('observation')->nullable();
            $table->enum('result', ['pass', 'fail']);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['production_batch_id', 'test_name'], 'production_batch_test_unique');
        });

        Schema::create('finished_good_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('finished_good_id')->constrained()->restrictOnDelete();
            $table->foreignId('production_batch_id')->unique()->constrained()->restrictOnDelete();
            $table->string('type', 24);
            $table->unsignedInteger('quantity_packages');
            $table->date('occurred_on');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finished_good_movements');
        Schema::dropIfExists('production_batch_checks');
        Schema::dropIfExists('production_batches');
        Schema::dropIfExists('finished_goods');
    }
};
