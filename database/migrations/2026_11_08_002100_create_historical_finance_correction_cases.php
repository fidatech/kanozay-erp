<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up():void
    {
        Schema::create('finance_historical_correction_cases',function(Blueprint $t){
            $t->id();
            $t->string('case_number',45)->unique();
            $t->string('case_type',50);
            $t->unsignedBigInteger('source_id');
            $t->string('source_reference',150);
            $t->date('source_date');
            $t->foreignId('source_currency_id')->nullable()
                ->constrained('currencies',indexName:'fhcc_currency_fk')->restrictOnDelete();
            $t->char('source_fingerprint',64);
            $t->string('open_key',130)->nullable()->unique('fhcc_one_open_source');
            $t->string('status',20)->default('submitted');
            $t->text('identified_discrepancy');
            $t->text('source_evidence');
            $t->foreignId('requested_by')->constrained('users',indexName:'fhcc_requested_fk')->restrictOnDelete();
            $t->string('approval_reference',180)->nullable();
            $t->text('review_notes')->nullable();
            $t->foreignId('reviewed_by')->nullable()
                ->constrained('users',indexName:'fhcc_reviewer_fk')->restrictOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->string('resolution_reference',180)->nullable();
            $t->text('resolution_notes')->nullable();
            $t->foreignId('executed_by')->nullable()
                ->constrained('users',indexName:'fhcc_executed_fk')->restrictOnDelete();
            $t->timestamp('executed_at')->nullable();
            $t->unsignedInteger('posted_cogs_lines')->default(0);
            $t->timestamps();
            $t->index(['case_type','status','source_date'],'fhcc_type_status_date');
        });
        Schema::create('finance_historical_correction_events',function(Blueprint $t){
            $t->id();
            $t->foreignId('finance_historical_correction_case_id')
                ->constrained('finance_historical_correction_cases',indexName:'fhce_case_fk')
                ->restrictOnDelete();
            $t->string('action',40);
            $t->string('source_reference',150);
            $t->char('source_fingerprint',64);
            $t->string('approval_reference',180)->nullable();
            $t->text('notes');
            $t->foreignId('actor_id')
                ->constrained('users',indexName:'fhce_actor_fk')->restrictOnDelete();
            $t->timestamps();
        });
    }

    public function down():void
    {
        Schema::dropIfExists('finance_historical_correction_events');
        Schema::dropIfExists('finance_historical_correction_cases');
    }
};
