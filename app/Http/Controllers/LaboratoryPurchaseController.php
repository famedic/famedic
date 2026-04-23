<?php

namespace App\Http\Controllers;

use App\Actions\Laboratories\OrderAction;
use App\Enums\LaboratoryBrand;
use App\Exceptions\EfevooPaymentException;
use App\Exceptions\OdessaInsufficientFundsException;
use App\Http\Requests\Laboratories\LaboratoryPurchases\StoreLaboratoryPurchaseRequest;
use App\Http\Resources\PatientLaboratoryPurchaseCardResource;
use App\Models\Address;
use App\Models\Contact;
use App\Models\LaboratoryNotification;
use App\Models\LaboratoryPurchase;
use App\Services\Tracking\Purchase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

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
        $customer = $request->user()->customer;

        $filters = array_merge(
            [
                'deleted' => 'false',
            ],
            $request->only([
                'search',
                'patient',
                'payment_method',
                'brand',
                'start_date',
                'end_date',
                'deleted',
            ])
        );

        $studyStatus = $request->input('study_status', 'all');
        $pipeline = $request->input('pipeline', 'all');
        if (! in_array($pipeline, ['all', 'processing', 'completed', 'invoiced'], true)) {
            $pipeline = 'all';
        }

        $baseQuery = $customer->laboratoryPurchases()
            ->filter($filters);

        if ($pipeline !== 'all') {
            $baseQuery->wherePatientPipeline($pipeline);
        } else {
            $baseQuery->wherePatientStudyStatus($studyStatus === 'all' ? null : $studyStatus);
        }

        $resultsReceived = function ($q) {
            $q->where(function ($q2) {
                $q2->where('notification_type', LaboratoryNotification::TYPE_RESULTS)
                    ->orWhere('lineanegocio', LaboratoryNotification::LINEA_NEGOCIO_RESULTS);
            })->whereNotNull('results_received_at');
        };

        $pendingCount = $customer->laboratoryPurchases()
            ->whereNull('deleted_at')
            ->whereNull('results')
            ->whereDoesntHave('laboratoryNotifications', $resultsReceived)
            ->count();

        $readyCount = $customer->laboratoryPurchases()
            ->whereNull('deleted_at')
            ->where(function ($q) use ($resultsReceived) {
                $q->whereNotNull('results')
                    ->orWhereHas('laboratoryNotifications', $resultsReceived);
            })
            ->count();

        $invoicedCount = $customer->laboratoryPurchases()
            ->whereNull('deleted_at')
            ->whereHas('invoice')
            ->whereHas('invoiceRequest')
            ->count();

        $patientOptions = $customer->laboratoryPurchases()
            ->whereNull('deleted_at')
            ->select(['name', 'paternal_lastname', 'maternal_lastname'])
            ->orderByDesc('created_at')
            ->limit(500)
            ->get()
            ->map(fn ($p) => trim($p->name.' '.$p->paternal_lastname.' '.$p->maternal_lastname))
            ->filter()
            ->unique()
            ->values()
            ->take(80)
            ->map(fn ($label) => ['value' => $label, 'label' => $label])
            ->all();

        $paginator = $baseQuery
            ->withNotificationStatus()
            ->with([
                'transactions',
                'laboratoryPurchaseItems',
                'laboratoryAppointment',
                'invoice',
                'invoiceRequest',
            ])
            ->latest()
            ->paginate(12)
            ->withQueryString();

        $purchaseCards = collect($paginator->items())->map(
            fn (LaboratoryPurchase $p) => (new PatientLaboratoryPurchaseCardResource($p))->toArray($request)
        )->all();

        $laboratoryQuotes = $customer->laboratoryQuotes()
            ->filter($request->only('search'))
            ->with(['appointment', 'user'])
            ->latest()
            ->get();

        return Inertia::render('LaboratoryPurchases', [
            'purchaseCards' => $purchaseCards,
            'pagination' => [
                'total' => $paginator->total(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'prev_page_url' => $paginator->previousPageUrl(),
                'next_page_url' => $paginator->nextPageUrl(),
            ],
            'laboratoryQuotes' => $laboratoryQuotes,
            'filters' => array_merge($filters, [
                'study_status' => $studyStatus,
                'pipeline' => $pipeline,
            ]),
            'summary' => [
                'pending_count' => $pendingCount,
                'ready_count' => $readyCount,
                'processing_count' => $pendingCount,
                'completed_count' => $readyCount,
                'invoiced_count' => $invoicedCount,
            ],
            'filterOptions' => [
                'study_statuses' => [
                    ['value' => 'all', 'label' => 'Todos los estados'],
                    ['value' => 'in_progress', 'label' => 'En proceso'],
                    ['value' => 'sample_taken', 'label' => 'Muestra tomada'],
                    ['value' => 'results_ready', 'label' => 'Resultados listos'],
                    ['value' => 'cancelled', 'label' => 'Cancelados'],
                ],
                'payment_methods' => [
                    ['value' => '', 'label' => 'Cualquier forma de pago'],
                    ['value' => 'stripe', 'label' => 'Tarjeta (Stripe)'],
                    ['value' => 'odessa', 'label' => 'Caja de ahorro (Odessa)'],
                    ['value' => 'efevoopay', 'label' => 'Efevoo'],
                    ['value' => 'paypal', 'label' => 'PayPal'],
                ],
                'laboratory_brands' => collect(LaboratoryBrand::cases())->map(fn (LaboratoryBrand $b) => [
                    'value' => $b->value,
                    'label' => $b->label(),
                ])->values()->all(),
                'patients' => $patientOptions,
            ],
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
            'invoice',
        ]);

        // Usar los mismos métodos que en el admin para obtener las notificaciones
        $hasSampleCollected = $laboratoryPurchase->hasSampleCollected();
        $hasResultsAvailable = $laboratoryPurchase->hasResultsAvailable();

        $latestSampleCollection = $laboratoryPurchase->latestSampleCollection();
        $latestResultsNotification = $laboratoryPurchase->latestResultsNotification();

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
