<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
 public function up():void {
  Schema::create('production_overhead_allocations',function(Blueprint $table){
   $table->id();
   $table->string('number',40)->unique();$table->uuid('submission_token')->unique();
   $table->foreignId('production_batch_id')->constrained('production_batches',indexName:'production_overhead_batch_fk')->restrictOnDelete();
   $table->foreignId('source_journal_line_id')->constrained('journal_lines',indexName:'production_overhead_journal_line_fk')->restrictOnDelete();
   $table->foreignId('currency_id')->constrained('currencies',indexName:'production_overhead_currency_fk')->restrictOnDelete();
   $table->string('category',24);
   $table->decimal('amount',20,4);
   $table->string('status',20)->default('submitted');
   $table->string('source_reference',180);
   $table->text('notes')->nullable();$table->text('review_note')->nullable();
   $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
   $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
   $table->timestamp('reviewed_at')->nullable();
   $table->timestamps();
   $table->index(['production_batch_id','status'],'production_overhead_batch_status');
   $table->index(['source_journal_line_id','status'],'production_overhead_line_status');
  });
 }
 public function down():void {Schema::dropIfExists('production_overhead_allocations');}
};