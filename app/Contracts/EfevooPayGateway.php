<?php

namespace App\Contracts;

use App\Models\Efevoo3dsSession;

interface EfevooPayGateway
{
    public function chargeCard(array $data): array;

    public function tokenizeCard(array $cardData, int $customerId): array;

    public function initiate3DS(array $cardData, int $customerId): array;

    public function complete3DS(Efevoo3dsSession $session, array $cardData): array;

    public function healthCheck(): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getTestCards(): array;
}
