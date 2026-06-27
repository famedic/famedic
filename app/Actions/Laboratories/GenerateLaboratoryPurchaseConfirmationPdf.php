<?php

namespace App\Actions\Laboratories;

use App\Models\LaboratoryPurchase;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Genera el comprobante PDF de orden de laboratorio (DomPDF, solo PHP).
 * Mismos datos que el correo de confirmación (LaboratoryPurchaseConfirmationViewData).
 */
final class GenerateLaboratoryPurchaseConfirmationPdf
{
    public function binary(LaboratoryPurchase $purchase, object $notifiable, bool $withAppointment): string
    {
        $data = LaboratoryPurchaseConfirmationViewData::build($purchase, $notifiable, true);

        return Pdf::loadView('pdfs.laboratory-purchase-order', array_merge($data, [
            'withAppointment' => $withAppointment,
        ]))
            ->setPaper('a4', 'portrait')
            ->output();
    }
}
