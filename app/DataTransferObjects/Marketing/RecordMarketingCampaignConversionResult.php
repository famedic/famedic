<?php

namespace App\DataTransferObjects\Marketing;

use App\Enums\MarketingCampaignConversionStatus;
use App\Models\MarketingCampaignConversion;

readonly class RecordMarketingCampaignConversionResult
{
    public function __construct(
        public MarketingCampaignConversionStatus $status,
        public ?MarketingCampaignConversion $conversion = null,
    ) {}

    public function created(): bool
    {
        return $this->status === MarketingCampaignConversionStatus::Created;
    }
}
