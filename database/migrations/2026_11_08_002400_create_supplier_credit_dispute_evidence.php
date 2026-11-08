<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up():void
    {
        Schema::create('finance_supplier_return_credit_disputes',function(Blueprint $t){
            $t->id();
            $t->foreignId('finance_historical_correction_case_id')
                ->unique('supplier_credit_dispute_case_once')
                ->constrained('finance_historical_correction_cases',indexName:'supplier_dispute_case_fk')
                ->restrictOnDelete();
            $t->foreignId('purchase_return_record_id')
                ->unique('supplier_credit_dispute_source_once')
                ->constrained('purchase_return_records',indexName:'supplier_dispute_return_fk')
                ->restrictOnDelete();
            $t->foreignId('currency_id')
                ->constrained('currencies',indexName:'supplier_dispute_currency_fk')
                ->restrictOnDelete();
            $t->decimal('source_base_return_value',20,4);
            $t->decimal('supplier_offered_credit',20,4);
            $t->decimal('unresolved_difference',20,4);
            $t->string('requested_treatment',40);
            $t->string('supplier_document_reference',180);
            $t->text('source_evidence');
            $t->text('remaining_approval_action');
            $t->foreignId('reviewed_by')
                ->constrained('users',indexName:'supplier_dispute_reviewer_fk')
                ->restrictOnDelete();
            $t->timestamps();
        });
    }

    public function down():void
    {
        Schema::dropIfExists('finance_supplier_return_credit_disputes');
    }
};
