<?php

namespace ReachHub\Services;

use ReachHub\Models\Campaign;
use ReachHub\Models\Contact;
use ReachHub\Models\CampaignLog;
use ReachHub\Models\SuppressionList;
use ReachHub\Models\WebhookSubscription;
use ReachHub\Jobs\DispatchCampaignChunk;
use ReachHub\Services\Channels\ChannelContract;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class CampaignService
{
    private array $channelMap = [
        'email'    => \ReachHub\Services\Channels\EmailChannel::class,
        'whatsapp' => \ReachHub\Services\Channels\WhatsAppChannel::class,
        'sms'      => \ReachHub\Services\Channels\SmsChannel::class,
        'push'     => \ReachHub\Services\Channels\PushChannel::class,
    ];

    public function __construct(private readonly array $config) {}

    // ── Dispatch ──────────────────────────────────────────────────────────

    public function dispatch(Campaign $campaign): void
    {
        if (!in_array($campaign->status, ['draft', 'scheduled'])) {
            throw new \RuntimeException("Campaign {$campaign->id} cannot be sent (status: {$campaign->status}).");
        }

        $channel = $this->resolveChannel($campaign->channel);
        $channel->validateConfig();

        $campaign->update(['status' => 'sending', 'sent_at' => null]);

        WebhookSubscription::fire('campaign.sent', ['campaign_id' => $campaign->id, 'channel' => $campaign->channel]);

        $contactIds = $this->getRecipientIds($campaign);

        if ($contactIds->isEmpty()) {
            $campaign->update(['status' => 'sent', 'sent_at' => now()]);
            WebhookSubscription::fire('campaign.completed', ['campaign_id' => $campaign->id, 'total' => 0]);
            return;
        }

        cache()->put("rh:campaign:{$campaign->id}:total", $contactIds->count(), now()->addDays(7));

        $batchSize   = $this->config[$campaign->channel]['batch_size'] ?? 50;
        $chunks      = $contactIds->chunk($batchSize);
        $totalChunks = $chunks->count();

        $chunks->each(function (Collection $chunk, int $index) use ($campaign, $totalChunks) {
            DispatchCampaignChunk::dispatch(
                $campaign->id,
                $chunk->values()->all(),
                $index,
                $totalChunks,
            )
                ->onQueue($this->config['queue'] ?? 'campaigns')
                ->onConnection($this->config['queue_connection'] ?? 'sync');
        });
    }

    // ── Pause / Resume ────────────────────────────────────────────────────

    public function pause(Campaign $campaign): void
    {
        if ($campaign->status !== 'sending') {
            throw new \RuntimeException("Only a 'sending' campaign can be paused.");
        }
        $campaign->update(['status' => 'paused']);
        WebhookSubscription::fire('campaign.paused', ['campaign_id' => $campaign->id]);
    }

    public function resume(Campaign $campaign): void
    {
        if ($campaign->status !== 'paused') {
            throw new \RuntimeException("Only a 'paused' campaign can be resumed.");
        }

        $sentIds = $campaign->logs()
            ->whereIn('status', ['sent', 'delivered', 'failed', 'bounced'])
            ->pluck('contact_id')
            ->toArray();

        $remaining = $this->getRecipientIds($campaign)
            ->reject(fn($id) => in_array($id, $sentIds));

        if ($remaining->isEmpty()) {
            $campaign->update(['status' => 'sent', 'sent_at' => now()]);
            return;
        }

        $campaign->update(['status' => 'sending']);

        $batchSize   = $this->config[$campaign->channel]['batch_size'] ?? 50;
        $chunks      = $remaining->chunk($batchSize);
        $totalChunks = $chunks->count();

        $chunks->each(function (Collection $chunk, int $index) use ($campaign, $totalChunks) {
            DispatchCampaignChunk::dispatch(
                $campaign->id,
                $chunk->values()->all(),
                $index,
                $totalChunks,
            )
                ->onQueue($this->config['queue'] ?? 'campaigns')
                ->onConnection($this->config['queue_connection'] ?? 'sync');
        });
    }

    // ── Send to single contact ─────────────────────────────────────────────

    public function sendToContact(Campaign $campaign, Contact $contact): void
    {
        // 1. Global subscribed flag
        if (!$contact->subscribed) {
            $this->logSkip($campaign, $contact, 'Contact is unsubscribed.');
            return;
        }

        $recipient = $this->recipientFor($campaign->channel, $contact);

        // 2. Suppression list check (email, phone, or token)
        if (!empty($recipient) && SuppressionList::isSuppressed($recipient, $campaign->channel)) {
            $this->logSkip($campaign, $contact, 'Recipient is on the suppression list.', 'suppressed');
            WebhookSubscription::fire('contact.suppressed', [
                'contact_id' => $contact->id, 'recipient' => $recipient, 'channel' => $campaign->channel,
            ]);
            return;
        }

        // 3. Duplicate detection — skip if same body was sent to this contact recently
        if ($this->isDuplicate($campaign, $contact)) {
            $this->logSkip($campaign, $contact, 'Duplicate: similar campaign sent to this contact within 7 days.', 'skipped');
            return;
        }

        // 4. Send
        $channel = $this->resolveChannel($campaign->channel);
        $result  = $channel->send($campaign, $contact);

        CampaignLog::updateOrCreate(
            ['campaign_id' => $campaign->id, 'contact_id' => $contact->id],
            [
                'channel'       => $campaign->channel,
                'recipient'     => $recipient,
                'status'        => $result['success'] ? 'sent' : 'failed',
                'message_id'    => $result['message_id'],
                'error_message' => $result['error'],
                'sent_at'       => $result['success'] ? now() : null,
                'retry_count'   => 0,
            ]
        );

        // Auto-suppress hard bounces
        if (!$result['success'] && str_contains(strtolower($result['error'] ?? ''), 'bounce')) {
            SuppressionList::suppress($recipient, $campaign->channel, 'bounce', "campaign:{$campaign->id}");
        }
    }

    // ── Duplicate detection ───────────────────────────────────────────────

    public function isDuplicate(Campaign $campaign, Contact $contact): bool
    {
        if (!config('reachhub.duplicate_detection', true)) {
            return false;
        }

        $bodyHash = md5($campaign->body);

        return CampaignLog::where('contact_id', $contact->id)
            ->where('channel', $campaign->channel)
            ->where('created_at', '>=', now()->subDays(7))
            ->whereHas('campaign', function ($q) use ($campaign, $bodyHash) {
                $q->where('id', '!=', $campaign->id)
                  ->where(function ($inner) use ($campaign, $bodyHash) {
                      $inner->where('body_hash', $bodyHash)
                            ->orWhere('name', 'like', '%' . substr($campaign->name, 0, 20) . '%');
                  });
            })
            ->exists();
    }

    // ── Completion check ──────────────────────────────────────────────────

    public function checkCompletion(Campaign $campaign): void
    {
        $total = cache()->get("rh:campaign:{$campaign->id}:total")
            ?? $this->getRecipientIds($campaign)->count();

        $done = $campaign->logs()
            ->whereIn('status', ['sent', 'delivered', 'failed', 'bounced', 'suppressed', 'skipped'])
            ->count();

        if ($done >= $total) {
            $campaign->update(['status' => 'sent', 'sent_at' => now()]);
            cache()->forget("rh:campaign:{$campaign->id}:total");
            WebhookSubscription::fire('campaign.completed', [
                'campaign_id' => $campaign->id,
                'total'       => $total,
                'sent'        => $campaign->logs()->whereIn('status', ['sent', 'delivered'])->count(),
            ]);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    public function resolveChannel(string $name): ChannelContract
    {
        if (!isset($this->channelMap[$name])) {
            throw new \InvalidArgumentException(
                "Unknown channel: {$name}. Supported: " . implode(', ', array_keys($this->channelMap))
            );
        }
        return app($this->channelMap[$name]);
    }

    public function getRecipientIds(Campaign $campaign): Collection
    {
        $listIds = $campaign->contact_list_ids ?? [];
        $query   = Contact::where('subscribed', true)->select('id');

        if (!empty($listIds)) {
            $query->whereHas('lists', fn($q) => $q->whereIn('ck_contact_lists.id', $listIds));
        }

        return $query->pluck('id');
    }

    /** @deprecated Use getRecipientIds() */
    public function getRecipients(Campaign $campaign): Collection
    {
        $listIds = $campaign->contact_list_ids ?? [];
        $query   = Contact::where('subscribed', true);

        if (!empty($listIds)) {
            $query->whereHas('lists', fn($q) => $q->whereIn('ck_contact_lists.id', $listIds));
        }

        return $query->get();
    }

    public function supportedChannels(): array
    {
        return array_keys($this->channelMap);
    }

    private function recipientFor(string $channel, Contact $contact): string
    {
        return match ($channel) {
            'email'    => $contact->email    ?? '',
            'whatsapp' => $contact->whatsapp ?? $contact->phone ?? '',
            'sms'      => $contact->phone    ?? '',
            'push'     => $contact->fcm_token ?? '',
            default    => '',
        };
    }

    private function logSkip(Campaign $campaign, Contact $contact, string $reason, string $status = 'failed'): void
    {
        CampaignLog::updateOrCreate(
            ['campaign_id' => $campaign->id, 'contact_id' => $contact->id],
            ['channel' => $campaign->channel, 'status' => $status, 'error_message' => $reason]
        );
    }
}
