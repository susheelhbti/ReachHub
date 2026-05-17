<?php

namespace ReachHub\Console\Commands;

use ReachHub\Workflow\WorkflowEngine;
use Illuminate\Console\Command;

class WorkflowTick extends Command
{
    protected $signature   = 'reachhub:workflow-tick';
    protected $description = 'Process all pending workflow steps (run every minute via scheduler).';

    public function handle(WorkflowEngine $engine): int
    {
        $processed = $engine->tick();
        $this->info("Workflow tick complete. Processed {$processed} contact step(s).");
        return self::SUCCESS;
    }
}


// ─────────────────────────────────────────────────────────────────────────────

namespace ReachHub\Console\Commands;

use ReachHub\Privacy\PrivacyService;
use Illuminate\Console\Command;

class GdprCleanup extends Command
{
    protected $signature   = 'reachhub:gdpr-cleanup {--years=2 : Delete contacts inactive for this many years} {--dry-run}';
    protected $description = 'Auto-erase stale unsubscribed contacts per GDPR retention policy.';

    public function handle(PrivacyService $privacy): int
    {
        $years  = (int) $this->option('years');
        $dryRun = $this->option('dry-run');

        $this->info("Scanning for contacts unsubscribed for > {$years} year(s)" . ($dryRun ? ' [DRY RUN]' : '') . '...');

        if ($dryRun) {
            $count = \ReachHub\Models\Contact::where('subscribed', false)
                ->where('unsubscribed_at', '<=', now()->subYears($years))
                ->count();
            $this->info("Would erase {$count} contact(s).");
            return self::SUCCESS;
        }

        $erased = $privacy->autoExpireStale($years);
        $this->info("Erased {$erased} contact(s).");

        return self::SUCCESS;
    }
}
