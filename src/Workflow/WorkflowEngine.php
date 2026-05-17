<?php

namespace ReachHub\Workflow;

use ReachHub\Models\Campaign;
use ReachHub\Models\Contact;
use ReachHub\Services\CampaignService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * Workflow engine for drip sequences and multi-step automations.
 *
 * A Workflow is defined as a JSON array of steps. Example:
 *
 * [
 *   {"id": "s1", "type": "email",     "campaign_id": 5, "delay_hours": 0},
 *   {"id": "s2", "type": "wait",      "delay_hours": 48},
 *   {"id": "s3", "type": "condition", "field": "opened_campaign", "value": 5, "yes": "s4", "no": "s5"},
 *   {"id": "s4", "type": "email",     "campaign_id": 6, "delay_hours": 0},
 *   {"id": "s5", "type": "sms",       "campaign_id": 7, "delay_hours": 0},
 *   {"id": "s6", "type": "tag",       "tag": "engaged"},
 *   {"id": "s7", "type": "end"}
 * ]
 *
 * Supported step types:
 *   email      - send a campaign to the contact
 *   sms        - send an SMS campaign
 *   whatsapp   - send a WhatsApp campaign
 *   push       - send a push campaign
 *   wait       - pause for delay_hours before next step
 *   condition  - branch on contact field / engagement
 *   tag        - add tag to contact
 *   untag      - remove tag from contact
 *   list_add   - add to contact list
 *   list_remove- remove from list
 *   webhook    - POST to external URL
 *   end        - terminate workflow for this contact
 */
class WorkflowEngine
{
    public function __construct(private readonly CampaignService $campaignService) {}

