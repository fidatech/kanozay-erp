<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {Schema::create('currency_exchanges',function(Blueprint $t){$t->id();$t->date('exchange_date');$t->foreignId('source_account_id')->constrained('financial_accounts')->restrictOnDelete();$t->foreignId('destination_account_id')->constrained('financial_accounts')->restrictOnDelete();$t->foreignId('source_currency_id')->constrained('currencies')->restrictOnDelete();$t->foreignId('destination_currency_id')->constrained('currencies')->restrictOnDelete();$t->decimal('source_amount',20,4);$t->decimal('destination_amount',20,4);$t->decimal('applied_rate',24,12);$t->foreignId('reference_rate_id')->nullable()->constrained('exchange_rates')->nullOnDelete();$t->string('reference',100)->nullable();$t->text('description');$t->foreignId('source_journal_id')->unique()->constrained('journal_entries')->restrictOnDelete();$t->foreignId('destination_journal_id')->unique()->constrained('journal_entries')->restrictOnDelete();$t->foreignId('created_by')->constrained('users')->restrictOnDelete();$t->timestamps();$t->index('exchange_date');});}
 public function down():void {Schema::dropIfExists('currency_exchanges');}
};
