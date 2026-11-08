<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up():void
    {
        Schema::create('production_waste_financial_review_events',function(Blueprint $table){
            $table->id();
            $table->foreignId('production_waste_financial_review_id')
                ->constrained('production_waste_financial_reviews',indexName:'waste_review_event_review_fk')
                ->restrictOnDelete();
            $table->string('previous_treatment',32)->nullable();
            $table->string('new_treatment',32);
            $table->string('previous_approval_reference',180)->nullable();
            $table->string('approval_reference',180);
            $table->string('qc_reinspection_reference',180)->nullable();
            $table->string('approved_loss_policy_reference',180)->nullable();
            $table->date('physical_rework_completed_on')->nullable();
            $table->text('evidence_notes');
            $table->foreignId('reviewed_by')
                ->constrained('users',indexName:'waste_review_event_user_fk')
                ->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down():void
    {
        Schema::dropIfExists('production_waste_financial_review_events');
    }
};
