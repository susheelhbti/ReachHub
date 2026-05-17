<?php
// 2024_01_01_000003_create_reachhub_v3_tables.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Suppression List ──────────────────────────────────────────────
        Schema::create('ck_suppression_list', function (Blueprint $table) {
            $table->id();
            $table->string('recipient');        // email, phone, or FCM token
            $table->string('channel', 20);      // email|whatsapp|sms|push|all
            $table->string('reason', 30)->default('user_unsubscribed');
            $table->string('source')->nullable(); // campaign:123, user_action, webhook, etc
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['recipient', 'channel']);
            $table->index('recipient');
            $table->index('channel');
        });

        // ── API Keys ──────────────────────────────────────────────────────
        Schema::create('ck_api_keys', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('key', 80)->unique();
            $table->json('permissions');        // ['*'] or ['campaigns.read', ...]
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['key', 'is_active']);
        });

        // ── Webhook Subscriptions ─────────────────────────────────────────
        Schema::create('ck_webhook_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('url');
            $table->json('events');             // ['*'] or ['campaign.sent', ...]
            $table->string('secret', 64)->nullable();
            $table->string('auth_type', 20)->default('none');
            $table->json('auth_config')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('failure_count')->default(0);
            $table->timestamp('last_triggered_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // ── Webhook Attempt Log ───────────────────────────────────────────
        Schema::create('ck_webhook_attempts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->string('event', 60);
            $table->json('payload');
            $table->integer('response_status')->default(0);
            $table->text('response_body')->nullable();
            $table->boolean('success')->default(false);
            $table->timestamp('created_at');

            $table->index(['subscription_id', 'success']);
        });

        // ── Email Templates ───────────────────────────────────────────────
        Schema::create('ck_email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category', 50)->nullable();
            $table->string('subject')->nullable();
            $table->longText('html');
            $table->string('thumbnail_url')->nullable();
            $table->boolean('is_public')->default(false);
            $table->integer('usage_count')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('category');
        });

        // ── Campaign Log Archive ──────────────────────────────────────────
        Schema::create('ck_campaign_logs_archive', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('campaign_id');
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->string('channel', 20);
            $table->string('recipient')->nullable();
            $table->string('status', 30);
            $table->string('message_id')->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->integer('retry_count')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->index('body_hash');
            $table->index(['channel', 'status'], 'ck_campaigns_channel_status_index');
            $table->timestamps();

            $table->index('campaign_id');
        });

        // ── Add new columns to existing tables ───────────────────────────
        Schema::table('ck_campaigns', function (Blueprint $table) {
            $table->string('approval_status', 20)->default('not_required')->after('status');
            $table->unsignedBigInteger('approved_by')->nullable()->after('approval_status');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->text('approval_notes')->nullable()->after('approved_at');
            $table->json('tags')->nullable()->after('approval_notes');
            $table->string('category', 50)->nullable()->after('tags');
            $table->string('body_hash', 32)->nullable()->after('body');
            $table->integer('rate_limit_per_minute')->nullable()->after('body_hash');
            $table->timestamp('archived_at')->nullable()->after('sent_at');
        });

        Schema::table('ck_campaign_logs', function (Blueprint $table) {
            $table->integer('retry_count')->default(0)->after('error_message');
            $table->timestamp('last_retry_at')->nullable()->after('retry_count');
            $table->timestamp('next_retry_at')->nullable()->after('last_retry_at');
        });
    }

    public function down(): void
    {
        Schema::table('ck_campaign_logs', fn($t) => $t->dropColumn(['retry_count', 'last_retry_at', 'next_retry_at']));
        Schema::table('ck_campaigns', fn($t) => $t->dropColumn(['approval_status', 'approved_by', 'approved_at', 'approval_notes', 'tags', 'category', 'body_hash', 'rate_limit_per_minute', 'archived_at']));
        Schema::dropIfExists('ck_campaign_logs_archive');
        Schema::dropIfExists('ck_email_templates');
        Schema::dropIfExists('ck_webhook_attempts');
        Schema::dropIfExists('ck_webhook_subscriptions');
        Schema::dropIfExists('ck_api_keys');
        Schema::dropIfExists('ck_suppression_list');
    }
};
