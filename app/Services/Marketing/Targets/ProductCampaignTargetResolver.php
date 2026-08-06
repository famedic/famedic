<?php

namespace App\Services\Marketing\Targets;

use App\Enums\MarketingCampaignTargetType;
use App\Models\LaboratoryTest;
use App\Models\MarketingCampaignLink;

class ProductCampaignTargetResolver implements MarketingCampaignTargetResolver
{
    public function supports(MarketingCampaignTargetType $type): bool
    {
        return $type === MarketingCampaignTargetType::Product;
    }

    public function resolve(MarketingCampaignLink $link, array $allowedQuery): MarketingCampaignTargetResolution
    {
        $payload = is_array($link->target_payload) ? $link->target_payload : [];
        $testId = $payload['laboratory_test_id'] ?? null;

        if (! is_numeric($testId)) {
            return MarketingCampaignTargetResolution::invalid();
        }

        $test = LaboratoryTest::query()->find((int) $testId);
        if ($test === null || $test->brand === null) {
            return MarketingCampaignTargetResolution::invalid();
        }

        $url = route('laboratory-tests.test', [
            'laboratory_test' => $test->id,
            ...$allowedQuery,
        ]);

        return MarketingCampaignTargetResolution::redirect($url);
    }
}
