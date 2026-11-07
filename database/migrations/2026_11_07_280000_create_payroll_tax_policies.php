<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('payroll_tax_policies',function(Blueprint $t){
   $t->id();$t->string('code',60)->unique();$t->string('name',160);
   $t->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
   $t->date('effective_from');$t->string('basis',32)->default('base_salary');
   $t->foreignId('liability_ledger_id')->constrained('ledger_accounts')->restrictOnDelete();
   $t->string('status',20)->default('draft');
   $t->string('approval_reference',180)->nullable();$t->text('notes');
   $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
   $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
   $t->timestamp('approved_at')->nullable();$t->timestamps();
   $t->index(['currency_id','effective_from']);
  });
  Schema::create('payroll_tax_policy_brackets',function(Blueprint $t){
   $t->id();$t->foreignId('payroll_tax_policy_id')
    ->constrained('payroll_tax_policies',indexName:'payroll_tax_bracket_policy_fk')->restrictOnDelete();
   $t->unsignedSmallInteger('sort_order');
   $t->decimal('lower_bound',20,4);$t->decimal('upper_bound',20,4)->nullable();
   $t->decimal('fixed_tax',20,4);$t->decimal('marginal_percent',8,4);
   $t->decimal('excess_above',20,4);$t->timestamps();
   $t->unique(['payroll_tax_policy_id','sort_order'],'payroll_tax_policy_order_unique');
  });
  Schema::create('payroll_tax_applications',function(Blueprint $t){
   $t->id();
   $t->foreignId('payroll_line_id')->unique()->constrained('payroll_lines')->restrictOnDelete();
   $t->foreignId('payroll_line_component_id')->unique()
    ->constrained('payroll_line_components',indexName:'payroll_tax_application_component_fk')->restrictOnDelete();
   $t->foreignId('payroll_tax_policy_id')->constrained('payroll_tax_policies')->restrictOnDelete();
   $t->decimal('taxable_base',20,4);$t->decimal('withheld',20,4);
   $t->foreignId('applied_by')->constrained('users')->restrictOnDelete();
   $t->timestamps();
  });
 }
 public function down():void {
  Schema::dropIfExists('payroll_tax_applications');
  Schema::dropIfExists('payroll_tax_policy_brackets');
  Schema::dropIfExists('payroll_tax_policies');
 }
};