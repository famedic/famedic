<?php

namespace App\Actions\Laboratories;

use App\Actions\Transactions\RefundTransactionAction;
use App\Models\LaboratoryPurchase;
use App\Notifications\CustomerLaboratoryPurchaseDeleted;
use App\Notifications\GDALaboratoryPurchaseDeleted;
use Illuminate\Support\Facades\Notification;

class DeleteLaboratoryPurchaseAction
{
    private RefundTransactionAction $refundTransactionAction;

    public function __construct(RefundTransactionAction $refundTransactionAction)
    {
        $this->refundTransactionAction = $refundTransactionAction;
    }

    public function __invoke(LaboratoryPurchase $laboratoryPurchase)
    {
        foreach ($laboratoryPurchase->transactions as $transaction) {
            ($this->refundTransactionAction)($transaction);

            if (config('services.gda.report_emails')) {
                Notification::route('mail', config('services.gda.report_emails'))
                    ->notify(new GDALaboratoryPurchaseDeleted($laboratoryPurchase));
            }
        }

        $laboratoryPurchase->customer->user->notify(new CustomerLaboratoryPurchaseDeleted($laboratoryPurchase));

        $laboratoryPurchase->laboratoryPurchaseItems()->delete();

        $laboratoryPurchase->delete();
    }
}
