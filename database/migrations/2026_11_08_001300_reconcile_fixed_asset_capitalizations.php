<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void
    {
        Schema::create('fixed_asset_capitalizations',function(Blueprint $table){
            $table->id();
            $table->foreignId('fixed_asset_id')->unique()
                ->constrained('fixed_assets')->restrictOnDelete();
            $table->foreignId('acquisition_journal_id')
                ->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('journal_line_id')->unique()
                ->constrained('journal_lines')->restrictOnDelete();
            $table->foreignId('asset_ledger_id')
                ->constrained('ledger_accounts')->restrictOnDelete();
            $table->string('reconciliation_reference',180);
            $table->text('evidence_notes');
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }
    public function down():void
    {
        Schema::dropIfExists('fixed_asset_capitalizations');
    }
};
