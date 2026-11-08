<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_orders',function(Blueprint $table){
            // Nullable for legacy plans and existing integration callers.
            $table->uuid('submission_token')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('production_orders',function(Blueprint $table){
            $table->dropUnique(['submission_token']);
            $table->dropColumn('submission_token');
        });
    }
};
