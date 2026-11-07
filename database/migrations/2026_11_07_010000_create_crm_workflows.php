<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_segments', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('crm_leads', function (Blueprint $table) {
            $table->id();
            $table->string('number', 48)->unique();
            $table->string('name', 180);
            $table->string('company', 180)->nullable();
            $table->string('phone', 80)->nullable();
            $table->string('email', 180)->nullable();
            $table->string('source', 80)->nullable();
            $table->string('status', 24)->default('new');
            $table->foreignId('segment_id')->nullable()->constrained('crm_segments')->restrictOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'assigned_to']);
        });

        Schema::create('crm_opportunities', function (Blueprint $table) {
            $table->id();
            $table->string('number', 48)->unique();
            $table->string('title', 180);
            $table->foreignId('lead_id')->nullable()->constrained('crm_leads')->restrictOnDelete();
            $table->foreignId('trade_party_id')->nullable()->constrained('trade_parties')->restrictOnDelete();
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->restrictOnDelete();
            $table->decimal('amount', 24, 4)->nullable();
            $table->string('stage', 24)->default('qualification');
            $table->unsignedTinyInteger('probability')->default(0);
            $table->date('expected_close_on')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['stage', 'expected_close_on']);
        });

        Schema::create('crm_contacts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 180);
            $table->string('position', 120)->nullable();
            $table->string('phone', 80)->nullable();
            $table->string('email', 180)->nullable();
            $table->foreignId('trade_party_id')->nullable()->constrained('trade_parties')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('crm_activities', function (Blueprint $table) {
            $table->id();
            $table->string('subject', 180);
            $table->string('type', 24);
            $table->foreignId('lead_id')->nullable()->constrained('crm_leads')->restrictOnDelete();
            $table->foreignId('opportunity_id')->nullable()->constrained('crm_opportunities')->restrictOnDelete();
            $table->foreignId('trade_party_id')->nullable()->constrained('trade_parties')->restrictOnDelete();
            $table->dateTime('occurred_at');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index('occurred_at');
        });

        Schema::create('crm_follow_ups', function (Blueprint $table) {
            $table->id();
            $table->string('subject', 180);
            $table->foreignId('lead_id')->nullable()->constrained('crm_leads')->restrictOnDelete();
            $table->foreignId('opportunity_id')->nullable()->constrained('crm_opportunities')->restrictOnDelete();
            $table->foreignId('trade_party_id')->nullable()->constrained('trade_parties')->restrictOnDelete();
            $table->dateTime('due_at');
            $table->string('status', 24)->default('pending');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'due_at']);
        });

        Schema::create('crm_complaints', function (Blueprint $table) {
            $table->id();
            $table->string('number', 48)->unique();
            $table->string('subject', 180);
            $table->foreignId('trade_party_id')->constrained('trade_parties')->restrictOnDelete();
            $table->string('priority', 16)->default('medium');
            $table->string('status', 24)->default('open');
            $table->text('description');
            $table->text('resolution')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'priority']);
        });

        Schema::create('crm_visit_plans', function (Blueprint $table) {
            $table->id();
            $table->string('purpose', 180);
            $table->foreignId('lead_id')->nullable()->constrained('crm_leads')->restrictOnDelete();
            $table->foreignId('trade_party_id')->nullable()->constrained('trade_parties')->restrictOnDelete();
            $table->dateTime('scheduled_at');
            $table->string('status', 24)->default('scheduled');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->text('outcome')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        foreach (['crm_visit_plans', 'crm_complaints', 'crm_follow_ups', 'crm_activities', 'crm_contacts', 'crm_opportunities', 'crm_leads', 'crm_segments'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
