<?php

namespace ReachHub\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contact extends Model
{
    use SoftDeletes;

    protected $table = 'ck_contacts';

    protected $fillable = [
        'name',
        'email',
        'phone',        // E.164 format: +91xxxxxxxxxx
        'whatsapp',     // defaults to phone if empty
        'fcm_token',    // Firebase push token
        'tags',         // JSON array
        'custom_fields',// JSON object
        'subscribed',
        'unsubscribed_at',
    ];

    protected $casts = [
        'tags'           => 'array',
        'custom_fields'  => 'array',
        'subscribed'     => 'boolean',
        'unsubscribed_at'=> 'datetime',
    ];

    public function lists()
    {
        return $this->belongsToMany(
            ContactList::class,
            'ck_contact_list_pivot',
            'contact_id',
            'list_id'
        );
    }
}

// ─────────────────────────────────────────────────────────────────────────────

namespace ReachHub\Models;

use Illuminate\Database\Eloquent\Model;

class ContactList extends Model
{
    protected $table = 'ck_contact_lists';

    protected $fillable = ['name', 'description', 'tags'];

    protected $casts = ['tags' => 'array'];

    public function contacts()
    {
        return $this->belongsToMany(
            Contact::class,
            'ck_contact_list_pivot',
            'list_id',
            'contact_id'
        );
    }

    public function campaigns()
    {
        return $this->belongsToMany(
            Campaign::class,
            'ck_campaign_contact_list',
            'contact_list_id',
            'campaign_id'
        );
    }
}

// ─────────────────────────────────────────────────────────────────────────────

namespace ReachHub\Models;

use Illuminate\Database\Eloquent\Model;

class CampaignLog extends Model
{
    protected $table = 'ck_campaign_logs';

    protected $fillable = [
        'campaign_id',
        'contact_id',
        'channel',
        'recipient',        // email / phone / wa_number / fcm_token
        'status',           // queued | sent | delivered | failed | bounced | opened | clicked
        'message_id',       // provider message ID
        'error_message',
        'sent_at',
        'delivered_at',
        'opened_at',
        'clicked_at',
        'metadata',
    ];

    protected $casts = [
        'metadata'     => 'array',
        'sent_at'      => 'datetime',
        'delivered_at' => 'datetime',
        'opened_at'    => 'datetime',
        'clicked_at'   => 'datetime',
    ];

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }
}
