<?php
// 2024_01_01_000002_create_reachhub_v2_tables.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── GDPR Consent Records ──────────────────────────────────────────
        Schema::create('ck_consents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('contact_id');
            $table->string('channel', 20);       // email|whatsapp|sms|push|all
            $table->string('purpose', 30);        // marketing|transactional|analytics
            $table->string('source_ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->string('source_url')->nullable();
            $table->string('proof_hash', 64)->nullable();
            $table->timestamp('granted_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->foreign('contact_id')->references('id')->on('ck_contacts')->onDelete('cascade');
            $table->unique(['contact_id', 'channel', 'purpose']);
            $table->index('expires_at');
        });

        // ── Erased Contact Stats (anonymized, kept for reporting) ─────────
        Schema::create('ck_erased_stats', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('original_contact_id');
            $table->integer('total_campaigns')->default(0);
            $table->integer('total_opens')->default(0);
            $table->integer('total_clicks')->default(0);
            $table->timestamp('erased_at');
        });

        // ── Workflows ─────────────────────────────────────────────────────
        Schema::create('ck_workflows', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('steps');              // array of step definitions
            $table->string('trigger', 50)->default('manual'); // manual|contact_added|tag_added|campaign_opened
            $table->json('trigger_config')->nullable();       // trigger-specific config
            $table->string('status', 20)->default('draft');  // draft|active|paused|archived
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });

        // ── Workflow Contact State ─────────────────────────────────────────
        Schema::create('ck_workflow_contacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workflow_id');
            $table->unsignedBigInteger('contact_id');
            $table->string('current_step', 50)->nullable();
            $table->string('status', 20)->default('active'); // active|completed|cancelled|errored
            $table->timestamp('enrolled_at');
            $table->timestamp('next_action_at')->nullable();
            $table->timestamps();

            $table->foreign('workflow_id')->references('id')->on('ck_workflows')->onDelete('cascade');
            $table->foreign('contact_id')->references('id')->on('ck_contacts')->onDelete('cascade');
            $table->index(['status', 'next_action_at']);
            $table->index(['workflow_id', 'contact_id']);
        });

        // ── Campaign Preview / Test Sends ─────────────────────────────────
        Schema::create('ck_test_sends', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('campaign_id');
            $table->string('sent_to');       // email address(es)
            $table->string('sent_by')->nullable();
            $table->json('validation_report')->nullable();
            $table->timestamps();

            $table->foreign('campaign_id')->references('id')->on('ck_campaigns')->onDelete('cascade');
        });

        // ── Workflow event log ─────────────────────────────────────────────
        Schema::create('ck_workflow_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workflow_id');
            $table->unsignedBigInteger('contact_id');
            $table->string('step_id', 50);
            $table->string('step_type', 30);
            $table->string('result', 20)->default('ok'); // ok|skipped|error
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['workflow_id', 'contact_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ck_workflow_logs');
        Schema::dropIfExists('ck_test_sends');
        Schema::dropIfExists('ck_workflow_contacts');
        Schema::dropIfExists('ck_workflows');
        Schema::dropIfExists('ck_erased_stats');
        Schema::dropIfExists('ck_consents');
    }
};
