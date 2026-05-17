<?php
// 2024_01_01_000001_create_reachhub_tables.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Contacts ─────────────────────────────────────────────────────
        Schema::create('ck_contacts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique()->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('whatsapp', 30)->nullable();
            $table->text('fcm_token')->nullable();
            $table->json('tags')->nullable();
            $table->json('custom_fields')->nullable();
            $table->boolean('subscribed')->default(true);
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('email');
            $table->index('phone');
            $table->index('subscribed');
        });

        // ── Contact Lists ─────────────────────────────────────────────────
        Schema::create('ck_contact_lists', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('tags')->nullable();
            $table->timestamps();
        });

        // ── Contact <-> List pivot ────────────────────────────────────────
        Schema::create('ck_contact_list_pivot', function (Blueprint $table) {
            $table->unsignedBigInteger('contact_id');
            $table->unsignedBigInteger('list_id');
            $table->primary(['contact_id', 'list_id']);
            $table->foreign('contact_id')->references('id')->on('ck_contacts')->onDelete('cascade');
            $table->foreign('list_id')->references('id')->on('ck_contact_lists')->onDelete('cascade');
        });

        // ── Campaigns ─────────────────────────────────────────────────────
        Schema::create('ck_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('channel', 20);                  // email|whatsapp|sms|push
            $table->string('status', 20)->default('draft'); // draft|scheduled|sending|sent|paused|cancelled|failed
            $table->string('subject')->nullable();
            $table->longText('body');
            $table->json('template_vars')->nullable();
            $table->string('from_name')->nullable();
            $table->string('from_address')->nullable();
            $table->json('contact_list_ids')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('channel');
            $table->index('status');
            $table->index('scheduled_at');
        });

        // ── Campaign <-> ContactList pivot ────────────────────────────────
        Schema::create('ck_campaign_contact_list', function (Blueprint $table) {
            $table->unsignedBigInteger('campaign_id');
            $table->unsignedBigInteger('contact_list_id');
            $table->primary(['campaign_id', 'contact_list_id']);
            $table->foreign('campaign_id')->references('id')->on('ck_campaigns')->onDelete('cascade');
            $table->foreign('contact_list_id')->references('id')->on('ck_contact_lists')->onDelete('cascade');
        });

        // ── Campaign Logs ─────────────────────────────────────────────────
        Schema::create('ck_campaign_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('campaign_id');
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->string('channel', 20);
            $table->string('recipient')->nullable();
            $table->string('status', 30)->default('queued'); // queued|sent|delivered|failed|bounced|opened|clicked
            $table->string('message_id')->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->timestamps();

            $table->foreign('campaign_id')->references('id')->on('ck_campaigns')->onDelete('cascade');
            $table->index(['campaign_id', 'status']);
            $table->index(['contact_id', 'campaign_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ck_campaign_logs');
        Schema::dropIfExists('ck_campaign_contact_list');
        Schema::dropIfExists('ck_campaigns');
        Schema::dropIfExists('ck_contact_list_pivot');
        Schema::dropIfExists('ck_contact_lists');
        Schema::dropIfExists('ck_contacts');
    }
};
