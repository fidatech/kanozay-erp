<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('supplier_advance_postings',function(Blueprint $t){
   $t->id();$t->string('number',40)->unique();$t->uuid('submission_token')->unique();
   $t->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
   $t->foreignId('supplier_id')->constrained('trade_parties')->restrictOnDelete();
   $t->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
   $t->foreignId('source_account_id')->constrained('financial_accounts')->restrictOnDelete();
   $t->foreignId('advance_ledger_id')->constrained('ledger_accounts')->restrictOnDelete();
   $t->foreignId('money_transaction_id')->unique()->constrained('money_transactions')->restrictOnDelete();
   $t->date('paid_on');$t->decimal('amount',20,4);
   $t->string('reference',120);$t->foreignId('created_by')->constrained('users')->restrictOnDelete();
   $t->timestamps();$t->index(['supplier_id','currency_id','paid_on'],'advance_supplier_ccy_date');
  });
  Schema::create('supplier_advance_applications',function(Blueprint $t){
   $t->id();$t->uuid('submission_token')->unique();$t->string('number',40)->unique();
   $t->foreignId('supplier_advance_posting_id')->constrained('supplier_advance_postings',indexName:'advance_application_source_fk')->restrictOnDelete();
   $t->foreignId('trade_document_id')->constrained('trade_documents')->restrictOnDelete();
   $t->foreignId('journal_entry_id')->unique()->constrained('journal_entries')->restrictOnDelete();
   $t->date('applied_on');$t->decimal('amount',20,4);
   $t->string('approval_reference',180);
   $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
   $t->timestamps();$t->index(['supplier_advance_posting_id','applied_on'],'advance_application_date');
  });
  Schema::table('trade_document_credits',function(Blueprint $t){
   $t->foreignId('supplier_advance_application_id')->nullable()->unique()
    ->constrained('supplier_advance_applications',indexName:'trade_advance_application_fk')->restrictOnDelete();
  });
 }
 public function down():void {
  Schema::table('trade_document_credits',fn(Blueprint $t)=>$t->dropConstrainedForeignId('supplier_advance_application_id'));
  Schema::dropIfExists('supplier_advance_applications');
  Schema::dropIfExists('supplier_advance_postings');
 }
};