<?php

namespace ReachHub\Console\Commands;

use ReachHub\Models\Campaign;
use ReachHub\Services\CampaignService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DispatchScheduledCampaigns extends Command
{
    protected $signature   = 'reachhub:dispatch-scheduled {--dry-run : Preview which campaigns would be dispatched without actually sending}';
    protected $description = 'Dispatch all campaigns that are scheduled and due to send.';

    public function handle(CampaignService $service): int
    {
        $now = now();
        $dryRun = $this->option('dry-run');

        $campaigns = Campaign::where('status', 'scheduled')
            ->where('scheduled_at', '<=', $now)
            ->get();

        if ($campaigns->isEmpty()) {
            $this->info('No scheduled campaigns are due.');
            return self::SUCCESS;
        }

        $this->info("Found {$campaigns->count()} campaign(s) due for dispatch" . ($dryRun ? ' [DRY RUN]' : '') . '.');

        foreach ($campaigns as $campaign) {
            $this->line("  → [{$campaign->id}] {$campaign->name} ({$campaign->channel}) scheduled at {$campaign->scheduled_at}");

            if ($dryRun) {
                continue;
            }

            try {
                $service->dispatch($campaign);
                $this->info("    ✓ Dispatched successfully.");
                Log::info("[ReachHub] Scheduled campaign {$campaign->id} dispatched via scheduler.");
            } catch (\Throwable $e) {
                $this->error("    ✗ Failed: {$e->getMessage()}");
                Log::error("[ReachHub] Failed to dispatch scheduled campaign {$campaign->id}: " . $e->getMessage());

                $campaign->update(['status' => 'failed']);
            }
        }

        return self::SUCCESS;
    }
}
