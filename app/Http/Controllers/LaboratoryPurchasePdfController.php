<?php

namespace App\Http\Controllers;

use App\Actions\Laboratories\ResolveLaboratoryPurchasePdfPath;
use App\Exceptions\ChromiumNotAvailableException;
use App\Http\Requests\Laboratories\DownloadLaboratoryPurchasePdfRequest;
use App\Http\Requests\Laboratories\EmailLaboratoryPurchasePdfRequest;
use App\Models\LaboratoryPurchase;
use App\Notifications\LaboratoryPurchasePdfEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

class LaboratoryPurchasePdfController extends Controller
{
    private ResolveLaboratoryPurchasePdfPath $resolvePdfPath;

    public function __construct(ResolveLaboratoryPurchasePdfPath $resolvePdfPath)
    {
        $this->resolvePdfPath = $resolvePdfPath;
    }

    public function download(DownloadLaboratoryPurchasePdfRequest $request, LaboratoryPurchase $laboratoryPurchase)
    {
        try {
            $storagePath = ($this->resolvePdfPath)($laboratoryPurchase);
        } catch (ChromiumNotAvailableException $exception) {
            report($exception);

            return redirect()
                ->route('laboratory-purchases.show', $laboratoryPurchase)
                ->withErrors(['pdf' => $exception->getMessage()]);
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()
                ->route('laboratory-purchases.show', $laboratoryPurchase)
                ->withErrors([
                    'pdf' => 'No se pudo generar el PDF de la orden. Verifica que Node, Chromium y las dependencias del sistema estén instalados en el servidor.',
                ]);
        }

        $filename = 'orden-laboratorio-'.($laboratoryPurchase->gda_order_id ?: $laboratoryPurchase->id).'.pdf';

        return Storage::disk(config('filesystems.default'))->download($storagePath, $filename, [
            'Content-Type' => 'application/pdf',
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
