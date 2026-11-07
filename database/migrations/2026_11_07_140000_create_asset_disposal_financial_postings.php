<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
 public function up():void {
  Schema::create('fixed_asset_disposal_postings',function(Blueprint $table){
   $table->id();
   $table->foreignId('fixed_asset_disposal_id')->unique()->constrained('fixed_asset_disposals')->restrictOnDelete();
   $table->foreignId('acquisition_journal_id')->constrained('journal_entries')->restrictOnDelete();
   $table->foreignId('asset_ledger_id')->constrained('ledger_accounts')->restrictOnDelete();
   $table->foreignId('receivable_ledger_id')->nullable()->constrained('ledger_accounts')->restrictOnDelete();
   $table->foreignId('loss_ledger_id')->nullable()->constrained('ledger_accounts')->restrictOnDelete();
   $table->foreignId('gain_ledger_id')->nullable()->constrained('ledger_accounts')->restrictOnDelete();
   $table->foreignId('disposal_journal_id')->unique()->constrained('journal_entries')->restrictOnDelete();
   $table->string('reconciliation_reference',180);
   $table->decimal('gain_or_loss',20,4);
   $table->foreignId('posted_by')->constrained('users')->restrictOnDelete();
   $table->timestamps();
  });
 }
 public function down():void {Schema::dropIfExists('fixed_asset_disposal_postings');}
};