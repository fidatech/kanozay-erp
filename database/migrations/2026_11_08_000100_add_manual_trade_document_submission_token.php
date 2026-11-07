<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trade_documents', function (Blueprint $table) {
            // Existing sales, GRN-matched supplier and historical documents
            // keep null tokens. New manual charges have unique submissions.
            $table->uuid('submission_token')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('trade_documents', function (Blueprint $table) {
            $table->dropUnique(['submission_token']);
            $table->dropColumn('submission_token');
        });
    }
};
