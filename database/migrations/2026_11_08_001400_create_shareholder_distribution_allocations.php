<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up():void
    {
        Schema::create('shareholder_distribution_allocations',function(Blueprint $table){
            $table->id();
            $table->foreignId('shareholder_resolution_id')
                ->constrained('shareholder_resolutions',indexName:'sh_alloc_resolution_fk')
                ->restrictOnDelete();
            $table->foreignId('shareholder_id')->constrained('shareholders')->restrictOnDelete();
            $table->decimal('authorized_amount',20,4);
            $table->string('approval_reference',180);
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['shareholder_resolution_id','shareholder_id'],'sh_alloc_one_recipient');
        });

        Schema::create('shareholder_distribution_allocation_events',function(Blueprint $table){
            $table->id();
            $table->foreignId('shareholder_distribution_allocation_id')
                ->constrained('shareholder_distribution_allocations',indexName:'sh_alloc_event_fk')
                ->restrictOnDelete();
            $table->decimal('previous_amount',20,4);
            $table->decimal('new_amount',20,4);
            $table->string('approval_reference',180);
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down():void
    {
        Schema::dropIfExists('shareholder_distribution_allocation_events');
        Schema::dropIfExists('shareholder_distribution_allocations');
    }
};
