<?php

namespace App\Http\Controllers;

use App\Actions\Laboratories\ResolveLaboratoryPurchasePdfPath;
use App\Http\Requests\Laboratories\DownloadLaboratoryPurchasePdfRequest;
use App\Http\Requests\Laboratories\EmailLaboratoryPurchasePdfRequest;
use App\Models\LaboratoryPurchase;
use App\Notifications\LaboratoryPurchasePdfEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class LaboratoryPurchasePdfController extends Controller
{
    private ResolveLaboratoryPurchasePdfPath $resolvePdfPath;

    public function __construct(ResolveLaboratoryPurchasePdfPath $resolvePdfPath)
    {
        $this->resolvePdfPath = $resolvePdfPath;
    }

    public function download(DownloadLaboratoryPurchasePdfRequest $request, LaboratoryPurchase $laboratoryPurchase)
    {
        $storagePath = ($this->resolvePdfPath)($laboratoryPurchase);

        return Inertia::location(
            Storage::temporaryUrl(
                $storagePath,
                now()->addMinutes(5)
            )
        );
    }

    public function email(EmailLaboratoryPurchasePdfRequest $request, LaboratoryPurchase $laboratoryPurchase)
    {
        $senderName = $request->user()->full_name ?: $request->user()->name;

        Notification::route('mail', $request->validated('email'))
            ->notify(new LaboratoryPurchasePdfEmail($laboratoryPurchase, $senderName, $this->resolvePdfPath));

        return back()->flashMessage('El PDF ha sido enviado por correo electrónico.');
    }
}
