<?php

namespace App\DataTransferObjects\Marketing;

use App\Models\MarketingCampaignLink;

readonly class MarketingCampaignEffectiveTrackingData
{
    public function __construct(
        public ?string $utmSource,
        public ?string $utmMedium,
        public ?string $utmCampaign,
        public ?string $utmTerm,
        public ?string $utmContent,
        public ?string $gclid,
        public ?string $fbclid,
    ) {}

    /**
     * @param  array<string, string>  $query
     */
    public static function fromLinkAndQuery(
        MarketingCampaignLink $link,
        array $query,
        int $utmLimit = 255,
        int $clickIdLimit = 255,
    ): self {
        return new self(
            utmSource: self::resolveField($query, 'utm_source', $link->utm_source, $utmLimit),
            utmMedium: self::resolveField($query, 'utm_medium', $link->utm_medium, $utmLimit),
            utmCampaign: self::resolveField($query, 'utm_campaign', $link->utm_campaign, $utmLimit),
            utmTerm: self::resolveField($query, 'utm_term', $link->utm_term, $utmLimit),
            utmContent: self::resolveField($query, 'utm_content', $link->utm_content, $utmLimit),
            gclid: self::resolveField($query, 'gclid', null, $clickIdLimit),
            fbclid: self::resolveField($query, 'fbclid', null, $clickIdLimit),
        );
    }

    /**
     * @param  array<string, string>  $query
     */
    private static function resolveField(
        array $query,
        string $key,
        mixed $linkDefault,
        int $maxLength,
    ): ?string {
        if (array_key_exists($key, $query)) {
            $value = $query[$key];

            if (is_array($value) || is_object($value)) {
                return null;
            }

            return self::normalize((string) $value, $maxLength);
        }

        if ($linkDefault === null) {
            return null;
        }

        if (! is_string($linkDefault) && ! is_numeric($linkDefault)) {
            return null;
        }

        return self::normalize((string) $linkDefault, $maxLength);
    }

    private static function normalize(string $value, int $maxLength): ?string
    {
        $string = trim($value);

        if ($string === '') {
            return null;
        }

        $string = preg_replace('/[\x00-\x1F\x7F]/u', '', $string) ?? '';

        if ($string === '') {
            return null;
        }

        if (mb_strlen($string) > $maxLength) {
            $string = mb_substr($string, 0, $maxLength);
        }

        return $string;
    }

    /**
     * @return array<string, string|null>
     */
    public function toVisitColumns(): array
    {
        return [
            'utm_source' => $this->utmSource,
            'utm_medium' => $this->utmMedium,
            'utm_campaign' => $this->utmCampaign,
            'utm_term' => $this->utmTerm,
            'utm_content' => $this->utmContent,
            'gclid' => $this->gclid,
            'fbclid' => $this->fbclid,
        ];
    }
}
