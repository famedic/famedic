<?php

namespace App\Services\Marketing;

use App\Models\MarketingCampaignLink;
use Illuminate\Support\Facades\URL;

class MarketingCampaignLinkUrlBuilder
{
    private const UTM_FIELDS = [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
    ];

    /**
     * @return array{utm_source?: string, utm_medium?: string, utm_campaign?: string, utm_term?: string, utm_content?: string}
     */
    public function utmParameters(MarketingCampaignLink $link): array
    {
        $parameters = [];

        foreach (self::UTM_FIELDS as $field) {
            $value = $link->{$field};

            if ($value === null || $value === '') {
                continue;
            }

            $parameters[$field] = (string) $value;
        }

        return $parameters;
    }

    public function baseUrl(MarketingCampaignLink $link): string
    {
        return route('campaign-links.show', ['slug' => $link->slug]);
    }

    public function fullUrl(MarketingCampaignLink $link): string
    {
        $baseUrl = $this->baseUrl($link);
        $parameters = $this->utmParameters($link);

        if ($parameters === []) {
            return $baseUrl;
        }

        return URL::query($baseUrl, $parameters);
    }
}
