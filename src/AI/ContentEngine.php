<?php

namespace ReachHub\AI;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * LLM-powered content engine.
 * Supports local Ollama (free, private) with optional OpenAI fallback.
 *
 * Config:
 *   reachhub.ai.driver  = 'ollama' | 'openai' | 'none'
 *   reachhub.ai.ollama.url    = 'http://localhost:11434'
 *   reachhub.ai.ollama.model  = 'mistral'
 *   reachhub.ai.openai.key    = env('OPENAI_API_KEY')
 *   reachhub.ai.openai.model  = 'gpt-3.5-turbo'
 */
class ContentEngine
{
    private Client $http;

    public function __construct()
    {
        $this->http = new Client(['timeout' => 60]);
    }

    // ── Subject Line Generation ────────────────────────────────────────────

    /**
     * Generate multiple subject line variants for A/B testing.
     * Returns array of strings.
     */
    public function generateSubjectLines(
        string $topic,
        string $tone = 'friendly',
        int $count = 5,
        string $audience = 'general'
    ): array {
        $prompt = <<<PROMPT
Generate exactly {$count} email subject lines for the following:
Topic: {$topic}
Tone: {$tone}
Target audience: {$audience}

Rules:
- Each subject line must be under 60 characters
- Mix curiosity, urgency, and personalization across variants
- Do not use spammy words (FREE!, Click here, URGENT)
- Return only the subject lines, one per line, no numbering or quotes
PROMPT;

        $raw = $this->ask($prompt);

        return collect(explode("\n", $raw))
            ->map(fn($l) => trim($l, '- "\''))
            ->filter(fn($l) => strlen($l) > 5 && strlen($l) < 80)
            ->values()
            ->take($count)
            ->toArray();
    }

    // ── Channel Adaptation ─────────────────────────────────────────────────

    /**
     * Rewrite email content optimized for a different channel.
     */
    public function adaptForChannel(string $content, string $fromChannel, string $toChannel): string
    {
        $rules = match ($toChannel) {
            'sms'       => 'Rewrite as a single SMS under 160 characters. Be concise and direct. Remove HTML.',
            'whatsapp'  => 'Rewrite for WhatsApp: casual tone, use line breaks, keep under 300 characters. Remove HTML. May use 1-2 relevant emojis.',
            'push'      => 'Rewrite as a push notification body: max 80 characters, create urgency or curiosity.',
            'email'     => 'Expand into a full email body with a clear CTA. Keep professional tone. Return HTML.',
            default     => 'Rewrite for ' . $toChannel . '. Be concise.',
        };

        $prompt = "You are a marketing copywriter. {$rules}\n\nOriginal content:\n{$content}\n\nRewritten version:";

        return trim($this->ask($prompt));
    }

    // ── Content Quality Analysis ───────────────────────────────────────────

    /**
     * Analyse content for spam risk, readability, and improvements.
     * Returns a structured analysis array.
     */
    public function analyseContent(string $subject, string $body, string $channel = 'email'): array
    {
        $bodyPreview = strip_tags(substr($body, 0, 800));

        $prompt = <<<PROMPT
You are an email deliverability and marketing expert. Analyse the following {$channel} campaign content and return a JSON object only (no markdown, no explanation):

Subject: {$subject}
Body preview: {$bodyPreview}

Return exactly this JSON structure:
{
  "spam_score": <float 0.0-1.0>,
  "readability_score": <float 0.0-1.0>,
  "sentiment": "<positive|neutral|negative>",
  "spam_triggers": [<list of problematic words/phrases>],
  "strengths": [<list of 2-3 things done well>],
  "improvements": [<list of 2-3 specific actionable suggestions>],
  "subject_length": <int>,
  "estimated_open_rate": "<percentage range e.g. 18-22%>"
}
PROMPT;

        try {
            $json = $this->ask($prompt);
            $clean = preg_replace('/```json|```/', '', $json);
            return json_decode(trim($clean), true) ?? $this->fallbackAnalysis($subject, $body);
        } catch (\Throwable) {
            return $this->fallbackAnalysis($subject, $body);
        }
    }

    // ── Smart Send-Time Prediction ─────────────────────────────────────────

