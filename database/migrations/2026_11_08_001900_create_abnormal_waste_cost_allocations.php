<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up():void
    {
        Schema::create('production_abnormal_waste_cost_reviews',function(Blueprint $t){
            $t->id();
            $t->foreignId('production_material_waste_id')->unique('abnormal_waste_one_review')
                ->constrained('production_material_wastes',indexName:'abnormal_waste_source_fk')->restrictOnDelete();
            $t->foreignId('currency_id')
                ->constrained('currencies',indexName:'abnormal_waste_currency_fk')->restrictOnDelete();
            $t->foreignId('expense_ledger_id')
                ->constrained('ledger_accounts',indexName:'abnormal_waste_expense_fk')->restrictOnDelete();
            $t->decimal('source_cost_amount',24,10);
            $t->string('approval_reference',180);
            $t->text('source_evidence');
            $t->foreignId('approved_by')
                ->constrained('users',indexName:'abnormal_waste_reviewer_fk')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('production_abnormal_waste_cost_allocations',function(Blueprint $t){
            $t->id();
            $t->foreignId('production_abnormal_waste_cost_review_id')
                ->constrained('production_abnormal_waste_cost_reviews',indexName:'abnormal_waste_allocation_review_fk')
                ->restrictOnDelete();
            $t->foreignId('inventory_cost_consumption_id')
                ->constrained('inventory_cost_consumptions',indexName:'abnormal_waste_fifo_source_fk')->restrictOnDelete();
            $t->decimal('quantity',22,6);
            $t->decimal('source_cost_amount',24,10);
            $t->unique(
                ['production_abnormal_waste_cost_review_id','inventory_cost_consumption_id'],
                'abnormal_waste_unique_source_line'
            );
            $t->timestamps();
        });
        Schema::table('production_batch_cost_postings',function(Blueprint $t){
            $t->decimal('abnormal_waste_amount',20,4)->default(0)->after('material_amount');
        });
    }

    public function down():void
    {
        Schema::table('production_batch_cost_postings',
            fn(Blueprint $t)=>$t->dropColumn('abnormal_waste_amount'));
        Schema::dropIfExists('production_abnormal_waste_cost_allocations');
        Schema::dropIfExists('production_abnormal_waste_cost_reviews');
    }
};
