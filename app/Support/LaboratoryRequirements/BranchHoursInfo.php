<?php

namespace App\Support\LaboratoryRequirements;

class BranchHoursInfo
{
    public function __construct(
        public readonly ?bool $isOpenNow,
        public readonly ?bool $opensOnRequestedDate,
        public readonly ?string $hoursSummary,
        public readonly string $appointmentAvailability = 'unknown',
    ) {}

    public function toArray(): array
    {
        return [
            'is_open_now' => $this->isOpenNow,
            'opens_on_requested_date' => $this->opensOnRequestedDate,
            'hours_summary' => $this->hoursSummary,
            'appointment_availability' => $this->appointmentAvailability,
        ];
    }
}
