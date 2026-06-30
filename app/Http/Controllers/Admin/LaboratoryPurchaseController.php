<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Laboratories\DeleteLaboratoryPurchaseAction;
use App\Enums\LaboratoryBrand;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LaboratoryPurchases\DestroyLaboratoryPurchaseRequest;
use App\Http\Requests\Admin\LaboratoryPurchases\IndexLaboratoryPurchaseRequest;
use App\Http\Requests\Admin\LaboratoryPurchases\ResendLaboratoryPurchaseConfirmationRequest;
use App\Http\Requests\Admin\LaboratoryPurchases\ShowLaboratoryPurchaseRequest;
use App\Notifications\LaboratoryPurchaseCreated;
use Illuminate\Support\Facades\Log;
use App\Models\LaboratoryPurchase;
use Carbon\Carbon;
use Inertia\Inertia;

class LaboratoryPurchaseController extends Controller
{
    public function index(IndexLaboratoryPurchaseRequest $request)
    {
        $filters = collect($request->only([
            'search',
            'deleted',
            'start_date',
            'end_date',
            'invoice_requested',
            'invoice_uploaded',
            'results_uploaded',
            'payment_method',
            'payment_status',
            'brand',
            'dev_assistance',
        ]))->filter()->all();

        // Sin fechas en la petición: últimos 3 meses (evita escanear toda la tabla y timeouts/502).
        // Si el usuario elige fechas en los filtros, se respetan tal cual.
        if (empty($filters['start_date']) && empty($filters['end_date'])) {
            $filters['start_date'] = Carbon::now('America/Monterrey')->subMonths(3)->startOfDay()->toDateString();
            $filters['end_date'] = Carbon::now('America/Monterrey')->endOfDay()->toDateString();
        }

        $laboratoryPurchases = LaboratoryPurchase::query()
            ->filter($filters)
            ->forAdminIndexList()
            ->latest('laboratory_purchases.created_at')
            ->paginate()
            ->withQueryString();

        if (!empty($filters['start_date'])) {
            $filters['formatted_start_date'] = Carbon::parse($filters['start_date'], 'America/Monterrey')->isoFormat('MMM D, Y');
        }

        if (!empty($filters['end_date'])) {
            $filters['formatted_end_date'] = Carbon::parse($filters['end_date'], 'America/Monterrey')->isoFormat('MMM D, Y');
        }

        return Inertia::render('Admin/LaboratoryPurchases', [
            'laboratoryPurchases' => $laboratoryPurchases,
            'filters' => $filters,
            'brands' => LaboratoryBrand::brandsData(),
            'canExport' => $request->user()->administrator->hasPermissionTo('laboratory-purchases.manage.export'),
        ]);
    }

    public function show(ShowLaboratoryPurchaseRequest $request, LaboratoryPurchase $laboratoryPurchase)
    {
        $laboratoryPurchase->load([
            'transactions',
            'vendorPayments',
            'laboratoryPurchaseItems',
            'customer.user',
            'invoice',
            'invoiceRequest',
            'laboratoryAppointment.laboratoryStore',
            'devAssistanceRequests.administrator.user',
            'devAssistanceRequests.comments.administrator.user',
            'laboratoryNotifications',
        ]);

        $laboratoryPurchase->hydrateLaboratoryPurchaseItemsFeatureLists();

        return Inertia::render('Admin/LaboratoryPurchase', [
            'laboratoryPurchase' => $laboratoryPurchase,
            'couponReversal' => $laboratoryPurchase->getCouponReversalSummary(),
            'showDeleteButton' => $request->user()->can('delete', $laboratoryPurchase),
            'canResendConfirmationEmail' => $request->user()->administrator?->hasPermissionTo('laboratory-purchases.manage') ?? false,

            'hasSampleCollected' => $laboratoryPurchase->hasSampleCollected(),
            'hasResultsAvailable' => $laboratoryPurchase->hasResultsAvailable(),
            'hasManualResults' => filled($laboratoryPurchase->results),
            'latestSampleCollectionAt' => optional(
                $laboratoryPurchase->latestSampleCollection()?->created_at
            )?->isoFormat('D MMM Y h:mm a'),

            'latestResultsAt' => optional(
                $laboratoryPurchase->latestResultsNotification()?->created_at
            )?->isoFormat('D MMM Y h:mm a'),
        ]);
    }

    public function destroy(
        DestroyLaboratoryPurchaseRequest $request,
        LaboratoryPurchase $laboratoryPurchase,
        DeleteLaboratoryPurchaseAction $deleteLaboratoryPurchaseAction
    ) {
        Log::info('🗑️ LaboratoryPurchaseController@destroy INICIO', [
            'laboratory_purchase_id' => $laboratoryPurchase->id,
            'gda_order_id' => $laboratoryPurchase->gda_order_id,
            'transactions_count' => $laboratoryPurchase->transactions->count(),
            'user_id' => $request->user()->id,
        ]);

        try {
            ($deleteLaboratoryPurchaseAction)($laboratoryPurchase, $request->user());

            Log::info('✅ LaboratoryPurchaseController@destroy COMPLETADO', [
                'laboratory_purchase_id' => $laboratoryPurchase->id,
            ]);

            return redirect()->route('admin.laboratory-purchases.index')
                ->flashMessage('Orden de laboratorio eliminada correctamente.');

        } catch (\Exception $e) {

            Log::error('❌ LaboratoryPurchaseController@destroy ERROR', [
                'laboratory_purchase_id' => $laboratoryPurchase->id,
                'error' => $e->getMessage(),
            ]);

            return back()->flashMessage(
                'No se pudo cancelar el pedido: ' . $e->getMessage(),
                'error'
            );
        }
    }

    public function resendConfirmationEmail(
        ResendLaboratoryPurchaseConfirmationRequest $request,
        LaboratoryPurchase $laboratoryPurchase
    ) {
        $user = $laboratoryPurchase->customer?->user;

        if (! $user || ! $user->email) {
            return back()->withErrors([
                'resend_confirmation' => 'Esta orden no tiene un usuario con correo electrónico para enviar la confirmación.',
            ]);
        }

        $user->notify(new LaboratoryPurchaseCreated($laboratoryPurchase));

        return back()->flashMessage(
            'Se reenvió el correo de confirmación de compra a '.$user->email.'.'
        );
    }

}

