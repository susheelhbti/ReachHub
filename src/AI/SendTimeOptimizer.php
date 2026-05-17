<?php

namespace ReachHub\AI;

use ReachHub\Models\Contact;
use ReachHub\Models\CampaignLog;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class SendTimeOptimizer
{
    public function __construct(private readonly ContentEngine $engine) {}

    /**
     * Compute the next optimal send DateTime for a contact.
     * Falls back to industry benchmarks if no history exists.
     */
    public function nextOptimalTime(Contact $contact): Carbon
    {
        $history = $this->engagementHistory($contact);

        if ($history->isEmpty()) {
            // Industry defaults: Tuesday/Thursday, 10am local
            return $this->nextOccurrence('Tuesday', 10);
        }

        $pattern = $this->analysePattern($history);

        // Try LLM suggestion if AI is enabled
        if (config('reachhub.ai.driver', 'none') !== 'none') {
            try {
                $suggestion = $this->engine->suggestSendTime(
                    $history->take(30)->toArray(),
                    $contact->custom_fields['timezone'] ?? 'UTC'
                );

                return $this->nextOccurrence($suggestion['day'], $suggestion['hour']);
            } catch (\Throwable) {
                // Fall through to statistical analysis
            }
        }

        return $this->nextOccurrence($pattern['day'], $pattern['hour']);
    }

    /**
     * For a batch of contacts, group them by optimal send time.
     * Returns a Collection keyed by Carbon datetime.
     */
    public function groupByOptimalTime(Collection $contacts): Collection
    {
        return $contacts->groupBy(function (Contact $contact) {
            $time = $this->nextOptimalTime($contact);
            return $time->format('Y-m-d H:00:00'); // group by hour
        });
    }

    // ── Internal ─────────────────────────────────────────────────────────

    private function engagementHistory(Contact $contact): Collection
    {
        return CampaignLog::where('contact_id', $contact->id)
            ->whereNotNull('opened_at')
            ->where('opened_at', '>=', now()->subDays(90))
            ->selectRaw('
                DAYNAME(opened_at) as day,
                HOUR(opened_at) as hour,
                COUNT(*) as opens
            ')
            ->groupBy('day', 'hour')
            ->orderByDesc('opens')
            ->get();
    }

    private function analysePattern(Collection $history): array
    {
        $best = $history->first();

        return [
            'day'  => $best->day,
            'hour' => (int) $best->hour,
        ];
    }

    private function nextOccurrence(string $dayOfWeek, int $hour): Carbon
    {
        $now  = now();
        $next = $now->copy()->next($dayOfWeek)->setHour($hour)->setMinute(0)->setSecond(0);

        // If the day is today and the hour hasn't passed yet, use today
        if ($now->isSameDay($next->copy()->subWeek()) && $now->hour < $hour) {
            $next->subWeek();
        }

        return $next;
    }
}
