<?php

namespace App\Http\Controllers\Debug;

use App\Http\Controllers\Controller;
use App\Models\LaboratoryPurchase;
use App\Notifications\LaboratoryPurchaseCreated;
use Illuminate\Http\Request;
use Illuminate\Mail\Markdown;

class LaboratoryPurchaseEmailPreviewController extends Controller
{
    /**
     * Vista previa del correo de confirmación de laboratorio (solo entorno local).
     *
     * GET /debug/laboratory-purchase-email/{laboratory_purchase}?variant=auto|with|without
     */
    public function __invoke(Request $request, LaboratoryPurchase $laboratoryPurchase)
    {
        $notifiable = $laboratoryPurchase->customer?->user;

        if (! $notifiable) {
            abort(404, 'Esta compra no tiene un usuario asociado; no se puede armar el correo de vista previa.');
        }

        $variant = (string) $request->query('variant', 'auto');

        $notification = new LaboratoryPurchaseCreated($laboratoryPurchase);
        $payload = $notification->previewMail($notifiable, $variant);

        $html = app(Markdown::class)->render($payload['view'], $payload['data']);

        $document = '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>'.e($payload['subject']).'</title>'
            .'<style>body{margin:0;padding:16px;background:#edf2f7;font-family:system-ui,sans-serif;}</style>'
            .'</head><body>'
            .'<p style="margin:0 0 16px;padding:12px 16px;background:#fff3cd;border:1px solid #ffc107;border-radius:8px;font-size:14px;color:#856404;">'
            .'<strong>Vista previa (solo local)</strong> — '
            .'Pedido #'.e((string) $laboratoryPurchase->id).' — '
            .'Variante: <code>'.e($variant).'</code> — '
            .'Asunto: '.e($payload['subject'])
            .'</p>'
            .(string) $html
            .'</body></html>';

        return response($document)->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
