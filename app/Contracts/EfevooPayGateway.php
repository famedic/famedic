<?php

namespace App\Contracts;

use App\Models\Efevoo3dsSession;

interface EfevooPayGateway
{
    public function chargeCard(array $data): array;

    public function tokenizeCard(array $cardData, int $customerId): array;

    public function initiate3DS(array $cardData, int $customerId): array;

    public function complete3DS(Efevoo3dsSession $session, array $cardData): array;

    /**
     * Consulta payments3DS_GetStatus sin tokenizar.
     *
     * @return array{phase: string, success?: bool, message?: string, error_type?: string|null, raw?: mixed}
     */
    public function poll3DSAuthentication(Efevoo3dsSession $session, array $cardData): array;

    /**
     * Tokeniza tras autenticación 3DS (sin CVV).
     *
     * @return array{success: bool, message?: string, error_type?: string|null, raw?: mixed}
     */
    public function finalize3DSTokenization(Efevoo3dsSession $session, array $cardData): array;

    public function healthCheck(): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getTestCards(): array;
}
