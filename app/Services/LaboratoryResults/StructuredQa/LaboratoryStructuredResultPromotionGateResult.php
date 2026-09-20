<?php

namespace App\Services\LaboratoryResults\StructuredQa;

use App\Enums\LaboratoryStructuredResultPromotionStatus;

final class LaboratoryStructuredResultPromotionGateResult
{
    public const GATE_VERSION = 'promotion_gate_v1';

    /**
     * @param  list<string>  $reasonCodes
     * @param  list<string>  $reasonsHuman
     */
    public function __construct(
        public readonly LaboratoryStructuredResultPromotionStatus $status,
        public readonly array $reasonCodes,
        public readonly array $reasonsHuman,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toMetadataPayload(): array
    {
        return [
            'gate_version' => self::GATE_VERSION,
            'promotion_status' => $this->status->value,
            'reason_codes' => $this->reasonCodes,
            'reasons' => $this->reasonsHuman,
        ];
    }
}
