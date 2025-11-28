<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\LaboratoryQuote;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryNotification;

class LaboratoryResultController extends Controller
{
    /**
     * Mostrar la lista de resultados disponibles del paciente
     */
    public function index()
    {
        $user = Auth::user();

        // Obtener todas las notificaciones del usuario como tabla principal
        $notifications = LaboratoryNotification::where('user_id', $user->id)
            ->with([
                'laboratoryQuote.quoteItems',
                'laboratoryPurchase.laboratoryPurchaseItems',
                'contact'
            ])
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($notification) {
                // Determinar si es de quote o purchase
                $relatedEntity = $notification->getRelatedEntity();
                $entityType = $notification->getEntityType();

                // Información común de la notificación
                $baseData = [
                    'notification_id' => $notification->id,
                    'type' => $notification->notification_type,
                    'status' => $notification->status,
                    'gda_status' => $notification->gda_status,
                    'created_at' => $notification->created_at,
                    'results_received_at' => $notification->results_received_at,
                    'read_at' => $notification->read_at,
                    'has_pdf' => !empty($notification->results_pdf_base64),
                    'pdf_base64' => $notification->results_pdf_base64,
                    'gda_acuse' => $notification->gda_acuse,
                    'gda_order_id' => $notification->gda_order_id,
                    'resource_type' => $notification->resource_type,
                ];

                // Si es de una cotización
                if ($entityType === 'quote' && $notification->laboratoryQuote) {
                    $quote = $notification->laboratoryQuote;
                    return array_merge($baseData, [
                        'entity_type' => 'quote',
                        'entity_id' => $quote->id,
                        'laboratory_brand' => $quote->laboratory_brand,
                        'patient_name' => $quote->patient_full_name,
                        'items' => $quote->quoteItems->map(fn($item) => [
                            'name' => $item->name,
                            'quantity' => $item->quantity ?? 1,
                            'price_cents' => $item->price_cents,
                        ]),
                        'quote_data' => [
                            'total_cents' => $quote->total_cents,
                            'status' => $quote->status,
                            'expires_at' => $quote->expires_at,
                        ]
                    ]);
                }

                // Si es de una compra
                if ($entityType === 'purchase' && $notification->laboratoryPurchase) {
                    $purchase = $notification->laboratoryPurchase;
                    return array_merge($baseData, [
                        'entity_type' => 'purchase',
                        'entity_id' => $purchase->id,
                        'laboratory_brand' => $purchase->brand->value ?? $purchase->brand,
                        'patient_name' => $purchase->full_name,
                        'items' => $purchase->laboratoryPurchaseItems->map(fn($item) => [
                            'name' => $item->name,
                            'quantity' => $item->quantity ?? 1,
                            'price_cents' => $item->price_cents,
                        ]),
                        'purchase_data' => [
                            'total_cents' => $purchase->total_cents,
                            'created_at' => $purchase->created_at,
                        ]
                    ]);
                }

                // Notificación sin entidad relacionada (caso raro)
                return array_merge($baseData, [
                    'entity_type' => 'unknown',
                    'entity_id' => null,
                    'laboratory_brand' => null,
                    'patient_name' => null,
                    'items' => [],
                ]);
            });

        //dd($notifications->count());
        // Estadísticas para debug
        $stats = [
            'total_notifications' => $notifications->count(),
            'by_type' => $notifications->groupBy('type')->map->count(),
            'by_entity' => $notifications->groupBy('entity_type')->map->count(),
            'with_pdf' => $notifications->where('has_pdf', true)->count(),
            'unread' => $notifications->whereNull('read_at')->count(),
        ];

        logger('🔍 [NOTIFICATIONS DEBUG]', $stats);

        return Inertia::render('LaboratoryResultsList', [
            'notifications' => $notifications,
            'stats' => $stats,
            'hasNotifications' => $notifications->isNotEmpty(),
        ]);
    }

    /**
     * Marcar un resultado como descargado
     */
    public function markAsDownloaded(Request $request, $type, $id)
    {
        $user = Auth::user();

        if ($type === 'quote') {
            $record = LaboratoryQuote::where('user_id', $user->id)
                ->where('id', $id)
                ->whereNotNull('ready_at')
                ->firstOrFail();
        } else {
            $record = LaboratoryPurchase::where('customer_id', $user->customer->id)
                ->where('id', $id)
                ->whereNotNull('ready_at')
                ->firstOrFail();
        }

        $record->update([
            'results_downloaded_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Descargar un resultado específico
     */
    public function download($type, $id)
    {
        $user = Auth::user();

        if ($type === 'quote') {
            $record = LaboratoryQuote::where('user_id', $user->id)
                ->where('id', $id)
                ->whereNotNull('ready_at')
                ->firstOrFail();

            $latestResult = $record->laboratoryNotifications()
                ->where('notification_type', 'results')
                ->whereNotNull('results_pdf_base64')
                ->latest()
                ->first();
        } else {
            $record = LaboratoryPurchase::where('customer_id', $user->customer->id)
                ->where('id', $id)
                ->whereNotNull('ready_at')
                ->firstOrFail();

            $latestResult = $record->laboratoryNotifications()
                ->where('notification_type', 'results')
                ->whereNotNull('results_pdf_base64')
                ->latest()
                ->first();
        }

        if (!$latestResult || !$latestResult->results_pdf_base64) {
            abort(404, 'Resultado no encontrado');
        }

        // Decodificar base64 y devolver PDF
        $pdfContent = base64_decode($latestResult->results_pdf_base64);

        return response($pdfContent)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="resultados_' . $record->gda_order_id . '.pdf"');
    }

    /**
     * Ver resultado en el navegador
     */
    public function view($type, $id)
    {
        $user = Auth::user();

        if ($type === 'quote') {
            $record = LaboratoryQuote::where('user_id', $user->id)
                ->where('id', $id)
                ->whereNotNull('ready_at')
                ->firstOrFail();

            $latestResult = $record->laboratoryNotifications()
                ->where('notification_type', 'results')
                ->whereNotNull('results_pdf_base64')
                ->latest()
                ->first();
        } else {
            $record = LaboratoryPurchase::where('customer_id', $user->customer->id)
                ->where('id', $id)
                ->whereNotNull('ready_at')
                ->firstOrFail();

            $latestResult = $record->laboratoryNotifications()
                ->where('notification_type', 'results')
                ->whereNotNull('results_pdf_base64')
                ->latest()
                ->first();
        }

        if (!$latestResult || !$latestResult->results_pdf_base64) {
            abort(404, 'Resultado no encontrado');
        }

        // Decodificar base64 y mostrar PDF en el navegador
        $pdfContent = base64_decode($latestResult->results_pdf_base64);

        return response($pdfContent)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="resultados_' . $record->gda_order_id . '.pdf"');
    }
}