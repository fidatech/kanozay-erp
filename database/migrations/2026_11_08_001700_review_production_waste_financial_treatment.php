<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up():void
    {
        Schema::create('production_waste_financial_reviews',function(Blueprint $table){
            $table->id();
            $table->foreignId('production_material_waste_id')
                ->unique('production_waste_review_once')
                ->constrained('production_material_wastes',indexName:'production_waste_source_review_fk')
                ->restrictOnDelete();
            $table->string('treatment',32);
            $table->string('approval_reference',180);
            $table->text('review_notes');
            $table->foreignId('reviewed_by')
                ->constrained('users',indexName:'production_waste_finance_reviewer_fk')
                ->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down():void
    {
        Schema::dropIfExists('production_waste_financial_reviews');
    }
};
