<?php

namespace ReachHub\Services\Preview;

use ReachHub\AI\ContentEngine;
use ReachHub\Models\Campaign;
use ReachHub\Models\Contact;
use GuzzleHttp\Client;

class CampaignPreviewService
{
    private Client $http;

    public function __construct(private readonly ContentEngine $ai)
    {
        $this->http = new Client(['timeout' => 10]);
    }

    /**
     * Render the campaign body/subject with a real or sample contact's data.
     */
    public function render(Campaign $campaign, ?Contact $contact = null): array
    {
        $sampleContact = $contact ?? $this->fakeSampleContact();

        $subject = $this->interpolate($campaign->subject ?? $campaign->name, $sampleContact, $campaign->template_vars ?? []);
        $body    = $this->interpolate($campaign->body, $sampleContact, $campaign->template_vars ?? []);

        return [
            'subject'        => $subject,
            'body'           => $body,
            'preview_text'   => substr(strip_tags($body), 0, 140),
            'contact_sample' => [
                'name'  => $sampleContact->name,
                'email' => $sampleContact->email,
            ],
        ];
    }

    /**
     * Run all pre-send validations. Returns structured report.
     */
    public function validate(Campaign $campaign): array
    {
        $errors   = [];
        $warnings = [];

        // ── Required field checks ──
        if (empty($campaign->body)) {
            $errors[] = 'Campaign body is empty.';
        }

        if ($campaign->channel === 'email' && empty($campaign->subject)) {
            $errors[] = 'Email campaigns require a subject line.';
        }

        if ($campaign->channel === 'push' && empty($campaign->subject)) {
            $errors[] = 'Push campaigns require a title (subject).';
        }

        // ── Broken link detection ──
        $brokenLinks = $this->checkLinks($campaign->body);
        foreach ($brokenLinks as $link) {
            $warnings[] = "Broken or unreachable link: {$link}";
        }

        // ── Unresolved variable detection ──
        $unresolved = $this->findUnresolvedVars($campaign->body . ' ' . $campaign->subject);
        foreach ($unresolved as $var) {
            $warnings[] = "Unresolved template variable: {$var}";
        }

        // ── Channel-specific length checks ──
        if ($campaign->channel === 'sms') {
            $len = mb_strlen(strip_tags($campaign->body));
            if ($len > 459) {
                $warnings[] = "SMS body is {$len} chars. Consider shortening (max 459 chars = 3 parts).";
            }
        }

        if ($campaign->channel === 'push') {
            $bodyLen    = mb_strlen($campaign->body);
            $subjectLen = mb_strlen($campaign->subject ?? '');
            if ($bodyLen > 100) $warnings[] = "Push body is {$bodyLen} chars (recommended < 100).";
            if ($subjectLen > 65) $warnings[] = "Push title is {$subjectLen} chars (recommended < 65).";
        }

        // ── AI quality check (if enabled) ──
        $aiAnalysis = [];
        if (config('reachhub.ai.driver', 'none') !== 'none' && !empty($campaign->body)) {
            try {
                $aiAnalysis = $this->ai->analyseContent(
                    $campaign->subject ?? '',
                    $campaign->body,
                    $campaign->channel
                );

                if (($aiAnalysis['spam_score'] ?? 0) > 0.4) {
                    $warnings[] = 'High spam score (' . round($aiAnalysis['spam_score'] * 100) . '%). Check: ' . implode(', ', $aiAnalysis['spam_triggers'] ?? []);
                }
            } catch (\Throwable) {
                // AI unavailable — skip
            }
        }

        return [
            'valid'       => empty($errors),
            'errors'      => $errors,
            'warnings'    => $warnings,
            'ai_analysis' => $aiAnalysis,
            'channel'     => $campaign->channel,
        ];
    }

    /**
     * Send a test dispatch to one or more email addresses.
     * Uses the Email channel regardless of the campaign's channel.
     */
    public function sendTestEmail(Campaign $campaign, array $emails, ?Contact $sampleContact = null): array
    {
        $preview = $this->render($campaign, $sampleContact);
        $sent    = [];
        $failed  = [];

        foreach ($emails as $email) {
            try {
                \Illuminate\Support\Facades\Mail::html(
                    '<p style="background:#fffbe6;padding:8px;border-radius:4px;font-family:sans-serif;font-size:13px;">⚠️ <strong>TEST EMAIL</strong> — this is a preview, not a live campaign.</p>' . $preview['body'],
                    function ($msg) use ($email, $preview, $campaign) {
                        $msg->to($email)
                            ->subject('[TEST] ' . $preview['subject'])
                            ->from(
                                $campaign->from_address ?? config('reachhub.email.from_address'),
                                $campaign->from_name    ?? config('reachhub.email.from_name')
                            );
                    }
                );
                $sent[] = $email;
            } catch (\Throwable $e) {
                $failed[$email] = $e->getMessage();
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    // ── Internal ─────────────────────────────────────────────────────────

    private function checkLinks(string $html): array
    {
        preg_match_all('/href=["\']([^"\']+)["\']/', $html, $matches);
        $broken = [];

        foreach (array_unique($matches[1] ?? []) as $url) {
            if (!filter_var($url, FILTER_VALIDATE_URL)) continue;
            if (str_starts_with($url, 'mailto:') || str_starts_with($url, 'tel:')) continue;

            try {
                $response = $this->http->head($url, ['allow_redirects' => true]);
                if ($response->getStatusCode() >= 400) {
                    $broken[] = $url;
                }
            } catch (\Throwable) {
                $broken[] = $url;
            }
        }

        return $broken;
    }

    private function findUnresolvedVars(string $content): array
    {
        preg_match_all('/\{\{[^}]+\}\}/', $content, $matches);
        return array_unique($matches[0] ?? []);
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

    private function fakeSampleContact(): Contact
    {
        $c = new Contact([
            'id'    => 0,
            'name'  => 'Sample User',
            'email' => 'sample@example.com',
            'phone' => '+919876543210',
        ]);
        $c->exists = false;
        return $c;
    }
}
