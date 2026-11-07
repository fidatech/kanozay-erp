<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('sales_credit_note_refunds',function(Blueprint $t){
   $t->id();
   $t->foreignId('sales_credit_note_id')->unique()->constrained('sales_credit_notes',indexName:'sales_refund_credit_fk')->restrictOnDelete();
   $t->foreignId('money_transaction_id')->unique()->constrained('money_transactions',indexName:'sales_refund_money_fk')->restrictOnDelete();
   $t->foreignId('source_account_id')->constrained('financial_accounts')->restrictOnDelete();
   $t->decimal('amount',20,4);$t->string('authorization_reference',120);
   $t->foreignId('created_by')->constrained('users')->restrictOnDelete();$t->timestamps();
  });
 }
 public function down():void {Schema::dropIfExists('sales_credit_note_refunds');}
};