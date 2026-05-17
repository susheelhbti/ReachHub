<?php

namespace ReachHub\Services\Channels;

use ReachHub\Models\Campaign;
use ReachHub\Models\Contact;
use GuzzleHttp\Client;

class PushChannel implements ChannelContract
{
    private Client $http;

    public function __construct()
    {
        $this->http = new Client(['timeout' => 15]);
    }

    public function channelName(): string { return 'push'; }

    public function validateConfig(): void
    {
        if (!config('reachhub.push.enabled')) {
            throw new \RuntimeException('Push channel is disabled.');
        }
        if (!config('reachhub.push.fcm_server_key')) {
            throw new \RuntimeException('FCM server key is required.');
        }
    }

    public function send(Campaign $campaign, Contact $contact): array
    {
        if (empty($contact->fcm_token)) {
            return ['success' => false, 'message_id' => null, 'error' => 'Contact has no FCM token.'];
        }

        $meta = $campaign->metadata ?? [];

        try {
            $payload = [
                'to' => $contact->fcm_token,
                'notification' => [
                    'title' => $this->interpolate($campaign->subject ?? $campaign->name, $contact, $campaign->template_vars ?? []),
                    'body'  => $this->interpolate($campaign->body, $contact, $campaign->template_vars ?? []),
                    'sound' => $meta['sound'] ?? 'default',
                    'icon'  => $meta['icon']  ?? null,
                    'image' => $meta['image'] ?? null,
                ],
                'data' => $meta['data'] ?? [],
                'android'       => ['priority' => 'high'],
                'apns'          => ['headers' => ['apns-priority' => '10']],
            ];

            $response = $this->http->post('https://fcm.googleapis.com/fcm/send', [
                'headers' => [
                    'Authorization' => 'key=' . config('reachhub.push.fcm_server_key'),
                    'Content-Type'  => 'application/json',
                ],
                'json' => $payload,
            ]);

            $data  = json_decode($response->getBody()->getContents(), true);
            $msgId = $data['results'][0]['message_id'] ?? null;

            if (!empty($data['results'][0]['error'])) {
                return ['success' => false, 'message_id' => null, 'error' => $data['results'][0]['error']];
            }

            return ['success' => true, 'message_id' => $msgId, 'error' => null];

        } catch (\Throwable $e) {
            return ['success' => false, 'message_id' => null, 'error' => $e->getMessage()];
        }
    }

    private function interpolate(string $template, Contact $contact, array $vars): string
    {
        $replacements = array_merge([
            '{{name}}'  => $contact->name  ?? '',
            '{{email}}' => $contact->email ?? '',
        ], $vars);

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }
}
