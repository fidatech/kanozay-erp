<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_historical_batch_capitalizations', function(Blueprint $t) {
            // Null dispatch count identifies existing all-invoiced proposals
            // created before the mixed-source ledger was introduced.
            $t->unsignedInteger('dispatch_line_count')->nullable();
            $t->unsignedInteger('non_sale_line_count')->default(0);
            $t->unsignedInteger('posted_non_sale_lines')->default(0);
        });

        Schema::create('finance_historical_batch_dispatch_allocations', function(Blueprint $t) {
            $t->id();
            $t->foreignId('finance_historical_batch_capitalization_id')
                ->constrained('finance_historical_batch_capitalizations', indexName:'fhba_plan_fk')
                ->restrictOnDelete();
            $t->foreignId('finished_good_dispatch_line_id')
                ->unique('fhba_once_per_physical_line')
                ->constrained('finished_good_dispatch_lines', indexName:'fhba_dispatch_fk')
                ->restrictOnDelete();
            $t->foreignId('sales_invoice_line_id')->nullable()->unique('fhba_one_invoice_line')
                ->constrained('sales_invoice_lines', indexName:'fhba_invoice_fk')->restrictOnDelete();
            $t->foreignId('finance_historical_dispatch_disposition_id')->nullable()
                ->constrained('finance_historical_dispatch_dispositions', indexName:'fhba_disposition_fk')
                ->restrictOnDelete();
            $t->foreignId('currency_id')->constrained('currencies', indexName:'fhba_currency_fk')
                ->restrictOnDelete();
            $t->foreignId('expense_ledger_id')->nullable()
                ->constrained('ledger_accounts', indexName:'fhba_expense_fk')->restrictOnDelete();
            $t->string('business_purpose',40);
            $t->date('dispatch_date');
            $t->unsignedInteger('quantity_packages');
            $t->decimal('source_cost_amount',20,4);
            $t->foreignId('journal_entry_id')->nullable()->unique('fhba_unique_gl')
                ->constrained('journal_entries', indexName:'fhba_journal_fk')->restrictOnDelete();
            $t->timestamps();
            $t->index('finance_historical_batch_capitalization_id','fhba_by_plan');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_historical_batch_dispatch_allocations');
        Schema::table('finance_historical_batch_capitalizations', function(Blueprint $t) {
            $t->dropColumn(['dispatch_line_count','non_sale_line_count','posted_non_sale_lines']);
        });
    }
};
