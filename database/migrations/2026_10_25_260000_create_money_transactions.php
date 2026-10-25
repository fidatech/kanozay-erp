<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {Schema::create('money_transactions',function(Blueprint $t){$t->id();$t->string('type',20);$t->date('transaction_date');$t->foreignId('source_account_id')->nullable()->constrained('financial_accounts')->restrictOnDelete();$t->foreignId('destination_account_id')->nullable()->constrained('financial_accounts')->restrictOnDelete();$t->foreignId('counterpart_ledger_account_id')->nullable()->constrained('ledger_accounts')->restrictOnDelete();$t->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();$t->decimal('amount',20,4);$t->string('reference',120)->nullable();$t->text('description');$t->foreignId('journal_entry_id')->unique()->constrained('journal_entries')->restrictOnDelete();$t->foreignId('created_by')->constrained('users')->restrictOnDelete();$t->timestamps();$t->index(['type','transaction_date']);});}
 public function down():void {Schema::dropIfExists('money_transactions');}
};
