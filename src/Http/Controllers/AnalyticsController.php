<?php

namespace ReachHub\Http\Controllers;

use ReachHub\Models\Campaign;
use ReachHub\Models\CampaignLog;
use ReachHub\Services\CampaignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class AnalyticsController extends Controller
{
    public function __construct(private CampaignService $service) {}

    // GET /analytics/campaigns/{campaign}
    public function campaign(Campaign $campaign): JsonResponse
    {
        $logs    = $campaign->logs();
        $total   = $this->service->getRecipients($campaign)->count();

        $stats = [
            'total_recipients' => $total,
            'sent'             => (clone $logs)->where('status', 'sent')->count(),
            'delivered'        => (clone $logs)->where('status', 'delivered')->count(),
            'opened'           => (clone $logs)->where('status', 'opened')->count(),
            'clicked'          => (clone $logs)->where('status', 'clicked')->count(),
            'failed'           => (clone $logs)->where('status', 'failed')->count(),
            'bounced'          => (clone $logs)->where('status', 'bounced')->count(),
        ];

        $stats['open_rate']    = $stats['sent']      > 0 ? round($stats['opened']    / $stats['sent'] * 100, 2) : 0;
        $stats['click_rate']   = $stats['opened']    > 0 ? round($stats['clicked']   / $stats['opened'] * 100, 2) : 0;
        $stats['failure_rate'] = $total              > 0 ? round($stats['failed']    / $total * 100, 2) : 0;

        $recent = (clone $logs)
            ->latest()
            ->limit(20)
            ->get(['id', 'contact_id', 'recipient', 'status', 'sent_at', 'error_message']);

        return response()->json([
            'success' => true,
            'data' => [
                'campaign' => $campaign->only(['id', 'name', 'channel', 'status', 'sent_at', 'scheduled_at']),
                'stats'    => $stats,
                'recent'   => $recent,
            ],
        ]);
    }

    // GET /analytics/overview
    public function overview(): JsonResponse
    {
        $overview = Campaign::query()
            ->selectRaw('channel, status, count(*) as total')
            ->groupBy('channel', 'status')
            ->get()
            ->groupBy('channel');

        $logStats = CampaignLog::query()
            ->selectRaw('channel, status, count(*) as total')
            ->groupBy('channel', 'status')
            ->get()
            ->groupBy('channel');

        return response()->json([
            'success' => true,
            'data'    => [
                'campaigns' => $overview,
                'messages'  => $logStats,
            ],
        ]);
    }
}
