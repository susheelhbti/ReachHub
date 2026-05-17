<?php

namespace ReachHub\Privacy;

use ReachHub\Models\Contact;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * GDPR & privacy compliance service.
 *
 * Features:
 * - AES-256-GCM field encryption for PII
 * - Right to erasure (anonymize in < 5s)
 * - Consent tracking with cryptographic proof
 * - Automated data expiry
 */
class PrivacyService
{
    private string $cipher = 'AES-256-GCM';

    // ── Encryption / Decryption ────────────────────────────────────────────

    /**
     * Encrypt a PII string. Returns base64-encoded ciphertext:iv:tag.
     * Uses a per-installation key from config, NOT per-contact.
     * For true zero-knowledge, rotate key and re-encrypt periodically.
     */
    public function encrypt(string $plaintext): string
    {
        $key = $this->derivedKey();
        $iv  = random_bytes(12); // 96-bit IV for GCM

        $tag        = '';
        $ciphertext = openssl_encrypt($plaintext, $this->cipher, $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);

        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed: ' . openssl_error_string());
        }

        return base64_encode($ciphertext . '::' . $iv . '::' . $tag);
    }

    public function decrypt(string $encoded): string
    {
        $raw  = base64_decode($encoded);
        [$ciphertext, $iv, $tag] = explode('::', $raw, 3);

        $plain = openssl_decrypt($ciphertext, $this->cipher, $this->derivedKey(), OPENSSL_RAW_DATA, $iv, $tag);

        if ($plain === false) {
            throw new \RuntimeException('Decryption failed.');
        }

        return $plain;
    }

    // ── Right to Erasure (GDPR Art. 17) ────────────────────────────────────

    /**
     * Anonymize all personal data for a contact while preserving aggregate analytics.
     * Completes in a single transaction — target < 5 seconds.
     */
    public function eraseContact(Contact $contact): void
    {
        DB::transaction(function () use ($contact) {
            // Preserve anonymized campaign stats before wiping
            $this->snapshotAnonymousStats($contact);

            // Wipe all PII
            $contact->update([
                'name'            => 'Deleted User #' . $contact->id,
                'email'           => null,
                'phone'           => null,
                'whatsapp'        => null,
                'fcm_token'       => null,
                'custom_fields'   => null,
                'tags'            => null,
                'subscribed'      => false,
                'unsubscribed_at' => now(),
            ]);

            // Remove from all lists
            $contact->lists()->detach();

            // Scrub recipient field in logs (keep status/timestamp for analytics)
            $contact->logs()->update(['recipient' => '[erased]']);

            // Delete consent records
            DB::table('ck_consents')->where('contact_id', $contact->id)->delete();

            Log::info("[ReachHub:Privacy] Contact #{$contact->id} erased (GDPR Art. 17).");
        });
    }

    /**
     * Auto-erase contacts inactive for more than $years years.
     * Run via scheduler: reachhub:gdpr-cleanup
     */
    public function autoExpireStale(int $years = 2): int
    {
        $erased = 0;

        Contact::where('subscribed', false)
            ->where('unsubscribed_at', '<=', now()->subYears($years))
            ->chunk(100, function ($contacts) use (&$erased) {
                foreach ($contacts as $contact) {
                    $this->eraseContact($contact);
                    $erased++;
                }
            });

        return $erased;
    }

    // ── Consent Management ─────────────────────────────────────────────────

    /**
     * Record explicit consent with full audit trail (IP, UA, URL, timestamp hash).
     */
    public function recordConsent(
        Contact $contact,
        string  $channel,
        string  $purpose = 'marketing', // marketing | transactional | analytics
        ?string $sourceUrl = null,
        ?string $sourceIp = null,
        ?string $userAgent = null,
    ): void {
        $payload = [
            'contact_id'  => $contact->id,
            'channel'     => $channel,
            'purpose'     => $purpose,
            'source_ip'   => $sourceIp,
            'user_agent'  => $userAgent,
            'source_url'  => $sourceUrl,
            'proof_hash'  => hash('sha256', json_encode([
                'contact_id' => $contact->id,
                'channel'    => $channel,
                'ip'         => $sourceIp,
                'timestamp'  => now()->toISOString(),
            ])),
            'expires_at'  => now()->addYears(2),
            'granted_at'  => now(),
        ];

        DB::table('ck_consents')->updateOrInsert(
            ['contact_id' => $contact->id, 'channel' => $channel, 'purpose' => $purpose],
            $payload
        );
    }

    /**
     * Check if a contact has valid, unexpired consent for a channel/purpose.
     */
    public function hasConsent(Contact $contact, string $channel, string $purpose = 'marketing'): bool
    {
        return DB::table('ck_consents')
            ->where('contact_id', $contact->id)
            ->where('channel', $channel)
            ->where('purpose', $purpose)
            ->where('expires_at', '>', now())
            ->exists();
    }

    /**
     * Withdraw consent — blocks future sends on that channel.
     */
    public function withdrawConsent(Contact $contact, string $channel, string $purpose = 'marketing'): void
    {
        DB::table('ck_consents')
            ->where('contact_id', $contact->id)
            ->where('channel', $channel)
            ->where('purpose', $purpose)
            ->delete();

        // If withdrawing all marketing consent, unsubscribe globally
        $remaining = DB::table('ck_consents')
            ->where('contact_id', $contact->id)
            ->where('purpose', 'marketing')
            ->count();

        if ($remaining === 0) {
            $contact->update(['subscribed' => false, 'unsubscribed_at' => now()]);
        }
    }

    /**
     * Export all data held on a contact (GDPR Art. 15 — Right of Access).
     */
    public function exportContactData(Contact $contact): array
    {
        return [
            'contact'  => $contact->only(['id', 'name', 'email', 'phone', 'tags', 'custom_fields', 'created_at']),
            'lists'    => $contact->lists()->pluck('name'),
            'consents' => DB::table('ck_consents')->where('contact_id', $contact->id)->get(),
            'logs'     => $contact->logs()->get(['campaign_id', 'channel', 'status', 'sent_at', 'opened_at', 'clicked_at']),
        ];
    }

    // ── Internal ─────────────────────────────────────────────────────────

    private function derivedKey(): string
    {
        $appKey = config('app.key');

        if (!$appKey) {
            throw new \RuntimeException('APP_KEY must be set for ReachHub encryption.');
        }

        // Derive a 256-bit key using HKDF
        return hash_hkdf('sha256', base64_decode(str_replace('base64:', '', $appKey)), 32, 'reachhub-pii');
    }

    private function snapshotAnonymousStats(Contact $contact): void
    {
        // Keep aggregate stats (open count, click count) without PII
        $stats = [
            'original_contact_id' => $contact->id,
            'total_campaigns'  => $contact->logs()->count(),
            'total_opens'      => $contact->logs()->whereNotNull('opened_at')->count(),
            'total_clicks'     => $contact->logs()->whereNotNull('clicked_at')->count(),
            'erased_at'        => now(),
        ];

        DB::table('ck_erased_stats')->insert($stats);
    }
}
