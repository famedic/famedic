<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class EfevooPayFactoryService
{
    protected $environment;
    protected $forceSimulation;
    
    public function __construct()
    {
        // Verifica qué prefijo de configuración existe
        $configPrefix = config('efevoopay') ? 'efevoopay' : 'efevoo';
        
        $this->environment = config($configPrefix . '.environment', 'test');
        $this->forceSimulation = config($configPrefix . '.force_simulation', false);
        
        Log::info('DEBUG - EfevooPayFactoryService initialized', [
            'environment' => $this->environment,
            'force_simulation' => $this->forceSimulation,
            'api_url' => config($configPrefix . '.api_url'),
        ]);
    }
    
    /**
     * Crea la instancia del servicio de pago - SIEMPRE devuelve EfevooPayService
     */
    public function createService()
    {
        // 1. Si force_simulation es true, usar simulador
        if ($this->forceSimulation === true) {
            Log::info('Usando servicio SIMULADO de EfevooPay', [
                'environment' => $this->environment,
                'force_simulation' => $this->forceSimulation
            ]);
            return new EfevooPaySimulatorService();
        }
        
        // 2. Para TODOS los demás casos, usar EfevooPayService (servicio original)
        Log::info('Usando EfevooPayService (servicio original funcional)', [
            'environment' => $this->environment,
            'force_simulation' => $this->forceSimulation,
            'api_url' => config('efevoo.api_url') ?? config('efevoopay.api_url'),
        ]);
        
        return new \App\Services\EfevooPayService();
    }
    
    /**
     * Método proxy para healthCheck
     */
    public function healthCheck()
    {
        return $this->createService()->healthCheck();
    }
    
    /**
     * Método proxy para tokenizeCard
     */
    public function tokenizeCard(array $cardData, $customerId)
    {
        $service = $this->createService();
        
        // Para EfevooPayService, usar fastTokenize si está disponible
        if ($service instanceof \App\Services\EfevooPayService && method_exists($service, 'fastTokenize')) {
            return $service->fastTokenize($cardData, $customerId);
        }
        
        return $service->tokenizeCard($cardData, $customerId);
    }
    
    /**
     * Método proxy para getTestCards
     */
    public function getTestCards()
    {
        $service = $this->createService();
        
        if (method_exists($service, 'getTestCards')) {
            return $service->getTestCards();
        }
        
        return [];
    }
    
    /**
     * Método proxy para forceSimulation
     */
    public function forceSimulation(bool $force = true)
    {
        $this->forceSimulation = $force;
        
        // Actualizar configuración
        config(['efevoo.force_simulation' => $force]);
        config(['efevoopay.force_simulation' => $force]);
        
        Log::info('Simulación forzada ' . ($force ? 'activada' : 'desactivada'));
        
        return $this;
    }
}