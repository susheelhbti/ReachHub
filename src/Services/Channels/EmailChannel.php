<?php

namespace ReachHub\Services\Channels;

use ReachHub\Models\Campaign;
use ReachHub\Models\Contact;
use Illuminate\Support\Facades\Mail;

class EmailChannel implements ChannelContract
{
    public function channelName(): string
    {
        return 'email';
    }

    public function validateConfig(): void
    {
        if (!config('reachhub.email.enabled')) {
            throw new \RuntimeException('Email channel is disabled in reachhub config.');
        }
    }

    public function send(Campaign $campaign, Contact $contact): array
    {
        if (empty($contact->email)) {
            return ['success' => false, 'message_id' => null, 'error' => 'Contact has no email address.'];
        }

        try {
            $body    = $this->interpolate($campaign->body, $contact, $campaign->template_vars ?? []);
            $subject = $this->interpolate($campaign->subject ?? $campaign->name, $contact, $campaign->template_vars ?? []);

            $fromAddress = $campaign->from_address ?? config('reachhub.email.from_address');
            $fromName    = $campaign->from_name    ?? config('reachhub.email.from_name');

            Mail::html($body, function ($message) use ($contact, $subject, $fromAddress, $fromName) {
                $message->to($contact->email, $contact->name)
                        ->subject($subject)
                        ->from($fromAddress, $fromName);
            });

            return ['success' => true, 'message_id' => null, 'error' => null];

        } catch (\Throwable $e) {
            return ['success' => false, 'message_id' => null, 'error' => $e->getMessage()];
        }
    }

    private function interpolate(string $template, Contact $contact, array $vars): string
    {
        $replacements = array_merge([
            '{{name}}'  => $contact->name  ?? '',
            '{{email}}' => $contact->email ?? '',
            '{{phone}}' => $contact->phone ?? '',
        ], $vars);

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }
}
