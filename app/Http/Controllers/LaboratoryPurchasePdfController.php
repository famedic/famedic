<?php

namespace App\Http\Controllers;

use App\Actions\Laboratories\ResolveLaboratoryPurchasePdfPath;
use App\Http\Requests\Laboratories\DownloadLaboratoryPurchasePdfRequest;
use App\Http\Requests\Laboratories\EmailLaboratoryPurchasePdfRequest;
use App\Models\LaboratoryPurchase;
use App\Notifications\LaboratoryPurchasePdfEmail;
use Illuminate\Support\Facades\Notification;

class LaboratoryPurchasePdfController extends Controller
{
    public function __construct(
        private ResolveLaboratoryPurchasePdfPath $resolvePdfPath,
    ) {
    }

    public function download(DownloadLaboratoryPurchasePdfRequest $request, LaboratoryPurchase $laboratoryPurchase)
    {
        try {
            $binary = $this->resolvePdfPath->binary($laboratoryPurchase);
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()
                ->route('laboratory-purchases.show', $laboratoryPurchase)
                ->withErrors([
                    'pdf' => 'No se pudo generar el PDF de la orden. Intenta de nuevo más tarde o contacta a soporte.',
                ]);
        }

        $filename = 'orden-laboratorio-'.($laboratoryPurchase->gda_order_id ?: $laboratoryPurchase->id).'.pdf';

        return response($binary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    public function email(EmailLaboratoryPurchasePdfRequest $request, LaboratoryPurchase $laboratoryPurchase)
    {
        $senderName = $request->user()->full_name ?: $request->user()->name;

        Notification::route('mail', $request->validated('email'))
            ->notify(new LaboratoryPurchasePdfEmail($laboratoryPurchase, $senderName, $this->resolvePdfPath));

        return back()->flashMessage('El PDF ha sido enviado por correo electrónico.');
    }
}
