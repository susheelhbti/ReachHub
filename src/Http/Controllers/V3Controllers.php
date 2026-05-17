<?php

namespace ReachHub\Http\Controllers;

use ReachHub\Models\SuppressionList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class SuppressionController extends Controller
{
    // GET /suppression
    public function index(Request $request): JsonResponse
    {
        $query = SuppressionList::query()->orderByDesc('created_at');

        if ($request->filled('channel'))   $query->where('channel', $request->channel);
        if ($request->filled('reason'))    $query->where('reason', $request->reason);
        if ($request->filled('recipient')) $query->where('recipient', 'like', '%' . $request->recipient . '%');

        return response()->json(['success' => true, 'data' => $query->paginate(20)]);
    }

    // POST /suppression
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'recipient'  => 'required|string|max:255',
            'channel'    => 'required|in:email,whatsapp,sms,push,all',
            'reason'     => 'in:bounce,complaint,user_unsubscribed,admin,spam',
            'expires_at' => 'nullable|date|after:now',
        ]);

        $entry = SuppressionList::suppress(
            $request->recipient,
            $request->channel,
            $request->get('reason', 'admin'),
            'manual',
            $request->filled('expires_at') ? \Carbon\Carbon::parse($request->expires_at) : null,
        );

        return response()->json(['success' => true, 'data' => $entry], 201);
    }

    // POST /suppression/bulk
    public function bulkStore(Request $request): JsonResponse
    {
        $request->validate([
            'recipients'  => 'required|array|min:1|max:5000',
            'recipients.*'=> 'string',
            'channel'     => 'required|in:email,whatsapp,sms,push,all',
            'reason'      => 'in:bounce,complaint,user_unsubscribed,admin,spam',
        ]);

        $count = SuppressionList::bulkSuppress(
            $request->recipients,
            $request->channel,
            $request->get('reason', 'admin'),
            'bulk_import',
        );

        return response()->json(['success' => true, 'data' => ['suppressed' => $count]]);
    }

    // DELETE /suppression/{id}
    public function destroy(SuppressionList $suppression): JsonResponse
    {
        $suppression->delete();
        return response()->json(['success' => true, 'message' => 'Removed from suppression list.']);
    }

    // GET /suppression/check
    public function check(Request $request): JsonResponse
    {
        $request->validate(['recipient' => 'required|string', 'channel' => 'required|in:email,whatsapp,sms,push,all']);
        $suppressed = SuppressionList::isSuppressed($request->recipient, $request->channel);
        return response()->json(['success' => true, 'data' => ['suppressed' => $suppressed]]);
    }
}


// ─────────────────────────────────────────────────────────────────────────────

namespace ReachHub\Http\Controllers;

use ReachHub\Models\ApiKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ApiKeyController extends Controller
{
    // GET /api-keys
    public function index(): JsonResponse
    {
        $keys = ApiKey::orderByDesc('created_at')->get()->makeVisible('key');
        return response()->json(['success' => true, 'data' => $keys]);
    }

    // POST /api-keys
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'            => 'required|string|max:255',
            'permissions'     => 'array',
            'permissions.*'   => 'string',
            'expires_in_days' => 'integer|min:1|max:3650',
        ]);

        $key = ApiKey::generate(
            $request->name,
            $request->get('permissions', ['*']),
            $request->get('expires_in_days', 365),
        );

        return response()->json(['success' => true, 'data' => $key->makeVisible('key')], 201);
    }

    // DELETE /api-keys/{key}
    public function destroy(ApiKey $apiKey): JsonResponse
    {
        $apiKey->revoke();
        return response()->json(['success' => true, 'message' => 'API key revoked.']);
    }
}


// ─────────────────────────────────────────────────────────────────────────────

namespace ReachHub\Http\Controllers;

