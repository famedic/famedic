<?php
// app/Services/EfevooPayFactoryService.php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class EfevooPayFactoryService
{
    protected $realService;
    protected $simulatorService;
    protected $forceSimulation = false;

    public function __construct(
        EfevooPayService $realService,
        EfevooPaySimulatorService $simulatorService
    ) {
        $this->realService = $realService;
        $this->simulatorService = $simulatorService;
        
        // Configurar si forzar simulación basado en entorno
        $this->forceSimulation = config('efevoopay.force_simulation', false) 
            || $this->simulatorService->isRealServiceAvailable() === false;
    }

    /**
     * Obtener el servicio activo
     */
    public function getService()
    {
        if ($this->forceSimulation) {
            Log::info('Usando servicio SIMULADO de EfevooPay');
            return $this->simulatorService;
        }
        
        // Verificar si el servicio real está disponible
        try {
            // Intentar una conexión rápida al servicio real
            $health = $this->realService->healthCheck();
            if ($health['status'] === 'online') {
                Log::info('Usando servicio REAL de EfevooPay');
                return $this->realService;
            }
        } catch (\Exception $e) {
            Log::warning('Servicio real no disponible, usando simulador', [
                'error' => $e->getMessage(),
            ]);
        }
        
        return $this->simulatorService;
    }

    /**
     * Forzar uso del simulador
     */
    public function forceSimulation(bool $force = true): self
    {
        $this->forceSimulation = $force;
        return $this;
    }

    /**
     * Métodos delegados
     */
    public function tokenizeCard(array $cardData, int $customerId): array
    {
        return $this->getService()->tokenizeCard($cardData, $customerId);
    }

    public function fastTokenize(array $cardData, int $customerId): array
    {
        return $this->getService()->fastTokenize($cardData, $customerId);
    }

    public function getClientToken(bool $forceRefresh = false): array
    {
        return $this->getService()->getClientToken($forceRefresh);
    }

    public function healthCheck(): array
    {
        return $this->getService()->healthCheck();
    }

    public function processPayment(array $paymentData, int $customerId, ?int $tokenId = null): array
    {
        $service = $this->getService();
        
        // Si el servicio es el simulador, usar su método
        if ($service instanceof EfevooPaySimulatorService) {
            return $service->processPayment($paymentData, $customerId, $tokenId);
        }
        
        // Para el servicio real, necesitarías implementar este método
        throw new \RuntimeException('Método processPayment no implementado en el servicio real');
    }

    public function processRefund(array $refundData, int $transactionId): array
    {
        $service = $this->getService();
        
        if ($service instanceof EfevooPaySimulatorService) {
            return $service->processRefund($refundData, $transactionId);
        }
        
        throw new \RuntimeException('Método processRefund no implementado en el servicio real');
    }

    public function searchTransactions(array $filters = []): array
    {
        return $this->getService()->searchTransactions($filters);
    }

    /**
     * Método específico del simulador para obtener tarjetas de prueba
     */
    public function getTestCards(): ?array
    {
        $service = $this->getService();
        
        if ($service instanceof EfevooPaySimulatorService) {
            return $service->getTestCards();
        }
        
        return null;
    }
}