<?php

namespace ReachHub\Http\Controllers;

use ReachHub\Models\CampaignLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

/**
 * Handles inbound webhook callbacks from Email, WhatsApp, and SMS providers
 * to update delivery/open/click status on CampaignLog records.
 *
 * Routes (no auth middleware — providers don't send your Bearer token):
 *   POST /api/reachhub/webhooks/email
 *   POST /api/reachhub/webhooks/whatsapp
 *   POST /api/reachhub/webhooks/sms
 *   GET  /api/reachhub/webhooks/whatsapp   ← Meta verification challenge
 */
class WebhookController extends Controller
{
    // ── Email (Mailgun / SendGrid / SES / Postmark) ─────────────────────────

    public function email(Request $request): JsonResponse
    {
        $payload = $request->all();
        $event   = $this->normalizeEmailEvent($payload);

        if (!$event) {
            return response()->json(['ok' => true]);
        }

        $this->updateLog('email', $event['message_id'], $event['status'], $event['metadata']);

        return response()->json(['ok' => true]);
    }

    private function normalizeEmailEvent(array $payload): ?array
    {
        // ── Mailgun format ──
        if (isset($payload['event-data']['message']['headers']['message-id'])) {
            $data = $payload['event-data'];
            return [
                'message_id' => trim($data['message']['headers']['message-id'], '<>'),
                'status'     => $this->mailgunEventToStatus($data['event']),
                'metadata'   => ['provider' => 'mailgun', 'raw_event' => $data['event']],
            ];
        }

        // ── SendGrid format ──
        if (isset($payload[0]['sg_message_id'])) {
            $data = $payload[0];
            return [
                'message_id' => $data['sg_message_id'],
                'status'     => $this->sendgridEventToStatus($data['event']),
                'metadata'   => ['provider' => 'sendgrid', 'raw_event' => $data['event']],
            ];
        }

        // ── Postmark format ──
        if (isset($payload['MessageID']) && isset($payload['RecordType'])) {
            return [
                'message_id' => $payload['MessageID'],
                'status'     => $this->postmarkEventToStatus($payload['RecordType']),
                'metadata'   => ['provider' => 'postmark', 'raw_event' => $payload['RecordType']],
            ];
        }

        Log::debug('[ReachHub] Unrecognized email webhook payload', compact('payload'));
        return null;
    }

    private function mailgunEventToStatus(string $event): string
    {
        return match ($event) {
            'delivered'   => 'delivered',
            'opened'      => 'opened',
            'clicked'     => 'clicked',
            'failed', 'rejected', 'bounced' => 'bounced',
            default       => 'delivered',
        };
    }

    private function sendgridEventToStatus(string $event): string
    {
        return match ($event) {
            'delivered'       => 'delivered',
            'open'            => 'opened',
            'click'           => 'clicked',
            'bounce', 'dropped', 'spamreport' => 'bounced',
            default           => 'delivered',
        };
    }

    private function postmarkEventToStatus(string $type): string
    {
        return match ($type) {
            'Delivery'    => 'delivered',
            'Open'        => 'opened',
            'Click'       => 'clicked',
            'Bounce'      => 'bounced',
            'SpamComplaint' => 'bounced',
            default       => 'delivered',
        };
    }

    // ── WhatsApp (Meta Cloud API) ────────────────────────────────────────────

    /**
     * Meta sends a GET request to verify the webhook URL.
     */
    public function whatsappVerify(Request $request): mixed
    {
        $mode      = $request->query('hub_mode');
        $token     = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        $configToken = config('reachhub.whatsapp.webhook_verify_token');

        if ($mode === 'subscribe' && $token === $configToken) {
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response()->json(['error' => 'Verification failed'], 403);
    }

    public function whatsapp(Request $request): JsonResponse
    {
        $entries = data_get($request->all(), 'entry', []);

        foreach ($entries as $entry) {
            foreach (data_get($entry, 'changes', []) as $change) {
                $statuses = data_get($change, 'value.statuses', []);

                foreach ($statuses as $status) {
                    $msgId  = $status['id']     ?? null;
                    $state  = $status['status'] ?? null;

                    if (!$msgId || !$state) continue;

                    $normalized = match ($state) {
                        'sent'      => 'sent',
                        'delivered' => 'delivered',
                        'read'      => 'opened',
                        'failed'    => 'failed',
                        default     => null,
                    };

                    if ($normalized) {
                        $this->updateLog('whatsapp', $msgId, $normalized, ['provider' => 'meta', 'raw_status' => $state]);
                    }
                }
            }
        }

        return response()->json(['ok' => true]);
    }

    // ── SMS (Twilio / Vonage) ────────────────────────────────────────────────

    public function sms(Request $request): JsonResponse
    {
        $payload = $request->all();

        // ── Twilio format ──
        if (isset($payload['MessageSid'])) {
            $status = match (strtolower($payload['MessageStatus'] ?? '')) {
                'delivered'   => 'delivered',
                'sent'        => 'sent',
                'failed', 'undelivered' => 'failed',
                default       => null,
            };

            if ($status) {
                $this->updateLog('sms', $payload['MessageSid'], $status, ['provider' => 'twilio']);
            }
        }

        // ── Vonage (Nexmo) DLR format ──
        if (isset($payload['messageId']) && isset($payload['status'])) {
            $status = match ($payload['status']) {
                'delivered'   => 'delivered',
                'submitted'   => 'sent',
                'failed', 'rejected', 'expired' => 'failed',
                default       => null,
            };

            if ($status) {
                $this->updateLog('sms', $payload['messageId'], $status, ['provider' => 'vonage']);
            }
        }

        return response()->json(['ok' => true]);
    }

    // ── Shared ───────────────────────────────────────────────────────────────

    private function updateLog(string $channel, string $messageId, string $status, array $metadata = []): void
    {
        $log = CampaignLog::where('channel', $channel)
                          ->where('message_id', $messageId)
                          ->first();

        if (!$log) {
            Log::debug("[ReachHub] Webhook: no log found for {$channel} message_id={$messageId}");
            return;
        }

        $timestamps = [];

        if ($status === 'delivered' && !$log->delivered_at) {
            $timestamps['delivered_at'] = now();
        }
        if ($status === 'opened' && !$log->opened_at) {
            $timestamps['opened_at'] = now();
        }
        if ($status === 'clicked' && !$log->clicked_at) {
            $timestamps['clicked_at'] = now();
        }

        $log->update(array_merge([
            'status'   => $status,
            'metadata' => array_merge($log->metadata ?? [], $metadata),
        ], $timestamps));

        Log::info("[ReachHub] Webhook updated log #{$log->id} → {$status}");
    }
}
