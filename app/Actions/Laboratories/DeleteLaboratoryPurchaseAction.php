<?php

namespace App\Actions\Laboratories;

use App\Actions\Transactions\RefundTransactionAction;
use App\Models\LaboratoryPurchase;
use App\Notifications\CustomerLaboratoryPurchaseDeleted;
use App\Notifications\GDALaboratoryPurchaseDeleted;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Log;

class DeleteLaboratoryPurchaseAction
{
    private RefundTransactionAction $refundTransactionAction;

    public function __construct(RefundTransactionAction $refundTransactionAction)
    {
        $this->refundTransactionAction = $refundTransactionAction;
    }

    public function __invoke(LaboratoryPurchase $laboratoryPurchase)
    {
        Log::info('🔁 DeleteLaboratoryPurchaseAction INICIO', [
            'laboratory_purchase_id' => $laboratoryPurchase->id,
            'transactions_count' => $laboratoryPurchase->transactions->count(),
        ]);

        foreach ($laboratoryPurchase->transactions as $transaction) {

            Log::info('💳 Intentando refund', [
                'transaction_id' => $transaction->id,
                'gateway' => $transaction->gateway,
                'gateway_transaction_id' => $transaction->gateway_transaction_id,
                'amount_cents' => $transaction->transaction_amount_cents,
            ]);

            $result = ($this->refundTransactionAction)($transaction);

            Log::info('🔎 Resultado refund', [
                'transaction_id' => $transaction->id,
                'result' => $result,
            ]);

            if (!$result) {
                Log::error('⛔ Refund falló, cancelación abortada', [
                    'transaction_id' => $transaction->id,
                ]);

                throw new \Exception(
                    "No se pudo reembolsar la transacción {$transaction->id}"
                );
            }
        }

        $this->sendCancellationNotifications($laboratoryPurchase);

        Log::info('🧹 Eliminando items del laboratorio', [
            'laboratory_purchase_id' => $laboratoryPurchase->id,
        ]);

        $laboratoryPurchase->laboratoryPurchaseItems()->delete();

        Log::info('🗑️ Eliminando LaboratoryPurchase (soft delete)', [
            'laboratory_purchase_id' => $laboratoryPurchase->id,
        ]);

        $laboratoryPurchase->delete();

        Log::info('🎉 DeleteLaboratoryPurchaseAction FINALIZADO', [
            'laboratory_purchase_id' => $laboratoryPurchase->id,
        ]);
    }

    private function sendCancellationNotifications(LaboratoryPurchase $laboratoryPurchase): void
    {
        $laboratoryPurchase->loadMissing('customer.user');

        if (config('services.gda.report_emails')) {
            Log::info('📧 Enviando notificación GDA', [
                'laboratory_purchase_id' => $laboratoryPurchase->id,
            ]);

            Notification::route('mail', config('services.gda.report_emails'))
                ->notify(new GDALaboratoryPurchaseDeleted($laboratoryPurchase));
        }

        $user = $laboratoryPurchase->customer?->user;

        if ($user?->email) {
            Log::info('📧 Enviando notificación de cancelación al cliente', [
                'laboratory_purchase_id' => $laboratoryPurchase->id,
                'user_id' => $user->id,
                'email' => $user->email,
            ]);

            $user->notify(new CustomerLaboratoryPurchaseDeleted($laboratoryPurchase));

            return;
        }

        Log::warning('⚠️ No se envió correo de cancelación al cliente: sin usuario o correo', [
            'laboratory_purchase_id' => $laboratoryPurchase->id,
            'customer_id' => $laboratoryPurchase->customer_id,
        ]);
    }
}
