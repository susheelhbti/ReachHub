<?php

namespace ReachHub\Console\Commands;

use ReachHub\Models\Campaign;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ArchiveOldCampaigns extends Command
{
    protected $signature   = 'reachhub:archive-campaigns
                              {--days=90 : Archive campaigns sent more than this many days ago}
                              {--dry-run : Preview without making changes}
                              {--purge   : Permanently delete logs instead of archiving them}';
    protected $description = 'Archive sent campaigns older than the retention threshold.';

    public function handle(): int
    {
        $days   = (int) $this->option('days');
        $dryRun = $this->option('dry-run');
        $purge  = $this->option('purge');

        $campaigns = Campaign::where('status', 'sent')
            ->where('sent_at', '<=', now()->subDays($days))
            ->whereNull('archived_at')
            ->get();

        if ($campaigns->isEmpty()) {
            $this->info("No campaigns are older than {$days} days.");
            return self::SUCCESS;
        }

        $this->info("Found {$campaigns->count()} campaign(s) to archive" . ($dryRun ? ' [DRY RUN]' : '') . '.');

        foreach ($campaigns as $campaign) {
            $logCount = $campaign->logs()->count();
            $this->line("  → [{$campaign->id}] {$campaign->name} ({$logCount} logs)");

            if ($dryRun) continue;

            DB::transaction(function () use ($campaign, $purge) {
                if (!$purge) {
                    // Archive logs to separate table for long-term storage
                    $logs = $campaign->logs()->get()->toArray();

                    if (!empty($logs)) {
                        // Ensure archive table exists (created in migration 003)
                        foreach (array_chunk($logs, 500) as $chunk) {
                            DB::table('ck_campaign_logs_archive')->insert(
                                array_map(fn($l) => array_merge($l, ['archived_at' => now()]), $chunk)
                            );
                        }
                    }
                }

                // Remove from active logs table
                $campaign->logs()->delete();

                // Mark archived
                $campaign->update(['archived_at' => now()]);
            });
        }

        if (!$dryRun) {
            $this->info('Archive complete.');
        }

        return self::SUCCESS;
    }
}
