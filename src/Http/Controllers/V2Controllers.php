<?php

namespace ReachHub\Http\Controllers;

use ReachHub\AI\ContentEngine;
use ReachHub\AI\SendTimeOptimizer;
use ReachHub\Models\Campaign;
use ReachHub\Models\Contact;
use ReachHub\Services\Preview\CampaignPreviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AiController extends Controller
{
    public function __construct(
        private readonly ContentEngine $ai,
        private readonly SendTimeOptimizer $optimizer,
        private readonly CampaignPreviewService $preview,
    ) {}

    // POST /ai/subject-lines
    public function subjectLines(Request $request): JsonResponse
    {
        $request->validate([
            'topic'    => 'required|string|max:500',
            'tone'     => 'in:friendly,professional,urgent,playful,formal',
            'count'    => 'integer|min:1|max:10',
            'audience' => 'nullable|string|max:200',
        ]);

        $lines = $this->ai->generateSubjectLines(
            topic:    $request->topic,
            tone:     $request->get('tone', 'friendly'),
            count:    $request->get('count', 5),
            audience: $request->get('audience', 'general'),
        );

        return response()->json(['success' => true, 'data' => $lines]);
    }

    // POST /ai/adapt-channel
    public function adaptChannel(Request $request): JsonResponse
    {
        $request->validate([
            'content'      => 'required|string',
            'from_channel' => 'required|in:email,whatsapp,sms,push',
            'to_channel'   => 'required|in:email,whatsapp,sms,push',
        ]);

        $adapted = $this->ai->adaptForChannel(
            $request->content,
            $request->from_channel,
            $request->to_channel,
        );

        return response()->json(['success' => true, 'data' => ['content' => $adapted]]);
    }

    // POST /ai/analyse
    public function analyse(Request $request): JsonResponse
    {
        $request->validate([
            'subject' => 'nullable|string',
            'body'    => 'required|string',
            'channel' => 'in:email,whatsapp,sms,push',
        ]);

        $analysis = $this->ai->analyseContent(
            $request->get('subject', ''),
            $request->body,
            $request->get('channel', 'email'),
        );

        return response()->json(['success' => true, 'data' => $analysis]);
    }

    // GET /ai/send-time/{contact}
    public function sendTime(Contact $contact): JsonResponse
    {
        $time = $this->optimizer->nextOptimalTime($contact);

        return response()->json([
            'success' => true,
            'data'    => [
                'contact_id'       => $contact->id,
                'optimal_send_at'  => $time->toISOString(),
                'day'              => $time->format('l'),
                'hour'             => $time->format('H:00'),
            ],
        ]);
    }

    // GET /campaigns/{campaign}/preview
    public function campaignPreview(Request $request, Campaign $campaign): JsonResponse
    {
        $contact = $request->filled('contact_id')
            ? Contact::findOrFail($request->contact_id)
            : null;

        $rendered = (new CampaignPreviewService(new ContentEngine()))->render($campaign, $contact);

        return response()->json(['success' => true, 'data' => $rendered]);
    }

    // POST /campaigns/{campaign}/validate
    public function campaignValidate(Campaign $campaign): JsonResponse
    {
        $report = $this->preview->validate($campaign);

        return response()->json(['success' => true, 'data' => $report]);
    }

    // POST /campaigns/{campaign}/test-send
    public function testSend(Request $request, Campaign $campaign): JsonResponse
    {
        $request->validate([
            'emails'     => 'required|array|min:1|max:10',
            'emails.*'   => 'email',
            'contact_id' => 'nullable|exists:ck_contacts,id',
        ]);

        $contact = $request->filled('contact_id') ? Contact::find($request->contact_id) : null;
        $result  = $this->preview->sendTestEmail($campaign, $request->emails, $contact);

        return response()->json(['success' => true, 'data' => $result]);
    }
}


// ─────────────────────────────────────────────────────────────────────────────

namespace ReachHub\Http\Controllers;

use ReachHub\Workflow\WorkflowEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use ReachHub\Models\Contact;

class WorkflowController extends Controller
{
    public function __construct(private readonly WorkflowEngine $engine) {}

    // GET /workflows
    public function index(): JsonResponse
    {
        $workflows = DB::table('ck_workflows')
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->paginate(15);

        return response()->json(['success' => true, 'data' => $workflows]);
    }

