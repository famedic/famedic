<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Laboratories\ResolveConsultableGdaId;
use App\Actions\Laboratories\ResolveGdaResultsPdfAction;
use App\Exceptions\GdaConsultIdNotResolvableException;
use App\Exceptions\GdaResultsNotAvailableException;
use App\Http\Controllers\Controller;
use App\Models\LabOrderEventState;
use App\Models\LaboratoryNotification;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class LaboratoryNotificationMonitorController extends Controller
{
    public function index(Request $request)
    {
        $request->user()->administrator->hasPermissionTo('laboratory-notifications.monitor') || abort(403);

        $tz = config('app.timezone', 'UTC');

        $startDate = $request->get('start_date')
            ? Carbon::parse($request->get('start_date'), $tz)->startOfDay()->utc()
            : now($tz)->subDays(30)->startOfDay()->utc();

        $endDate = $request->get('end_date')
            ? Carbon::parse($request->get('end_date'), $tz)->endOfDay()->utc()
            : now($tz)->endOfDay()->utc();

        $search = trim((string) $request->get('search', ''));

        $baseQuery = $this->baseNotificationsQuery($startDate, $endDate, $search);

        // Serie diaria (notificaciones recibidas por día)
        $dailyRows = (clone $baseQuery)
            ->selectRaw('DATE(created_at) as date')
            ->selectRaw('SUM(CASE WHEN notification_type = ? THEN 1 ELSE 0 END) as sample_count', [LaboratoryNotification::TYPE_SAMPLE_COLLECTION])
            ->selectRaw('SUM(CASE WHEN notification_type = ? THEN 1 ELSE 0 END) as results_count', [LaboratoryNotification::TYPE_RESULTS])
            ->selectRaw('COUNT(*) as total_count')
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get();

        $dailyDataPoints = $dailyRows->map(function ($row) use ($tz) {
            $date = Carbon::parse($row->date, 'UTC')->timezone($tz)->locale('es');

            return [
                'date' => ucfirst($date->isoFormat('D MMM')),
                'sample' => (int) $row->sample_count,
                'results' => (int) $row->results_count,
                'total' => (int) $row->total_count,
                'formattedValue' => (int) $row->total_count,
            ];
        })->values();

        $total = (int) $dailyRows->sum('total_count');
        $days = max(1, $dailyRows->count());
        $averagePerDay = round($total / $days, 2);

        $dailyChart = [
            'total' => $total,
            'averagePerDay' => $averagePerDay,
            'dataPoints' => $dailyDataPoints,
        ];

        // Agrupación por orden (gda_consecutivo, con respaldo en gda_order_id)
        $orders = (clone $baseQuery)
            ->selectRaw('COALESCE(gda_consecutivo, gda_order_id) as order_key')
            ->selectRaw('MAX(gda_consecutivo) as gda_consecutivo')
            ->selectRaw('MAX(gda_order_id) as gda_order_id')
            ->selectRaw('MIN(CASE WHEN notification_type = ? THEN created_at END) as sample_at', [LaboratoryNotification::TYPE_SAMPLE_COLLECTION])
            ->selectRaw('MIN(CASE WHEN notification_type = ? THEN COALESCE(results_received_at, created_at) END) as results_at', [LaboratoryNotification::TYPE_RESULTS])
            ->selectRaw('SUM(CASE WHEN notification_type = ? THEN 1 ELSE 0 END) as sample_notifications', [LaboratoryNotification::TYPE_SAMPLE_COLLECTION])
            ->selectRaw('SUM(CASE WHEN notification_type = ? THEN 1 ELSE 0 END) as results_notifications', [LaboratoryNotification::TYPE_RESULTS])
            ->selectRaw('MAX(user_id) as user_id')
            ->selectRaw('MAX(laboratory_purchase_id) as laboratory_purchase_id')
            ->groupBy(DB::raw('COALESCE(gda_consecutivo, gda_order_id)'))
            ->orderByRaw('COALESCE(results_at, sample_at) DESC')
            ->paginate(25)
            ->withQueryString();

        $orders->getCollection()->transform(function ($row) use ($tz) {
            $sampleAt = $row->sample_at ? Carbon::parse($row->sample_at, 'UTC')->timezone($tz) : null;
            $resultsAt = $row->results_at ? Carbon::parse($row->results_at, 'UTC')->timezone($tz) : null;

            $diffMinutes = null;
            if ($sampleAt && $resultsAt) {
                $diffMinutes = $sampleAt->diffInMinutes($resultsAt);
            }

            return [
                'order_key' => (string) $row->order_key,
                'gda_consecutivo' => $row->gda_consecutivo,
                'gda_order_id' => $row->gda_order_id,
                'sample_at' => $sampleAt?->toISOString(),
                'results_at' => $resultsAt?->toISOString(),
                'sample_notifications' => (int) $row->sample_notifications,
                'results_notifications' => (int) $row->results_notifications,
                'diff_minutes' => $diffMinutes,
                'user_id' => $row->user_id,
                'laboratory_purchase_id' => $row->laboratory_purchase_id,
            ];
        });

        $orderKeys = collect($orders->items())->pluck('order_key')->all();

        $notificationsGrouped = LaboratoryNotification::query()
            ->where(function (Builder $query) use ($orderKeys) {
                $query->whereIn('gda_consecutivo', $orderKeys)
                    ->orWhereIn('gda_order_id', $orderKeys);
            })
            ->with([
                'user',
                'laboratoryPurchase.customer.user',
                'laboratoryPurchase.laboratoryPurchaseItems',
            ])
            ->get()
            ->groupBy(fn (LaboratoryNotification $n) => (string) ($n->gda_consecutivo ?? $n->gda_order_id));

        $ownersMap = $notificationsGrouped->map(function ($group) {
            $n = $group->first();
            $user = $n->user ?: $n->laboratoryPurchase?->customer?->user;

            return $user ? $this->formatOwner($user) : null;
        });

        $purchaseInfoMap = $notificationsGrouped->map(function ($group) {
            $purchase = $group->first(fn (LaboratoryNotification $n) => $n->laboratoryPurchase !== null)?->laboratoryPurchase;

            if (! $purchase) {
                return null;
            }

            return [
                'brand' => $purchase->brand?->label(),
                'patient_name' => trim(($purchase->name ?? '').' '.($purchase->paternal_lastname ?? '').' '.($purchase->maternal_lastname ?? '')),
                'studies_count' => $purchase->laboratoryPurchaseItems->count(),
            ];
        });

        $orders->getCollection()->transform(function ($row) use ($ownersMap, $purchaseInfoMap) {
            $row['owner'] = $ownersMap[$row['order_key']] ?? null;
            $purchaseInfo = $purchaseInfoMap[$row['order_key']] ?? null;
            $row['brand'] = $purchaseInfo['brand'] ?? null;
            $row['patient_name'] = $purchaseInfo['patient_name'] ?? null;
            $row['studies_count'] = $purchaseInfo['studies_count'] ?? null;

            return $row;
        });

        return Inertia::render('Admin/LaboratoryNotificationsMonitor', [
            'filters' => [
                'start_date' => $startDate->timezone($tz)->toDateString(),
                'end_date' => $endDate->timezone($tz)->toDateString(),
                'search' => $search,
            ],
            'dailyChart' => $dailyChart,
            'orders' => $orders,
        ]);
    }

    public function show(Request $request, string $gdaOrderId)
    {
        $request->user()->administrator->hasPermissionTo('laboratory-notifications.monitor') || abort(403);

        $detail = $this->buildOrderDetail($gdaOrderId);

        return Inertia::render('Admin/LaboratoryNotificationsMonitorShow', $detail);
    }

    public function orderDetails(Request $request, string $orderKey)
    {
        $request->user()->administrator->hasPermissionTo('laboratory-notifications.monitor') || abort(403);

        return response()->json($this->buildOrderDetail($orderKey));
    }

    public function fetchResults(Request $request, string $orderKey): JsonResponse
    {
        $request->user()->administrator->hasPermissionTo('laboratory-notifications.monitor') || abort(403);

        $resultsNotifications = $this->notificationsForOrder($orderKey)
            ->where('notification_type', LaboratoryNotification::TYPE_RESULTS)
            ->values();

        $notification = $resultsNotifications->sortByDesc('created_at')->first();

        if (! $notification) {
            return response()->json([
                'success' => false,
                'message' => 'No existe notificación de resultados para esta orden.',
            ], 404);
        }

        try {
            $result = app(ResolveGdaResultsPdfAction::class)($notification);
        } catch (GdaResultsNotAvailableException $e) {
            $resultsNotifications = $this->notificationsForOrder($orderKey)
                ->where('notification_type', LaboratoryNotification::TYPE_RESULTS)
                ->values();

            return response()->json([
                'success' => false,
                'gda_not_available' => true,
                'message' => 'GDA respondió: No contiene resultados todavía. El PDF aún no está disponible en la API de consulta.',
                'last_attempt_at' => now()->toISOString(),
                'gda_message' => $e->gdaMessage,
                'results_pdf' => $this->buildResultsPdfSummary($resultsNotifications),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error consultando GDA: '.$e->getMessage(),
            ], 500);
        }

        $resultsNotifications = $this->notificationsForOrder($orderKey)
            ->where('notification_type', LaboratoryNotification::TYPE_RESULTS)
            ->values();

        return response()->json([
            'success' => true,
            'cached' => $result['cached'],
            'refreshed' => $result['refreshed'],
            'message' => $result['refreshed']
                ? 'PDF actualizado desde GDA y guardado en storage/S3.'
                : (! empty($result['storage_path'])
                    ? 'El PDF ya estaba almacenado en storage/S3.'
                    : 'El PDF ya estaba almacenado en la base de datos.'),
            'pdf_base64' => $result['pdf_base64'],
            'results_pdf' => $this->buildResultsPdfSummary($resultsNotifications),
        ]);
    }

    public function forceRefreshResults(Request $request, string $orderKey): JsonResponse
    {
        $request->user()->administrator->hasPermissionTo('laboratory-notifications.monitor') || abort(403);

        $resultsNotifications = $this->notificationsForOrder($orderKey)
            ->where('notification_type', LaboratoryNotification::TYPE_RESULTS)
            ->values();

        $notification = $resultsNotifications->sortByDesc('created_at')->first();

        if (! $notification) {
            return response()->json([
                'success' => false,
                'message' => 'No existe notificación de resultados para esta orden.',
            ], 404);
        }

        try {
            $result = app(ResolveGdaResultsPdfAction::class)->forceRefresh($notification);
        } catch (GdaResultsNotAvailableException $e) {
            $resultsNotifications = $this->notificationsForOrder($orderKey)
                ->where('notification_type', LaboratoryNotification::TYPE_RESULTS)
                ->values();

            return response()->json([
                'success' => false,
                'gda_not_available' => true,
                'message' => 'GDA respondió: No contiene resultados todavía. El PDF aún no está disponible en la API de consulta.',
                'last_attempt_at' => now()->toISOString(),
                'gda_message' => $e->gdaMessage,
                'results_pdf' => $this->buildResultsPdfSummary($resultsNotifications),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error forzando actualización desde GDA: '.$e->getMessage(),
            ], 500);
        }

        $resultsNotifications = $this->notificationsForOrder($orderKey)
            ->where('notification_type', LaboratoryNotification::TYPE_RESULTS)
            ->values();

        return response()->json([
            'success' => true,
            'cached' => false,
            'refreshed' => true,
            'forced' => true,
            'message' => 'PDF actualizado desde GDA y guardado en storage/S3.',
            'pdf_base64' => $result['pdf_base64'],
            'results_pdf' => $this->buildResultsPdfSummary($resultsNotifications),
        ]);
    }

    public function downloadResults(Request $request, string $orderKey)
    {
        $request->user()->administrator->hasPermissionTo('laboratory-notifications.monitor') || abort(403);

        $resultsNotifications = $this->notificationsForOrder($orderKey)
            ->where('notification_type', LaboratoryNotification::TYPE_RESULTS)
            ->values();

        $notification = LaboratoryNotification::latestResultsForOrder(
            $resultsNotifications->first()?->laboratory_purchase_id,
            $resultsNotifications->first()?->gda_order_id,
            $resultsNotifications->first()?->gda_consecutivo ?? $orderKey
        );

        if (! $notification) {
            $notification = $resultsNotifications->sortByDesc('created_at')->first();
        }

        if (! $notification || ! $notification->hasAvailableResults()) {
            abort(404, 'PDF no disponible.');
        }

        $purchase = $notification->laboratoryPurchase;

        if ($purchase && ! empty($purchase->results) && Storage::exists($purchase->results)) {
            $filename = 'resultados_'.($notification->gda_consecutivo ?? $notification->gda_order_id ?? $orderKey).'.pdf';

            return Storage::download($purchase->results, $filename);
        }

        try {
            $result = app(ResolveGdaResultsPdfAction::class)($notification);
            $pdfContent = base64_decode($result['pdf_base64'], true);

            if ($pdfContent === false || $pdfContent === '') {
                abort(404, 'PDF no disponible.');
            }

            $filename = 'resultados_'.($notification->gda_consecutivo ?? $notification->gda_order_id ?? $orderKey).'.pdf';

            return response($pdfContent)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'attachment; filename="'.$filename.'"');
        } catch (\Throwable $e) {
            abort(404, 'PDF no disponible: '.$e->getMessage());
        }
    }

    private function baseNotificationsQuery(Carbon $startDate, Carbon $endDate, string $search = ''): Builder
    {
        $query = LaboratoryNotification::query()
            ->whereBetween('created_at', [$startDate, $endDate])
            ->where(function (Builder $query) {
                $query->whereNotNull('gda_order_id')
                    ->orWhereNotNull('gda_consecutivo');
            });

        if ($search !== '') {
            $query->where(function (Builder $query) use ($search) {
                $query->where('gda_order_id', 'like', "%{$search}%")
                    ->orWhere('gda_consecutivo', 'like', "%{$search}%")
                    ->orWhereHas('user', fn (Builder $userQuery) => $this->applyOwnerSearch($userQuery, $search))
                    ->orWhereHas('laboratoryPurchase.customer.user', fn (Builder $userQuery) => $this->applyOwnerSearch($userQuery, $search));
            });
        }

        return $query;
    }

    private function applyOwnerSearch(Builder $query, string $search): Builder
    {
        return $query->where(function (Builder $query) use ($search) {
            $query->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('paternal_lastname', 'like', "%{$search}%")
                ->orWhere('maternal_lastname', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%");
        });
    }

    private function notificationsForOrder(string $orderKey)
    {
        return LaboratoryNotification::query()
            ->where(function (Builder $query) use ($orderKey) {
                $query->where('gda_order_id', $orderKey)
                    ->orWhere('gda_consecutivo', $orderKey);
            })
            ->with(['user', 'contact', 'laboratoryPurchase.customer.user', 'laboratoryQuote'])
            ->orderBy('created_at')
            ->get();
    }

    private function buildOrderDetail(string $orderKey): array
    {
        $tz = config('app.timezone', 'UTC');
        $notifications = $this->notificationsForOrder($orderKey);

        abort_if($notifications->isEmpty(), 404);

        $sampleAt = $notifications->firstWhere('notification_type', LaboratoryNotification::TYPE_SAMPLE_COLLECTION)?->created_at;
        $resultsNotification = $notifications->firstWhere('notification_type', LaboratoryNotification::TYPE_RESULTS);
        $resultsAt = $resultsNotification?->results_received_at ?: $resultsNotification?->created_at;

        $sampleAtTz = $this->toTimezone($sampleAt, $tz);
        $resultsAtTz = $this->toTimezone($resultsAt, $tz);

        $diffMinutes = null;
        if ($sampleAtTz && $resultsAtTz) {
            $diffMinutes = $sampleAtTz->diffInMinutes($resultsAtTz);
        }

        $ownerUser = $notifications->first()?->user ?: $notifications->first()?->laboratoryPurchase?->customer?->user;
        $first = $notifications->first();

        $sampleNotifications = $notifications
            ->where('notification_type', LaboratoryNotification::TYPE_SAMPLE_COLLECTION)
            ->values();

        $resultsNotifications = $notifications
            ->where('notification_type', LaboratoryNotification::TYPE_RESULTS)
            ->values();

        $eventState = $this->resolveEventState($first);
        $resultsPdf = $this->buildResultsPdfSummary($resultsNotifications);

        return [
            'orderKey' => $orderKey,
            'gdaOrderId' => $first->gda_order_id,
            'gdaConsecutivo' => $first->gda_consecutivo,
            'owner' => $ownerUser ? $this->formatOwner($ownerUser) : null,
            'summary' => [
                'sample_at' => $sampleAtTz?->toISOString(),
                'results_at' => $resultsAtTz?->toISOString(),
                'diff_minutes' => $diffMinutes,
                'sample_notifications' => $sampleNotifications->count(),
                'results_notifications' => $resultsNotifications->count(),
                'total_notifications' => $notifications->count(),
                'results_pdf' => $resultsPdf,
                'emails' => $this->buildEmailSummary($notifications, $eventState, $tz),
                'sync_logs' => $this->buildSyncLogs($notifications, $tz),
            ],
            'sampleNotifications' => $sampleNotifications->map(fn (LaboratoryNotification $n) => $this->formatNotification($n, $tz))->values(),
            'resultsNotifications' => $resultsNotifications->map(fn (LaboratoryNotification $n) => $this->formatNotification($n, $tz))->values(),
            'notifications' => $notifications->map(fn (LaboratoryNotification $n) => $this->formatNotification($n, $tz))->values(),
        ];
    }

    private function resolveEventState(LaboratoryNotification $notification): ?LabOrderEventState
    {
        if (! $notification->gda_order_id && ! $notification->laboratory_purchase_id) {
            return null;
        }

        return LabOrderEventState::query()
            ->where(function ($query) use ($notification) {
                if ($notification->gda_order_id) {
                    $query->where('gda_order_id', $notification->gda_order_id);
                }
                if ($notification->laboratory_purchase_id) {
                    $query->orWhere('laboratory_purchase_id', $notification->laboratory_purchase_id);
                }
            })
            ->first();
    }

    private function buildResultsPdfSummary($resultsNotifications): array
    {
        $latest = $resultsNotifications->sortByDesc(fn (LaboratoryNotification $n) => $n->results_received_at ?? $n->created_at)->first();

        if (! $latest) {
            return $this->emptyResultsPdfSummary();
        }

        $purchase = $latest->laboratoryPurchase;
        $hasPdfInStorage = $purchase && ! empty($purchase->results) && Storage::exists($purchase->results);
        $isGdaAutomatic = $hasPdfInStorage && str_contains($purchase->results ?? '', 'results/gda-');
        $isManual = $hasPdfInStorage && ! $isGdaAutomatic;
        $availableAtGda = $latest->hasAvailableResults();

        $cachedNotification = $resultsNotifications
            ->filter(fn (LaboratoryNotification $n) => $n->hasResults())
            ->sortByDesc(fn (LaboratoryNotification $n) => $n->pdfFetchedAt() ?? $n->updated_at)
            ->first();

        $hasPdfInDb = $cachedNotification !== null;
        $servingNotification = $cachedNotification ?? $latest;
        $isStale = ! $hasPdfInStorage && $latest->shouldRefreshPdfFromGda();

        $lastSyncAt = data_get($latest->gda_message, 'results_fetched_at');
        $lastSyncError = data_get($latest->gda_message, 'results_storage_error');
        $lastSyncErrorAt = data_get($latest->gda_message, 'results_storage_error_at');
        $lastGdaNotAvailableAt = data_get($latest->gda_message, 'last_gda_not_available_at');
        $lastGdaNotAvailableMessage = data_get($latest->gda_message, 'last_gda_not_available_message');
        $consultIdResolution = $this->resolveConsultIdForNotification($latest);
        $storagePath = $hasPdfInStorage ? $purchase->results : data_get($latest->gda_message, 'results_storage_path');

        if ($hasPdfInStorage) {
            $location = 'storage';
            $label = $isManual
                ? 'PDF manual almacenado en storage/S3'
                : 'PDF automático GDA almacenado en storage/S3';

            $pdfSource = $isManual ? 'manual' : (data_get($latest->gda_message, 'results_source') ?? 'gda');
        } elseif ($hasPdfInDb && $isStale) {
            $location = 'db_base64_stale';
            $label = 'PDF en BD desactualizado — existe notificación más reciente en GDA';
            $pdfSource = data_get($servingNotification->gda_message, 'results_source') === 'gda_api' ? 'gda_api' : 'webhook_or_legacy';
        } elseif ($hasPdfInDb) {
            $location = 'db_base64';
            $pdfSource = data_get($servingNotification->gda_message, 'results_source') === 'gda_api' ? 'gda_api' : 'webhook_or_legacy';
            $label = $pdfSource === 'gda_api'
                ? 'PDF servido desde caché en BD (obtenido vía API GDA)'
                : 'PDF almacenado en BD (webhook o carga previa)';
        } elseif ($availableAtGda) {
            $location = 'gda_provider';
            $label = 'PDF disponible en proveedor GDA (sin descargar)';
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
            'has_newer_results' => $isStale,
            'pdf_source' => $pdfSource,
            'pdf_source_label' => $this->pdfSourceLabel($pdfSource),
            'latest_results_at' => $latest->results_received_at?->toIso8601String(),
            'pdf_fetched_at' => $servingNotification->pdfFetchedAt()?->toIso8601String(),
            'last_sync_at' => $lastSyncAt,
            'last_sync_error' => $lastSyncError,
            'last_sync_error_at' => $lastSyncErrorAt,
            'last_gda_not_available_at' => $lastGdaNotAvailableAt,
            'last_gda_not_available_message' => $lastGdaNotAvailableMessage,
            'gda_consult_id' => $consultIdResolution['id'],
            'gda_consult_id_source' => $consultIdResolution['source'],
            'gda_consult_id_source_label' => $this->consultIdSourceLabel($consultIdResolution['source']),
            'results_notifications_count' => $resultsNotifications->count(),
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
            'notification_id' => null,
            'serving_notification_id' => null,
            'latest_notification_id' => null,
            'has_pdf_in_storage' => false,
            'storage_path' => null,
            'is_manual_result' => false,
            'is_gda_automatic' => false,
            'has_pdf_in_db' => false,
            'available_at_gda' => false,
            'is_stale' => false,
            'has_newer_results' => false,
            'pdf_source' => null,
            'pdf_source_label' => null,
            'latest_results_at' => null,
            'pdf_fetched_at' => null,
            'last_sync_at' => null,
            'last_sync_error' => null,
            'last_sync_error_at' => null,
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

    /**
     * @return array{id: ?string, source: string}
     */
    private function resolveConsultIdForNotification(LaboratoryNotification $notification): array
    {
        $payload = $notification->payload;

        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }

        if (! is_array($payload)) {
            return ['id' => null, 'source' => 'none'];
        }

        try {
            return app(ResolveConsultableGdaId::class)($notification->gda_order_id, $payload);
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

    private function buildEmailSummary($notifications, ?LabOrderEventState $eventState, string $tz): array
    {
        $entries = $notifications
            ->filter(fn (LaboratoryNotification $n) => $n->email_sent_at || $n->email_attempted_at || $n->email_error)
            ->map(fn (LaboratoryNotification $n) => $this->formatEmailEntry($n, $tz))
            ->values()
            ->all();

        return [
            'entries' => $entries,
            'sample_sent_count' => collect($entries)->where('type', LaboratoryNotification::TYPE_SAMPLE_COLLECTION)->where('sent', true)->count(),
            'results_sent_count' => collect($entries)->where('type', LaboratoryNotification::TYPE_RESULTS)->where('sent', true)->count(),
            'order_state' => $eventState ? [
                'sample_email_sent_at' => $this->toTimezone($eventState->sample_email_sent_at, $tz)?->toISOString(),
                'results_email_sent_at' => $this->toTimezone($eventState->results_email_sent_at, $tz)?->toISOString(),
            ] : null,
        ];
    }

    private function formatEmailEntry(LaboratoryNotification $notification, string $tz): array
    {
        $typeLabel = match ($notification->notification_type) {
            LaboratoryNotification::TYPE_SAMPLE_COLLECTION => 'Toma de muestra',
            LaboratoryNotification::TYPE_RESULTS => 'Resultados',
            default => $notification->notification_type,
        };

        return [
            'notification_id' => $notification->id,
            'type' => $notification->notification_type,
            'type_label' => $typeLabel,
            'recipient' => $notification->email_recipient_email,
            'sent' => (bool) $notification->email_sent_at,
            'sent_at' => $this->toTimezone($notification->email_sent_at, $tz)?->toISOString(),
            'attempted_at' => $this->toTimezone($notification->email_attempted_at, $tz)?->toISOString(),
            'error' => $notification->email_error,
        ];
    }

    private function buildSyncLogs($notifications, string $tz): array
    {
        return $notifications
            ->filter(fn (LaboratoryNotification $n) => $n->notification_type === LaboratoryNotification::TYPE_RESULTS)
            ->map(function (LaboratoryNotification $n) use ($tz) {
                $gdaMsg = $n->gda_message ?? [];
                $purchase = $n->laboratoryPurchase;

                $storedInStorage = ! empty($purchase?->results) && Storage::exists($purchase->results);
                $skippedManual = $storedInStorage && ! str_contains($purchase->results ?? '', 'results/gda-');
                $skippedExisting = $storedInStorage && ! $skippedManual;

                return [
                    'notification_id' => $n->id,
                    'created_at' => $this->toTimezone($n->created_at, $tz)?->toISOString(),
                    'notification_type' => $n->notification_type,
                    'gda_order_id' => $n->gda_order_id,
                    'gda_consecutivo' => $n->gda_consecutivo,
                    'gda_acuse' => $n->gda_acuse,
                    'results_received_at' => $this->toTimezone($n->results_received_at, $tz)?->toISOString(),
                    'results_source' => data_get($gdaMsg, 'results_source'),
                    'results_storage_path' => data_get($gdaMsg, 'results_storage_path'),
                    'results_fetched_at' => data_get($gdaMsg, 'results_fetched_at'),
                    'results_storage_error' => data_get($gdaMsg, 'results_storage_error'),
                    'results_storage_error_at' => data_get($gdaMsg, 'results_storage_error_at'),
                    'admin_forced_refresh_at' => data_get($gdaMsg, 'admin_forced_refresh_at'),
                    'stored_in_storage' => $storedInStorage,
                    'purchase_results_path' => $purchase?->results,
                    'skipped_manual_result' => $skippedManual,
                    'skipped_existing_result' => $skippedExisting && ! $skippedManual,
                    'email_sent_at' => $this->toTimezone($n->email_sent_at, $tz)?->toISOString(),
                    'email_recipient' => $n->email_recipient_email,
                    'email_error' => $n->email_error,
                ];
            })
            ->values()
            ->all();
    }

    private function formatNotification(LaboratoryNotification $notification, string $tz): array
    {
        $pdfLocation = 'none';
        if ($notification->notification_type === LaboratoryNotification::TYPE_RESULTS) {
            if ($notification->hasResults()) {
                $pdfLocation = 'db_base64';
            } elseif ($notification->needsPdfFetch() || $notification->hasAvailableResults()) {
                $pdfLocation = 'gda_provider';
            }
        }

        return [
            'id' => $notification->id,
            'notification_type' => $notification->notification_type,
            'status' => $notification->status,
            'gda_status' => $notification->gda_status,
            'gda_order_id' => $notification->gda_order_id,
            'gda_consecutivo' => $notification->gda_consecutivo,
            'lineanegocio' => $notification->lineanegocio,
            'created_at' => $this->toTimezone($notification->created_at, $tz)?->toISOString(),
            'results_received_at' => $this->toTimezone($notification->results_received_at, $tz)?->toISOString(),
            'email_sent_at' => $this->toTimezone($notification->email_sent_at, $tz)?->toISOString(),
            'email_attempted_at' => $this->toTimezone($notification->email_attempted_at, $tz)?->toISOString(),
            'email_recipient_email' => $notification->email_recipient_email,
            'email_error' => $notification->email_error,
            'has_pdf_in_db' => $notification->hasResults(),
            'pdf_at_gda' => $notification->needsPdfFetch(),
            'pdf_location' => $pdfLocation,
            'pdf_source' => data_get($notification->gda_message, 'results_source'),
            'pdf_fetched_at' => $notification->pdfFetchedAt()?->toIso8601String(),
            'is_stale' => $notification->notification_type === LaboratoryNotification::TYPE_RESULTS
                && $notification->hasResults()
                && $notification->shouldRefreshPdfFromGda(),
        ];
    }

    private function formatOwner(User $user): array
    {
        return [
            'id' => $user->id,
            'full_name' => $user->full_name ?? trim(($user->name ?? '').' '.($user->paternal_lastname ?? '').' '.($user->maternal_lastname ?? '')),
            'email' => $user->email,
        ];
    }

    private function toTimezone(mixed $value, string $tz): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = $value instanceof Carbon ? $value : Carbon::parse($value);

        return $date->timezone($tz);
    }
}
