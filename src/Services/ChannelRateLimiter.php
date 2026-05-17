<?php

namespace ReachHub\Services;

use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;

/**
 * Throttles sends per channel to stay within provider rate limits.
 *
 * Configure per-channel limits in reachhub.php:
 *   'rate_limits' => [
 *       'email'    => ['max' => 100,  'per_seconds' => 1],
 *       'whatsapp' => ['max' => 80,   'per_seconds' => 1],
 *       'sms'      => ['max' => 1,    'per_seconds' => 1],
 *       'push'     => ['max' => 500,  'per_seconds' => 1],
 *   ]
 */
class ChannelRateLimiter
{
    public function __construct(private RateLimiter $limiter) {}

    /**
     * Attempt to consume one token for the given channel.
     * If throttled, sleeps until a slot is available (up to $maxWaitSeconds).
     *
     * @throws \RuntimeException if rate limit cannot be acquired within maxWaitSeconds
     */
    public function acquire(string $channel, int $maxWaitSeconds = 30): void
    {
        $key         = "reachhub:rl:{$channel}";
        $limits      = config("reachhub.rate_limits.{$channel}", []);
        $max         = $limits['max']         ?? 0;
        $perSeconds  = $limits['per_seconds'] ?? 1;

        // 0 = unlimited
        if ($max === 0) {
            return;
        }

        $waited = 0;

        while (true) {
            if ($this->limiter->attempt($key, $max, function () {}, $perSeconds)) {
                return;
            }

            $retryAfter = $this->limiter->availableIn($key);

            if ($waited + $retryAfter > $maxWaitSeconds) {
                throw new \RuntimeException(
                    "Rate limit for channel '{$channel}' exceeded. Retry after {$retryAfter}s."
                );
            }

            sleep($retryAfter ?: 1);
            $waited += $retryAfter ?: 1;
        }
    }

    /**
     * Check remaining quota without consuming.
     */
    public function remaining(string $channel): int
    {
        $key    = "reachhub:rl:{$channel}";
        $limits = config("reachhub.rate_limits.{$channel}", []);
        $max    = $limits['max'] ?? 0;

        if ($max === 0) {
            return PHP_INT_MAX;
        }

        return $this->limiter->remaining($key, $max);
    }
}
