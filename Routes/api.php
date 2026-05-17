<?php

use Illuminate\Support\Facades\Route;
use ReachHub\Http\Controllers\CampaignController;
use ReachHub\Http\Controllers\ContactController;
use ReachHub\Http\Controllers\ContactListController;
use ReachHub\Http\Controllers\AnalyticsController;
use ReachHub\Http\Controllers\WebhookController;

$prefix     = config('reachhub.route_prefix', 'api/reachhub');
$middleware = config('reachhub.middleware', ['api', 'reachhub.auth']);

// ── Authenticated API ──────────────────────────────────────────────────────
Route::prefix($prefix)
    ->middleware($middleware)
    ->name('reachhub.')
    ->group(function () {

        /* ── Campaigns ─────────────────────────────────────────── */
        Route::apiResource('campaigns', CampaignController::class);
        Route::post('campaigns/{campaign}/send',      [CampaignController::class, 'send'])     ->name('campaigns.send');
        Route::post('campaigns/{campaign}/schedule',  [CampaignController::class, 'schedule']) ->name('campaigns.schedule');
        Route::post('campaigns/{campaign}/cancel',    [CampaignController::class, 'cancel'])   ->name('campaigns.cancel');
        Route::post('campaigns/{campaign}/pause',     [CampaignController::class, 'pause'])    ->name('campaigns.pause');
        Route::post('campaigns/{campaign}/resume',    [CampaignController::class, 'resume'])   ->name('campaigns.resume');
        Route::post('campaigns/{campaign}/duplicate', [CampaignController::class, 'duplicate'])->name('campaigns.duplicate');

        /* ── Contacts ───────────────────────────────────────────── */
        Route::apiResource('contacts', ContactController::class);
        Route::post('contacts/import',               [ContactController::class, 'import'])    ->name('contacts.import');
        Route::post('contacts/{contact}/unsubscribe',[ContactController::class, 'unsubscribe'])->name('contacts.unsubscribe');

        /* ── Contact Lists ──────────────────────────────────────── */
        Route::apiResource('lists', ContactListController::class);
        Route::post('lists/{list}/contacts/attach',  [ContactListController::class, 'attach'])->name('lists.contacts.attach');
        Route::post('lists/{list}/contacts/detach',  [ContactListController::class, 'detach'])->name('lists.contacts.detach');

        /* ── Analytics ──────────────────────────────────────────── */
        Route::get('analytics/campaigns/{campaign}', [AnalyticsController::class, 'campaign'])->name('analytics.campaign');
        Route::get('analytics/overview',             [AnalyticsController::class, 'overview']) ->name('analytics.overview');

    });

// ── Webhook endpoints (NO auth middleware — providers call these) ──────────
Route::prefix($prefix . '/webhooks')
    ->middleware(['api'])
    ->name('reachhub.webhooks.')
    ->group(function () {
        Route::post('email',     [WebhookController::class, 'email'])          ->name('email');
        Route::post('whatsapp',  [WebhookController::class, 'whatsapp'])       ->name('whatsapp');
        Route::get('whatsapp',   [WebhookController::class, 'whatsappVerify']) ->name('whatsapp.verify');
        Route::post('sms',       [WebhookController::class, 'sms'])            ->name('sms');
    });

// ── AI Endpoints ──────────────────────────────────────────────────────────
Route::prefix($prefix)
    ->middleware($middleware)
    ->name('reachhub.ai.')
    ->group(function () use ($prefix) {

        Route::post('ai/subject-lines',             [\ReachHub\Http\Controllers\AiController::class, 'subjectLines'])    ->name('subject-lines');
        Route::post('ai/adapt-channel',             [\ReachHub\Http\Controllers\AiController::class, 'adaptChannel'])    ->name('adapt-channel');
        Route::post('ai/analyse',                   [\ReachHub\Http\Controllers\AiController::class, 'analyse'])         ->name('analyse');
        Route::get('ai/send-time/{contact}',        [\ReachHub\Http\Controllers\AiController::class, 'sendTime'])        ->name('send-time');
        Route::get('campaigns/{campaign}/preview',  [\ReachHub\Http\Controllers\AiController::class, 'campaignPreview']) ->name('campaigns.preview');
        Route::post('campaigns/{campaign}/validate',[\ReachHub\Http\Controllers\AiController::class, 'campaignValidate'])->name('campaigns.validate');
        Route::post('campaigns/{campaign}/test-send',[\ReachHub\Http\Controllers\AiController::class, 'testSend'])       ->name('campaigns.test-send');

        /* ── Workflows ─────────────────────────────────────────── */
        Route::get('workflows',                     [\ReachHub\Http\Controllers\WorkflowController::class, 'index'])   ->name('workflows.index');
        Route::post('workflows',                    [\ReachHub\Http\Controllers\WorkflowController::class, 'store'])   ->name('workflows.store');
        Route::get('workflows/{id}',                [\ReachHub\Http\Controllers\WorkflowController::class, 'show'])    ->name('workflows.show');
        Route::post('workflows/{id}/enrol',         [\ReachHub\Http\Controllers\WorkflowController::class, 'enrol'])   ->name('workflows.enrol');
        Route::post('workflows/{id}/unenrol',       [\ReachHub\Http\Controllers\WorkflowController::class, 'unenrol']) ->name('workflows.unenrol');

        /* ── Privacy / GDPR ────────────────────────────────────── */
        Route::delete('privacy/contacts/{contact}/erase',   [\ReachHub\Http\Controllers\PrivacyController::class, 'erase'])          ->name('privacy.erase');
        Route::get('privacy/contacts/{contact}/export',     [\ReachHub\Http\Controllers\PrivacyController::class, 'export'])         ->name('privacy.export');
        Route::post('privacy/contacts/{contact}/consent',   [\ReachHub\Http\Controllers\PrivacyController::class, 'recordConsent'])   ->name('privacy.consent.record');
        Route::delete('privacy/contacts/{contact}/consent', [\ReachHub\Http\Controllers\PrivacyController::class, 'withdrawConsent']) ->name('privacy.consent.withdraw');
        Route::get('privacy/contacts/{contact}/consent',    [\ReachHub\Http\Controllers\PrivacyController::class, 'checkConsent'])    ->name('privacy.consent.check');

        /* ── Migration ─────────────────────────────────────────── */
        Route::post('migrate/mailchimp', [\ReachHub\Http\Controllers\MigrationController::class, 'fromMailchimp'])->name('migrate.mailchimp');
        Route::post('migrate/csv',       [\ReachHub\Http\Controllers\MigrationController::class, 'fromCSV'])      ->name('migrate.csv');
        Route::post('migrate/json',      [\ReachHub\Http\Controllers\MigrationController::class, 'fromJSON'])     ->name('migrate.json');
    });

