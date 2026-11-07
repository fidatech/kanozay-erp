<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('supplier_debit_notes',function(Blueprint $t){
   $t->id();$t->string('number',45)->unique();$t->uuid('submission_token')->unique();
   $t->foreignId('purchase_return_record_id')->unique()->constrained('purchase_return_records')->restrictOnDelete();
   $t->foreignId('trade_document_id')->constrained('trade_documents')->restrictOnDelete();
   $t->foreignId('journal_entry_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();
   $t->foreignId('credit_offset_ledger_id')->constrained('ledger_accounts')->restrictOnDelete();
   $t->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
   $t->date('credit_date');$t->decimal('amount',24,4);
   $t->string('supplier_credit_reference',180);$t->text('review_notes');
   $t->foreignId('created_by')->constrained('users')->restrictOnDelete();$t->timestamps();
  });
  Schema::table('trade_document_credits',function(Blueprint $t){
   $t->foreignId('sales_credit_note_id')->nullable()->change();
   $t->foreignId('supplier_debit_note_id')->nullable()->unique()->constrained('supplier_debit_notes')->restrictOnDelete();
  });
 }
 public function down():void {
  Schema::table('trade_document_credits',function(Blueprint $t){
   $t->dropConstrainedForeignId('supplier_debit_note_id');
   $t->foreignId('sales_credit_note_id')->nullable(false)->change();
  });
  Schema::dropIfExists('supplier_debit_notes');
 }
};