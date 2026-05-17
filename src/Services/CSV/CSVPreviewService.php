<?php

namespace ReachHub\Services\CSV;

use Illuminate\Support\Str;

class CSVPreviewService
{
    private const KNOWN_MAPPINGS = [
        'name'      => ['name', 'full_name', 'fullname', 'contact_name', 'subscriber_name', 'first_name', 'firstname'],
        'email'     => ['email', 'e-mail', 'mail', 'email_address', 'emailaddress', 'subscriber_email'],
        'phone'     => ['phone', 'mobile', 'telephone', 'tel', 'phone_number', 'mobile_number', 'cell'],
        'whatsapp'  => ['whatsapp', 'wa', 'whatsapp_number'],
        'fcm_token' => ['fcm_token', 'device_token', 'push_token'],
        'tags'      => ['tags', 'labels', 'groups', 'interests'],
    ];

    /**
     * Return headers, sample rows, total count, and suggested column mapping.
     */
    public function preview(string $filePath, int $sampleRows = 5): array
    {
        $handle = fopen($filePath, 'r');

        if (!$handle) {
            throw new \RuntimeException("Cannot open file: {$filePath}");
        }

        $headers = fgetcsv($handle);

        if (!$headers) {
            fclose($handle);
            throw new \RuntimeException('CSV file appears to be empty or has no header row.');
        }

        $headers = array_map('trim', $headers);
        $samples = [];
        $total   = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $total++;
            if ($total <= $sampleRows) {
                $samples[] = array_combine($headers, array_pad($row, count($headers), null));
            }
        }

        fclose($handle);

        return [
            'headers'          => $headers,
            'samples'          => $samples,
            'total_rows'       => $total,
            'suggested_mapping'=> $this->suggestMapping($headers),
            'unmapped_columns' => $this->findUnmapped($headers),
        ];
    }

    /**
     * Validate a mapping array against actual headers.
     * Returns errors for required fields that are missing.
     */
    public function validateMapping(array $mapping, array $headers): array
    {
        $errors = [];

        if (empty($mapping['email']) && empty($mapping['phone'])) {
            $errors[] = 'Mapping must include at least "email" or "phone" column.';
        }

        foreach ($mapping as $field => $column) {
            if ($column && !in_array($column, $headers)) {
                $errors[] = "Column '{$column}' mapped to '{$field}' not found in CSV headers.";
            }
        }

        return $errors;
    }

    // ── Internal ──────────────────────────────────────────────────────────

    private function suggestMapping(array $headers): array
    {
        $mapping = [];
        $lowerHeaders = array_map('strtolower', $headers);

        foreach (self::KNOWN_MAPPINGS as $field => $candidates) {
            foreach ($candidates as $candidate) {
                $index = array_search($candidate, $lowerHeaders);
                if ($index !== false) {
                    $mapping[$field] = $headers[$index];
                    break;
                }
            }
        }

        return $mapping;
    }

    private function findUnmapped(array $headers): array
    {
        $allKnown = array_merge(...array_values(self::KNOWN_MAPPINGS));
        $lower    = array_map('strtolower', $headers);

        return array_values(array_filter($headers, function ($h, $i) use ($allKnown, $lower) {
            return !in_array($lower[$i], $allKnown);
        }, ARRAY_FILTER_USE_BOTH));
    }
}
