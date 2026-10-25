<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {Schema::create('trade_opening_balances',function(Blueprint $t){$t->id();$t->foreignId('trade_party_id')->constrained('trade_parties')->restrictOnDelete();$t->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();$t->decimal('amount',20,4);$t->date('balance_date');$t->foreignId('journal_entry_id')->unique()->constrained('journal_entries')->restrictOnDelete();$t->foreignId('created_by')->constrained('users')->restrictOnDelete();$t->timestamps();$t->unique(['trade_party_id','currency_id']);});}
 public function down():void {Schema::dropIfExists('trade_opening_balances');}
};
