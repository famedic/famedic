<?php

namespace App\Http\Controllers\Debug;

use App\Actions\Laboratories\GenerateLaboratoryPurchaseConfirmationPdf;
use App\Actions\Laboratories\LaboratoryPurchaseConfirmationViewData;
use App\Http\Controllers\Controller;
use App\Models\LaboratoryPurchase;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class LaboratoryPurchasePdfPreviewController extends Controller
{
    /**
     * Vista previa del PDF de comprobante (solo entorno local).
     *
     * GET /debug/laboratory-purchase-pdf/{laboratory_purchase}
     *   ?variant=auto|with|without
     *   ?format=html|pdf
     */
    public function __invoke(
        Request $request,
        LaboratoryPurchase $laboratoryPurchase,
        GenerateLaboratoryPurchaseConfirmationPdf $generatePdf,
    ): Response|View {
        $laboratoryPurchase->loadMissing([
            'customer.user',
            'laboratoryPurchaseItems',
            'laboratoryAppointment.laboratoryStore',
            'transactions',
        ]);

        $notifiable = $laboratoryPurchase->customer?->user ?? (object) [
            'name' => 'Cliente (vista previa)',
            'full_name' => null,
        ];

        $variant = (string) $request->query('variant', 'auto');
        if (! in_array($variant, ['auto', 'with', 'without'], true)) {
            $variant = 'auto';
        }

        $format = strtolower((string) $request->query('format', 'html'));
        if (! in_array($format, ['html', 'pdf'], true)) {
            $format = 'html';
        }

        $withAppointment = $this->resolveWithAppointment($laboratoryPurchase, $variant);

        $viewData = LaboratoryPurchaseConfirmationViewData::build(
            $laboratoryPurchase,
            $notifiable,
            $format === 'pdf',
        );

        $viewName = 'pdfs.laboratory-purchase-order';
        $merged = array_merge($viewData, ['withAppointment' => $withAppointment]);

        if ($format === 'pdf') {
            $filename = 'orden-laboratorio-'.($laboratoryPurchase->gda_order_id ?: $laboratoryPurchase->id).'.pdf';
            $binary = $generatePdf->binary($laboratoryPurchase, $notifiable, $withAppointment);

            return response($binary, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.$filename.'"',
            ]);
        }

        return view('debug.laboratory-purchase-pdf-preview', [
            'laboratoryPurchase' => $laboratoryPurchase,
            'variant' => $variant,
            'withAppointment' => $withAppointment,
            'previewUrls' => $this->previewUrls($laboratoryPurchase, $variant),
            'pdfContent' => view($viewName, $merged)->render(),
        ]);
    }

    protected function resolveWithAppointment(LaboratoryPurchase $purchase, string $variant): bool
    {
        return match ($variant) {
            'with' => true,
            'without' => false,
            default => LaboratoryPurchaseConfirmationViewData::hasAppointmentForConfirmation($purchase),
        };
    }

    /**
     * @return array<string, string>
     */
    protected function previewUrls(LaboratoryPurchase $purchase, string $variant): array
    {
        $base = route('debug.laboratory-purchase-pdf', ['laboratory_purchase' => $purchase->id]);

        return [
            'html_auto' => $base.'?format=html&variant=auto',
            'html_with' => $base.'?format=html&variant=with',
            'html_without' => $base.'?format=html&variant=without',
            'pdf_auto' => $base.'?format=pdf&variant=auto',
            'pdf_with' => $base.'?format=pdf&variant=with',
            'pdf_without' => $base.'?format=pdf&variant=without',
            'download' => route('laboratory-purchases.download-pdf', ['laboratory_purchase' => $purchase->id]),
            'email' => route('debug.laboratory-purchase-email', ['laboratory_purchase' => $purchase->id]),
        ];
    }
}
