<?php

namespace App\Services\Marketing;

class MarketingCampaignQueryStringSanitizer
{
    /**
     * @var array<string, int>
     */
    private const ALLOWED = [
        'utm_source' => 120,
        'utm_medium' => 120,
        'utm_campaign' => 160,
        'utm_term' => 160,
        'utm_content' => 160,
        'gclid' => 255,
        'fbclid' => 255,
    ];

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, string>
     */
    public function sanitize(array $query): array
    {
        $clean = [];

        foreach (self::ALLOWED as $key => $maxLength) {
            if (! array_key_exists($key, $query)) {
                continue;
            }

            $value = $query[$key];

            if (! is_string($value) && ! is_numeric($value)) {
                continue;
            }

            $string = trim((string) $value);

            if ($string === '') {
                continue;
            }

            // Eliminar caracteres de control (incluye null bytes y saltos).
            $string = preg_replace('/[\x00-\x1F\x7F]/u', '', $string) ?? '';

            if ($string === '') {
                continue;
            }

            if (mb_strlen($string) > $maxLength) {
                $string = mb_substr($string, 0, $maxLength);
            }

            $clean[$key] = $string;
        }

        return $clean;
    }
}
