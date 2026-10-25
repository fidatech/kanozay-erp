<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::table('reconciliation_case_events',function(Blueprint $table){
   $table->string('previous_hash',64)->nullable();
   $table->string('event_hash',64)->nullable();
  });
 }
 public function down():void {
  Schema::table('reconciliation_case_events',fn(Blueprint $table)=>$table->dropColumn(['previous_hash','event_hash']));
 }
};