use ReachHub\Models\WebhookSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class WebhookSubscriptionController extends Controller
{
    // GET /webhooks/subscriptions
    public function index(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => WebhookSubscription::paginate(20)]);
    }

    // POST /webhooks/subscriptions
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'        => 'required|string|max:255',
            'url'         => 'required|url',
            'events'      => 'required|array',
            'events.*'    => 'in:' . implode(',', array_merge(['*'], WebhookSubscription::EVENTS)),
            'secret'      => 'nullable|string|min:8',
            'auth_type'   => 'in:none,bearer,basic,header',
            'auth_config' => 'nullable|array',
        ]);

        $sub = WebhookSubscription::create(array_merge(
            $request->validated(),
            ['secret' => $request->get('secret', bin2hex(random_bytes(16))), 'is_active' => true]
        ));

        return response()->json(['success' => true, 'data' => $sub], 201);
    }

    // PUT /webhooks/subscriptions/{id}
    public function update(Request $request, WebhookSubscription $webhookSubscription): JsonResponse
    {
        $webhookSubscription->update($request->only(['name', 'url', 'events', 'auth_type', 'auth_config', 'is_active']));
        return response()->json(['success' => true, 'data' => $webhookSubscription]);
    }

    // DELETE /webhooks/subscriptions/{id}
    public function destroy(WebhookSubscription $webhookSubscription): JsonResponse
    {
        $webhookSubscription->delete();
        return response()->json(['success' => true]);
    }

    // POST /webhooks/subscriptions/{id}/test
    public function test(WebhookSubscription $webhookSubscription): JsonResponse
    {
        $result = $webhookSubscription->sendTest();
        return response()->json(['success' => true, 'data' => $result]);
    }

    // GET /webhooks/events
    public function events(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => WebhookSubscription::EVENTS]);
    }
}


// ─────────────────────────────────────────────────────────────────────────────

namespace ReachHub\Http\Controllers;

use ReachHub\Models\EmailTemplate;
use ReachHub\Models\Campaign;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class EmailTemplateController extends Controller
{
    // GET /templates
    public function index(Request $request): JsonResponse
    {
        $query = EmailTemplate::query()->orderByDesc('usage_count');

        if ($request->filled('category')) $query->where('category', $request->category);
        if ($request->filled('search'))   $query->where('name', 'like', '%' . $request->search . '%');

        return response()->json(['success' => true, 'data' => $query->paginate(15)]);
    }

    // GET /templates/presets
    public function presets(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => EmailTemplate::presets()]);
    }

    // POST /templates
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'          => 'required|string|max:255',
            'category'      => 'required|in:welcome,newsletter,promotion,transactional,win-back,event,other',
            'subject'       => 'required|string|max:255',
            'html'          => 'required|string',
            'description'   => 'nullable|string',
            'is_public'     => 'boolean',
        ]);

        $template = EmailTemplate::create($validated);
        return response()->json(['success' => true, 'data' => $template], 201);
    }

    // POST /templates/presets/{key}/import
    public function importPreset(string $key): JsonResponse
    {
        $presets = EmailTemplate::presets();

        if (!isset($presets[$key])) {
            return response()->json(['success' => false, 'message' => "Preset '{$key}' not found."], 404);
        }

        $template = EmailTemplate::create(array_merge($presets[$key], ['is_public' => false]));
        return response()->json(['success' => true, 'data' => $template], 201);
    }

    // POST /campaigns/{campaign}/apply-template/{template}
    public function applyToCampaign(Campaign $campaign, EmailTemplate $template): JsonResponse
    {
        if ($campaign->isSent()) {
            return response()->json(['success' => false, 'message' => 'Cannot edit a sent campaign.'], 422);
        }

        $campaign->update([
            'subject' => $template->subject,
            'body'    => $template->html,
        ]);

        $template->incrementUsage();

        return response()->json(['success' => true, 'data' => $campaign->fresh()]);
    }

    // DELETE /templates/{id}
    public function destroy(EmailTemplate $emailTemplate): JsonResponse
    {
        $emailTemplate->delete();
        return response()->json(['success' => true]);
    }
}


// ─────────────────────────────────────────────────────────────────────────────

namespace ReachHub\Http\Controllers;

use ReachHub\Models\Campaign;
use ReachHub\Models\WebhookSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class CampaignApprovalController extends Controller
{
    // POST /campaigns/{campaign}/submit-approval
    public function submit(Campaign $campaign): JsonResponse
    {
        if (!in_array($campaign->status, ['draft'])) {
            return response()->json(['success' => false, 'message' => 'Only draft campaigns can be submitted for approval.'], 422);
        }

        $campaign->update(['status' => 'pending_approval', 'approval_status' => 'pending']);
        WebhookSubscription::fire('campaign.updated', ['campaign_id' => $campaign->id, 'event' => 'approval_requested']);

        return response()->json(['success' => true, 'data' => $campaign->fresh()]);
    }

    // POST /campaigns/{campaign}/approve
    public function approve(Request $request, Campaign $campaign): JsonResponse
    {
        if ($campaign->status !== 'pending_approval') {
            return response()->json(['success' => false, 'message' => 'Campaign is not pending approval.'], 422);
        }

        $campaign->update([
            'status'          => 'draft',
            'approval_status' => 'approved',
            'approved_at'     => now(),
            'approval_notes'  => $request->get('notes'),
        ]);

        return response()->json(['success' => true, 'data' => $campaign->fresh()]);
    }

    // POST /campaigns/{campaign}/reject
    public function reject(Request $request, Campaign $campaign): JsonResponse
    {
        $request->validate(['notes' => 'required|string']);

        $campaign->update([
            'status'          => 'draft',
            'approval_status' => 'rejected',
            'approval_notes'  => $request->notes,
        ]);

        return response()->json(['success' => true, 'data' => $campaign->fresh()]);
    }
}


