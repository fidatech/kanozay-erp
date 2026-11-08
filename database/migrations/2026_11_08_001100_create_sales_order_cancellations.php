<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up():void
    {
        Schema::create('sales_order_cancellations',function(Blueprint $table){
            $table->id();
            $table->foreignId('sales_order_id')->unique()
                ->constrained('sales_orders')->restrictOnDelete();
            $table->uuid('submission_token')->unique();
            $table->text('reason');
            $table->foreignId('cancelled_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at');
            $table->timestamps();
        });
    }

    public function down():void
    {
        Schema::dropIfExists('sales_order_cancellations');
    }
};
