<?php

namespace ReachHub\Http\Middleware;

use ReachHub\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Auth modes (set reachhub.auth_mode in config):
 *   'single_token' (default) — CAMPAIGNKIT_API_TOKEN env var, all-or-nothing
 *   'api_keys'               — ck_api_keys table, per-key permission scopes
 */
class ReachHubAuth
{
    private const ROUTE_PERMISSIONS = [
        'reachhub.campaigns'   => ['campaigns.read', 'campaigns.write'],
        'reachhub.contacts'    => ['contacts.read',  'contacts.write'],
        'reachhub.lists'       => ['contacts.read',  'contacts.write'],
        'reachhub.analytics'   => ['analytics.read', 'analytics.read'],
        'reachhub.ai'          => ['campaigns.read', 'campaigns.write'],
        'reachhub.privacy'     => ['privacy.read',   'privacy.write'],
        'reachhub.webhooks'    => ['webhooks.read',  'webhooks.write'],
        'reachhub.suppression' => ['contacts.read',  'contacts.write'],
        'reachhub.templates'   => ['campaigns.read', 'campaigns.write'],
        'reachhub.workflows'   => ['workflows.read', 'workflows.write'],
        'reachhub.api-keys'    => ['*',              '*'],
        'reachhub.migrate'     => ['contacts.write', 'contacts.write'],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        return config('reachhub.auth_mode', 'single_token') === 'api_keys'
            ? $this->handleApiKey($request, $next)
            : $this->handleSingleToken($request, $next);
    }

    private function handleSingleToken(Request $request, Closure $next): Response
    {
        $configToken = config('reachhub.api_token');

        if (empty($configToken)) {
            return $next($request);
        }

        $bearer = $request->bearerToken();

        if (!$bearer || !hash_equals($configToken, $bearer)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized. Provide a valid Bearer token.'], 401);
        }

        return $next($request);
    }

    private function handleApiKey(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken() ?? $request->header('X-API-Key');

        if (!$token) {
            return response()->json(['success' => false, 'message' => 'No API key provided.'], 401);
        }

        $apiKey = ApiKey::findByToken($token);

        if (!$apiKey) {
            return response()->json(['success' => false, 'message' => 'Invalid, expired, or revoked API key.'], 401);
        }

        $required = $this->requiredPermission($request);

        if ($required && !$apiKey->hasPermission($required)) {
            return response()->json(['success' => false, 'message' => "API key lacks permission: {$required}"], 403);
        }

        $apiKey->touch();
        $request->attributes->set('api_key', $apiKey);

        return $next($request);
    }

    private function requiredPermission(Request $request): ?string
    {
        $routeName  = $request->route()?->getName() ?? '';
        $isReadOnly = in_array($request->method(), ['GET', 'HEAD', 'OPTIONS']);

        foreach (self::ROUTE_PERMISSIONS as $prefix => [$read, $write]) {
            if (str_starts_with($routeName, $prefix)) {
                return $isReadOnly ? $read : $write;
            }
        }

        return null;
    }
}
