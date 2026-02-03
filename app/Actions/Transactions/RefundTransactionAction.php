<?php

namespace App\Actions\Transactions;

use App\Models\Transaction;
use App\Models\Customer;
use App\Notifications\OdessaPaymentRefunded;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Log;

class RefundTransactionAction
{
    public function __invoke(Transaction $transaction)
    {
        try {
            // Usar el nuevo método refund() que maneja todos los gateways
            $result = $this->refund($transaction);
            
            if ($result) {
                $transaction->delete();
            }
            
            return $result;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Método antiguo para reembolsos Stripe (mantener por compatibilidad)
     * Se usa desde __invoke() original
     */
    private function refundStripeTransactionOld(Transaction $transaction)
    {
        $customer = $this->getCustomerFromTransaction($transaction);
        $customer->refund($transaction->reference_id);
    }

    /**
     * Método antiguo para reembolsos Odessa (mantener por compatibilidad)
     */
    private function refundOdessaTransactionOld(Transaction $transaction)
    {
        if (config('services.odessa.refund_report_emails')) {
            $customer = $this->getCustomerFromTransaction($transaction);

            // Ensure we have an OdessaAfiliateAccount
            if (!$customer->customerable instanceof \App\Models\OdessaAfiliateAccount) {
                throw new \Exception('Transaction is marked as Odessa but customer does not have OdessaAfiliateAccount');
            }

            Notification::route('mail', config('services.odessa.refund_report_emails'))
                ->notify(
                    new OdessaPaymentRefunded(
                        $transaction->reference_id,
                        $transaction->formatted_amount,
                        $customer->customerable
                    )
                );
        }
    }

    /**
     * Método principal para reembolsos - maneja todos los gateways
     */
    public function refund(Transaction $transaction): bool
    {
        try {
            Log::info('RefundTransactionAction::refund - Iniciando', [
                'transaction_id' => $transaction->id,
                'payment_method' => $transaction->payment_method,
                'gateway' => $transaction->gateway,
                'gateway_transaction_id' => $transaction->gateway_transaction_id,
            ]);

            // Determinar el gateway REAL basado en múltiples campos
            $gateway = $this->determineGateway($transaction);

            Log::info('Gateway determinado', [
                'transaction_id' => $transaction->id,
                'gateway_determined' => $gateway,
                'payment_method_field' => $transaction->payment_method,
                'gateway_field' => $transaction->gateway,
            ]);

            // Verificar si ya fue reembolsada
            if ($transaction->refunded_at) {
                Log::warning('Transacción ya reembolsada', [
                    'transaction_id' => $transaction->id,
                    'refunded_at' => $transaction->refunded_at,
                ]);
                return false;
            }

            // Obtener el cliente
            $customer = $this->getCustomerFromTransaction($transaction);

            // Ejecutar reembolso según el gateway
            switch ($gateway) {
                case 'efevoopay':
                    return $this->refundEfevooPayTransaction($transaction, $customer);

                case 'stripe':
                    return $this->refundStripeTransactionNew($transaction, $customer);

                case 'odessa':
                    return $this->refundOdessaTransactionNew($transaction, $customer);

                default:
                    Log::error('Gateway no soportado para reembolso', [
                        'transaction_id' => $transaction->id,
                        'gateway' => $gateway,
                        'transaction_data' => [
                            'payment_method' => $transaction->payment_method,
                            'gateway' => $transaction->gateway,
                            'gateway_transaction_id' => $transaction->gateway_transaction_id,
                        ],
                    ]);
                    return false;
            }

        } catch (\Exception $e) {
            Log::error('Error en RefundTransactionAction::refund', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * Determinar el gateway real basado en múltiples campos
     */
    private function determineGateway(Transaction $transaction): string
    {
        // Prioridad 1: Verificar gateway_transaction_id
        if ($transaction->gateway_transaction_id) {
            if (str_starts_with($transaction->gateway_transaction_id, 'sim_')) {
                return 'efevoopay';
            }
            if (
                str_starts_with($transaction->gateway_transaction_id, 'pi_') ||
                str_starts_with($transaction->gateway_transaction_id, 'ch_')
            ) {
                return 'stripe';
            }
        }

        // Prioridad 2: Verificar campo gateway
        if ($transaction->gateway) {
            $gateway = strtolower($transaction->gateway);
            if (in_array($gateway, ['efevoopay', 'stripe', 'odessa'])) {
                return $gateway;
            }
        }

        // Prioridad 3: Verificar campo payment_method
        if ($transaction->payment_method) {
            $method = strtolower($transaction->payment_method);
            if (in_array($method, ['efevoopay', 'stripe', 'odessa'])) {
                return $method;
            }
        }

        // Prioridad 4: Verificar detalles
        $details = $transaction->details ?? [];
        if (is_string($details)) {
            $details = json_decode($details, true) ?? [];
        }

        if (isset($details['simulated']) && $details['simulated'] === true) {
            return 'efevoopay';
        }

        // Default: asumir stripe para compatibilidad
        return 'stripe';
    }

    /**
     * Reembolso para transacciones EfevooPay
     */
    private function refundEfevooPayTransaction(Transaction $transaction, Customer $customer): bool
    {
        try {
            Log::info('RefundTransactionAction::refundEfevooPayTransaction', [
                'transaction_id' => $transaction->id,
                'gateway_transaction_id' => $transaction->gateway_transaction_id,
                'customer_id' => $customer->id,
                'amount_cents' => $transaction->transaction_amount_cents,
            ]);

            // Si es una transacción simulada, solo marcar como reembolsada
            $details = $transaction->details ?? [];
            if (is_string($details)) {
                $details = json_decode($details, true) ?? [];
            }

            if ($details['simulated'] ?? false) {
                Log::info('Reembolso simulado para transacción EfevooPay', [
                    'transaction_id' => $transaction->id,
                    'simulated' => true,
                ]);

                $transaction->update([
                    'refunded_at' => now(),
                    'gateway_status' => 'refunded',
                    'gateway_response' => json_encode(array_merge(
                        json_decode($transaction->gateway_response ?? '{}', true) ?? [],
                        [
                            'refunded_at' => now()->toISOString(),
                            'refund_simulated' => true,
                            'refund_note' => 'Reembolso simulado - Error GDA',
                        ]
                    )),
                ]);

                return true;
            }

            // TODO: Implementar reembolso real con EfevooPay API
            // Por ahora, marcar como reembolsado
            $transaction->update([
                'refunded_at' => now(),
                'gateway_status' => 'refunded',
            ]);

            Log::info('Reembolso EfevooPay marcado como completado', [
                'transaction_id' => $transaction->id,
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Error en reembolso EfevooPay', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Reembolso para transacciones Stripe (nuevo método con Customer param)
     */
    private function refundStripeTransactionNew(Transaction $transaction, Customer $customer): bool
    {
        try {
            Log::info('RefundTransactionAction::refundStripeTransactionNew', [
                'transaction_id' => $transaction->id,
                'customer_id' => $customer->id,
            ]);

            // Para transacciones EfevooPay marcadas incorrectamente como Stripe, no intentar reembolsar con Stripe
            if (str_starts_with($transaction->gateway_transaction_id ?? '', 'sim_')) {
                Log::warning('Transacción EfevooPay detectada en refundStripeTransaction', [
                    'transaction_id' => $transaction->id,
                    'gateway_transaction_id' => $transaction->gateway_transaction_id,
                ]);

                return $this->refundEfevooPayTransaction($transaction, $customer);
            }

            // Reembolso real con Stripe
            $customer->refund($transaction->reference_id);
            
            // Actualizar transacción
            $transaction->update([
                'refunded_at' => now(),
                'gateway_status' => 'refunded',
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Error en reembolso Stripe', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Reembolso para transacciones Odessa (nuevo método con Customer param)
     */
    private function refundOdessaTransactionNew(Transaction $transaction, Customer $customer): bool
    {
        try {
            Log::info('RefundTransactionAction::refundOdessaTransactionNew', [
                'transaction_id' => $transaction->id,
                'customer_id' => $customer->id,
            ]);

            // Llamar al método antiguo para mantener funcionalidad
            $this->refundOdessaTransactionOld($transaction);
            
            // Actualizar transacción
            $transaction->update([
                'refunded_at' => now(),
                'gateway_status' => 'refunded',
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Error en reembolso Odessa', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function getCustomerFromTransaction(Transaction $transaction): Customer
    {
        try {
            // Método 1: Intentar obtener desde details
            $details = $transaction->details ?? [];
            if (is_string($details)) {
                $details = json_decode($details, true) ?? [];
            }

            if (isset($details['customer_id'])) {
                $customer = Customer::find($details['customer_id']);
                if ($customer) {
                    return $customer;
                }
            }

            // Método 2: Intentar obtener desde gateway_response
            $gatewayResponse = $transaction->gateway_response ?? [];
            if (is_string($gatewayResponse)) {
                $gatewayResponse = json_decode($gatewayResponse, true) ?? [];
            }

            if (isset($gatewayResponse['metadata']['customer_id'])) {
                $customer = Customer::find($gatewayResponse['metadata']['customer_id']);
                if ($customer) {
                    return $customer;
                }
            }

            // Método 3: Buscar por referencia
            if ($transaction->reference_id && str_starts_with($transaction->reference_id, 'LAB-')) {
                // Extraer customer_id de la referencia: LAB-timestamp-customer_id
                $parts = explode('-', $transaction->reference_id);
                if (count($parts) >= 3) {
                    $customerId = $parts[2];
                    $customer = Customer::find($customerId);
                    if ($customer) {
                        return $customer;
                    }
                }
            }

            // Método 4: Buscar en laboratory_purchases relacionadas
            if ($transaction->laboratoryPurchases()->exists()) {
                $laboratoryPurchase = $transaction->laboratoryPurchases()->first();
                if ($laboratoryPurchase && $laboratoryPurchase->customer) {
                    return $laboratoryPurchase->customer;
                }
            }

            throw new \Exception("No se pudo encontrar el cliente para la transacción {$transaction->id}");

        } catch (\Exception $e) {
            Log::error('Error obteniendo cliente de transacción', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
                'transaction_data' => [
                    'reference_id' => $transaction->reference_id,
                    'details' => $transaction->details,
                    'gateway_response' => $transaction->gateway_response,
                ],
            ]);

            throw $e;
        }
    }
}