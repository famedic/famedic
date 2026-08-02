<?php

namespace App\Http\Controllers\OnlinePharmacy\OnlinePharmacyPurchases;

use App\Actions\CreateInvoiceRequestAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\OnlinePharmacy\OnlinePharmacyPurchases\StoreInvoiceRequestRequest;
use App\Models\OnlinePharmacyPurchase;
use Illuminate\Support\Facades\Log;

class InvoiceRequestController extends Controller
{
    public function __invoke(
        StoreInvoiceRequestRequest $request,
        OnlinePharmacyPurchase $onlinePharmacyPurchase,
        CreateInvoiceRequestAction $action
    ) {
        $taxProfile = $request->user()->customer->taxProfiles()->find($request->tax_profile);

        if (! $taxProfile) {
            Log::warning('Solicitud de factura farmacia: perfil fiscal no encontrado para el customer autenticado.', [
                'user_id' => $request->user()->id,
                'customer_id' => $request->user()->customer->id,
                'online_pharmacy_purchase_id' => $onlinePharmacyPurchase->id,
                'operation' => 'pharmacy_invoice_request',
            ]);

            return redirect()->back()->withErrors(['tax_profile' => 'Perfil fiscal no encontrado.']);
        }

        // Snapshot con el cfdi_use elegido. No se actualiza el perfil (sin efecto secundario nuevo).
        $action($onlinePharmacyPurchase, $taxProfile, $request->validated('cfdi_use'));

        return redirect()->route('online-pharmacy-purchases.show', [
            'online_pharmacy_purchase' => $onlinePharmacyPurchase,
        ])->flashMessage('Se ha solicitado la factura.');
    }
}
