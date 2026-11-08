<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quality_nonconformances',function(Blueprint $table){
            $table->id();
            $table->string('number',42)->unique();
            $table->uuid('submission_token')->unique();
            $table->foreignId('quality_sample_id')->nullable()->constrained('quality_samples',indexName:'quality_ncr_sample_fk')->restrictOnDelete();
            $table->foreignId('inventory_cost_layer_id')->nullable()->constrained('inventory_cost_layers',indexName:'quality_ncr_lot_fk')->restrictOnDelete();
            $table->string('severity',12);
            $table->string('status',18)->default('open');
            $table->date('opened_on');
            $table->date('due_on')->nullable();
            $table->text('description');
            $table->text('root_cause')->nullable();
            $table->text('corrective_action')->nullable();
            $table->text('verification_evidence')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('investigated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['status','due_on'],'quality_ncr_open_due');
        });
        Schema::create('quality_nonconformance_events',function(Blueprint $table){
            $table->id();
            $table->foreignId('quality_nonconformance_id')->constrained('quality_nonconformances',indexName:'quality_ncr_event_parent_fk')->restrictOnDelete();
            $table->string('action',18);
            $table->text('notes');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down():void
    {
        Schema::dropIfExists('quality_nonconformance_events');
        Schema::dropIfExists('quality_nonconformances');
    }
};