// ─────────────────────────────────────────────────────────────────────────────

namespace ReachHub\Http\Controllers;

use ReachHub\Services\CSV\CSVPreviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class CSVPreviewController extends Controller
{
    public function __construct(private readonly CSVPreviewService $csv) {}

    // POST /contacts/import/preview
    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'file'  => 'required|file|mimes:csv,txt|max:10240',
            'rows'  => 'integer|min:1|max:20',
        ]);

        $path    = $request->file('file')->store('ck-csv-previews');
        $result  = $this->csv->preview(storage_path('app/' . $path), $request->get('rows', 5));

        return response()->json(['success' => true, 'data' => $result]);
    }

    // POST /contacts/import/validate-mapping
    public function validateMapping(Request $request): JsonResponse
    {
        $request->validate([
            'mapping' => 'required|array',
            'headers' => 'required|array',
        ]);

        $errors = $this->csv->validateMapping($request->mapping, $request->headers);

        return response()->json(['success' => empty($errors), 'data' => ['errors' => $errors]]);
    }
}


// ─────────────────────────────────────────────────────────────────────────────

namespace ReachHub\Http\Controllers;

use ReachHub\Models\Campaign;
use ReachHub\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CalendarController extends Controller
{
    // GET /campaigns/calendar?month=2024-12
    public function index(Request $request): JsonResponse
    {
        $start = Carbon::parse($request->get('month', now()->format('Y-m')))->startOfMonth();
        $end   = $start->copy()->endOfMonth();

        $campaigns = Campaign::where(function ($q) use ($start, $end) {
            $q->whereBetween('scheduled_at', [$start, $end])
              ->orWhereBetween('sent_at', [$start, $end]);
        })->get(['id', 'name', 'channel', 'status', 'scheduled_at', 'sent_at']);

        $calendar = [];
        foreach ($campaigns as $c) {
            $date = ($c->scheduled_at ?? $c->sent_at)?->format('Y-m-d');
            if ($date) {
                $calendar[$date][] = $c->only(['id', 'name', 'channel', 'status']);
            }
        }

        return response()->json(['success' => true, 'data' => ['month' => $start->format('Y-m'), 'days' => $calendar]]);
    }
}


// ─────────────────────────────────────────────────────────────────────────────

namespace ReachHub\Http\Controllers;

use ReachHub\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class ContactTimelineController extends Controller
{
    // GET /contacts/{contact}/timeline
    public function show(Contact $contact): JsonResponse
    {
        $events = collect();

        // Campaign log events
        $contact->load(['logs.campaign']);
        foreach ($contact->logs as $log) {
            $events->push(['type' => 'campaign_' . $log->status, 'timestamp' => $log->created_at?->toISOString(), 'details' => ['campaign_name' => $log->campaign?->name, 'channel' => $log->channel, 'message_id' => $log->message_id]]);
            if ($log->opened_at)    $events->push(['type' => 'opened',    'timestamp' => $log->opened_at->toISOString(),   'details' => ['campaign_name' => $log->campaign?->name, 'channel' => $log->channel]]);
            if ($log->clicked_at)   $events->push(['type' => 'clicked',   'timestamp' => $log->clicked_at->toISOString(),  'details' => ['campaign_name' => $log->campaign?->name]]);
            if ($log->delivered_at) $events->push(['type' => 'delivered', 'timestamp' => $log->delivered_at->toISOString(),'details' => ['campaign_name' => $log->campaign?->name]]);
        }

        // Consent events
        $consents = DB::table('ck_consents')->where('contact_id', $contact->id)->get();
        foreach ($consents as $c) {
            $events->push(['type' => 'consent_granted', 'timestamp' => $c->granted_at, 'details' => ['channel' => $c->channel, 'purpose' => $c->purpose]]);
        }

        // Suppression events
        $suppressions = DB::table('ck_suppression_list')->where('recipient', $contact->email)->orWhere('recipient', $contact->phone)->get();
        foreach ($suppressions as $s) {
            $events->push(['type' => 'suppressed', 'timestamp' => $s->created_at, 'details' => ['channel' => $s->channel, 'reason' => $s->reason]]);
        }

        $sorted = $events->filter(fn($e) => !empty($e['timestamp']))->sortByDesc('timestamp')->values();

        return response()->json(['success' => true, 'data' => $sorted]);
    }
}
