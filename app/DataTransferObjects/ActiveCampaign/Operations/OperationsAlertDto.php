<?php

namespace App\DataTransferObjects\ActiveCampaign\Operations;

class OperationsAlertDto extends OperationsDto
{
    public function __construct(
        public readonly string $key,
        public readonly string $priority, // info|warning|critical
        public readonly string $title,
        public readonly string $message,
        public readonly ?string $at = null,
    ) {}

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'priority' => $this->priority,
            'title' => $this->title,
            'message' => $this->message,
            'at' => $this->at,
        ];
    }
}
