<?php

namespace ReachHub\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Outbound webhook subscriptions — notify external systems of ReachHub events.
 *
 * Supported events:
 *   campaign.created / updated / sent / completed / failed / paused / cancelled
 *   contact.created / updated / unsubscribed / suppressed
 *   workflow.enrolled / completed / errored
 *   bounce.received / complaint.received
 *   open.tracked / click.tracked / delivery.confirmed
 */
class WebhookSubscription extends Model
{
    // All known events — used for validation
    public const EVENTS = [
        'campaign.created', 'campaign.updated', 'campaign.sent',
        'campaign.completed', 'campaign.failed', 'campaign.paused', 'campaign.cancelled',
        'contact.created', 'contact.updated', 'contact.unsubscribed', 'contact.suppressed',
        'workflow.enrolled', 'workflow.completed', 'workflow.errored',
        'bounce.received', 'complaint.received',
        'open.tracked', 'click.tracked', 'delivery.confirmed',
    ];

    protected $table = 'rh_webhook_subscriptions';

    protected $fillable = [
        'name',
        'url',
        'events',       // JSON array of event names, or ['*'] for all
        'secret',       // HMAC signing secret
        'auth_type',    // none | bearer | basic | header
        'auth_config',  // JSON: {bearer_token, username, password, headers{}}
        'is_active',
        'last_triggered_at',
        'failure_count',
    ];

    protected $casts = [
        'events'             => 'array',
        'auth_config'        => 'array',
        'is_active'          => 'boolean',
        'last_triggered_at'  => 'datetime',
    ];

    protected $hidden = ['secret', 'auth_config'];

    // ── Dispatch ─────────────────────────────────────────────────────────

    /**
     * Fire an event to all active, matching subscriptions.
     * Called statically so it can be used anywhere without DI.
     */
    public static function fire(string $event, array $payload): void
    {
        static::where('is_active', true)
            ->get()
            ->filter(fn($sub) => $sub->subscribesTo($event))
            ->each(fn($sub) => $sub->dispatchEvent($event, $payload));
    }

    public function subscribesTo(string $event): bool
    {
        $events = $this->events ?? [];
        return in_array('*', $events) || in_array($event, $events);
    }

    public function dispatchEvent(string $event, array $payload): void
    {
        $envelope = [
            'event'      => $event,
            'fired_at'   => now()->toISOString(),
            'payload'    => $payload,
        ];

        $signature = hash_hmac('sha256', json_encode($envelope), $this->secret ?? '');

        try {
            $request = Http::timeout(15)
                ->withHeaders([
                    'Content-Type'              => 'application/json',
                    'X-ReachHub-Event'       => $event,
                    'X-ReachHub-Signature'   => 'sha256=' . $signature,
                    'X-ReachHub-Delivery'    => uniqid('rh_', true),
                ]);

            // Apply auth
            $auth = $this->auth_config ?? [];
            match ($this->auth_type ?? 'none') {
                'bearer' => $request->withToken($auth['bearer_token'] ?? ''),
                'basic'  => $request->withBasicAuth($auth['username'] ?? '', $auth['password'] ?? ''),
                'header' => $request->withHeaders($auth['headers'] ?? []),
                default  => null,
            };

            $response = $request->retry(3, 500, fn($e) => true)->post($this->url, $envelope);

            $this->logAttempt($event, $envelope, $response->status(), substr($response->body(), 0, 500), true);
            $this->forceFill(['last_triggered_at' => now(), 'failure_count' => 0])->save();

        } catch (\Throwable $e) {
            $this->logAttempt($event, $envelope, 0, $e->getMessage(), false);
            $this->increment('failure_count');

            // Auto-disable after 10 consecutive failures
            if ($this->failure_count >= 10) {
                $this->update(['is_active' => false]);
                Log::warning("[ReachHub:Webhook] Subscription #{$this->id} auto-disabled after 10 failures.");
            }
        }
    }

    /**
     * Send a test payload to verify the URL is reachable.
     */
    public function sendTest(): array
    {
        try {
            $response = Http::timeout(10)->post($this->url, [
                'event'   => 'webhook.test',
                'fired_at'=> now()->toISOString(),
                'payload' => ['message' => 'ReachHub webhook test — if you see this, it works!'],
            ]);

            return ['success' => $response->successful(), 'status' => $response->status()];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function logAttempt(string $event, array $payload, int $status, string $response, bool $success): void
    {
        DB::table('rh_webhook_attempts')->insert([
            'subscription_id' => $this->id,
            'event'           => $event,
            'payload'         => json_encode($payload),
            'response_status' => $status,
            'response_body'   => $response,
            'success'         => $success,
            'created_at'      => now(),
        ]);
    }
}
