<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {Schema::create('financial_accounts',function(Blueprint $t){$t->id();$t->string('type',20);$t->string('name',160);$t->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();$t->string('institution',160)->nullable();$t->string('account_number',120)->nullable();$t->string('contact',160)->nullable();$t->text('notes')->nullable();$t->boolean('is_active')->default(true);$t->foreignId('created_by')->constrained('users')->restrictOnDelete();$t->timestamps();$t->index(['type','is_active']);});}
 public function down():void {Schema::dropIfExists('financial_accounts');}
};
