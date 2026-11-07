<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('payroll_line_components',function(Blueprint $table){
   $table->id();
   $table->foreignId('payroll_line_id')->constrained('payroll_lines')->restrictOnDelete();
   $table->uuid('submission_token')->unique();
   $table->string('kind',20);
   $table->string('label',120);
   $table->decimal('amount',20,4);
   $table->foreignId('ledger_account_id')->constrained('ledger_accounts')->restrictOnDelete();
   $table->string('approval_reference',180);
   $table->text('reason');
   $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
   $table->timestamps();
   $table->index(['payroll_line_id','kind']);
  });
 }
 public function down():void {Schema::dropIfExists('payroll_line_components');}
};