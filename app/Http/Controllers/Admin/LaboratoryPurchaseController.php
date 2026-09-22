<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Laboratories\DeleteLaboratoryPurchaseAction;
use App\Actions\Laboratories\CreateReplacementGdaLaboratoryPurchaseAction;
use App\Actions\Laboratories\RecoverUncertainGdaLaboratoryPurchaseAction;
use App\Http\Requests\Admin\LaboratoryPurchases\ReplaceGdaLaboratoryPurchaseRequest;
use App\Exceptions\CouponApplicationException;
use App\Exceptions\GdaOrderResultUncertainException;
use App\Exceptions\RecoverGdaLaboratoryPurchaseException;
use App\Http\Requests\Admin\LaboratoryPurchases\RecoverUncertainGdaLaboratoryPurchaseRequest;
use App\Enums\LaboratoryBrand;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LaboratoryPurchases\DestroyLaboratoryPurchaseRequest;
use App\Http\Requests\Admin\LaboratoryPurchases\IndexLaboratoryPurchaseRequest;
use App\Http\Requests\Admin\LaboratoryPurchases\ResendLaboratoryPurchaseConfirmationRequest;
use App\Http\Requests\Admin\LaboratoryPurchases\ShowLaboratoryPurchaseRequest;
use App\Notifications\LaboratoryPurchaseCreated;
use App\Services\LaboratoryResults\LaboratoryPurchaseResultControlPresenter;
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
        $filters['using_default_date_range'] = empty($filters['start_date']) && empty($filters['end_date']);

        if ($filters['using_default_date_range']) {
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

    public function show(
        ShowLaboratoryPurchaseRequest $request,
        LaboratoryPurchase $laboratoryPurchase,
        LaboratoryPurchaseResultControlPresenter $resultControlPresenter,
        RecoverUncertainGdaLaboratoryPurchaseAction $recoverUncertainGdaLaboratoryPurchaseAction,
        CreateReplacementGdaLaboratoryPurchaseAction $createReplacementGdaLaboratoryPurchaseAction,
    )
    {
        $laboratoryPurchase->load([
            'transactions',
            'vendorPayments',
            'laboratoryPurchaseItems.laboratoryResultStatus.versions',
            'laboratoryResultStatuses.versions',
            'customer.user',
            'invoice',
            'invoiceRequest',
            'laboratoryAppointment.laboratoryStore',
            'replacementLaboratoryPurchase',
            'replacedLaboratoryPurchase',
            'devAssistanceRequests.administrator.user',
            'devAssistanceRequests.comments.administrator.user',
            'laboratoryNotifications',
        ]);

        $laboratoryPurchase->hydrateLaboratoryPurchaseItemsFeatureLists();

        $canReplaceGda = $request->user()->can('replaceGda', $laboratoryPurchase);
        $canRecoverGda = ! $laboratoryPurchase->replacement_laboratory_purchase_id
            && $request->user()->can('recoverGda', $laboratoryPurchase);

        return Inertia::render('Admin/LaboratoryPurchase', [
            'laboratoryPurchase' => $laboratoryPurchase,
            'isCancelled' => $laboratoryPurchase->trashed(),
            'couponReversal' => $laboratoryPurchase->getCouponReversalSummary(),
            'showDeleteButton' => $request->user()->can('delete', $laboratoryPurchase),
            'canResendConfirmationEmail' => $request->user()->administrator?->hasPermissionTo('laboratory-purchases.manage') ?? false,
            'canUploadInvoice' => $request->user()->can('uploadInvoice', $laboratoryPurchase),
            'canRecoverGda' => $canRecoverGda,
            'gdaRecoverPreview' => $canRecoverGda
                ? $recoverUncertainGdaLaboratoryPurchaseAction->preview($laboratoryPurchase)
                : null,
            'canReplaceGda' => $canReplaceGda,
            'gdaReplacePreview' => $canReplaceGda
                ? $createReplacementGdaLaboratoryPurchaseAction->preview($laboratoryPurchase)
                : null,

            'hasSampleCollected' => $laboratoryPurchase->hasSampleCollected(),
            'hasResultsAvailable' => $laboratoryPurchase->hasResultsAvailable(),
            'hasManualResults' => filled($laboratoryPurchase->results),
            'latestSampleCollectionAt' => optional(
                $laboratoryPurchase->latestSampleCollection()?->created_at
            )?->isoFormat('D MMM Y h:mm a'),

            'latestResultsAt' => optional(
                $laboratoryPurchase->latestResultsNotification()?->created_at
            )?->isoFormat('D MMM Y h:mm a'),
            ...$resultControlPresenter->present($laboratoryPurchase, $request->user()),
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

    public function replaceGda(
        ReplaceGdaLaboratoryPurchaseRequest $request,
        LaboratoryPurchase $laboratoryPurchase,
        CreateReplacementGdaLaboratoryPurchaseAction $createReplacementGdaLaboratoryPurchaseAction,
    ) {
        try {
            $replacement = $createReplacementGdaLaboratoryPurchaseAction(
                $laboratoryPurchase,
                (int) $request->validated('coupon_id'),
                $request->user(),
            );

            return redirect()
                ->route('admin.laboratory-purchases.show', $replacement)
                ->flashMessage(
                    'Pedido de reemplazo #'.$replacement->id.' creado y confirmado en GDA. '
                    .'El pedido original #'.$laboratoryPurchase->id.' quedó referenciado.'
                );
        } catch (GdaOrderResultUncertainException $e) {
            $summary = $e->context()['response_summary'] ?? [];
            $gdaDetail = $summary['gda_description'] ?? null;
            $gdaMensaje = $summary['gda_mensaje'] ?? null;
            $gdaCodeHttp = $summary['gda_code_http'] ?? $e->httpStatus();

            $message = 'GDA rechazó el pedido de reemplazo.';
            if (filled($gdaDetail)) {
                $message .= ' Detalle GDA: '.$gdaDetail;
            } elseif (filled($gdaMensaje)) {
                $message .= ' Respuesta GDA: '.$gdaMensaje.' (codeHttp '.$gdaCodeHttp.').';
            } else {
                $message .= ' '.$e->getMessage();
            }

            return back()->flashMessage($message, 'error');
        } catch (CouponApplicationException|RecoverGdaLaboratoryPurchaseException $e) {
            return back()->flashMessage($e->getMessage(), 'error');
        } catch (\Throwable $e) {
            Log::error('[GDA Replace] Unexpected failure', [
                'source_purchase_id' => $laboratoryPurchase->id,
                'error' => $e->getMessage(),
            ]);

            return back()->flashMessage(
                'No se pudo crear el pedido de reemplazo: '.$e->getMessage(),
                'error',
            );
        }
    }

    public function recoverGda(
        RecoverUncertainGdaLaboratoryPurchaseRequest $request,
        LaboratoryPurchase $laboratoryPurchase,
        RecoverUncertainGdaLaboratoryPurchaseAction $recoverUncertainGdaLaboratoryPurchaseAction,
    ) {
        try {
            $recoverUncertainGdaLaboratoryPurchaseAction(
                $laboratoryPurchase,
                (int) $request->validated('coupon_id'),
                $request->user(),
            );

            return redirect()
                ->route('admin.laboratory-purchases.show', $laboratoryPurchase)
                ->flashMessage('Pedido recuperado en GDA con saldo a favor. No se envió correo al cliente.');
        } catch (GdaOrderResultUncertainException $e) {
            $summary = $e->context()['response_summary'] ?? [];
            $gdaDetail = $summary['gda_description'] ?? null;
            $gdaMensaje = $summary['gda_mensaje'] ?? null;
            $gdaCodeHttp = $summary['gda_code_http'] ?? $e->httpStatus();

            $message = 'GDA volvió a responder de forma incierta.';
            if (filled($gdaDetail)) {
                $message .= ' Detalle GDA: '.$gdaDetail;
            } elseif (filled($gdaMensaje)) {
                $message .= ' Respuesta GDA: '.$gdaMensaje.' (codeHttp '.$gdaCodeHttp.').';
            } else {
                $message .= ' '.$e->getMessage();
            }

            return back()->flashMessage($message, 'error');
        } catch (CouponApplicationException|RecoverGdaLaboratoryPurchaseException $e) {
            return back()->flashMessage($e->getMessage(), 'error');
        } catch (\Throwable $e) {
            Log::error('[GDA Recover] Unexpected failure', [
                'purchase_id' => $laboratoryPurchase->id,
                'error' => $e->getMessage(),
            ]);

            return back()->flashMessage(
                'No se pudo recuperar el pedido: '.$e->getMessage(),
                'error',
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
