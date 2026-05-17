<?php

namespace ReachHub\Console\Commands;

use ReachHub\Models\Campaign;
use ReachHub\Models\CampaignLog;
use ReachHub\Models\Contact;
use ReachHub\Services\CampaignService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RetryFailedMessages extends Command
{
    protected $signature   = 'reachhub:retry-failed
                              {--limit=500    : Max messages to retry per run}
                              {--max-retries=3 : Skip messages already retried this many times}
                              {--dry-run      : Preview without actually sending}';
    protected $description = 'Retry failed campaign messages with exponential backoff.';

    public function handle(CampaignService $service): int
    {
        $limit      = (int) $this->option('limit');
        $maxRetries = (int) $this->option('max-retries');
        $dryRun     = $this->option('dry-run');

        $failed = CampaignLog::where('status', 'failed')
            ->where('retry_count', '<', $maxRetries)
            ->where(function ($q) {
                $q->whereNull('next_retry_at')
                  ->orWhere('next_retry_at', '<=', now());
            })
            ->with(['campaign'])
            ->limit($limit)
            ->get();

        if ($failed->isEmpty()) {
            $this->info('No failed messages are due for retry.');
            return self::SUCCESS;
        }

        $this->info("Found {$failed->count()} message(s) to retry" . ($dryRun ? ' [DRY RUN]' : '') . '.');

        $retried = $succeeded = $permanentlyFailed = 0;

        foreach ($failed as $log) {
            $campaign = $log->campaign;
            $contact  = Contact::find($log->contact_id);

            if (!$campaign || !$contact) {
                $log->update(['status' => 'failed', 'error_message' => 'Campaign or contact no longer exists.']);
                continue;
            }

            // Campaign must still be in a sendable state
            if (!in_array($campaign->status, ['sent', 'sending', 'paused'])) {
                continue;
            }

            $this->line("  → Log #{$log->id}: {$log->channel} → {$log->recipient}");

            if ($dryRun) {
                $retried++;
                continue;
            }

            try {
                $service->sendToContact($campaign, $contact);

                // sendToContact writes the log — check the fresh record
                $log->refresh();
                if ($log->status === 'sent') {
                    $succeeded++;
                    $this->info("    ✓ Sent.");
                } else {
                    $this->scheduleNextRetry($log);
                    $permanentlyFailed++;
                }

            } catch (\Throwable $e) {
                $this->scheduleNextRetry($log, $e->getMessage());
                $this->error("    ✗ {$e->getMessage()}");
                Log::warning("[ReachHub:Retry] Log #{$log->id} failed: " . $e->getMessage());
            }

            $retried++;
        }

        $this->info("Retry complete. Retried: {$retried} | Succeeded: {$succeeded} | Still failing: {$permanentlyFailed}");

        return self::SUCCESS;
    }

    private function scheduleNextRetry(CampaignLog $log, ?string $error = null): void
    {
        // Exponential backoff: 5, 10, 20 minutes
        $retryCount = $log->retry_count + 1;
        $delayMins  = 5 * (2 ** ($retryCount - 1));

        $log->update([
            'retry_count'   => $retryCount,
            'last_retry_at' => now(),
            'next_retry_at' => now()->addMinutes($delayMins),
            'error_message' => $error ?? $log->error_message,
        ]);
    }
}
