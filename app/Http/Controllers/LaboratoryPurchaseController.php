<?php

namespace App\Http\Controllers;

use App\Actions\Laboratories\OrderAction;
use App\Enums\LaboratoryBrand;
use App\Exceptions\OdessaInsufficientFundsException;
use App\Exceptions\EfevooPaymentException;
use App\Http\Requests\Laboratories\LaboratoryPurchases\StoreLaboratoryPurchaseRequest;
use App\Models\Address;
use App\Models\Contact;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryNotification;
use App\Services\Tracking\Purchase;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Log;


class LaboratoryPurchaseController extends Controller
{
    public function store(StoreLaboratoryPurchaseRequest $request, LaboratoryBrand $laboratoryBrand, OrderAction $orderAction)
    {
        try {
            $laboratoryPurchase = $orderAction(
                customer: $request->user()->customer,
                address: Address::find($request->address),
                contact: Contact::find($request->contact),
                paymentMethod: $request->payment_method,
                laboratoryBrand: $laboratoryBrand,
                totalCents: $request->total,
            );
        } catch (EfevooPaymentException $e) {
            return redirect()->back()
                ->withErrors(['payment_method' => $e->getMessage()]);
        } catch (OdessaInsufficientFundsException $e) {
            return redirect()->back()
                ->withErrors(['payment_method' => 'No cuentas con suficiente Saldo a la Vista en tu caja de ahorro para realizar el pago.']);
        }

        session()->flash('confetti', true);

        Purchase::track(
            purchaseId: $laboratoryPurchase->id,
            contents: $laboratoryPurchase->laboratoryPurchaseItems->map(function ($item) {
                return [
                    'id' => $item->gda_id,
                    'quantity' => 1,
                ];
            })->all(),
            value: $laboratoryPurchase->total,
            source: 'laboratory',
            customProperties: [
                'laboratory_brand' => $laboratoryBrand->value,
            ]
        );

        return redirect()->route('laboratory-purchases.show', [
            'laboratory_purchase' => $laboratoryPurchase,
        ])->flashMessage('Pedido realizado con éxito.');
    }

    public function index(Request $request)
    {
        $filters = $request->only('search');

        $laboratoryPurchases = $request->user()->customer->laboratoryPurchases()
            ->filter($filters)
            ->withNotificationStatus()
            ->with([
                'transactions',
                'laboratoryPurchaseItems',
                'invoice',
                'invoiceRequest'
            ])
            ->latest()
            ->get();

        // Log para ver los datos después del scope
        foreach ($laboratoryPurchases as $purchase) {
            \Illuminate\Support\Facades\Log::info('Purchase after scope', [
                'id' => $purchase->id,
                'has_sample_collected' => $purchase->has_sample_collected,
                'has_results_available' => $purchase->has_results_available,
                'latest_sample_collection_at' => $purchase->latest_sample_collection_at,
                'latest_results_at' => $purchase->latest_results_at,
                'formatted_sample_collection_at' => $purchase->formatted_sample_collection_at,
                'formatted_results_at' => $purchase->formatted_results_at,
            ]);
        }

        $laboratoryQuotes = $request->user()->customer->laboratoryQuotes()
            ->filter($filters)
            ->with(['appointment', 'user'])
            ->latest()
            ->get();

        return Inertia::render('LaboratoryPurchases', [
            'laboratoryPurchases' => $laboratoryPurchases,
            'laboratoryQuotes' => $laboratoryQuotes,
            'filters' => $filters,
        ]);
    }

    public function show(Request $request, LaboratoryPurchase $laboratoryPurchase)
    {
        $lastDayOfPurchaseMonth = localizedDate($laboratoryPurchase->created_at)->endOfMonth();
        $nowInMonterrey = localizedDate(now());

        // Cargar relaciones necesarias
        $laboratoryPurchase->load([
            'transactions',
            'laboratoryPurchaseItems',
            'laboratoryAppointment.laboratoryStore',
            'invoiceRequest',
            'invoice'
        ]);

        // Usar los mismos métodos que en el admin para obtener las notificaciones
        $hasSampleCollected = $laboratoryPurchase->hasSampleCollected();
        $hasResultsAvailable = $laboratoryPurchase->hasResultsAvailable();

        $latestSampleCollection = $laboratoryPurchase->latestSampleCollection()?->first();
        $latestResultsNotification = $laboratoryPurchase->latestResultsNotification()?->first();

        Log::info('🔍 LaboratoryPurchase show', [
            'purchase_id' => $laboratoryPurchase->id,
            'gda_consecutivo' => $laboratoryPurchase->gda_consecutivo,
            'hasSampleCollected' => $hasSampleCollected,
            'hasResultsAvailable' => $hasResultsAvailable,
            'latestSampleCollection' => $latestSampleCollection?->created_at,
            'latestResultsNotification' => $latestResultsNotification?->created_at,
        ]);

        return Inertia::render('LaboratoryPurchase', [
            'laboratoryPurchase' => $laboratoryPurchase,

            'hasSampleCollected' => $hasSampleCollected,
            'hasResultsAvailable' => $hasResultsAvailable,

            'latestSampleCollectionAt' => $latestSampleCollection?->created_at
                ? localizedDate($latestSampleCollection->created_at)->isoFormat('D MMM Y h:mm a')
                : null,

            'latestResultsAt' => $latestResultsNotification?->created_at
                ? localizedDate($latestResultsNotification->created_at)->isoFormat('D MMM Y h:mm a')
                : null,

            'taxProfiles' => auth()->user()->customer->taxProfiles,

            'daysLeftToRequestInvoice' => $nowInMonterrey->lt($lastDayOfPurchaseMonth)
                ? (int) ceil($nowInMonterrey->diffInDays($lastDayOfPurchaseMonth, false))
                : 0,

            ...session()->get('confetti') ? ['confetti' => true] : [],
        ]);
    }
}
