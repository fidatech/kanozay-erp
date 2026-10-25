<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {Schema::create('ledger_accounts',function(Blueprint $table){$table->id();$table->string('code',24)->unique();$table->string('name',160);$table->string('type',20);$table->foreignId('parent_id')->nullable()->constrained('ledger_accounts')->restrictOnDelete();$table->boolean('is_group')->default(false);$table->boolean('is_active')->default(true);$table->string('description',500)->nullable();$table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();$table->timestamps();$table->index(['type','is_active']);});}
 public function down():void {Schema::dropIfExists('ledger_accounts');}
};
