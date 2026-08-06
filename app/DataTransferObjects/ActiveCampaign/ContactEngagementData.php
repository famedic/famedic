<?php

namespace App\DataTransferObjects\ActiveCampaign;

class ContactEngagementData
{
    public const UNAVAILABLE = 'No disponible';

    public function __construct(
        public readonly int|string|null $emailsSent = self::UNAVAILABLE,
        public readonly ?string $lastOpen = self::UNAVAILABLE,
        public readonly ?string $lastClick = self::UNAVAILABLE,
        public readonly int|string|null $openRate = self::UNAVAILABLE,
        public readonly int|string|null $clickRate = self::UNAVAILABLE,
        public readonly ?string $lastCampaign = self::UNAVAILABLE,
    ) {}

    /**
     * @return array{
     *     emails_sent: int|string|null,
     *     last_open: string|null,
     *     last_click: string|null,
     *     open_rate: int|string|null,
     *     click_rate: int|string|null,
     *     last_campaign: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'emails_sent' => $this->emailsSent ?? self::UNAVAILABLE,
            'last_open' => $this->lastOpen ?? self::UNAVAILABLE,
            'last_click' => $this->lastClick ?? self::UNAVAILABLE,
            'open_rate' => $this->openRate ?? self::UNAVAILABLE,
            'click_rate' => $this->clickRate ?? self::UNAVAILABLE,
            'last_campaign' => $this->lastCampaign ?? self::UNAVAILABLE,
        ];
    }

    public static function unavailable(): self
    {
        return new self;
    }
}
