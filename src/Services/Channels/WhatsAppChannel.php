<?php

namespace ReachHub\Services\Channels;

use ReachHub\Models\Campaign;
use ReachHub\Models\Contact;
use GuzzleHttp\Client;

class WhatsAppChannel implements ChannelContract
{
    private Client $http;

    public function __construct()
    {
        $this->http = new Client(['timeout' => 15]);
    }

    public function channelName(): string
    {
        return 'whatsapp';
    }

    public function validateConfig(): void
    {
        if (!config('reachhub.whatsapp.enabled')) {
            throw new \RuntimeException('WhatsApp channel is disabled in reachhub config.');
        }
        if (!config('reachhub.whatsapp.phone_id') || !config('reachhub.whatsapp.access_token')) {
            throw new \RuntimeException('WhatsApp phone_id and access_token are required.');
        }
    }

    public function send(Campaign $campaign, Contact $contact): array
    {
        $to = $contact->whatsapp ?: $contact->phone;

        if (empty($to)) {
            return ['success' => false, 'message_id' => null, 'error' => 'Contact has no WhatsApp/phone number.'];
        }

        // Strip non-numeric chars except leading +
        $to = preg_replace('/[^0-9]/', '', $to);

        $meta = $campaign->metadata ?? [];

        try {
            // If a pre-approved template is specified, use template message
            if (!empty($meta['whatsapp_template'])) {
                $payload = $this->buildTemplatePayload($to, $meta, $campaign);
            } else {
                // Free-form text (only works within 24h customer service window)
                $body    = $this->interpolate($campaign->body, $contact, $campaign->template_vars ?? []);
                $payload = [
                    'messaging_product' => 'whatsapp',
                    'to'                => $to,
                    'type'              => 'text',
                    'text'              => ['body' => $body],
                ];
            }

            $response = $this->http->post($this->endpoint(), [
                'headers' => [
                    'Authorization' => 'Bearer ' . config('reachhub.whatsapp.access_token'),
                    'Content-Type'  => 'application/json',
                ],
                'json' => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $msgId = $data['messages'][0]['id'] ?? null;

            return ['success' => true, 'message_id' => $msgId, 'error' => null];

        } catch (\Throwable $e) {
            return ['success' => false, 'message_id' => null, 'error' => $e->getMessage()];
        }
    }

    private function buildTemplatePayload(string $to, array $meta, Campaign $campaign): array
    {
        $components = [];

        // Header component (if template has a header with variables)
        if (!empty($meta['whatsapp_header_params'])) {
            $components[] = [
                'type'       => 'header',
                'parameters' => array_map(
                    fn($p) => ['type' => 'text', 'text' => $p],
                    $meta['whatsapp_header_params']
                ),
            ];
        }

        // Body component variables
        $bodyParams = $meta['whatsapp_body_params'] ?? [];
        if (!empty($bodyParams)) {
            $components[] = [
                'type'       => 'body',
                'parameters' => array_map(
                    fn($p) => ['type' => 'text', 'text' => $p],
                    $bodyParams
                ),
            ];
        }

        return [
            'messaging_product' => 'whatsapp',
            'to'                => $to,
            'type'              => 'template',
            'template'          => [
                'name'       => $meta['whatsapp_template'],
                'language'   => ['code' => $meta['whatsapp_language'] ?? 'en'],
                'components' => $components,
            ],
        ];
    }

    private function endpoint(): string
    {
        $base    = rtrim(config('reachhub.whatsapp.base_url'), '/');
        $version = config('reachhub.whatsapp.api_version', 'v19.0');
        $phoneId = config('reachhub.whatsapp.phone_id');
        return "{$base}/{$version}/{$phoneId}/messages";
    }

    private function interpolate(string $template, Contact $contact, array $vars): string
    {
        $replacements = array_merge([
            '{{name}}'  => $contact->name  ?? '',
            '{{phone}}' => $contact->phone ?? '',
        ], $vars);

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }
}
