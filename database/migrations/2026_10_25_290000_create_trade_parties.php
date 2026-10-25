<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {Schema::create('trade_parties',function(Blueprint $t){$t->id();$t->string('type',16);$t->string('name',180);$t->string('code',40)->nullable();$t->string('contact_person',150)->nullable();$t->string('phone',80)->nullable();$t->string('email',180)->nullable();$t->text('address')->nullable();$t->text('notes')->nullable();$t->boolean('is_active')->default(true);$t->foreignId('created_by')->constrained('users')->restrictOnDelete();$t->timestamps();$t->unique(['type','code']);$t->index(['type','is_active']);});Schema::create('trade_party_currencies',function(Blueprint $t){$t->id();$t->foreignId('trade_party_id')->constrained('trade_parties')->cascadeOnDelete();$t->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();$t->timestamps();$t->unique(['trade_party_id','currency_id']);});}
 public function down():void {Schema::dropIfExists('trade_party_currencies');Schema::dropIfExists('trade_parties');}
};