// ── V3 Routes ─────────────────────────────────────────────────────────────
Route::prefix($prefix)
    ->middleware($middleware)
    ->name('reachhub.')
    ->group(function () {

        // Suppression list
        Route::get('suppression',          [\ReachHub\Http\Controllers\SuppressionController::class, 'index']);
        Route::post('suppression',         [\ReachHub\Http\Controllers\SuppressionController::class, 'store']);
        Route::post('suppression/bulk',    [\ReachHub\Http\Controllers\SuppressionController::class, 'bulkStore']);
        Route::get('suppression/check',    [\ReachHub\Http\Controllers\SuppressionController::class, 'check']);
        Route::delete('suppression/{suppression}', [\ReachHub\Http\Controllers\SuppressionController::class, 'destroy']);

        // API key management
        Route::get('api-keys',             [\ReachHub\Http\Controllers\ApiKeyController::class, 'index']);
        Route::post('api-keys',            [\ReachHub\Http\Controllers\ApiKeyController::class, 'store']);
        Route::delete('api-keys/{apiKey}', [\ReachHub\Http\Controllers\ApiKeyController::class, 'destroy']);

        // Outbound webhook subscriptions
        Route::get('webhooks/subscriptions',                    [\ReachHub\Http\Controllers\WebhookSubscriptionController::class, 'index']);
        Route::post('webhooks/subscriptions',                   [\ReachHub\Http\Controllers\WebhookSubscriptionController::class, 'store']);
        Route::put('webhooks/subscriptions/{webhookSubscription}',    [\ReachHub\Http\Controllers\WebhookSubscriptionController::class, 'update']);
        Route::delete('webhooks/subscriptions/{webhookSubscription}', [\ReachHub\Http\Controllers\WebhookSubscriptionController::class, 'destroy']);
        Route::post('webhooks/subscriptions/{webhookSubscription}/test', [\ReachHub\Http\Controllers\WebhookSubscriptionController::class, 'test']);
        Route::get('webhooks/events',                           [\ReachHub\Http\Controllers\WebhookSubscriptionController::class, 'events']);

        // Email templates
        Route::get('templates',                                         [\ReachHub\Http\Controllers\EmailTemplateController::class, 'index']);
        Route::get('templates/presets',                                 [\ReachHub\Http\Controllers\EmailTemplateController::class, 'presets']);
        Route::post('templates',                                        [\ReachHub\Http\Controllers\EmailTemplateController::class, 'store']);
        Route::post('templates/presets/{key}/import',                   [\ReachHub\Http\Controllers\EmailTemplateController::class, 'importPreset']);
        Route::delete('templates/{emailTemplate}',                      [\ReachHub\Http\Controllers\EmailTemplateController::class, 'destroy']);
        Route::post('campaigns/{campaign}/apply-template/{template}',   [\ReachHub\Http\Controllers\EmailTemplateController::class, 'applyToCampaign']);

        // Approval workflow
        Route::post('campaigns/{campaign}/submit-approval', [\ReachHub\Http\Controllers\CampaignApprovalController::class, 'submit']);
        Route::post('campaigns/{campaign}/approve',         [\ReachHub\Http\Controllers\CampaignApprovalController::class, 'approve']);
        Route::post('campaigns/{campaign}/reject',          [\ReachHub\Http\Controllers\CampaignApprovalController::class, 'reject']);

        // CSV import preview
        Route::post('contacts/import/preview',           [\ReachHub\Http\Controllers\CSVPreviewController::class, 'preview']);
        Route::post('contacts/import/validate-mapping',  [\ReachHub\Http\Controllers\CSVPreviewController::class, 'validateMapping']);

        // Calendar view & contact timeline
        Route::get('campaigns/calendar',            [\ReachHub\Http\Controllers\CalendarController::class, 'index']);
        Route::get('contacts/{contact}/timeline',   [\ReachHub\Http\Controllers\ContactTimelineController::class, 'show']);
    });
