<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {Schema::create('trade_documents',function(Blueprint $t){$t->id();$t->string('type',16);$t->string('number',50)->unique();$t->foreignId('trade_party_id')->constrained('trade_parties')->restrictOnDelete();$t->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();$t->date('document_date');$t->date('due_date');$t->decimal('amount',20,4);$t->string('reference',100)->nullable();$t->text('description');$t->foreignId('journal_entry_id')->unique()->constrained('journal_entries')->restrictOnDelete();$t->foreignId('created_by')->constrained('users')->restrictOnDelete();$t->timestamps();$t->index(['type','due_date']);});}
 public function down():void {Schema::dropIfExists('trade_documents');}
};
