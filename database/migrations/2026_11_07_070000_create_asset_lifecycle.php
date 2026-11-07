<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void
    {
        Schema::table('fixed_assets',function(Blueprint $table){
            $table->foreignId('company_warehouse_id')->nullable()->constrained('company_warehouses')->restrictOnDelete();
        });
        Schema::create('fixed_asset_maintenance_orders',function(Blueprint $table){
            $table->id();
            $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->restrictOnDelete();
            $table->string('ticket',40)->unique();
            $table->uuid('submission_token')->unique();
            $table->string('type',20);
            $table->string('status',24)->default('requested');
            $table->string('summary',180);
            $table->date('planned_on')->nullable();
            $table->date('serviced_on')->nullable();
            $table->string('provider',160)->nullable();
            $table->text('notes')->nullable();
            $table->text('completion_notes')->nullable();
            $table->decimal('actual_cost',20,4)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status','planned_on']);
        });
        Schema::create('fixed_asset_location_movements',function(Blueprint $table){
            $table->id();
            $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->restrictOnDelete();
            $table->foreignId('source_warehouse_id')->nullable()->constrained('company_warehouses')->restrictOnDelete();
            $table->foreignId('destination_warehouse_id')->constrained('company_warehouses')->restrictOnDelete();
            $table->date('moved_on');
            $table->string('reason',500);
            $table->uuid('submission_token')->unique();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['fixed_asset_id','moved_on']);
        });
        Schema::create('fixed_asset_disposals',function(Blueprint $table){
            $table->id();
            $table->foreignId('fixed_asset_id')->unique()->constrained('fixed_assets')->restrictOnDelete();
            $table->date('disposed_on');
            $table->string('method',20);
            $table->decimal('proceeds',20,4)->default(0);
            $table->decimal('accumulated_depreciation',20,4);
            $table->decimal('book_value',20,4);
            $table->string('financial_status',30)->default('pending_reconciliation');
            $table->text('reason');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }
    public function down():void
    {
        Schema::dropIfExists('fixed_asset_disposals');
        Schema::dropIfExists('fixed_asset_location_movements');
        Schema::dropIfExists('fixed_asset_maintenance_orders');
        Schema::table('fixed_assets',fn(Blueprint $table)=>$table->dropConstrainedForeignId('company_warehouse_id'));
    }
};