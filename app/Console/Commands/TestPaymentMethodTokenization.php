<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\PaymentMethodService;
use Illuminate\Console\Command;

class TestPaymentMethodTokenization extends Command
{
    protected $signature = 'payment-methods:test 
                            {customer_id : ID del cliente}
                            {--list : Listar tarjetas existentes}';
    
    protected $description = 'Probar tokenización de tarjetas';
    
    public function handle(PaymentMethodService $paymentMethodService): void
    {
        $customer = Customer::find($this->argument('customer_id'));
        
        if (!$customer) {
            $this->error('Cliente no encontrado');
            return;
        }
        
        $this->info("Cliente: {$customer->id} - {$customer->user->email}");
        
        if ($this->option('list')) {
            $this->listCards($customer, $paymentMethodService);
            return;
        }
        
        // Probar tokenización
        $this->testTokenization($customer, $paymentMethodService);
    }
    
    private function listCards(Customer $customer, PaymentMethodService $service): void
    {
        $result = $service->listCustomerCards($customer, false);
        
        if (!$result['success']) {
            $this->error('Error: ' . $result['error']);
            return;
        }
        
        $this->info("Tarjetas encontradas: {$result['count']}");
        
        if ($result['count'] === 0) {
            return;
        }
        
        $this->table(
            ['ID', 'Últimos 4', 'Marca', 'Alias', 'Default', 'Activa', 'Verificada', 'Creada'],
            $result['cards']->map(function ($card) {
                return [
                    $card->id,
                    $card->last_four,
                    $card->brand_name,
                    $card->alias ?? 'N/A',
                    $card->is_default ? '✅' : '❌',
                    $card->is_active ? '✅' : '❌',
                    $card->is_verified ? '✅' : '❌',
                    $card->created_at->format('d/m/Y'),
                ];
            })->toArray()
        );
    }
    
    private function testTokenization(Customer $customer, PaymentMethodService $service): void
    {
        if (!$this->confirm('¿Deseas simular tokenización de una tarjeta de prueba?')) {
            return;
        }
        
        $cardData = [
            'number' => '4242424242424242', // Tarjeta de prueba
            'exp_month' => '12',
            'exp_year' => '2025',
            'cvc' => '123',
            'alias' => 'Tarjeta de Prueba',
            'type' => 'credit',
        ];
        
        $this->info('Simulando tokenización...');
        
        $result = $service->tokenizeCard($customer, $cardData, [
            'test' => true,
            'command' => true,
        ]);
        
        if ($result['success']) {
            $this->info('✅ Tokenización exitosa!');
            $this->info("Token: {$result['payment_method']->gateway_token}");
            $this->info("Últimos 4: {$result['payment_method']->last_four}");
            $this->info("Marca: {$result['payment_method']->brand_name}");
        } else {
            $this->error('❌ Error: ' . $result['error']);
        }
    }
}