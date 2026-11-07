<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('bank_reconciliation_periods',function(Blueprint $table){
   $table->id();
   $table->string('number',40)->unique();
   $table->foreignId('financial_account_id')->constrained('financial_accounts',indexName:'bank_rec_account_fk')->restrictOnDelete();
   $table->foreignId('currency_id')->constrained('currencies',indexName:'bank_rec_currency_fk')->restrictOnDelete();
   $table->date('period_start');$table->date('period_end');
   $table->decimal('statement_opening',24,4);
   $table->decimal('statement_closing',24,4);
   $table->string('status',20)->default('draft');
   $table->string('statement_reference',180);
   $table->text('review_notes')->nullable();
   $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
   $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
   $table->timestamp('approved_at')->nullable();
   $table->timestamps();
   $table->unique(['financial_account_id','period_start','period_end'],'bank_rec_account_period_unique');
  });
  Schema::create('bank_statement_entries',function(Blueprint $table){
   $table->id();
   $table->foreignId('bank_reconciliation_period_id')->constrained('bank_reconciliation_periods',indexName:'bank_statement_period_fk')->restrictOnDelete();
   $table->uuid('submission_token')->unique();
   $table->date('posted_on');
   $table->string('direction',16);
   $table->decimal('amount',24,4);
   $table->string('bank_reference',180);
   $table->string('description',500)->nullable();
   $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
   $table->timestamps();
  });
  Schema::create('bank_statement_matches',function(Blueprint $table){
   $table->id();
   $table->foreignId('bank_statement_entry_id')->unique()->constrained('bank_statement_entries',indexName:'bank_match_entry_fk')->restrictOnDelete();
   $table->foreignId('money_transaction_id')->constrained('money_transactions',indexName:'bank_match_money_fk')->restrictOnDelete();
   $table->foreignId('financial_account_id')->constrained('financial_accounts',indexName:'bank_match_account_fk')->restrictOnDelete();
   $table->foreignId('matched_by')->constrained('users')->restrictOnDelete();
   $table->timestamps();
   $table->unique(['financial_account_id','money_transaction_id'],'bank_match_account_money_unique');
  });
 }
 public function down():void {
  Schema::dropIfExists('bank_statement_matches');
  Schema::dropIfExists('bank_statement_entries');
  Schema::dropIfExists('bank_reconciliation_periods');
 }
};