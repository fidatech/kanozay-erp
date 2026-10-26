<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('purchase_order_expenses',function(Blueprint $t){
   $t->id();$t->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();$t->string('category',30);$t->string('description',255)->nullable();$t->decimal('amount',20,4);$t->date('expense_date');$t->foreignId('created_by')->constrained('users')->restrictOnDelete();$t->timestamps();
  });
  Schema::create('purchase_order_advances',function(Blueprint $t){
   $t->id();$t->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();$t->date('payment_date');$t->decimal('amount',20,4);$t->string('reference',100)->nullable();$t->text('notes')->nullable();$t->foreignId('created_by')->constrained('users')->restrictOnDelete();$t->timestamps();
  });
 }
 public function down():void {Schema::dropIfExists('purchase_order_advances');Schema::dropIfExists('purchase_order_expenses');}
};
