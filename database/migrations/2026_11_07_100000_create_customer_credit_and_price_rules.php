<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('customer_credit_limits',function(Blueprint $t){
   $t->id();$t->foreignId('trade_party_id')->constrained('trade_parties')->restrictOnDelete();
   $t->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
   $t->decimal('limit_amount',24,4);$t->boolean('is_active')->default(true);
   $t->string('approval_reference',180);$t->foreignId('approved_by')->constrained('users')->restrictOnDelete();
   $t->timestamps();$t->unique(['trade_party_id','currency_id'],'credit_customer_currency_unique');
  });
  Schema::create('customer_sales_prices',function(Blueprint $t){
   $t->id();$t->foreignId('trade_party_id')->constrained('trade_parties')->restrictOnDelete();
   $t->foreignId('finished_good_id')->constrained('finished_goods')->restrictOnDelete();
   $t->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
   $t->decimal('unit_price',20,4);$t->date('effective_on');$t->date('expires_on')->nullable();
   $t->boolean('is_active')->default(true);$t->foreignId('created_by')->constrained('users')->restrictOnDelete();
   $t->timestamps();
   $t->unique(['trade_party_id','finished_good_id','currency_id','effective_on'],'sales_customer_price_unique');
  });
 }
 public function down():void {Schema::dropIfExists('customer_sales_prices');Schema::dropIfExists('customer_credit_limits');}
};