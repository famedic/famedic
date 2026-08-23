<?php
// app/Jobs/ProcessEfevooPayment.php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\EfevooPayService;
use App\Support\EfevooPayLogSanitizer;
use Illuminate\Support\Facades\Log;

class ProcessEfevooPayment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [30, 60, 120];

    protected $paymentData;
    protected $operation; // 'tokenize', 'payment', 'token_payment'

    public function __construct(array $paymentData, string $operation = 'payment')
    {
        $this->paymentData = $paymentData;
        $this->operation = $operation;
    }

    public function handle(EfevooPayService $efevooService)
    {
        Log::info("Procesando pago Efevoo ({$this->operation})", $this->safeLogContext());

        try {
            switch ($this->operation) {
                case 'tokenize':
                    $result = $efevooService->tokenizeCard(
                        $this->paymentData['card_number'],
                        $this->paymentData['expiry'],
                        $this->paymentData['amount'] ?? 2.00
                    );
                    break;
                    
                case 'token_payment':
                    $result = $efevooService->tokenPayment(
                        $this->paymentData['card_token'],
                        $this->paymentData['amount'],
                        $this->paymentData['cav'],
                        $this->paymentData['msi'] ?? 0,
                        $this->paymentData['contrato'] ?? '',
                        $this->paymentData['fiid_comercio'] ?? '',
                        $this->paymentData['referencia'] ?? 'Famedic'
                    );
                    break;
                    
                case 'payment':
                default:
                    $result = $efevooService->simplePayment(
                        $this->paymentData['card_number'],
                        $this->paymentData['expiry'],
                        $this->paymentData['cvv'],
                        $this->paymentData['amount'],
                        $this->paymentData['cav'],
                        $this->paymentData['msi'] ?? 0,
                        $this->paymentData['contrato'] ?? '',
                        $this->paymentData['fiid_comercio'] ?? '',
                        $this->paymentData['referencia'] ?? 'Famedic'
                    );
                    break;
            }

            // Aquí puedes guardar el resultado en tu base de datos
            // o disparar eventos según el resultado
            
            Log::info("Pago Efevoo procesado ({$this->operation})", [
                'success' => $result['success'] ?? false,
                'transaction_id' => $result['transaction_id'] ?? ($result['data']['transaccion'] ?? null),
                ...$this->safeLogContext(),
            ]);

        } catch (\Exception $e) {
            Log::error("Error procesando pago Efevoo ({$this->operation})", [
                ...$this->safeLogContext(),
                ...EfevooPayLogSanitizer::exception($e),
            ]);
            
            $this->fail($e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function safeLogContext(): array
    {
        return EfevooPayLogSanitizer::context([
            'operation' => $this->operation,
            'user_id' => $this->paymentData['user_id'] ?? null,
            'customer_id' => $this->paymentData['customer_id'] ?? null,
            'cart_id' => $this->paymentData['cart_id'] ?? null,
            'purchase_id' => $this->paymentData['purchase_id'] ?? null,
            'payment_attempt_id' => $this->paymentData['payment_attempt_id'] ?? null,
            'reference' => $this->paymentData['referencia'] ?? ($this->paymentData['reference'] ?? null),
            'token_id' => $this->paymentData['token_id'] ?? null,
        ]);
    }
}
