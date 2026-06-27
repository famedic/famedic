<?php

namespace App\Services;

use App\Contracts\EfevooPayGateway;
use App\Services\EfevooPay\MockEfevooPayGateway;
use Illuminate\Support\Facades\Log;

class EfevooPayFactoryService
{
    public function __construct(
        protected EfevooPayGateway $gateway,
    ) {}

    public function createService(): EfevooPayGateway
    {
        if (! app()->environment('production')) {
            Log::info('Usando MockEfevooPayGateway (APP_ENV != production)', [
                'app_env' => app()->environment(),
            ]);
        }

        return $this->gateway;
    }

    public function healthCheck(): array
    {
        return $this->gateway->healthCheck();
    }

    public function tokenizeCard(array $cardData, $customerId): array
    {
        return $this->gateway->tokenizeCard($cardData, (int) $customerId);
    }

    public function chargeCard(array $chargeData): array
    {
        return $this->gateway->chargeCard($chargeData);
    }

    public function getTestCards(): array
    {
        return $this->gateway->getTestCards();
    }

    /**
     * @param  array<string, mixed>  $paymentData
     */
    public function processPayment(array $paymentData, int $customerId, int|string $efevooTokenId): array
    {
        if (method_exists($this->gateway, 'processPayment')) {
            return $this->gateway->processPayment($paymentData, $customerId, $efevooTokenId);
        }

        $token = \App\Models\EfevooToken::find($efevooTokenId);

        if (! $token) {
            return ['success' => false, 'message' => 'Token no encontrado'];
        }

        return $this->chargeCard([
            'card_token' => $token->card_token,
            'token_id' => $token->id,
            'amount' => $paymentData['amount'] ?? 0,
            'reference' => $paymentData['reference'] ?? null,
            'customer_id' => $customerId,
        ]);
    }
}