    // POST /workflows
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'           => 'required|string|max:255',
            'description'    => 'nullable|string',
            'steps'          => 'required|array|min:1',
            'trigger'        => 'in:manual,contact_added,tag_added,campaign_opened',
            'trigger_config' => 'nullable|array',
        ]);

        $id = DB::table('ck_workflows')->insertGetId([
            ...$validated,
            'steps'          => json_encode($validated['steps']),
            'trigger_config' => json_encode($validated['trigger_config'] ?? null),
            'status'         => 'draft',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        return response()->json(['success' => true, 'data' => DB::table('ck_workflows')->find($id)], 201);
    }

    // GET /workflows/{id}
    public function show(int $id): JsonResponse
    {
        $workflow = DB::table('ck_workflows')->find($id);
        abort_unless($workflow, 404);

        $enrolled = DB::table('ck_workflow_contacts')->where('workflow_id', $id)->count();

        return response()->json(['success' => true, 'data' => [...(array)$workflow, 'enrolled_count' => $enrolled]]);
    }

    // POST /workflows/{id}/enrol
    public function enrol(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'contact_ids'   => 'required|array|min:1',
            'contact_ids.*' => 'integer|exists:ck_contacts,id',
        ]);

        $enrolled = 0;
        foreach ($request->contact_ids as $contactId) {
            $contact = Contact::find($contactId);
            if ($contact) {
                $this->engine->enrol($id, $contact);
                $enrolled++;
            }
        }

        return response()->json(['success' => true, 'data' => ['enrolled' => $enrolled]]);
    }

    // POST /workflows/{id}/unenrol
    public function unenrol(Request $request, int $id): JsonResponse
    {
        $request->validate(['contact_ids' => 'required|array', 'contact_ids.*' => 'integer']);

        foreach ($request->contact_ids as $contactId) {
            $contact = Contact::find($contactId);
            if ($contact) $this->engine->unenrol($id, $contact);
        }

        return response()->json(['success' => true]);
    }
}


// ─────────────────────────────────────────────────────────────────────────────

namespace ReachHub\Http\Controllers;

use ReachHub\Migration\MigrationWizard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class MigrationController extends Controller
{
    public function __construct(private readonly MigrationWizard $wizard) {}

    // POST /migrate/mailchimp
    public function fromMailchimp(Request $request): JsonResponse
    {
        $request->validate([
            'api_key'    => 'required|string',
            'datacenter' => 'nullable|string|regex:/^us\d+$/',
        ]);

        $report = $this->wizard->fromMailchimp(
            $request->api_key,
            $request->get('datacenter', 'us1')
        );

        return response()->json(['success' => true, 'data' => $report->toArray()]);
    }

    // POST /migrate/csv
    public function fromCSV(Request $request): JsonResponse
    {
        $request->validate([
            'file'    => 'required|file|mimes:csv,txt|max:10240',
            'mapping' => 'required|array',
            'list_id' => 'nullable|exists:ck_contact_lists,id',
        ]);

        $path   = $request->file('file')->store('reachhub-imports');
        $report = $this->wizard->fromCSV(storage_path('app/' . $path), $request->mapping, $request->list_id);

        return response()->json(['success' => true, 'data' => $report->toArray()]);
    }

    // POST /migrate/json
    public function fromJSON(Request $request): JsonResponse
    {
        $request->validate([
            'contacts'   => 'required|array|min:1|max:10000',
            'list_id'    => 'nullable|exists:ck_contact_lists,id',
        ]);

        $report = $this->wizard->fromJSON($request->contacts, $request->list_id);

        return response()->json(['success' => true, 'data' => $report->toArray()]);
    }
}


// ─────────────────────────────────────────────────────────────────────────────

namespace ReachHub\Http\Controllers;

use ReachHub\Privacy\PrivacyService;
use ReachHub\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PrivacyController extends Controller
{
    public function __construct(private readonly PrivacyService $privacy) {}

    // DELETE /privacy/contacts/{contact}/erase
    public function erase(Contact $contact): JsonResponse
    {
        $this->privacy->eraseContact($contact);
        return response()->json(['success' => true, 'message' => 'Contact data erased (GDPR Art. 17).']);
    }

    // GET /privacy/contacts/{contact}/export
    public function export(Contact $contact): JsonResponse
    {
        $data = $this->privacy->exportContactData($contact);
        return response()->json(['success' => true, 'data' => $data]);
    }

    // POST /privacy/contacts/{contact}/consent
    public function recordConsent(Request $request, Contact $contact): JsonResponse
    {
        $request->validate([
            'channel'    => 'required|in:email,whatsapp,sms,push,all',
            'purpose'    => 'in:marketing,transactional,analytics',
            'source_url' => 'nullable|url',
        ]);

        $this->privacy->recordConsent(
            $contact,
            $request->channel,
            $request->get('purpose', 'marketing'),
            $request->get('source_url'),
            $request->ip(),
            $request->userAgent(),
        );

        return response()->json(['success' => true, 'message' => 'Consent recorded.']);
    }

    // DELETE /privacy/contacts/{contact}/consent
    public function withdrawConsent(Request $request, Contact $contact): JsonResponse
    {
        $request->validate([
            'channel' => 'required|in:email,whatsapp,sms,push,all',
            'purpose' => 'in:marketing,transactional,analytics',
        ]);

        $this->privacy->withdrawConsent($contact, $request->channel, $request->get('purpose', 'marketing'));

        return response()->json(['success' => true, 'message' => 'Consent withdrawn.']);
    }

    // GET /privacy/contacts/{contact}/consent
    public function checkConsent(Request $request, Contact $contact): JsonResponse
    {
        $request->validate([
            'channel' => 'required|in:email,whatsapp,sms,push',
            'purpose' => 'in:marketing,transactional,analytics',
        ]);

        $has = $this->privacy->hasConsent($contact, $request->channel, $request->get('purpose', 'marketing'));

        return response()->json(['success' => true, 'data' => ['has_consent' => $has]]);
    }
}
