<?php

namespace App\Http\Controllers\Laboratories\LaboratoryPurchases;

use App\Actions\CreateInvoiceRequestAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Laboratories\LaboratoryPurchases\StoreInvoiceRequestRequest;
use App\Models\LaboratoryPurchase;
use App\Models\TaxProfile;
use Illuminate\Support\Facades\Log;

class InvoiceRequestController extends Controller
{
    /**
     * Método invocable - Procesa la solicitud de factura para una compra de laboratorio
     *
     * @param StoreInvoiceRequestRequest $request - Validación de los datos del formulario
     * @param LaboratoryPurchase $laboratoryPurchase - Modelo de la compra de laboratorio
     * @param CreateInvoiceRequestAction $action - Action que crea la solicitud de factura
     * @return \Illuminate\Http\RedirectResponse
     */
    public function __invoke(
        StoreInvoiceRequestRequest $request,
        LaboratoryPurchase $laboratoryPurchase,
        CreateInvoiceRequestAction $action
    ) {
        Log::info('Iniciando solicitud de factura', [
            'laboratory_purchase_id' => $laboratoryPurchase->id,
            'user_id' => auth()->id(),
            'customer_id' => auth()->user()->customer->id,
            'tax_profile_id' => $request->tax_profile,
            'operation' => 'laboratory_invoice_request',
        ]);

        $taxProfile = auth()->user()->customer->taxProfiles()->find($request->tax_profile);

        if (! $taxProfile) {
            Log::warning('Solicitud de factura laboratorio: perfil fiscal no encontrado para el customer autenticado.', [
                'user_id' => auth()->id(),
                'customer_id' => auth()->user()->customer->id,
                'laboratory_purchase_id' => $laboratoryPurchase->id,
                'operation' => 'laboratory_invoice_request',
            ]);

            return redirect()->back()->withErrors(['tax_profile' => 'Perfil fiscal no encontrado.']);
        }

        $cfdiUse = $request->validated('cfdi_use');

        Log::info('Ejecutando CreateInvoiceRequestAction', [
            'laboratory_purchase_id' => $laboratoryPurchase->id,
            'tax_profile_id' => $taxProfile->id,
            'operation' => 'laboratory_invoice_request',
        ]);
        $action($laboratoryPurchase, $taxProfile, $cfdiUse);

        $cfdiUses = config('taxregimes.uses', []);
        $cfdiUseName = $cfdiUses[$cfdiUse] ?? $cfdiUse;

        $message = 'Se ha solicitado la factura y estará disponible después de 3 días hábiles. ';
        $message .= "Uso de CFDI: {$cfdiUse} - {$cfdiUseName}";

        Log::info('Solicitud de factura laboratorio completada', [
            'laboratory_purchase_id' => $laboratoryPurchase->id,
            'tax_profile_id' => $taxProfile->id,
            'operation' => 'laboratory_invoice_request',
            'result' => 'success',
        ]);

        return redirect()->route('laboratory-purchases.show', [
            'laboratory_purchase' => $laboratoryPurchase,
        ])->flashMessage($message);
    }
}
