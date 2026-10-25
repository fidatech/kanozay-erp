<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {Schema::create('entity_ledger_accounts',function(Blueprint $t){$t->id();$t->string('entity_type',40);$t->unsignedBigInteger('entity_id');$t->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();$t->string('purpose',40);$t->foreignId('ledger_account_id')->unique()->constrained('ledger_accounts')->restrictOnDelete();$t->timestamps();$t->unique(['entity_type','entity_id','currency_id','purpose'],'entity_account_unique');$t->index(['entity_type','entity_id']);});}
 public function down():void {Schema::dropIfExists('entity_ledger_accounts');}
};
