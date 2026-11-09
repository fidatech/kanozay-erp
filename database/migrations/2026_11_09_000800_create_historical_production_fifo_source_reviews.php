<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_historical_fifo_production_cost_reviews',function(Blueprint $t){
            $t->id();
            $t->foreignId('inventory_cost_layer_id')->unique('fhfp_one_lot')
                ->constrained('inventory_cost_layers',indexName:'fhfp_lot_fk')->restrictOnDelete();
            $t->foreignId('finance_legacy_fifo_source_review_id')->unique('fhfp_provenance_once')
                ->constrained('finance_legacy_fifo_source_reviews',indexName:'fhfp_proof_fk')->restrictOnDelete();
            $t->string('status',24)->default('submitted'); // submitted / approved / rejected (no GL posting in this workflow)
            $t->foreignId('currency_id')->constrained('currencies',indexName:'fhfp_currency_fk')->restrictOnDelete();
            $t->foreignId('source_inventory_ledger_id')
                ->constrained('ledger_accounts',indexName:'fhfp_asset_fk')->restrictOnDelete();
            $t->foreignId('target_wip_ledger_id')
                ->constrained('ledger_accounts',indexName:'fhfp_wip_fk')->restrictOnDelete();
            $t->decimal('original_lot_cost',20,4);
            $t->decimal('consumed_production_cost',20,4);
            $t->decimal('remaining_inventory_cost',20,4);
            $t->decimal('original_quantity',20,6);
            $t->decimal('consumed_quantity',20,6);
            $t->decimal('remaining_quantity',20,6);
            $t->char('source_fingerprint',64);
            $t->string('original_cost_evidence_reference',180);
            $t->text('original_cost_evidence_notes');
            $t->string('approval_reference',180)->nullable();
            $t->text('approval_notes')->nullable();
            $t->foreignId('submitted_by')->constrained('users',indexName:'fhfp_submit_fk')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users',indexName:'fhfp_approve_fk')->restrictOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
        });
        Schema::create('finance_historical_fifo_production_cost_lines',function(Blueprint $t){
            $t->id();
            $t->foreignId('finance_historical_fifo_production_cost_review_id')
                ->constrained('finance_historical_fifo_production_cost_reviews',indexName:'fhfpl_review_fk')->restrictOnDelete();
            $t->foreignId('inventory_cost_consumption_id')->unique('fhfpl_one_issue')
                ->constrained('inventory_cost_consumptions',indexName:'fhfpl_issue_fk')->restrictOnDelete();
            $t->foreignId('inventory_stock_movement_id')
                ->constrained('inventory_stock_movements',indexName:'fhfpl_move_fk')->restrictOnDelete();
            $t->foreignId('production_order_material_id')
                ->constrained('production_order_materials',indexName:'fhfpl_material_fk')->restrictOnDelete();
            $t->foreignId('production_batch_id')
                ->constrained('production_batches',indexName:'fhfpl_batch_fk')->restrictOnDelete();
            $t->date('original_material_issue_date');
            $t->decimal('quantity',20,6);
            $t->decimal('proposed_original_wip_cost',20,4);
            $t->char('source_fingerprint',64);
            $t->timestamps();
            $t->index('production_batch_id','fhfpl_batch_idx');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('finance_historical_fifo_production_cost_lines');
        Schema::dropIfExists('finance_historical_fifo_production_cost_reviews');
    }
};
