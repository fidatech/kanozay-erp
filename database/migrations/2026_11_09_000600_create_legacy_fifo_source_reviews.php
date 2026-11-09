<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_legacy_fifo_source_reviews',function(Blueprint $table){
            $table->id();
            $table->foreignId('inventory_cost_layer_id')
                ->constrained('inventory_cost_layers',indexName:'flfsr_layer_fk')->restrictOnDelete();
            $table->foreignId('inventory_material_id')
                ->constrained('inventory_materials',indexName:'flfsr_material_fk')->restrictOnDelete();
            $table->string('review_type',32); // consumed_provenance | locate_remaining
            $table->string('status',20)->default('submitted'); // submitted | approved | rejected
            $table->foreignId('company_warehouse_id')->nullable()
                ->constrained('company_warehouses',indexName:'flfsr_warehouse_fk')->restrictOnDelete();
            $table->decimal('quantity',20,6)->nullable(); // solely remaining stock for physical attribution
            $table->decimal('opening_quantity',20,6);
            $table->decimal('consumed_quantity',20,6);
            $table->decimal('remaining_quantity',20,6);
            $table->char('source_fingerprint',64);
            $table->string('source_evidence_reference',180);
            $table->text('source_reconciliation_notes');
            $table->string('decision_reference',180)->nullable();
            $table->text('decision_notes')->nullable();
            $table->foreignId('requested_by')->constrained('users',indexName:'flfsr_requester_fk')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users',indexName:'flfsr_reviewer_fk')->restrictOnDelete();
            $table->foreignId('inventory_legacy_lot_assignment_id')->nullable()
                ->constrained('inventory_legacy_lot_assignments',indexName:'flfsr_assignment_fk')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['review_type','status','inventory_cost_layer_id'],'flfsr_type_status_lot');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_legacy_fifo_source_reviews');
    }
};
