<?php

namespace ReachHub\Http\Controllers;

use ReachHub\Models\Campaign;
use ReachHub\Services\CampaignService;
use ReachHub\Http\Requests\StoreCampaignRequest;
use ReachHub\Http\Requests\UpdateCampaignRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class CampaignController extends Controller
{
    public function __construct(private CampaignService $service) {}

    // GET /campaigns
    public function index(Request $request): JsonResponse
    {
        $query = Campaign::query()->orderByDesc('created_at');

        if ($request->filled('channel')) {
            $query->byChannel($request->channel);
        }
        if ($request->filled('status')) {
            $query->byStatus($request->status);
        }
        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        $campaigns = $query->paginate($request->get('per_page', 15));

        return response()->json(['success' => true, 'data' => $campaigns]);
    }

    // POST /campaigns
    public function store(StoreCampaignRequest $request): JsonResponse
    {
        $campaign = Campaign::create(array_merge(
            $request->validated(),
            ['status' => 'draft']
        ));

        return response()->json(['success' => true, 'data' => $campaign], 201);
    }

    // GET /campaigns/{campaign}
    public function show(Campaign $campaign): JsonResponse
    {
        $campaign->load('contactLists');

        return response()->json(['success' => true, 'data' => $campaign]);
    }

    // PUT /campaigns/{campaign}
    public function update(UpdateCampaignRequest $request, Campaign $campaign): JsonResponse
    {
        if ($campaign->isSent()) {
            return response()->json(['success' => false, 'message' => 'Cannot edit a sent campaign.'], 422);
        }

        $campaign->update($request->validated());

        return response()->json(['success' => true, 'data' => $campaign]);
    }

    // DELETE /campaigns/{campaign}
    public function destroy(Campaign $campaign): JsonResponse
    {
        if ($campaign->status === 'sending') {
            return response()->json(['success' => false, 'message' => 'Cannot delete a campaign that is currently sending.'], 422);
        }

        $campaign->delete();

        return response()->json(['success' => true, 'message' => 'Campaign deleted.']);
    }

    // POST /campaigns/{campaign}/send
    public function send(Campaign $campaign): JsonResponse
    {
        try {
            $this->service->dispatch($campaign);
            return response()->json(['success' => true, 'message' => 'Campaign dispatched successfully.', 'data' => $campaign->fresh()]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    // POST /campaigns/{campaign}/schedule
    public function schedule(Request $request, Campaign $campaign): JsonResponse
    {
        $request->validate(['scheduled_at' => 'required|date|after:now']);

        if (!$campaign->isDraft()) {
            return response()->json(['success' => false, 'message' => 'Only draft campaigns can be scheduled.'], 422);
        }

        $campaign->update([
            'status'       => 'scheduled',
            'scheduled_at' => $request->scheduled_at,
        ]);

        return response()->json(['success' => true, 'data' => $campaign]);
    }

    // POST /campaigns/{campaign}/cancel
    public function cancel(Campaign $campaign): JsonResponse
    {
        if (!$campaign->isCancellable()) {
            return response()->json(['success' => false, 'message' => "Campaign cannot be cancelled (status: {$campaign->status})."], 422);
        }

        $campaign->update(['status' => 'cancelled']);

        return response()->json(['success' => true, 'data' => $campaign]);
    }

    // POST /campaigns/{campaign}/duplicate
    public function duplicate(Campaign $campaign): JsonResponse
    {
        $new = $campaign->replicate();
        $new->name       = $campaign->name . ' (Copy)';
        $new->status     = 'draft';
        $new->sent_at    = null;
        $new->scheduled_at = null;
        $new->save();

        return response()->json(['success' => true, 'data' => $new], 201);
    }
}

    // NOTE: Add these methods to the existing CampaignController class
    // POST /campaigns/{campaign}/pause
    public function pause(Campaign $campaign): JsonResponse
    {
        try {
            $this->service->pause($campaign);
            return response()->json(['success' => true, 'data' => $campaign->fresh()]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    // POST /campaigns/{campaign}/resume
    public function resume(Campaign $campaign): JsonResponse
    {
        try {
            $this->service->resume($campaign);
            return response()->json(['success' => true, 'data' => $campaign->fresh()]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }
