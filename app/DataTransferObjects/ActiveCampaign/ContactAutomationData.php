<?php

namespace App\DataTransferObjects\ActiveCampaign;

class ContactAutomationData
{
    public function __construct(
        public readonly int $contactAutomationId,
        public readonly int $automationId,
        public readonly ?string $name,
        public readonly ?string $status,
        public readonly ?string $addDate,
        public readonly ?string $lastDate,
        public readonly ?int $completedElements = null,
        public readonly ?int $totalElements = null,
        public readonly ?int $completeValue = null,
    ) {}

    /**
     * @return array{
     *     contact_automation_id: int,
     *     automation_id: int,
     *     name: string|null,
     *     status: string|null,
     *     add_date: string|null,
     *     last_date: string|null,
     *     completed_elements: int|null,
     *     total_elements: int|null,
     *     complete_value: int|null
     * }
     */
    public function toArray(): array
    {
        return [
            'contact_automation_id' => $this->contactAutomationId,
            'automation_id' => $this->automationId,
            'name' => $this->name,
            'status' => $this->status,
            'add_date' => $this->addDate,
            'last_date' => $this->lastDate,
            'completed_elements' => $this->completedElements,
            'total_elements' => $this->totalElements,
            'complete_value' => $this->completeValue,
        ];
    }
}
