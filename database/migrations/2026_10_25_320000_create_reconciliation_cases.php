<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {Schema::create('reconciliation_cases',function(Blueprint $table){$table->id();$table->string('type',20);$table->foreignId('trade_party_id')->constrained('trade_parties')->restrictOnDelete();$table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();$table->string('status',20)->default('open');$table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();$table->text('notes')->nullable();$table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();$table->timestamp('resolved_at')->nullable();$table->timestamps();$table->unique(['type','trade_party_id','currency_id']);});}
 public function down():void {Schema::dropIfExists('reconciliation_cases');}
};
