<?php

namespace ReachHub\Jobs;

use ReachHub\Models\Campaign;
use ReachHub\Models\Contact;
use ReachHub\Models\CampaignLog;
use ReachHub\Services\CampaignService;
use ReachHub\Services\ChannelRateLimiter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DispatchCampaignChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int   $tries   = 5;
    public int   $timeout = 180;

    /**
     * Exponential backoff: 1min, 5min, 15min, 30min, 60min
     */
    public function backoff(): array
    {
        return [60, 300, 900, 1800, 3600];
    }

    public function __construct(
        private readonly int   $campaignId,
        private readonly array $contactIds,
        private readonly int   $chunkIndex = 0,
        private readonly int   $totalChunks = 1,
    ) {}

    public function handle(CampaignService $service, ChannelRateLimiter $rateLimiter): void
    {
        $campaign = Campaign::find($this->campaignId);

        if (!$campaign || in_array($campaign->status, ['cancelled', 'failed'])) {
            return;
        }

        $contacts = Contact::whereIn('id', $this->contactIds)->get();

        foreach ($contacts as $contact) {
            try {
                // Honour channel rate limits before each send
                $rateLimiter->acquire($campaign->channel);
                $service->sendToContact($campaign, $contact);
            } catch (\RuntimeException $e) {
                // Rate limit wait timed out — re-queue this chunk after backoff
                Log::warning("[ReachHub] Rate limit hit for campaign {$this->campaignId} on {$campaign->channel}: " . $e->getMessage());
                $this->release($this->backoff()[min($this->attempts(), 4)]);
                return;
            } catch (\Throwable $e) {
                // Per-contact failure: log it and keep going — don't abort the chunk
                Log::error("[ReachHub] Failed sending campaign {$this->campaignId} to contact {$contact->id}: " . $e->getMessage());

                CampaignLog::updateOrCreate(
                    ['campaign_id' => $this->campaignId, 'contact_id' => $contact->id],
                    ['channel' => $campaign->channel, 'status' => 'failed', 'error_message' => $e->getMessage()]
                );
            }
        }

        // Check completion only after the last chunk
        $service->checkCompletion($campaign);
    }

    public function failed(\Throwable $exception): void
    {
        // BUG FIX: A chunk job failing (after all retries) does NOT mean the
        // entire campaign failed — other chunks may have succeeded or be in-flight.
        // We log the failure and mark individual contacts as failed instead.

        Log::error("[ReachHub] Chunk #{$this->chunkIndex}/{$this->totalChunks} permanently failed "
            . "for campaign {$this->campaignId} after {$this->tries} attempts: " . $exception->getMessage());

        // Mark unprocessed contacts in this chunk as failed
        foreach ($this->contactIds as $contactId) {
            CampaignLog::firstOrCreate(
                ['campaign_id' => $this->campaignId, 'contact_id' => $contactId],
                [
                    'channel'       => Campaign::find($this->campaignId)?->channel ?? 'unknown',
                    'status'        => 'failed',
                    'error_message' => 'Chunk job permanently failed: ' . $exception->getMessage(),
                ]
            );
        }

        // Only mark the campaign itself as failed if this was the ONLY chunk
        // or if every chunk has now failed — check by comparing failed log count to total recipients
        $campaign = Campaign::find($this->campaignId);
        if ($campaign && $campaign->status === 'sending') {
            $service = app(CampaignService::class);
            $service->checkCompletion($campaign);
        }
    }
}
