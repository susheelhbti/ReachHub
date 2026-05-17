<?php

namespace ReachHub\Migration;

use ReachHub\Models\Contact;
use ReachHub\Models\ContactList;
use ReachHub\Models\Campaign;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use League\Csv\Reader;

class MigrationWizard
{
    private Client $http;

    public function __construct()
    {
        $this->http = new Client(['timeout' => 30]);
    }

    // ── Mailchimp ─────────────────────────────────────────────────────────

    public function fromMailchimp(string $apiKey, string $datacenter = 'us1'): MigrationReport
    {
        $base    = "https://{$datacenter}.api.mailchimp.com/3.0";
        $headers = ['Authorization' => 'apikey ' . $apiKey];

        $report = new MigrationReport('mailchimp');

        // Migrate audiences → contact lists
        $audiences = $this->mcGet("{$base}/lists?count=100", $headers)['lists'] ?? [];

        foreach ($audiences as $audience) {
            $list = ContactList::firstOrCreate(['name' => $audience['name']]);

            $offset = 0;
            do {
                $page    = $this->mcGet("{$base}/lists/{$audience['id']}/members?count=500&offset={$offset}", $headers);
                $members = $page['members'] ?? [];

                foreach ($members as $member) {
                    $mergeFields = $member['merge_fields'] ?? [];
                    $firstName   = $mergeFields['FNAME'] ?? '';
                    $lastName    = $mergeFields['LNAME'] ?? '';

                    $contact = Contact::firstOrCreate(
                        ['email' => $member['email_address']],
                        [
                            'name'       => trim("{$firstName} {$lastName}") ?: $member['email_address'],
                            'subscribed' => $member['status'] === 'subscribed',
                            'tags'       => array_column($member['tags'] ?? [], 'name'),
                        ]
                    );

                    $contact->lists()->syncWithoutDetaching([$list->id]);
                    $report->contacts++;
                }

                $offset += count($members);
            } while (count($members) === 500);

            $report->lists++;
        }

        // Migrate campaigns (content only, not sends)
        $campaigns = $this->mcGet("{$base}/campaigns?count=100&type=regular", $headers)['campaigns'] ?? [];

        foreach ($campaigns as $mc) {
            try {
                $content = $this->mcGet("{$base}/campaigns/{$mc['id']}/content", $headers);

                Campaign::firstOrCreate(
                    ['name' => $mc['settings']['title'] ?? $mc['settings']['subject_line']],
                    [
                        'channel' => 'email',
                        'subject' => $mc['settings']['subject_line'] ?? '',
                        'body'    => $content['html'] ?? $content['plain_text'] ?? '',
                        'status'  => 'draft',
                        'metadata' => ['imported_from' => 'mailchimp', 'original_id' => $mc['id']],
                    ]
                );

                $report->campaigns++;
            } catch (\Throwable $e) {
                $report->addError("Campaign {$mc['id']}: " . $e->getMessage());
            }
        }

        return $report;
    }

    // ── CSV Import ────────────────────────────────────────────────────────

    /**
     * Import contacts from a CSV file with flexible column mapping.
     *
     * $mapping example:
     *   ['name' => 'Full Name', 'email' => 'Email Address', 'phone' => 'Mobile']
     *
     * Any unmapped columns are stored in custom_fields.
     */
    public function fromCSV(string $path, array $mapping, ?int $listId = null): MigrationReport
    {
        $report = new MigrationReport('csv');

        // Support both league/csv and plain fgetcsv
        if (class_exists(\League\Csv\Reader::class)) {
            $csv = Reader::createFromPath($path)->setHeaderOffset(0);
            $rows = $csv->getRecords();
        } else {
            $rows = $this->parseCsvFallback($path);
        }

        foreach ($rows as $row) {
            $row = (array) $row;

            $name  = $this->mapField($row, $mapping, 'name');
            $email = $this->mapField($row, $mapping, 'email');
            $phone = $this->mapField($row, $mapping, 'phone');

            if (!$email && !$phone) {
                $report->addError("Row skipped — no email or phone: " . json_encode($row));
                $report->skipped++;
                continue;
            }

            // Collect unmapped columns as custom_fields
            $mappedCols  = array_values($mapping);
            $customFields = array_filter($row, fn($k) => !in_array($k, $mappedCols), ARRAY_FILTER_USE_KEY);

            $contact = Contact::firstOrCreate(
                array_filter(['email' => $email ?: null, 'phone' => $phone ?: null]),
                [
                    'name'          => $name ?: ($email ?: $phone),
                    'email'         => $email ?: null,
                    'phone'         => $phone ?: null,
                    'subscribed'    => true,
                    'custom_fields' => $customFields ?: null,
                ]
            );

            if ($listId) {
                $contact->lists()->syncWithoutDetaching([$listId]);
            }

            $report->contacts++;
        }

        return $report;
    }

    // ── Generic JSON import ───────────────────────────────────────────────

    /**
     * Import from a JSON array of contacts.
     * Fields: name, email, phone, whatsapp, fcm_token, tags[], custom_fields{}
     */
    public function fromJSON(array $contacts, ?int $listId = null): MigrationReport
    {
        $report = new MigrationReport('json');

        foreach ($contacts as $row) {
            if (empty($row['email']) && empty($row['phone'])) {
                $report->skipped++;
                continue;
            }

            $contact = Contact::firstOrCreate(
                array_filter(['email' => $row['email'] ?? null, 'phone' => $row['phone'] ?? null]),
                [
                    'name'          => $row['name']          ?? null,
                    'whatsapp'      => $row['whatsapp']       ?? null,
                    'fcm_token'     => $row['fcm_token']      ?? null,
                    'tags'          => $row['tags']           ?? null,
                    'custom_fields' => $row['custom_fields']  ?? null,
                    'subscribed'    => $row['subscribed']     ?? true,
                ]
            );

            if ($listId) {
                $contact->lists()->syncWithoutDetaching([$listId]);
            }

            $report->contacts++;
        }

        return $report;
    }

    // ── Internal ─────────────────────────────────────────────────────────

    private function mcGet(string $url, array $headers): array
    {
        $response = $this->http->get($url, ['headers' => $headers]);
        return json_decode($response->getBody()->getContents(), true) ?? [];
    }

    private function mapField(array $row, array $mapping, string $field): string
    {
        $column = $mapping[$field] ?? null;
        return $column ? ($row[$column] ?? '') : '';
    }

    private function parseCsvFallback(string $path): array
    {
        $rows    = [];
        $handle  = fopen($path, 'r');
        $headers = fgetcsv($handle);

        while ($row = fgetcsv($handle)) {
            $rows[] = array_combine($headers, $row);
        }

        fclose($handle);
        return $rows;
    }
}


// ─────────────────────────────────────────────────────────────────────────────

class MigrationReport
{
    public int   $contacts  = 0;
    public int   $lists     = 0;
    public int   $campaigns = 0;
    public int   $skipped   = 0;
    public array $errors    = [];

    public function __construct(public readonly string $source) {}

    public function addError(string $message): void
    {
        $this->errors[] = $message;
        Log::warning("[ReachHub:Migration] {$message}");
    }

    public function toArray(): array
    {
        return [
            'source'    => $this->source,
            'contacts'  => $this->contacts,
            'lists'     => $this->lists,
            'campaigns' => $this->campaigns,
            'skipped'   => $this->skipped,
            'errors'    => $this->errors,
        ];
    }
}
