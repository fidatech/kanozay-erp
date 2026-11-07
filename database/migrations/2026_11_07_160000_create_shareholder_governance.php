<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('shareholder_ownership_records',function(Blueprint $t){
   $t->id();$t->foreignId('shareholder_id')->constrained('shareholders')->restrictOnDelete();
   $t->string('share_class',60);$t->decimal('percentage',7,4);
   $t->string('approval_reference',180);$t->date('effective_on');
   $t->foreignId('updated_by')->constrained('users')->restrictOnDelete();
   $t->timestamps();$t->unique(['shareholder_id','share_class'],'ownership_share_class_unique');
  });
  Schema::create('shareholder_ownership_changes',function(Blueprint $t){
   $t->id();$t->foreignId('ownership_record_id')->constrained('shareholder_ownership_records')->restrictOnDelete();
   $t->decimal('old_percentage',7,4);$t->decimal('new_percentage',7,4);
   $t->string('approval_reference',180);$t->date('effective_on');
   $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();$t->timestamps();
  });
  Schema::create('shareholder_resolutions',function(Blueprint $t){
   $t->id();$t->string('number',45)->unique();$t->string('title',180);
   $t->string('purpose',30);$t->foreignId('currency_id')->nullable()->constrained('currencies')->restrictOnDelete();
   $t->decimal('distribution_cap',20,4)->nullable();
   $t->date('resolution_on');$t->string('document_reference',180);
   $t->string('status',20)->default('draft');$t->text('notes')->nullable();
   $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
   $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
   $t->timestamp('reviewed_at')->nullable();$t->timestamps();
  });
  Schema::create('shareholder_profit_distributions',function(Blueprint $t){
   $t->id();$t->uuid('submission_token')->unique();
   $t->foreignId('shareholder_resolution_id')->constrained('shareholder_resolutions')->restrictOnDelete();
   $t->foreignId('shareholder_id')->constrained('shareholders')->restrictOnDelete();
   $t->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
   $t->foreignId('financial_account_id')->constrained('financial_accounts')->restrictOnDelete();
   $t->decimal('amount',20,4);$t->date('paid_on');
   $t->foreignId('journal_entry_id')->unique()->constrained('journal_entries')->restrictOnDelete();
   $t->foreignId('created_by')->constrained('users')->restrictOnDelete();$t->timestamps();
   $t->index(['shareholder_resolution_id','paid_on'],'distribution_resolution_day');
  });
 }
 public function down():void {
  Schema::dropIfExists('shareholder_profit_distributions');
  Schema::dropIfExists('shareholder_resolutions');
  Schema::dropIfExists('shareholder_ownership_changes');
  Schema::dropIfExists('shareholder_ownership_records');
 }
};