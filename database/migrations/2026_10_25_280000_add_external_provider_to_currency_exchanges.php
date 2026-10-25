<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {Schema::table('currency_exchanges',function(Blueprint $t){$t->string('exchange_type',20)->default('internal');$t->string('provider_name',200)->nullable();$t->string('provider_contact',150)->nullable();});}
 public function down():void {Schema::table('currency_exchanges',function(Blueprint $t){$t->dropColumn(['exchange_type','provider_name','provider_contact']);});}
};
