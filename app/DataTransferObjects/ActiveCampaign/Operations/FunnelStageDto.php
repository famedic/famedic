<?php

namespace App\DataTransferObjects\ActiveCampaign\Operations;

class FunnelStageDto extends OperationsDto
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly int $count,
        public readonly ?float $conversionPercent,
        public readonly ?float $dropoffPercent,
    ) {}

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'count' => $this->count,
            'conversion_percent' => $this->conversionPercent,
            'dropoff_percent' => $this->dropoffPercent,
        ];
    }
}
