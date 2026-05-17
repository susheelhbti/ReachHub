<?php

namespace ReachHub\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Global suppression list — enforced before EVERY send regardless of channel.
 * Sources: bounces, complaints, user unsubscribes, webhook callbacks.
 */
class SuppressionList extends Model
{
    protected $table = 'ck_suppression_list';

    protected $fillable = [
        'recipient',    // email address, phone number, or FCM token
        'channel',      // email | whatsapp | sms | push | all
        'reason',       // bounce | complaint | user_unsubscribed | admin | spam
        'source',       // campaign_id, user_action, webhook, import
        'expires_at',   // null = permanent
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    /**
     * Check whether a recipient is suppressed for a given channel.
     * Checks both the exact channel and the 'all' wildcard.
     */
    public static function isSuppressed(string $recipient, string $channel): bool
    {
        if (empty($recipient)) {
            return false;
        }

        return static::where('recipient', $recipient)
            ->where(function ($q) use ($channel) {
                $q->where('channel', $channel)->orWhere('channel', 'all');
            })
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->exists();
    }

    /**
     * Suppress a recipient permanently or for a set duration.
     * Safe to call multiple times (updateOrCreate).
     */
    public static function suppress(
        string  $recipient,
        string  $channel = 'all',
        string  $reason  = 'user_unsubscribed',
        string  $source  = 'user_action',
        ?\Carbon\Carbon $expiresAt = null,
    ): static {
        return static::updateOrCreate(
            ['recipient' => $recipient, 'channel' => $channel],
            ['reason' => $reason, 'source' => $source, 'expires_at' => $expiresAt],
        );
    }

    /**
     * Remove a suppression (e.g. admin re-activates a contact).
     */
    public static function unsuppress(string $recipient, string $channel = 'all'): void
    {
        static::where('recipient', $recipient)
            ->where('channel', $channel)
            ->delete();
    }

    /**
     * Bulk suppress from a bounce/complaint list.
     */
    public static function bulkSuppress(array $recipients, string $channel, string $reason, string $source): int
    {
        $inserted = 0;
        foreach (array_unique($recipients) as $recipient) {
            static::suppress($recipient, $channel, $reason, $source);
            $inserted++;
        }
        return $inserted;
    }
}
