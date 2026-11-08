<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up():void
    {
        Schema::create('finance_historical_dispatch_dispositions',function(Blueprint $t){
            $t->id();
            $t->foreignId('finance_historical_correction_case_id')
                ->unique('legacy_dispatch_one_case')
                ->constrained('finance_historical_correction_cases',indexName:'legacy_disp_case_fk')
                ->restrictOnDelete();
            $t->foreignId('finished_good_dispatch_id')
                ->unique('legacy_dispatch_one_source')
                ->constrained('finished_good_dispatches',indexName:'legacy_disp_source_fk')
                ->restrictOnDelete();
            $t->string('business_purpose',40);
            $t->string('supporting_document_reference',180);
            $t->text('source_evidence');
            $t->text('remaining_financial_action');
            $t->foreignId('reviewed_by')
                ->constrained('users',indexName:'legacy_disp_finance_review_fk')
                ->restrictOnDelete();
            $t->timestamps();
        });
    }

    public function down():void
    {
        Schema::dropIfExists('finance_historical_dispatch_dispositions');
    }
};
