<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {Schema::create('trade_settlement_allocations',function(Blueprint $t){$t->id();$t->foreignId('trade_document_id')->constrained('trade_documents')->restrictOnDelete();$t->foreignId('money_transaction_id')->constrained('money_transactions')->restrictOnDelete();$t->decimal('amount',20,4);$t->foreignId('created_by')->constrained('users')->restrictOnDelete();$t->timestamps();$t->unique(['trade_document_id','money_transaction_id'],'trade_allocation_unique');});}
 public function down():void {Schema::dropIfExists('trade_settlement_allocations');}
};
