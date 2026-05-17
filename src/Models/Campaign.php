<?php

namespace ReachHub\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Campaign extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'ck_campaigns';

    protected $fillable = [
        'name',
        'description',
        'channel',              // email | whatsapp | sms | push
        'status',               // draft | pending_approval | scheduled | sending | sent | paused | cancelled | failed
        'approval_status',      // not_required | pending | approved | rejected
        'approved_by',
        'approved_at',
        'approval_notes',
        'subject',
        'body',
        'body_hash',            // md5(body) — auto-populated on save
        'template_vars',
        'from_name',
        'from_address',
        'contact_list_ids',
        'scheduled_at',
        'sent_at',
        'archived_at',
        'metadata',
        'tags',
        'category',
        'rate_limit_per_minute',
    ];

    protected $casts = [
        'template_vars'    => 'array',
        'contact_list_ids' => 'array',
        'metadata'         => 'array',
        'tags'             => 'array',
        'scheduled_at'     => 'datetime',
        'sent_at'          => 'datetime',
        'archived_at'      => 'datetime',
        'approved_at'      => 'datetime',
    ];

    // ── Auto-populate body_hash on every save ─────────────────────────────

    protected static function booted(): void
    {
        static::saving(function (Campaign $campaign) {
            if ($campaign->isDirty('body') || empty($campaign->body_hash)) {
                $campaign->body_hash = md5($campaign->body ?? '');
            }
        });
    }

    // ── Relationships ─────────────────────────────────────────────────────

    public function logs()
    {
        return $this->hasMany(CampaignLog::class, 'campaign_id');
    }

    public function contactLists()
    {
        return $this->belongsToMany(
            ContactList::class,
            'ck_campaign_contact_list',
            'campaign_id',
            'contact_list_id'
        );
    }

    // ── Scopes ────────────────────────────────────────────────────────────

    public function scopeByChannel($query, string $channel)
    {
        return $query->where('channel', $channel);
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeWithTag($query, string $tag)
    {
        return $query->whereJsonContains('tags', $tag);
    }

    public function scopeActive($query)
    {
        return $query->whereNull('archived_at');
    }

    // ── Status helpers ────────────────────────────────────────────────────

    public function isDraft(): bool           { return $this->status === 'draft'; }
    public function isSent(): bool            { return $this->status === 'sent'; }
    public function isScheduled(): bool       { return $this->status === 'scheduled'; }
    public function isSending(): bool         { return $this->status === 'sending'; }
    public function isPendingApproval(): bool { return $this->status === 'pending_approval'; }
    public function isArchived(): bool        { return !is_null($this->archived_at); }

    public function isCancellable(): bool
    {
        return in_array($this->status, ['draft', 'scheduled', 'pending_approval']);
    }

    // ── Rate limiting ─────────────────────────────────────────────────────

    /**
     * Returns the effective per-minute send rate for this campaign.
     * Campaign-specific value overrides the global channel default.
     */
    public function getEffectiveRateLimit(): int
    {
        if (!empty($this->rate_limit_per_minute)) {
            return (int) $this->rate_limit_per_minute;
        }

        return config("reachhub.rate_limits.{$this->channel}.max", 100);
    }
}
