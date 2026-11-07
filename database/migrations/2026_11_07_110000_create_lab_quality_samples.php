<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('quality_specifications',function(Blueprint $t) {
   $t->id();$t->string('subject_type',20);$t->unsignedBigInteger('subject_id');
   $t->string('test_name',120);$t->string('method',180)->nullable();
   $t->string('kind',20);$t->decimal('minimum',20,6)->nullable();
   $t->decimal('maximum',20,6)->nullable();$t->string('unit',40)->nullable();
   $t->unsignedSmallInteger('version')->default(1);
   $t->boolean('is_active')->default(true);
   $t->foreignId('approved_by')->constrained('users')->restrictOnDelete();$t->timestamps();
   $t->unique(['subject_type','subject_id','test_name','version'],'quality_spec_version_unique');
   $t->index(['subject_type','subject_id','is_active']);
  });
  Schema::create('quality_samples',function(Blueprint $t){
   $t->id();$t->string('number',42)->unique();$t->uuid('submission_token')->unique();
   $t->string('source_type',24);
   $t->foreignId('production_batch_id')->nullable()->constrained('production_batches')->restrictOnDelete();
   $t->foreignId('goods_receipt_line_id')->nullable()->constrained('goods_receipt_lines')->restrictOnDelete();
   $t->date('collected_on');$t->string('status',24)->default('registered');
   $t->text('notes')->nullable();$t->text('review_notes')->nullable();
   $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
   $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
   $t->timestamp('reviewed_at')->nullable();$t->timestamps();
   $t->index(['source_type','status']);
  });
  Schema::create('quality_sample_results',function(Blueprint $t){
   $t->id();$t->foreignId('quality_sample_id')->constrained('quality_samples')->restrictOnDelete();
   $t->foreignId('quality_specification_id')->constrained('quality_specifications')->restrictOnDelete();
   $t->decimal('observed_value',20,6)->nullable();
   $t->string('outcome',8);$t->text('observation')->nullable();
   $t->foreignId('tested_by')->constrained('users')->restrictOnDelete();$t->timestamps();
   $t->unique(['quality_sample_id','quality_specification_id'],'sample_spec_once');
  });
 }
 public function down():void {
  Schema::dropIfExists('quality_sample_results');
  Schema::dropIfExists('quality_samples');
  Schema::dropIfExists('quality_specifications');
 }
};