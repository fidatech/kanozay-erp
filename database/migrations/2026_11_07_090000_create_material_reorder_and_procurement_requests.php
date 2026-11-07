<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void
    {
        Schema::create('material_reorder_settings',function(Blueprint $table){
            $table->id();
            $table->foreignId('inventory_material_id')->unique()->constrained('inventory_materials')->restrictOnDelete();
            $table->decimal('minimum_quantity',20,6);
            $table->decimal('target_quantity',20,6);
            $table->unsignedSmallInteger('lead_days')->default(7);
            $table->foreignId('supplier_id')->nullable()->constrained('trade_parties')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('procurement_requests',function(Blueprint $table){
            $table->id();
            $table->string('number',42)->unique();
            $table->uuid('submission_token')->unique();
            $table->string('status',24)->default('submitted');
            $table->date('requested_on');
            $table->date('required_on')->nullable();
            $table->text('notes')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('purchase_order_id')->nullable()->unique()->constrained('purchase_orders')->restrictOnDelete();
            $table->timestamps();
            $table->index(['status','requested_on']);
        });
        Schema::create('procurement_request_lines',function(Blueprint $table){
            $table->id();
            $table->foreignId('procurement_request_id')->constrained('procurement_requests')->restrictOnDelete();
            $table->foreignId('inventory_material_id')->constrained('inventory_materials')->restrictOnDelete();
            $table->decimal('quantity',20,6);
            $table->string('unit_snapshot',30);
            $table->string('description',255)->nullable();
            $table->timestamps();
            $table->unique(['procurement_request_id','inventory_material_id'],'request_material_unique');
        });
    }
    public function down():void
    {
        Schema::dropIfExists('procurement_request_lines');
        Schema::dropIfExists('procurement_requests');
        Schema::dropIfExists('material_reorder_settings');
    }
};