    /**
     * Enrol a contact into a workflow and immediately execute the first step.
     */
    public function enrol(int $workflowId, Contact $contact): void
    {
        $workflow = DB::table('ck_workflows')->find($workflowId);

        if (!$workflow) {
            throw new \RuntimeException("Workflow {$workflowId} not found.");
        }

        // Prevent duplicate enrolment unless re-enrolment is allowed
        $existing = DB::table('ck_workflow_contacts')
            ->where('workflow_id', $workflowId)
            ->where('contact_id', $contact->id)
            ->where('status', 'active')
            ->exists();

        if ($existing) {
            return;
        }

        $steps = json_decode($workflow->steps, true);
        $firstStep = $steps[0]['id'] ?? null;

        DB::table('ck_workflow_contacts')->insert([
            'workflow_id'    => $workflowId,
            'contact_id'     => $contact->id,
            'current_step'   => $firstStep,
            'status'         => 'active',
            'enrolled_at'    => now(),
            'next_action_at' => now(),
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $this->executeStep($workflow, $contact, $firstStep);
    }

    /**
     * Execute all workflow contacts whose next_action_at <= now().
     * Run via scheduler: reachhub:workflow-tick
     */
    public function tick(): int
    {
        $processed = 0;

        DB::table('ck_workflow_contacts')
            ->where('status', 'active')
            ->where('next_action_at', '<=', now())
            ->chunkById(50, function ($rows) use (&$processed) {
                foreach ($rows as $row) {
                    try {
                        $workflow = DB::table('ck_workflows')->find($row->workflow_id);
                        $contact  = Contact::find($row->contact_id);

                        if (!$workflow || !$contact || !$contact->subscribed) {
                            $this->updateContactStatus($row->id, 'completed');
                            continue;
                        }

                        $this->executeStep($workflow, $contact, $row->current_step, $row->id);
                        $processed++;
                    } catch (\Throwable $e) {
                        Log::error("[ReachHub:Workflow] Error for contact {$row->contact_id}: " . $e->getMessage());
                        $this->updateContactStatus($row->id, 'errored');
                    }
                }
            }, 'id');

        return $processed;
    }

    /**
     * Remove a contact from a workflow.
     */
    public function unenrol(int $workflowId, Contact $contact): void
    {
        DB::table('ck_workflow_contacts')
            ->where('workflow_id', $workflowId)
            ->where('contact_id', $contact->id)
            ->where('status', 'active')
            ->update(['status' => 'cancelled', 'updated_at' => now()]);
    }

    // ── Step Execution ─────────────────────────────────────────────────────

    private function executeStep(\stdClass $workflow, Contact $contact, string $stepId, ?int $rowId = null): void
    {
        $steps   = collect(json_decode($workflow->steps, true))->keyBy('id');
        $step    = $steps[$stepId] ?? null;

        if (!$step) {
            if ($rowId) $this->updateContactStatus($rowId, 'completed');
            return;
        }

        $nextStepId   = null;
        $delayHours   = 0;

        match ($step['type']) {
            'email', 'sms', 'whatsapp', 'push' => (function () use ($step, $contact, &$nextStepId, &$delayHours, $steps) {
                if (!empty($step['campaign_id'])) {
                    $campaign = Campaign::find($step['campaign_id']);
                    if ($campaign) {
                        $this->campaignService->sendToContact($campaign, $contact);
                    }
                }
                $nextStepId = $this->getNext($steps, $step['id']);
                $delayHours = $step['delay_hours'] ?? 0;
            })(),

            'wait' => (function () use ($step, $steps, &$nextStepId, &$delayHours) {
                $nextStepId = $this->getNext($steps, $step['id']);
                $delayHours = $step['delay_hours'] ?? 24;
            })(),

            'condition' => (function () use ($step, $contact, $steps, &$nextStepId) {
                $result     = $this->evaluateCondition($step, $contact);
                $nextStepId = $result ? ($step['yes'] ?? null) : ($step['no'] ?? null);
                if (!$nextStepId) $nextStepId = $this->getNext($steps, $step['id']);
            })(),

            'tag' => (function () use ($step, $contact, $steps, &$nextStepId) {
                $tags = $contact->tags ?? [];
                $tags[] = $step['tag'];
                $contact->update(['tags' => array_unique($tags)]);
                $nextStepId = $this->getNext($steps, $step['id']);
            })(),

            'untag' => (function () use ($step, $contact, $steps, &$nextStepId) {
                $tags = array_filter($contact->tags ?? [], fn($t) => $t !== $step['tag']);
                $contact->update(['tags' => array_values($tags)]);
                $nextStepId = $this->getNext($steps, $step['id']);
            })(),

            'list_add' => (function () use ($step, $contact, $steps, &$nextStepId) {
                $contact->lists()->syncWithoutDetaching([$step['list_id']]);
                $nextStepId = $this->getNext($steps, $step['id']);
            })(),

            'list_remove' => (function () use ($step, $contact, $steps, &$nextStepId) {
                $contact->lists()->detach($step['list_id']);
                $nextStepId = $this->getNext($steps, $step['id']);
            })(),

            'webhook' => (function () use ($step, $contact, $steps, &$nextStepId) {
                try {
                    app(\GuzzleHttp\Client::class)->post($step['url'], [
                        'json' => ['contact_id' => $contact->id, 'contact_email' => $contact->email, 'step' => $step],
                        'timeout' => 10,
                    ]);
                } catch (\Throwable $e) {
                    Log::warning("[ReachHub:Workflow] Webhook failed: " . $e->getMessage());
                }
                $nextStepId = $this->getNext($steps, $step['id']);
            })(),

            'end', default => (function () use ($rowId) {
                if ($rowId) $this->updateContactStatus($rowId, 'completed');
            })(),
        };

        if ($rowId && $nextStepId) {
            DB::table('ck_workflow_contacts')->where('id', $rowId)->update([
                'current_step'   => $nextStepId,
                'next_action_at' => now()->addHours($delayHours),
                'updated_at'     => now(),
            ]);
        }
    }

    private function evaluateCondition(array $step, Contact $contact): bool
    {
        return match ($step['field'] ?? '') {
            'opened_campaign' => $contact->logs()
                ->where('campaign_id', $step['value'])
                ->whereNotNull('opened_at')
                ->exists(),

            'clicked_campaign' => $contact->logs()
                ->where('campaign_id', $step['value'])
                ->whereNotNull('clicked_at')
                ->exists(),

            'has_tag' => in_array($step['value'], $contact->tags ?? []),

            'custom_field' => data_get($contact->custom_fields, $step['key']) == $step['value'],

            default => false,
        };
    }

    private function getNext(\Illuminate\Support\Collection $steps, string $currentId): ?string
    {
        $keys  = $steps->keys()->toArray();
        $index = array_search($currentId, $keys);

        return $keys[$index + 1] ?? null;
    }

    private function updateContactStatus(int $rowId, string $status): void
    {
        DB::table('ck_workflow_contacts')->where('id', $rowId)->update([
            'status'     => $status,
            'updated_at' => now(),
        ]);
    }
}
