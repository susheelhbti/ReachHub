<?php

namespace ReachHub\Services\Channels;

use ReachHub\Models\Campaign;
use ReachHub\Models\Contact;
use GuzzleHttp\Client;

class SmsChannel implements ChannelContract
{
    // GSM 7-bit alphabet characters — messages using only these stay at 160 chars/part
    private const GSM7_CHARS = '@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞ\x1BÆæßÉ !"#¤%&\'()*+,-./:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZ'
        . 'ÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà0123456789';

    private Client $http;

    public function __construct()
    {
        $this->http = new Client(['timeout' => 15]);
    }

    public function channelName(): string { return 'sms'; }

    public function validateConfig(): void
    {
        if (!config('reachhub.sms.enabled')) {
            throw new \RuntimeException('SMS channel is disabled.');
        }
    }

    public function send(Campaign $campaign, Contact $contact): array
    {
        $to = $contact->phone;

        if (empty($to)) {
            return ['success' => false, 'message_id' => null, 'error' => 'Contact has no phone number.'];
        }

        $body = $this->interpolate($campaign->body, $contact, $campaign->template_vars ?? []);

        // FIX: Proper GSM-7 / UCS-2 encoding detection and length limits
        // GSM-7: 160 chars single / 153 chars per part (multi-part)
        // UCS-2: 70 chars single / 67 chars per part (multi-part)
        $isGsm7      = $this->isGsm7($body);
        $singleLimit = $isGsm7 ? 160 : 70;
        $partLimit   = $isGsm7 ? 153 : 67;

        $maxParts = config('reachhub.sms.max_parts', 3);
        $maxChars = $maxParts > 1 ? $partLimit * $maxParts : $singleLimit;

        if (mb_strlen($body) > $maxChars) {
            $body = mb_substr($body, 0, $maxChars - 3) . '...';
        }

        $provider = config('reachhub.sms.provider', 'twilio');

        return match ($provider) {
            'twilio' => $this->sendViaTwilio($to, $body),
            'vonage' => $this->sendViaVonage($to, $body, $isGsm7),
            default  => ['success' => false, 'message_id' => null, 'error' => "Unknown SMS provider: {$provider}"],
        };
    }

    private function isGsm7(string $text): bool
    {
        for ($i = 0; $i < mb_strlen($text); $i++) {
            if (strpos(self::GSM7_CHARS, mb_substr($text, $i, 1)) === false) {
                return false;
            }
        }
        return true;
    }

    private function sendViaTwilio(string $to, string $body): array
    {
        try {
            $cfg = config('reachhub.sms.twilio');
            $url = "https://api.twilio.com/2010-04-01/Accounts/{$cfg['account_sid']}/Messages.json";

            $response = $this->http->post($url, [
                'auth'        => [$cfg['account_sid'], $cfg['auth_token']],
                'form_params' => [
                    'From' => $cfg['from'],
                    'To'   => $to,
                    'Body' => $body,
                ],
            ]);

            $data  = json_decode($response->getBody()->getContents(), true);
            $msgId = $data['sid'] ?? null;

            return ['success' => true, 'message_id' => $msgId, 'error' => null];

        } catch (\Throwable $e) {
            return ['success' => false, 'message_id' => null, 'error' => $e->getMessage()];
        }
    }

    private function sendViaVonage(string $to, string $body, bool $isGsm7): array
    {
        try {
            $cfg = config('reachhub.sms.vonage');

            $params = [
                'api_key'    => $cfg['api_key'],
                'api_secret' => $cfg['api_secret'],
                'from'       => $cfg['from'],
                'to'         => ltrim($to, '+'),
                'text'       => $body,
            ];

            // Tell Vonage to use unicode encoding for UCS-2 messages
            if (!$isGsm7) {
                $params['type'] = 'unicode';
            }

            $response = $this->http->post('https://rest.nexmo.com/sms/json', [
                'form_params' => $params,
            ]);

            $data   = json_decode($response->getBody()->getContents(), true);
            $status = $data['messages'][0]['status'] ?? '1';
            $msgId  = $data['messages'][0]['message-id'] ?? null;

            if ($status !== '0') {
                return ['success' => false, 'message_id' => null, 'error' => $data['messages'][0]['error-text'] ?? 'Unknown error'];
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
            '{{phone}}' => $contact->phone ?? '',
        ], $vars);

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }
}
