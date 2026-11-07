<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('managed_documents', function (Blueprint $table) {
            $table->id();
            $table->string('number', 42)->unique();
            $table->string('title', 180);
            $table->foreignId('document_category_id')->nullable()->constrained('document_categories')->restrictOnDelete();
            $table->string('type', 16)->default('document');
            $table->string('status', 24)->default('draft');
            $table->unsignedInteger('current_version')->default(1);
            $table->date('expires_on')->nullable();
            $table->date('retention_until')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['type','status']);
            $table->index('expires_on');
        });

        Schema::create('managed_document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('managed_document_id')->constrained('managed_documents')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('storage_path', 255)->unique();
            $table->string('original_name', 200);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('file_size_bytes');
            $table->string('sha256', 64);
            $table->text('change_note')->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['managed_document_id','version'],'managed_version_unique');
        });

        Schema::create('managed_document_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('managed_document_id')->constrained('managed_documents')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 16)->default('pending');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();
            $table->index(['status','created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('managed_document_approvals');
        Schema::dropIfExists('managed_document_versions');
        Schema::dropIfExists('managed_documents');
        Schema::dropIfExists('document_categories');
    }
};
