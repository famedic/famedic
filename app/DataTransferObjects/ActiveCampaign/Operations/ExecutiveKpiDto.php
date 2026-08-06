<?php

namespace App\DataTransferObjects\ActiveCampaign\Operations;

class ExecutiveKpiDto extends OperationsDto
{
    /**
     * @param  list<int|float>  $sparkline
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly int|float|string $value,
        public readonly int|float|string|null $previousValue,
        public readonly ?float $growthPercent,
        public readonly string $trend, // up|down|flat|unknown
        public readonly array $sparkline = [],
        public readonly string $tone = 'default',
        public readonly ?string $hint = null,
    ) {}

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'value' => $this->value,
            'previous_value' => $this->previousValue,
            'growth_percent' => $this->growthPercent,
            'trend' => $this->trend,
            'sparkline' => $this->sparkline,
            'tone' => $this->tone,
            'hint' => $this->hint,
        ];
    }
}
