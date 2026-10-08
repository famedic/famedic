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
use App\Services\InvoiceRequests\InvoiceRequestWorkflowPresenter;
use App\Services\LaboratoryResults\LaboratoryPurchaseResultControlPresenter;
use App\Actions\Laboratories\ResolveConsultableGdaId;
use App\Exceptions\GdaConsultIdNotResolvableException;
use App\Support\Laboratory\GdaResultsPdfStatus;
use Illuminate\Support\Facades\Log;
use App\Models\LaboratoryNotification;
use App\Models\LaboratoryPurchase;
use Illuminate\Support\Facades\Storage;
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
        InvoiceRequestWorkflowPresenter $invoiceRequestWorkflowPresenter,
        RecoverUncertainGdaLaboratoryPurchaseAction $recoverUncertainGdaLaboratoryPurchaseAction,
        CreateReplacementGdaLaboratoryPurchaseAction $createReplacementGdaLaboratoryPurchaseAction
    )
    {
        $laboratoryPurchase->load([
            'transactions',
            'vendorPayments',
            'laboratoryPurchaseItems.laboratoryResultStatus.versions',
            'laboratoryResultStatuses.versions',
            'customer.user',
            'invoice',
            'invoiceRequest.taxProfile',
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
            'laboratoryPurchase' => $this->presentForShow($laboratoryPurchase),
            'isCancelled' => $laboratoryPurchase->trashed(),
            'couponReversal' => $laboratoryPurchase->getCouponReversalSummary(),
            'showDeleteButton' => $request->user()->can('delete', $laboratoryPurchase),
            'canResendConfirmationEmail' => optional($request->user()->administrator)->hasPermissionTo('laboratory-purchases.manage') ?? false,
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
                optional($laboratoryPurchase->latestSampleCollection())->created_at
            )->isoFormat('D MMM Y h:mm a'),

            'latestResultsAt' => optional(
                optional($laboratoryPurchase->latestResultsNotification())->created_at
            )->isoFormat('D MMM Y h:mm a'),
            'sampleCollectionNotifications' => $this->sampleCollectionNotificationsFor($laboratoryPurchase),
            'resultsGdaSummary' => $this->resultsGdaSummaryFor($laboratoryPurchase),
            ...$resultControlPresenter->present($laboratoryPurchase, $request->user()),
            'invoiceRequestWorkflow' => $laboratoryPurchase->invoiceRequest
                ? $invoiceRequestWorkflowPresenter->presentForAdmin($laboratoryPurchase->invoiceRequest)
                : null,
            'fiscalCertificateAvailability' => $this->fiscalCertificateAvailabilityFor($laboratoryPurchase),
        ]);
    }

    private function presentForShow(LaboratoryPurchase $laboratoryPurchase): LaboratoryPurchase
    {
        $taxProfile = $laboratoryPurchase->invoiceRequest?->taxProfile;

        if ($taxProfile) {
            $taxProfile->setAttribute(
                'formatted_profile_updated_at',
                localizedDate($taxProfile->updated_at)?->locale('es')->isoFormat('D MMM Y h:mm a')
            );
        }

        return $laboratoryPurchase;
    }

    private function fiscalCertificateAvailabilityFor(LaboratoryPurchase $laboratoryPurchase): array
    {
        $invoiceRequest = $laboratoryPurchase->invoiceRequest;
        $taxProfile = $invoiceRequest?->taxProfile;

        return [
            'invoice_request' => [
                'has_path' => filled($invoiceRequest?->fiscal_certificate),
                'exists' => filled($invoiceRequest?->fiscal_certificate)
                    ? Storage::exists($invoiceRequest->fiscal_certificate)
                    : false,
            ],
            'tax_profile' => [
                'has_path' => filled($taxProfile?->fiscal_certificate),
                'exists' => filled($taxProfile?->fiscal_certificate)
                    ? $this->storedFileExists($taxProfile->fiscal_certificate)
                    : false,
            ],
        ];
    }

    private function storedFileExists(string $path): bool
    {
        foreach (array_filter(['local', 'private', config('filesystems.default')]) as $disk) {
            if (! is_string($disk) || $disk === '') {
                continue;
            }

            if (config("filesystems.disks.{$disk}") && Storage::disk($disk)->exists($path)) {
                return true;
            }
        }

        return Storage::exists($path);
    }

    private function sampleCollectionNotificationsFor(LaboratoryPurchase $laboratoryPurchase): array
    {
        $gdaReferences = collect([
            $laboratoryPurchase->gda_order_id,
            $laboratoryPurchase->gda_consecutivo,
        ])->filter()->unique()->values();

        return LaboratoryNotification::query()
            ->where(function ($query) {
                $query->where('notification_type', LaboratoryNotification::TYPE_SAMPLE_COLLECTION)
                    ->orWhere('lineanegocio', LaboratoryNotification::LINEA_NEGOCIO_SAMPLE);
            })
            ->where(function ($query) use ($laboratoryPurchase, $gdaReferences) {
                $query->where('laboratory_purchase_id', $laboratoryPurchase->id);

                foreach ($gdaReferences as $reference) {
                    $query->orWhere('gda_order_id', $reference)
                        ->orWhere('gda_consecutivo', $reference);
                }
            })
            ->latest('created_at')
            ->limit(8)
            ->get()
            ->map(function (LaboratoryNotification $notification) {
                return [
                    'id' => $notification->id,
                    'status' => $notification->status,
                    'gda_status' => $notification->gda_status,
                    'gda_order_id' => $notification->gda_order_id,
                    'gda_consecutivo' => $notification->gda_consecutivo,
                    'gda_acuse' => $notification->gda_acuse,
                    'lineanegocio' => $notification->lineanegocio,
                    'created_at' => $notification->created_at?->toIso8601String(),
                    'formatted_created_at' => $notification->created_at
                        ? $notification->created_at->timezone('America/Monterrey')->isoFormat('D MMM Y h:mm a')
                        : null,
                    'email_sent_at' => $notification->email_sent_at?->toIso8601String(),
                    'formatted_email_sent_at' => $notification->email_sent_at
                        ? $notification->email_sent_at->timezone('America/Monterrey')->isoFormat('D MMM Y h:mm a')
                        : null,
                    'email_attempted_at' => $notification->email_attempted_at?->toIso8601String(),
                    'formatted_email_attempted_at' => $notification->email_attempted_at
                        ? $notification->email_attempted_at->timezone('America/Monterrey')->isoFormat('D MMM Y h:mm a')
                        : null,
                    'email_recipient_email' => $notification->email_recipient_email,
                    'email_error' => $notification->email_error,
                ];
            })
            ->values()
            ->all();
    }

    private function resultsGdaSummaryFor(LaboratoryPurchase $laboratoryPurchase): array
    {
        $notifications = GdaResultsPdfStatus::resultsNotificationsForPurchase($laboratoryPurchase)
            ->sortBy('created_at')
            ->values();

        $latest = $notifications
            ->sortByDesc(fn (LaboratoryNotification $notification) => $notification->results_received_at ?? $notification->created_at)
            ->first();

        return [
            'order_key' => $latest?->gda_consecutivo
                ?: $latest?->gda_order_id
                ?: $laboratoryPurchase->gda_consecutivo
                ?: $laboratoryPurchase->gda_order_id,
            'results_pdf' => $this->resultsPdfSummaryFor($laboratoryPurchase, $notifications),
            'sync_logs' => $this->resultsSyncLogsFor($notifications),
            'notifications' => $this->resultNotificationsForDisplay($notifications),
        ];
    }

    private function resultsPdfSummaryFor(LaboratoryPurchase $laboratoryPurchase, $notifications): array
    {
        $latest = $notifications
            ->sortByDesc(fn (LaboratoryNotification $notification) => $notification->results_received_at ?? $notification->created_at)
            ->first();

        if (! $latest) {
            return $this->emptyResultsPdfSummary();
        }

        $assessment = GdaResultsPdfStatus::assess($laboratoryPurchase, $notifications);

        $cachedNotification = $notifications
            ->filter(fn (LaboratoryNotification $notification) => $notification->hasResults())
            ->sortByDesc(fn (LaboratoryNotification $notification) => $notification->pdfFetchedAt() ?? $notification->updated_at)
            ->first();

        $hasPdfInStorage = $assessment->hasPdfInStorage;
        $hasPdfInDb = $cachedNotification !== null;
        $servingNotification = $cachedNotification ?? $latest;
        $isManual = $assessment->isManual;
        $isGdaAutomatic = $assessment->isGdaManaged;
        $availableAtGda = $assessment->availableAtGda;
        $isStale = $assessment->isStale;
        $consultIdResolution = $this->resolveConsultIdForNotification($latest);
        $storagePath = $hasPdfInStorage
            ? $laboratoryPurchase->results
            : data_get($latest->gda_message, 'results_storage_path');

        $pdfKind = $assessment->pdfKind;
        $freshnessStatus = $assessment->freshnessStatus;
        $freshnessStatusLabel = $assessment->freshnessStatusLabel;

        if ($hasPdfInDb && ! $hasPdfInStorage) {
            $pdfKind = 'legacy';
            $freshnessStatus = $isStale ? 'legacy_stale' : 'legacy';
            $freshnessStatusLabel = GdaResultsPdfStatus::freshnessStatusLabel($freshnessStatus);
        }

        if ($hasPdfInStorage && $isStale && $isGdaAutomatic) {
            $location = 'storage_stale';
            $label = 'PDF GDA desactualizado';
            $pdfSource = data_get($latest->gda_message, 'results_source') ?? 'gda';
        } elseif ($hasPdfInStorage) {
            $location = 'storage';
            $label = $isManual
                ? 'PDF manual almacenado en storage/S3'
                : 'PDF automático GDA almacenado en storage/S3';
            $pdfSource = $isManual ? 'manual' : (data_get($latest->gda_message, 'results_source') ?? 'gda');
        } elseif ($hasPdfInDb && $isStale) {
            $location = 'db_base64_stale';
            $label = 'PDF en BD desactualizado';
            $pdfSource = data_get($servingNotification->gda_message, 'results_source') === 'gda_api'
                ? 'gda_api'
                : 'webhook_or_legacy';
        } elseif ($hasPdfInDb) {
            $location = 'db_base64';
            $pdfSource = data_get($servingNotification->gda_message, 'results_source') === 'gda_api'
                ? 'gda_api'
                : 'webhook_or_legacy';
            $label = $pdfSource === 'gda_api'
                ? 'PDF servido desde caché en BD'
                : 'PDF almacenado en BD';
        } elseif ($availableAtGda) {
            $location = 'gda_provider';
            $label = 'Resultados notificados en GDA';
            $pdfSource = null;
        } else {
            $location = 'none';
            $label = 'Sin PDF de resultados registrado';
            $pdfSource = null;
        }

        return [
            'location' => $location,
            'label' => $label,
            'notification_id' => $servingNotification->id,
            'serving_notification_id' => $servingNotification->id,
            'latest_notification_id' => $latest->id,
            'has_pdf_in_storage' => $hasPdfInStorage,
            'storage_path' => $storagePath,
            'is_manual_result' => $isManual,
            'is_gda_automatic' => $isGdaAutomatic,
            'has_pdf_in_db' => $hasPdfInDb,
            'available_at_gda' => $availableAtGda,
            'is_stale' => $isStale,
            'has_newer_results' => $assessment->hasNewerResults,
            'is_automatic_overwrite_candidate' => $assessment->isAutomaticOverwriteCandidate,
            'pdf_kind' => $pdfKind,
            'pdf_kind_label' => GdaResultsPdfStatus::pdfKindLabel($pdfKind),
            'freshness_status' => $freshnessStatus,
            'freshness_status_label' => $freshnessStatusLabel,
            'pdf_source' => $pdfSource,
            'pdf_source_label' => $this->pdfSourceLabel($pdfSource),
            'latest_results_at' => $assessment->latestResultsAt?->toIso8601String(),
            'stored_pdf_at' => $assessment->storedPdfAt?->toIso8601String(),
            'stored_pdf_at_source' => $assessment->storedPdfAtSource,
            'stale_lag_label' => $assessment->staleLagLabel,
            'stored_pdf_timestamp_unreliable' => $assessment->storedPdfTimestampUnreliable,
            'pdf_fetched_at' => $servingNotification->pdfFetchedAt()?->toIso8601String(),
            'last_sync_at' => data_get($latest->gda_message, 'results_fetched_at'),
            'last_sync_error' => data_get($latest->gda_message, 'results_storage_error'),
            'last_sync_error_at' => data_get($latest->gda_message, 'results_storage_error_at'),
            'last_gda_not_available_at' => data_get($latest->gda_message, 'last_gda_not_available_at'),
            'last_gda_not_available_message' => data_get($latest->gda_message, 'last_gda_not_available_message'),
            'gda_consult_id' => $consultIdResolution['id'],
            'gda_consult_id_source' => $consultIdResolution['source'],
            'gda_consult_id_source_label' => $this->consultIdSourceLabel($consultIdResolution['source']),
            'results_notifications_count' => $notifications->count(),
            'can_fetch_from_gda' => $availableAtGda && ! $hasPdfInStorage && ! $hasPdfInDb,
            'can_force_refresh_from_gda' => $availableAtGda && ! $isManual,
            'can_download' => $hasPdfInStorage || $hasPdfInDb || $availableAtGda,
            'can_download_from_db' => $hasPdfInDb,
        ];
    }

    private function emptyResultsPdfSummary(): array
    {
        return [
            'location' => 'none',
            'label' => 'Sin resultados recibidos',
            'has_pdf_in_storage' => false,
            'storage_path' => null,
            'is_manual_result' => false,
            'is_gda_automatic' => false,
            'has_pdf_in_db' => false,
            'available_at_gda' => false,
            'is_stale' => false,
            'has_newer_results' => false,
            'pdf_kind' => GdaResultsPdfStatus::PDF_KIND_NONE,
            'pdf_kind_label' => GdaResultsPdfStatus::pdfKindLabel(GdaResultsPdfStatus::PDF_KIND_NONE),
            'freshness_status' => 'none',
            'freshness_status_label' => GdaResultsPdfStatus::freshnessStatusLabel('none'),
            'pdf_source' => null,
            'pdf_source_label' => null,
            'latest_results_at' => null,
            'stored_pdf_at' => null,
            'pdf_fetched_at' => null,
            'last_sync_at' => null,
            'last_sync_error' => null,
            'last_sync_error_at' => null,
            'last_gda_not_available_at' => null,
            'last_gda_not_available_message' => null,
            'gda_consult_id' => null,
            'gda_consult_id_source' => 'none',
            'gda_consult_id_source_label' => null,
            'results_notifications_count' => 0,
            'can_fetch_from_gda' => false,
            'can_force_refresh_from_gda' => false,
            'can_download' => false,
            'can_download_from_db' => false,
        ];
    }

    private function resultsSyncLogsFor($notifications): array
    {
        return $notifications
            ->map(function (LaboratoryNotification $notification) {
                return [
                    'notification_id' => $notification->id,
                    'gda_order_id' => $notification->gda_order_id,
                    'gda_consecutivo' => $notification->gda_consecutivo,
                    'received_at' => $notification->created_at?->timezone('America/Monterrey')->isoFormat('D MMM Y h:mm a'),
                    'gda_acuse' => $notification->gda_acuse,
                    'results_source' => data_get($notification->gda_message, 'results_source'),
                    'results_storage_path' => data_get($notification->gda_message, 'results_storage_path'),
                    'results_fetched_at' => data_get($notification->gda_message, 'results_fetched_at'),
                    'results_storage_error' => data_get($notification->gda_message, 'results_storage_error'),
                    'status' => $notification->gda_status ?? $notification->status,
                    'email' => $notification->email_recipient_email,
                    'email_sent_at' => $notification->email_sent_at?->timezone('America/Monterrey')->isoFormat('D MMM Y h:mm a'),
                ];
            })
            ->values()
            ->all();
    }

    private function resultNotificationsForDisplay($notifications): array
    {
        return $notifications
            ->sortByDesc(fn (LaboratoryNotification $notification) => $notification->results_received_at ?? $notification->created_at)
            ->map(function (LaboratoryNotification $notification) {
                return [
                    'id' => $notification->id,
                    'status' => $notification->status,
                    'gda_status' => $notification->gda_status,
                    'gda_order_id' => $notification->gda_order_id,
                    'gda_consecutivo' => $notification->gda_consecutivo,
                    'created_at' => $notification->created_at?->toIso8601String(),
                    'formatted_created_at' => $notification->created_at?->timezone('America/Monterrey')->isoFormat('D MMM Y h:mm a'),
                    'results_received_at' => $notification->results_received_at?->toIso8601String(),
                    'formatted_results_received_at' => $notification->results_received_at?->timezone('America/Monterrey')->isoFormat('D MMM Y h:mm a'),
                    'email_recipient_email' => $notification->email_recipient_email,
                    'email_sent_at' => $notification->email_sent_at?->toIso8601String(),
                    'formatted_email_sent_at' => $notification->email_sent_at?->timezone('America/Monterrey')->isoFormat('D MMM Y h:mm a'),
                    'has_pdf_in_db' => $notification->hasResults(),
                    'pdf_at_gda' => $notification->needsPdfFetch(),
                    'pdf_source' => data_get($notification->gda_message, 'results_source'),
                    'pdf_fetched_at' => $notification->pdfFetchedAt()?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    private function resolveConsultIdForNotification(LaboratoryNotification $notification): array
    {
        try {
            return app(ResolveConsultableGdaId::class)($notification->gda_order_id, $notification->payload);
        } catch (GdaConsultIdNotResolvableException) {
            return ['id' => null, 'source' => 'none'];
        }
    }

    private function consultIdSourceLabel(?string $source): ?string
    {
        return match ($source) {
            'gda_order_id' => 'gda_order_id',
            'payload.id' => 'payload.id',
            'infogda_etiqueta' => 'infogda_etiqueta',
            'requisition.value' => 'requisition.value',
            'none' => 'Sin ID consultable',
            default => $source,
        };
    }

    private function pdfSourceLabel(?string $source): ?string
    {
        return match ($source) {
            'gda_api' => 'API de consulta GDA',
            'gda', 'storage' => 'Storage / S3 (GDA automático)',
            'manual' => 'Storage / S3 (subido manualmente)',
            'webhook_or_legacy' => 'Webhook GDA o caché legacy',
            default => null,
        };
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
        CreateReplacementGdaLaboratoryPurchaseAction $createReplacementGdaLaboratoryPurchaseAction
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
        RecoverUncertainGdaLaboratoryPurchaseAction $recoverUncertainGdaLaboratoryPurchaseAction
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
        $user = optional($laboratoryPurchase->customer)->user;

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