    /**
     * Given a contact's historical engagement pattern, suggest optimal send time.
     * Uses LLM to interpret engagement data when ML models aren't available.
     */
    public function suggestSendTime(array $engagementData, string $timezone = 'UTC'): array
    {
        if (empty($engagementData)) {
            return [
                'day'  => 'Tuesday',
                'hour' => 10,
                'reason' => 'Industry default: Tuesday 10am has highest average open rates.',
            ];
        }

        $summary = json_encode($engagementData);
        $prompt = <<<PROMPT
A contact has the following email engagement history (day, hour, opens):
{$summary}

Based on this data, in timezone {$timezone}, what day of week and hour (0-23) should we send the next campaign for maximum open probability?

Return only JSON: {"day": "<Monday|Tuesday|...>", "hour": <0-23>, "reason": "<one sentence>"}
PROMPT;

        try {
            $json = $this->ask($prompt);
            return json_decode(trim(preg_replace('/```json|```/', '', $json)), true)
                ?? ['day' => 'Tuesday', 'hour' => 10, 'reason' => 'Default fallback.'];
        } catch (\Throwable) {
            return ['day' => 'Tuesday', 'hour' => 10, 'reason' => 'Default fallback.'];
        }
    }

    // ── Personalization ────────────────────────────────────────────────────

    /**
     * Generate a personalized intro paragraph for a contact.
     */
    public function personalizeOpening(string $name, array $contactTags, string $campaignTopic): string
    {
        $tags = implode(', ', $contactTags);
        $prompt = "Write a 1-sentence personalized email opening for {$name} who is interested in: {$tags}. The email is about: {$campaignTopic}. Keep it natural and warm, under 25 words.";

        return trim($this->ask($prompt));
    }

    // ── Core LLM Driver ───────────────────────────────────────────────────

    public function ask(string $prompt, bool $cache = true): string
    {
        $driver = config('reachhub.ai.driver', 'none');

        if ($driver === 'none') {
            return '';
        }

        $cacheKey = 'ck:ai:' . md5($driver . $prompt);

        if ($cache && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $result = match ($driver) {
            'ollama' => $this->askOllama($prompt),
            'openai' => $this->askOpenAI($prompt),
            default  => throw new \InvalidArgumentException("Unknown AI driver: {$driver}"),
        };

        if ($cache) {
            Cache::put($cacheKey, $result, now()->addHours(24));
        }

        return $result;
    }

    private function askOllama(string $prompt): string
    {
        $url   = rtrim(config('reachhub.ai.ollama.url', 'http://localhost:11434'), '/');
        $model = config('reachhub.ai.ollama.model', 'mistral');

        $response = $this->http->post("{$url}/api/generate", [
            'json' => [
                'model'  => $model,
                'prompt' => $prompt,
                'stream' => false,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        return trim($data['response'] ?? '');
    }

    private function askOpenAI(string $prompt): string
    {
        $apiKey = config('reachhub.ai.openai.key');
        $model  = config('reachhub.ai.openai.model', 'gpt-3.5-turbo');

        if (!$apiKey) {
            throw new \RuntimeException('OpenAI API key is not configured.');
        }

        $response = $this->http->post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type'  => 'application/json',
            ],
            'json' => [
                'model'       => $model,
                'messages'    => [['role' => 'user', 'content' => $prompt]],
                'temperature' => 0.7,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        return trim($data['choices'][0]['message']['content'] ?? '');
    }

    // ── Fallback Analysis (rule-based, no LLM) ────────────────────────────

    private function fallbackAnalysis(string $subject, string $body): array
    {
        $spamWords = ['free', 'urgent', 'click here', 'limited time', 'act now', 'guarantee', 'winner'];
        $bodyLower = strtolower($subject . ' ' . strip_tags($body));
        $found     = array_filter($spamWords, fn($w) => str_contains($bodyLower, $w));

        return [
            'spam_score'          => count($found) * 0.1,
            'readability_score'   => 0.7,
            'sentiment'           => 'neutral',
            'spam_triggers'       => array_values($found),
            'strengths'           => [],
            'improvements'        => count($found) > 0
                ? ['Remove spam trigger words: ' . implode(', ', $found)]
                : ['Add personalization using {{name}}'],
            'subject_length'      => strlen($subject),
            'estimated_open_rate' => '15-20%',
        ];
    }
}
