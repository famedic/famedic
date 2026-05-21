<?php

namespace App\Services\EfevooPay;

use App\Contracts\EfevooPayGateway;
use App\Models\Efevoo3dsSession;
use App\Models\EfevooToken;
use App\Support\MockEfevooPaymentSupport;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MockEfevooPayGateway implements EfevooPayGateway
{
    public const MOCK_3DS_ORDER_PREFIX = 'MOCK3DS-';

    public function healthCheck(): array
    {
        Log::info('Mock payment executed', ['operation' => 'healthCheck', 'app_env' => app()->environment()]);

        return [
            'success' => true,
            'message' => 'Mock EfevooPay gateway activo',
            'simulated' => true,
            'environment' => MockEfevooPaymentSupport::efevooTokenEnvironment(),
        ];
    }

    public function chargeCard(array $data): array
    {
        Log::info('Mock payment executed', [
            'operation' => 'chargeCard',
            'app_env' => app()->environment(),
            'reference' => $data['reference'] ?? null,
            'amount' => $data['amount'] ?? null,
        ]);

        $token = $this->resolveTokenFromChargeData($data);

        if (! $token) {
            return [
                'success' => false,
                'message' => 'Token de tarjeta inválido (simulación)',
                'simulated' => true,
            ];
        }

        $scenario = $token->metadata['scenario'] ?? $this->detectScenarioFromPan($token->card_last_four);

        if ($scenario === 'decline') {
            return [
                'success' => false,
                'message' => 'Tarjeta rechazada (simulación)',
                'error_type' => 'bank',
                'simulated' => true,
                'raw' => [
                    'data' => [
                        'codigo' => '05',
                        'descripcion' => 'Tarjeta rechazada (simulación)',
                    ],
                ],
            ];
        }

        $transactionId = 'TEST-'.strtoupper(Str::random(8));

        return [
            'success' => true,
            'transaction_id' => $transactionId,
            'efevoo_transaction_id' => $transactionId,
            'authorization_code' => 'MOCK-AUTH-'.strtoupper(Str::random(6)),
            'message' => 'Pago aprobado en ambiente '.app()->environment(),
            'simulated' => true,
            'raw' => [
                'data' => [
                    'id' => $transactionId,
                    'codigo' => '00',
                    'descripcion' => 'Aprobado (mock)',
                    'numref' => 'MOCK-REF',
                    'commission' => 0,
                ],
            ],
        ];
    }

    public function tokenizeCard(array $cardData, int $customerId): array
    {
        Log::info('Mock payment executed', [
            'operation' => 'tokenizeCard',
            'customer_id' => $customerId,
            'card_last4' => isset($cardData['card_number']) ? substr(preg_replace('/\D/', '', $cardData['card_number']), -4) : null,
        ]);

        $cardData = $this->normalizeCardInput($cardData);

        if (empty($cardData['card_number']) || empty($cardData['expiration'])) {
            return [
                'success' => false,
                'message' => 'Datos de tarjeta incompletos',
                'simulated' => true,
            ];
        }

        $pan = $cardData['card_number'];
        $lastFour = substr($pan, -4);
        $scenario = $this->detectScenarioFromPan($pan);

        if ($scenario === 'decline') {
            return [
                'success' => false,
                'message' => 'Tarjeta rechazada (simulación)',
                'simulated' => true,
            ];
        }

        $existing = EfevooToken::query()
            ->where('customer_id', $customerId)
            ->where('card_last_four', $lastFour)
            ->where('card_expiration', $cardData['expiration'])
            ->where('is_active', true)
            ->first();

        if ($existing) {
            $existing->update([
                'alias' => $cardData['alias'] ?? $existing->alias,
                'card_holder' => $cardData['card_holder'] ?? $existing->card_holder,
            ]);

            return [
                'success' => true,
                'token_id' => $existing->id,
                'reused' => true,
                'simulated' => true,
            ];
        }

        $token = EfevooToken::create([
            'customer_id' => $customerId,
            'card_token' => self::mockCardTokenForPan($pan),
            'client_token' => 'mock_clt_'.Str::random(12),
            'card_last_four' => $lastFour,
            'card_expiration' => $cardData['expiration'],
            'card_holder' => $cardData['card_holder'] ?? 'Titular Mock',
            'alias' => $cardData['alias'] ?? null,
            'card_brand' => $this->detectCardBrand($pan),
            'environment' => MockEfevooPaymentSupport::efevooTokenEnvironment(),
            'is_active' => true,
            'metadata' => [
                'mock' => true,
                'scenario' => $scenario,
                'app_env' => app()->environment(),
            ],
        ]);

        return [
            'success' => true,
            'token_id' => $token->id,
            'simulated' => true,
            'message' => 'Tarjeta tokenizada (simulación)',
        ];
    }

    public function initiate3DS(array $cardData, int $customerId): array
    {
        Log::info('Mock payment executed', [
            'operation' => 'initiate3DS',
            'customer_id' => $customerId,
        ]);

        $cardData = $this->normalizeCardInput($cardData);
        $pan = $cardData['card_number'] ?? '';

        $session = Efevoo3dsSession::create([
            'customer_id' => $customerId,
            'order_id' => self::MOCK_3DS_ORDER_PREFIX.time(),
            'card_last_four' => strlen($pan) >= 4 ? substr($pan, -4) : '0000',
            'amount' => $cardData['amount'] ?? 1.5,
            'status' => 'mock_pending',
            'url_3dsecure' => null,
            'token_3dsecure' => 'mock_3ds_'.Str::random(8),
        ]);

        $session->update([
            'url_3dsecure' => route('payment-methods.3ds-mock', ['sessionId' => $session->id]),
        ]);

        return [
            'success' => true,
            'session_id' => $session->id,
            'url_3dsecure' => $session->url_3dsecure,
            'token_3dsecure' => $session->token_3dsecure,
            'simulated' => true,
        ];
    }

    public function complete3DS(Efevoo3dsSession $session, array $cardData): array
    {
        Log::info('Mock payment executed', [
            'operation' => 'complete3DS',
            'session_id' => $session->id,
            'status' => $session->status,
        ]);

        if (in_array($session->status, ['completed', 'declined', 'tokenization_failed'], true)) {
            return [
                'success' => $session->status === 'completed',
                'message' => $session->status === 'completed'
                    ? '3DS completado (simulación)'
                    : ($session->error_message ?? 'Proceso finalizado'),
                'simulated' => true,
            ];
        }

        $cardData = $this->normalizeCardInput($cardData);
        $pan = $cardData['card_number'] ?? '';
        $scenario = $this->detectScenarioFromPan($pan);

        if ($scenario === 'decline') {
            $session->update([
                'status' => 'declined',
                'error_message' => 'Tarjeta rechazada (simulación)',
                'status_checked_at' => now(),
            ]);

            return [
                'success' => false,
                'message' => 'Tarjeta rechazada (simulación)',
                'error_type' => 'bank',
                'simulated' => true,
            ];
        }

        $tokenResult = $this->tokenizeCard($cardData, $session->customer_id);

        if (! $tokenResult['success']) {
            $session->update([
                'status' => 'tokenization_failed',
                'error_message' => $tokenResult['message'] ?? 'Error tokenizando',
            ]);

            return array_merge($tokenResult, ['simulated' => true]);
        }

        $session->update([
            'status' => 'completed',
            'efevoo_token_id' => $tokenResult['token_id'],
            'completed_at' => now(),
            'status_checked_at' => now(),
        ]);

        return [
            'success' => true,
            'message' => '3DS completado (simulación)',
            'simulated' => true,
        ];
    }

    public function getTestCards(): array
    {
        return self::testCardDefinitions();
    }

    /**
     * Compatibilidad con flujos legacy (factory / jobs).
     *
     * @param  array<string, mixed>  $paymentData
     */
    public function processPayment(array $paymentData, int $customerId, int|string $efevooTokenId): array
    {
        $token = EfevooToken::query()
            ->where('id', $efevooTokenId)
            ->where('customer_id', $customerId)
            ->first();

        if (! $token) {
            return [
                'success' => false,
                'message' => 'Token no encontrado (simulación)',
                'simulated' => true,
            ];
        }

        return $this->chargeCard([
            'card_token' => $token->card_token,
            'token_id' => $token->id,
            'amount' => $paymentData['amount'] ?? 0,
            'reference' => $paymentData['reference'] ?? null,
            'customer_id' => $customerId,
        ]);
    }

    public function refundTransaction(int $transactionId): array
    {
        Log::info('Mock payment executed', [
            'operation' => 'refundTransaction',
            'transaction_id' => $transactionId,
        ]);

        return [
            'success' => true,
            'message' => 'Reembolso simulado',
            'simulated' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function searchTransactions(array $filters = []): array
    {
        Log::info('Mock payment executed', ['operation' => 'searchTransactions', 'filters' => $filters]);

        return [
            'success' => true,
            'data' => [],
            'simulated' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function tokenPayment(array $data): array
    {
        return $this->chargeCard($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function simplePayment(array $data): array
    {
        return $this->chargeCard($data);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function testCardDefinitions(): array
    {
        return [
            [
                'number' => '4242424242424242',
                'cvv' => '123',
                'exp_month' => '12',
                'exp_year' => '29',
                'brand' => 'visa',
                'card_holder' => 'APROBADA MOCK',
                'alias' => 'visa-mock-aprobada',
                'name' => 'Visa — siempre aprueba',
                'description' => 'Pago exitoso en ambiente de pruebas',
                'scenario' => 'success',
                'default' => true,
            ],
            [
                'number' => '5555555555554444',
                'cvv' => '123',
                'exp_month' => '12',
                'exp_year' => '29',
                'brand' => 'mastercard',
                'card_holder' => 'MC MOCK OK',
                'alias' => 'mc-mock-aprobada',
                'name' => 'Mastercard — siempre aprueba',
                'description' => 'Pago exitoso en ambiente de pruebas',
                'scenario' => 'success',
            ],
            [
                'number' => '4000000000000002',
                'cvv' => '123',
                'exp_month' => '12',
                'exp_year' => '29',
                'brand' => 'visa',
                'card_holder' => 'RECHAZADA MOCK',
                'alias' => 'visa-mock-rechazada',
                'name' => 'Visa — siempre rechaza',
                'description' => 'Simula tarjeta declinada',
                'scenario' => 'decline',
            ],
        ];
    }

    public static function mockCardTokenForPan(string $pan): string
    {
        $clean = preg_replace('/\D/', '', $pan);

        return 'mock_tok_'.substr(hash('sha256', $clean), 0, 24);
    }

    private function resolveTokenFromChargeData(array $data): ?EfevooToken
    {
        if (! empty($data['card_token'])) {
            return EfevooToken::query()->where('card_token', $data['card_token'])->first();
        }

        if (! empty($data['token_id'])) {
            return EfevooToken::find($data['token_id']);
        }

        return null;
    }

    private function detectScenarioFromPan(string $panOrLastFour): string
    {
        $digits = preg_replace('/\D/', '', $panOrLastFour);

        if (str_ends_with($digits, '0002')) {
            return 'decline';
        }

        return 'success';
    }

    private function detectCardBrand(string $cardNumber): string
    {
        $clean = preg_replace('/\D/', '', $cardNumber);

        if (preg_match('/^4/', $clean)) {
            return 'visa';
        }
        if (preg_match('/^5[1-5]/', $clean) || preg_match('/^2[2-7]/', $clean)) {
            return 'mastercard';
        }
        if (preg_match('/^3[47]/', $clean)) {
            return 'amex';
        }

        return 'unknown';
    }

    /**
     * @param  array<string, mixed>  $cardData
     * @return array<string, mixed>
     */
    private function normalizeCardInput(array $cardData): array
    {
        $pan = preg_replace('/\D/', '', (string) ($cardData['card_number'] ?? ''));
        $expiration = preg_replace('/\D/', '', (string) ($cardData['expiration'] ?? ''));

        if ($expiration === '' && ! empty($cardData['exp_month']) && ! empty($cardData['exp_year'])) {
            $month = str_pad((string) $cardData['exp_month'], 2, '0', STR_PAD_LEFT);
            $year = substr((string) $cardData['exp_year'], -2);
            $expiration = $month.$year;
        }

        return array_merge($cardData, [
            'card_number' => $pan,
            'expiration' => $expiration,
        ]);
    }
}
