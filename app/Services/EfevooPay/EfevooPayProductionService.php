<?php

namespace App\Services\EfevooPay;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Services\EfevooPayService as BaseEfevooPayService;
use App\Support\EfevooPayLogSanitizer;

class EfevooPayProductionService
{
    protected BaseEfevooPayService $baseService;
    protected string $environment = 'production';
    protected ?string $baseUrl;

    public function __construct()
    {
        // Obtener la URL desde la configuración, priorizando la personalizada
        $this->baseUrl = config(
            'efevoo.force_production_url',
            config(
                'efevoopay.production.api_url',
                config('efevoo.api_url')
            )
        );

        // Inicializar el servicio base funcional
        $this->baseService = new BaseEfevooPayService();

        // Sobreescribir configuración de URL si es necesario
        if (!empty($this->baseUrl)) {
            config(['efevoo.api_url' => $this->baseUrl]);
            config(['efevoopay.api_url' => $this->baseUrl]);
        }

        Log::info('EfevooPayProductionService inicializado', [
            'environment' => $this->environment,
            'using_base_service' => true,
        ]);
    }

    /**
     * Tokenizar una tarjeta (producción) - Usa el servicio base
     */
    public function tokenizeCard(array $cardData, int $customerId): array
    {
        Log::info('EfevooPayProductionService::tokenizeCard', [
            'customer_id' => $customerId,
            'card_data_masked' => $this->maskSensitiveData($cardData),
            'environment' => $this->environment,
        ]);

        try {
            // Usar el método del servicio base
            return $this->baseService->fastTokenize($cardData, $customerId);

        } catch (\Exception $e) {
            Log::error('Error en EfevooPayProductionService::tokenizeCard', [
                'customer_id' => $customerId,
                ...EfevooPayLogSanitizer::exception($e),
            ]);

            return [
                'success' => false,
                'message' => 'Error en tokenización.',
            ];
        }
    }

    /**
     * Realizar un cargo con tarjeta (producción)
     */
    public function chargeCard(array $chargeData): array
    {
        Log::info('EfevooPayProductionService::chargeCard - En producción', [
            'charge_data_masked' => $this->maskSensitiveData($chargeData),
            'environment' => $this->environment,
        ]);

        try {
            // TODO: Implementar método chargeCard en el servicio base o crear uno nuevo

            // Por ahora, devolver error indicando que no está implementado
            return [
                'success' => false,
                'message' => 'Método chargeCard no implementado aún en producción',
                'code' => 'NOT_IMPLEMENTED',
            ];

        } catch (\Exception $e) {
            Log::error('Error en EfevooPayProductionService::chargeCard', [
                ...EfevooPayLogSanitizer::exception($e),
            ]);

            return [
                'success' => false,
                'message' => 'Error al procesar el cargo.',
            ];
        }
    }

    /**
     * Obtener token de cliente
     */
    public function getClientToken(bool $forceRefresh = false): array
    {
        return $this->baseService->getClientToken($forceRefresh);
    }

    /**
     * Health check
     */
    public function healthCheck(): array
    {
        return $this->baseService->healthCheck();
    }

    /**
     * Buscar transacciones
     */
    public function searchTransactions(array $filters = []): array
    {
        if (method_exists($this->baseService, 'searchTransactions')) {
            return $this->baseService->searchTransactions($filters);
        }

        return [
            'success' => false,
            'message' => 'Método searchTransactions no disponible',
        ];
    }

    /**
     * Obtener tarjetas de prueba (para compatibilidad con simulador)
     */
    public function getTestCards(): array
    {
        return [
            'message' => 'Servicio de producción - No se proporcionan tarjetas de prueba',
            'note' => 'Use tarjetas reales para pruebas en producción',
        ];
    }

    /**
     * Enmascarar datos sensibles
     */
    private function maskSensitiveData(array $data): array
    {
        $masked = $data;

        if (isset($masked['token_id'])) {
            $masked['token_id'] = substr($masked['token_id'], 0, 8) . '...';
        }

        unset(
            $masked['authorization'],
            $masked['card_number'],
            $masked['card_token'],
            $masked['cav'],
            $masked['client_token'],
            $masked['cvv'],
            $masked['token'],
            $masked['track2']
        );

        return $masked;
    }
}
