<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table) {
            // Historical imports remain unknown; never invent hire dates.
            $table->date('hired_on')->nullable();
            $table->date('separated_on')->nullable();
        });

        Schema::create('payroll_line_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_line_id')->unique()
                ->constrained('payroll_lines')->restrictOnDelete();
            $table->char('evidence_hash', 64);
            $table->string('reference', 180);
            $table->text('review_notes');
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_line_reviews');
        Schema::table('employee_profiles', function(Blueprint $table) {
            $table->dropColumn(['hired_on','separated_on']);
        });
    }
};
