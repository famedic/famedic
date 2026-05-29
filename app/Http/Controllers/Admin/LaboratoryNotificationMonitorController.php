<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Laboratories\GetGDAResultsAction;
use App\Http\Controllers\Controller;
use App\Models\LabOrderEventState;
use App\Models\LaboratoryNotification;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        $ownersMap = LaboratoryNotification::query()
            ->where(function (Builder $query) use ($orderKeys) {
                $query->whereIn('gda_consecutivo', $orderKeys)
                    ->orWhereIn('gda_order_id', $orderKeys);
            })
            ->with([
                'user',
                'laboratoryPurchase.customer.user',
            ])
            ->get()
            ->groupBy(fn (LaboratoryNotification $n) => (string) ($n->gda_consecutivo ?? $n->gda_order_id))
            ->map(function ($group) {
                $n = $group->first();
                $user = $n->user ?: $n->laboratoryPurchase?->customer?->user;

                return $user ? $this->formatOwner($user) : null;
            });

        $orders->getCollection()->transform(function ($row) use ($ownersMap) {
            $row['owner'] = $ownersMap[$row['order_key']] ?? null;

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

        if ($notification->hasResults()) {
            return response()->json([
                'success' => true,
                'cached' => true,
                'message' => 'El PDF ya estaba almacenado en la base de datos.',
                'pdf_base64' => $notification->results_pdf_base64,
                'results_pdf' => $this->buildResultsPdfSummary($resultsNotifications),
            ]);
        }

        if (! $notification->hasAvailableResults() && ! $notification->needsPdfFetch()) {
            return response()->json([
                'success' => false,
                'message' => 'Los resultados aún no están disponibles en GDA.',
            ], 422);
        }

        try {
            $response = app(GetGDAResultsAction::class)(
                $notification->gda_order_id,
                $notification->payload
            );
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error consultando GDA: '.$e->getMessage(),
            ], 500);
        }

        $pdf = $response['infogda_resultado_b64'] ?? null;

        if (! $pdf) {
            return response()->json([
                'success' => false,
                'message' => 'GDA no devolvió el PDF de resultados.',
            ], 500);
        }

        $notification->update([
            'results_pdf_base64' => $pdf,
        ]);

        $notification->refresh();
        $resultsNotifications = $this->notificationsForOrder($orderKey)
            ->where('notification_type', LaboratoryNotification::TYPE_RESULTS)
            ->values();

        return response()->json([
            'success' => true,
            'cached' => false,
            'message' => 'PDF obtenido desde GDA y guardado en la base de datos.',
            'pdf_base64' => $pdf,
            'results_pdf' => $this->buildResultsPdfSummary($resultsNotifications),
        ]);
    }

    public function downloadResults(Request $request, string $orderKey)
    {
        $request->user()->administrator->hasPermissionTo('laboratory-notifications.monitor') || abort(403);

        $notification = $this->notificationsForOrder($orderKey)
            ->where('notification_type', LaboratoryNotification::TYPE_RESULTS)
            ->sortByDesc('created_at')
            ->first();

        if (! $notification || ! $notification->hasResults()) {
            abort(404, 'PDF no disponible en la base de datos.');
        }

        $pdfContent = base64_decode($notification->results_pdf_base64);
        $filename = 'resultados_'.($notification->gda_consecutivo ?? $notification->gda_order_id ?? $orderKey).'.pdf';

        return response($pdfContent)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="'.$filename.'"');
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
        $latest = $resultsNotifications->sortByDesc('created_at')->first();

        if (! $latest) {
            return [
                'location' => 'none',
                'label' => 'Sin resultados recibidos',
                'notification_id' => null,
                'has_pdf_in_db' => false,
                'available_at_gda' => false,
            ];
        }

        if ($latest->hasResults()) {
            return [
                'location' => 'db_base64',
                'label' => 'PDF almacenado en base de datos (base64)',
                'notification_id' => $latest->id,
                'has_pdf_in_db' => true,
                'available_at_gda' => false,
            ];
        }

        if ($latest->needsPdfFetch() || $latest->hasAvailableResults()) {
            return [
                'location' => 'gda_provider',
                'label' => 'PDF disponible en proveedor GDA (no descargado a la BD)',
                'notification_id' => $latest->id,
                'has_pdf_in_db' => false,
                'available_at_gda' => true,
            ];
        }

        return [
            'location' => 'none',
            'label' => 'Sin PDF de resultados registrado',
            'notification_id' => $latest->id,
            'has_pdf_in_db' => false,
            'available_at_gda' => false,
        ];
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
