<?php

namespace App\DataTransferObjects\ActiveCampaign;

class ContactOwnerData
{
    public function __construct(
        public readonly int $id,
        public readonly ?string $name,
        public readonly ?string $email,
    ) {}

    /**
     * @return array{id: int, name: string|null, email: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
        ];
    }
}
