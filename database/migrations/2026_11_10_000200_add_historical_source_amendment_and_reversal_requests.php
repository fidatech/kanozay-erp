<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_historical_source_amendments',function(Blueprint $t){
            $t->id();
            $t->foreignId('original_case_id')->unique('fhsa_original_once')
                ->constrained('finance_historical_correction_cases',indexName:'fhsa_original_fk')->restrictOnDelete();
            $t->foreignId('replacement_case_id')->unique('fhsa_replacement_once')
                ->constrained('finance_historical_correction_cases',indexName:'fhsa_replacement_fk')->restrictOnDelete();
            $t->char('original_fingerprint',64);
            $t->char('replacement_fingerprint',64);
            $t->text('amendment_reason');
            $t->foreignId('submitted_by')->constrained('users',indexName:'fhsa_user_fk')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('finance_historical_source_reversal_requests',function(Blueprint $t){
            $t->id();
            $t->foreignId('finance_historical_correction_case_id')
                ->unique('fhsrr_one_request_per_case')
                ->constrained('finance_historical_correction_cases',indexName:'fhsrr_source_fk')->restrictOnDelete();
            $t->string('status',24)->default('submitted'); // documentary submitted / approved / rejected, NO accounting reversal
            $t->char('source_fingerprint',64);
            $t->string('original_source_reference',150);
            $t->text('original_accounting_effects');
            $t->text('requested_replacement_treatment');
            $t->string('evidence_reference',180);
            $t->string('review_reference',180)->nullable();
            $t->text('review_notes')->nullable();
            $t->foreignId('requested_by')->constrained('users',indexName:'fhsrr_request_fk')->restrictOnDelete();
            $t->foreignId('reviewed_by')->nullable()->constrained('users',indexName:'fhsrr_review_fk')->restrictOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('finance_historical_source_reversal_requests');
        Schema::dropIfExists('finance_historical_source_amendments');
    }
};
