<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('journal_entries',function(Blueprint $t){$t->id();$t->string('number',40)->unique();$t->date('entry_date');$t->string('reference',100)->nullable();$t->text('description');$t->string('status',20)->default('draft');$t->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();$t->foreignId('created_by')->constrained('users')->restrictOnDelete();$t->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();$t->timestamp('posted_at')->nullable();$t->foreignId('reversal_of_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();$t->timestamps();$t->index(['status','entry_date']);});
  Schema::create('journal_lines',function(Blueprint $t){$t->id();$t->foreignId('journal_entry_id')->constrained('journal_entries')->cascadeOnDelete();$t->foreignId('ledger_account_id')->constrained('ledger_accounts')->restrictOnDelete();$t->decimal('debit',20,4)->default(0);$t->decimal('credit',20,4)->default(0);$t->string('memo',255)->nullable();$t->timestamps();});
 }
 public function down():void {Schema::dropIfExists('journal_lines');Schema::dropIfExists('journal_entries');}
};
