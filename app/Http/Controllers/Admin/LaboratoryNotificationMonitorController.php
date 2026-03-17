<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LaboratoryNotification;
use Carbon\Carbon;
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

        // Serie diaria (notificaciones recibidas por día)
        $dailyRows = LaboratoryNotification::query()
            ->whereBetween('created_at', [$startDate, $endDate])
            ->whereNotNull('gda_order_id')
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

        // Agrupación por orden (gda_order_id)
        $orders = LaboratoryNotification::query()
            ->whereBetween('created_at', [$startDate, $endDate])
            ->whereNotNull('gda_order_id')
            ->select('gda_order_id')
            ->selectRaw('MIN(CASE WHEN notification_type = ? THEN created_at END) as sample_at', [LaboratoryNotification::TYPE_SAMPLE_COLLECTION])
            ->selectRaw('MIN(CASE WHEN notification_type = ? THEN COALESCE(results_received_at, created_at) END) as results_at', [LaboratoryNotification::TYPE_RESULTS])
            ->selectRaw('SUM(CASE WHEN notification_type = ? THEN 1 ELSE 0 END) as sample_notifications', [LaboratoryNotification::TYPE_SAMPLE_COLLECTION])
            ->selectRaw('SUM(CASE WHEN notification_type = ? THEN 1 ELSE 0 END) as results_notifications', [LaboratoryNotification::TYPE_RESULTS])
            ->selectRaw('MAX(user_id) as user_id')
            ->selectRaw('MAX(laboratory_purchase_id) as laboratory_purchase_id')
            ->groupBy('gda_order_id')
            ->orderByRaw('COALESCE(results_at, sample_at) DESC')
            ->paginate(25)
            ->withQueryString();

        // Cargar relaciones de forma eficiente (sin N+1)
        $orders->getCollection()->transform(function ($row) use ($tz) {
            $sampleAt = $row->sample_at ? Carbon::parse($row->sample_at, 'UTC')->timezone($tz) : null;
            $resultsAt = $row->results_at ? Carbon::parse($row->results_at, 'UTC')->timezone($tz) : null;

            $diffMinutes = null;
            if ($sampleAt && $resultsAt) {
                $diffMinutes = $sampleAt->diffInMinutes($resultsAt);
            }

            return [
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

        // Cargar usuario y purchase/customer/user para mostrar dueño
        $orderIds = collect($orders->items())->pluck('gda_order_id')->all();

        $ownersMap = LaboratoryNotification::query()
            ->whereIn('gda_order_id', $orderIds)
            ->with([
                'user',
                'laboratoryPurchase.customer.user',
            ])
            ->get()
            ->groupBy('gda_order_id')
            ->map(function ($group) {
                $n = $group->first();
                $user = $n->user ?: $n->laboratoryPurchase?->customer?->user;
                return $user ? [
                    'id' => $user->id,
                    'full_name' => $user->full_name ?? trim(($user->name ?? '') . ' ' . ($user->paternal_lastname ?? '') . ' ' . ($user->maternal_lastname ?? '')),
                    'email' => $user->email,
                ] : null;
            });

        $orders->getCollection()->transform(function ($row) use ($ownersMap) {
            $row['owner'] = $ownersMap[$row['gda_order_id']] ?? null;
            return $row;
        });

        return Inertia::render('Admin/LaboratoryNotificationsMonitor', [
            'filters' => [
                'start_date' => $startDate->timezone($tz)->toDateString(),
                'end_date' => $endDate->timezone($tz)->toDateString(),
            ],
            'dailyChart' => $dailyChart,
            'orders' => $orders,
        ]);
    }

    public function show(Request $request, string $gdaOrderId)
    {
        $request->user()->administrator->hasPermissionTo('laboratory-notifications.monitor') || abort(403);

        $tz = config('app.timezone', 'UTC');

        $notifications = LaboratoryNotification::query()
            ->where('gda_order_id', $gdaOrderId)
            ->with(['user', 'contact', 'laboratoryPurchase.customer.user', 'laboratoryQuote'])
            ->orderBy('created_at')
            ->get();

        $sampleAt = $notifications->firstWhere('notification_type', LaboratoryNotification::TYPE_SAMPLE_COLLECTION)?->created_at;
        $resultsNotification = $notifications->firstWhere('notification_type', LaboratoryNotification::TYPE_RESULTS);
        $resultsAt = $resultsNotification?->results_received_at ?: $resultsNotification?->created_at;

        $sampleAtTz = $sampleAt ? $sampleAt->copy()->timezone($tz) : null;
        $resultsAtTz = $resultsAt ? Carbon::parse($resultsAt, 'UTC')->timezone($tz) : null;

        $diffMinutes = null;
        if ($sampleAtTz && $resultsAtTz) {
            $diffMinutes = $sampleAtTz->diffInMinutes($resultsAtTz);
        }

        $owner = $notifications->first()?->user ?: $notifications->first()?->laboratoryPurchase?->customer?->user;

        return Inertia::render('Admin/LaboratoryNotificationsMonitorShow', [
            'gdaOrderId' => $gdaOrderId,
            'owner' => $owner ? [
                'id' => $owner->id,
                'full_name' => $owner->full_name ?? trim(($owner->name ?? '') . ' ' . ($owner->paternal_lastname ?? '') . ' ' . ($owner->maternal_lastname ?? '')),
                'email' => $owner->email,
            ] : null,
            'summary' => [
                'sample_at' => $sampleAtTz?->toISOString(),
                'results_at' => $resultsAtTz?->toISOString(),
                'diff_minutes' => $diffMinutes,
                'sample_notifications' => $notifications->where('notification_type', LaboratoryNotification::TYPE_SAMPLE_COLLECTION)->count(),
                'results_notifications' => $notifications->where('notification_type', LaboratoryNotification::TYPE_RESULTS)->count(),
                'total_notifications' => $notifications->count(),
            ],
            'notifications' => $notifications->map(function (LaboratoryNotification $n) use ($tz) {
                return [
                    'id' => $n->id,
                    'notification_type' => $n->notification_type,
                    'status' => $n->status,
                    'gda_status' => $n->gda_status,
                    'gda_order_id' => $n->gda_order_id,
                    'created_at' => $n->created_at?->copy()->timezone($tz)->toISOString(),
                    'results_received_at' => $n->results_received_at?->copy()->timezone($tz)->toISOString(),
                    'email_sent_at' => $n->email_sent_at?->copy()->timezone($tz)->toISOString(),
                    'email_error' => $n->email_error,
                    'lineanegocio' => $n->lineanegocio,
                ];
            })->values(),
        ]);
    }
}

