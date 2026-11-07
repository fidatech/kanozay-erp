<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_delivery_notes', function (Blueprint $table) {
            $table->id();
            $table->string('number', 42)->unique();
            $table->uuid('submission_token')->unique();
            $table->foreignId('sales_invoice_id')->constrained('sales_invoices')->restrictOnDelete();
            $table->date('delivery_date');
            $table->string('status', 24)->default('issued');
            $table->string('delivery_address', 500)->nullable();
            $table->string('vehicle_number', 100)->nullable();
            $table->string('driver_name', 150)->nullable();
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->string('received_by', 150)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('delivered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['sales_invoice_id','status']);
        });

        Schema::create('sales_delivery_note_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_delivery_note_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_invoice_line_id')->constrained('sales_invoice_lines')->restrictOnDelete();
            $table->unsignedInteger('quantity_packages');
            $table->timestamps();
            $table->unique(['sales_delivery_note_id','sales_invoice_line_id'],'delivery_note_invoice_line_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_delivery_note_lines');
        Schema::dropIfExists('sales_delivery_notes');
    }
};
