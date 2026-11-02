<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_profile_id')->constrained('employee_profiles')->restrictOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->text('reason');
            $table->string('status', 20)->default('pending');
            $table->text('decision_note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['employee_profile_id', 'starts_on', 'ends_on'], 'elr_employee_dates_idx');
            $table->index(['status', 'starts_on']);
        });
    }

    public function down(): void { Schema::dropIfExists('employee_leave_requests'); }
};
