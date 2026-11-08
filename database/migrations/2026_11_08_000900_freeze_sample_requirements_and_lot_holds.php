<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quality_sample_required_specifications',function(Blueprint $table){
            $table->id();
            $table->foreignId('quality_sample_id')->constrained('quality_samples',indexName:'sample_requirement_sample_fk')->restrictOnDelete();
            $table->foreignId('quality_specification_id')->constrained('quality_specifications',indexName:'sample_requirement_spec_fk')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['quality_sample_id','quality_specification_id'],'sample_required_spec_unique');
        });
        Schema::create('quality_material_lot_holds',function(Blueprint $table){
            $table->id();
            $table->uuid('submission_token')->unique();
            $table->foreignId('inventory_cost_layer_id')->constrained('inventory_cost_layers',indexName:'quality_lot_hold_layer_fk')->restrictOnDelete();
            $table->string('status',18)->default('held');
            $table->string('reference',180);
            $table->text('reason');
            $table->foreignId('held_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('held_at');
            $table->foreignId('released_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->string('release_reference',180)->nullable();
            $table->text('release_reason')->nullable();
            $table->timestamps();
            $table->index(['inventory_cost_layer_id','status'],'quality_lot_hold_open');
        });
        Schema::create('quality_material_lot_hold_events',function(Blueprint $table){
            $table->id();
            $table->foreignId('quality_material_lot_hold_id')->constrained('quality_material_lot_holds',indexName:'quality_lot_event_hold_fk')->restrictOnDelete();
            $table->string('action',18);
            $table->string('reference',180);
            $table->text('reason');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_material_lot_hold_events');
        Schema::dropIfExists('quality_material_lot_holds');
        Schema::dropIfExists('quality_sample_required_specifications');
    }
};
