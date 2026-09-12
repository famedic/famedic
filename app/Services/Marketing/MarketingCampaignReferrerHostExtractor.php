<?php

namespace App\Services\Marketing;

class MarketingCampaignReferrerHostExtractor
{
    public function extract(?string $referer, int $maxLength = 255): ?string
    {
        if ($referer === null) {
            return null;
        }

        $referer = trim($referer);

        if ($referer === '') {
            return null;
        }

        $parts = parse_url($referer);

        if (! is_array($parts) || empty($parts['host'])) {
            return null;
        }

        $host = strtolower((string) $parts['host']);
        $host = preg_replace('/[\x00-\x1F\x7F]/u', '', $host) ?? '';

        if ($host === '') {
            return null;
        }

        if (mb_strlen($host) > $maxLength) {
            $host = mb_substr($host, 0, $maxLength);
        }

        return $host;
    }
}
