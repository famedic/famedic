<?php

namespace App\Actions\Admin\Customers;

use App\Actions\Laboratories\ResolveConsultableGdaId;
use App\Models\Customer;
use App\Models\LaboratoryNotification;
use App\Models\LaboratoryPurchase;
use App\Models\User;
use App\Support\Laboratory\GdaResultsPdfStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BuildCustomerMonitorExtrasAction
{
    private const TZ = 'America/Monterrey';

    /**
     * @param  list<array<string, mixed>>  $pendingPurchases
     * @param  list<array<string, mixed>>|null  $monitoringCarts
     * @return array{
     *     pendingActivity: list<array<string, mixed>>,
     *     notificationGroups: list<array<string, mixed>>,
     *     platformAccess: array<string, mixed>|null
     * }
     */
    public function __invoke(
        User $user,
        Customer $customer,
        array $pendingPurchases,
        ?array $monitoringCarts,
    ): array {
        return [
            'pendingActivity' => $this->mergePendingActivity($pendingPurchases, $monitoringCarts ?? []),
            'notificationGroups' => $this->buildNotificationGroups($customer, $user),
            'platformAccess' => $this->resolvePlatformAccess($user),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $pendingPurchases
     * @param  list<array<string, mixed>>  $monitoringCarts
     * @return list<array<string, mixed>>
     */
    private function mergePendingActivity(array $pendingPurchases, array $monitoringCarts): array
    {
        $items = collect($pendingPurchases)->map(function (array $purchase) use ($monitoringCarts) {
            $brandValue = $purchase['brand']['value'] ?? null;
            $matchedCart = collect($monitoringCarts)->first(function (array $cart) use ($brandValue, $purchase) {
                if (($cart['type_label'] ?? '') === 'Farmacia') {
                    return ($purchase['type'] ?? '') === 'pharmacy';
                }

                return $brandValue !== null && ($cart['type_label'] ?? '') === 'Laboratorio';
            });

            return [
                ...$purchase,
                'kind' => 'laboratory_pending',
                'intent_label' => $this->resolveIntentLabel($purchase),
                'monitoring_cart' => $matchedCart,
            ];
        });

        $coveredCartIds = $items
            ->pluck('monitoring_cart.id')
            ->filter()
            ->all();

        foreach ($monitoringCarts as $cart) {
            if (in_array($cart['id'] ?? null, $coveredCartIds, true)) {
                continue;
            }

            if (($cart['display_status'] ?? '') === 'completed' || ($cart['items_count'] ?? 0) === 0) {
                continue;
            }

            $cartBrand = is_array($cart['brand'] ?? null) ? $cart['brand'] : null;

            $items->push([
                'key' => 'monitoring-cart:'.$cart['id'],
                'kind' => 'monitoring_cart',
                'type' => ($cart['type_label'] ?? '') === 'Farmacia' ? 'pharmacy' : 'laboratory',
                'brand' => $cartBrand ?? [
                    'value' => ($cart['type_label'] ?? '') === 'Farmacia' ? 'olab' : null,
                    'label' => $cart['type_label'] ?? 'Carrito',
                ],
                'status' => $cart['display_status'] ?? 'active',
                'status_label' => $cart['display_status_label'] ?? 'Activo',
                'intent_label' => $this->monitoringCartIntentLabel($cart),
                'items_count' => $cart['items_count'] ?? 0,
                'pricing' => [
                    'formatted_total' => $cart['total_formatted'] ?? '—',
                ],
                'activity' => [
                    'last_activity_at' => $cart['last_activity_at']
                        ?? (isset($cart['updated_at'])
                            ? localizedDate($cart['updated_at'])?->isoFormat('D MMM Y h:mm a')
                            : null),
                    'is_abandoned' => ($cart['display_status'] ?? '') === 'abandoned',
                    'inactive_for_label' => $cart['inactive_for_label'] ?? null,
                ],
                'monitoring_cart' => $cart,
            ]);
        }

        return $items
            ->map(function (array $row) {
                $row['last_activity_label'] = $this->resolveLastActivityLabel($row);

                return $row;
            })
            ->sortByDesc(fn (array $row) => $row['activity']['last_activity_at'] ?? $row['monitoring_cart']['updated_at'] ?? '')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function resolveLastActivityLabel(array $row): ?string
    {
        $raw = $row['activity']['last_activity_at'] ?? null;

        if (is_string($raw) && $raw !== '') {
            if (preg_match('/^\d{4}-\d{2}-\d{2}T/', $raw)) {
                return localizedDate(Carbon::parse($raw))?->isoFormat('D MMM Y h:mm a');
            }

            return $raw;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $purchase
     */
    private function resolveIntentLabel(array $purchase): string
    {
        $status = (string) ($purchase['status'] ?? '');
        $checkoutStep = (string) ($purchase['checkout']['step'] ?? '');
        $hasDraft = $checkoutStep !== '' && $checkoutStep !== 'patient';

        return match ($status) {
            'cart_saved' => $hasDraft
                ? 'Carrito guardado · revisó catálogo'
                : 'Solo agregó estudios al carrito',
            'checkout_in_progress' => 'Checkout en curso · paso '.($purchase['checkout']['step_name'] ?? $checkoutStep),
            'appointment_pending' => 'Esperando confirmación de cita',
            'payment_pending' => 'Listo para pagar',
            default => $purchase['status_label'] ?? 'Pendiente',
        };
    }

    /**
     * @param  array<string, mixed>  $cart
     */
    private function monitoringCartIntentLabel(array $cart): string
    {
        return match ($cart['display_status'] ?? '') {
            'abandoned' => 'Carrito abandonado en monitoreo',
            'empty' => 'Carrito vacío',
            'completed' => 'Carrito completado',
            default => 'Carrito activo en monitoreo',
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildNotificationGroups(Customer $customer, User $user): array
    {
        $orderKeys = $this->customerNotificationsQuery($customer, $user)
            ->selectRaw('COALESCE(gda_consecutivo, gda_order_id, CAST(laboratory_purchase_id AS CHAR)) as order_key')
            ->selectRaw('MAX(created_at) as last_at')
            ->groupBy(DB::raw('COALESCE(gda_consecutivo, gda_order_id, CAST(laboratory_purchase_id AS CHAR))'))
            ->orderByDesc('last_at')
            ->limit(8)
            ->pluck('order_key')
            ->map(fn ($key) => (string) $key)
            ->all();

        if ($orderKeys === []) {
            return [];
        }

        $notifications = $this->customerNotificationsQuery($customer, $user)
            ->where(function (Builder $query) use ($orderKeys) {
                $query->whereIn('gda_consecutivo', $orderKeys)
                    ->orWhereIn('gda_order_id', $orderKeys);

                $purchaseIds = collect($orderKeys)
                    ->filter(fn (string $key) => ctype_digit($key))
                    ->all();

                if ($purchaseIds !== []) {
                    $query->orWhereIn('laboratory_purchase_id', $purchaseIds);
                }
            })
            ->with(['laboratoryPurchase'])
            ->orderBy('created_at')
            ->get();

        return $notifications
            ->groupBy(fn (LaboratoryNotification $notification) => $this->notificationOrderKey($notification))
            ->map(fn (Collection $group, string $orderKey) => $this->formatNotificationGroup($group, $orderKey))
            ->sortByDesc(fn (array $group) => collect($group['events'])->max('timestamp') ?? 0)
            ->values()
            ->take(8)
            ->all();
    }

    private function customerNotificationsQuery(Customer $customer, User $user): Builder
    {
        return LaboratoryNotification::query()
            ->where(function (Builder $query) use ($customer, $user) {
                $query->whereHas('laboratoryPurchase', function (Builder $purchase) use ($customer) {
                    $purchase->where('customer_id', $customer->id);
                });

                $query->orWhere('user_id', $user->id)
                    ->orWhere('email_recipient_id', $user->id);
            });
    }

    private function notificationOrderKey(LaboratoryNotification $notification): string
    {
        return (string) (
            $notification->gda_consecutivo
            ?? $notification->gda_order_id
            ?? $notification->laboratory_purchase_id
            ?? ('notification-'.$notification->id)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function formatNotificationGroup(Collection $group, string $orderKey): array
    {
        /** @var LaboratoryNotification $first */
        $first = $group->first();
        $purchase = $group
            ->first(fn (LaboratoryNotification $notification) => $notification->laboratoryPurchase !== null)
            ?->laboratoryPurchase;

        $sampleNotifications = $group->filter(fn (LaboratoryNotification $n) => $this->isSampleNotification($n));
        $resultsNotifications = $group->filter(fn (LaboratoryNotification $n) => $this->isResultsNotification($n));

        $sampleAt = $sampleNotifications->min('created_at');
        $resultsNotification = $resultsNotifications
            ->sortByDesc(fn (LaboratoryNotification $n) => $n->results_received_at ?? $n->created_at)
            ->first();
        $resultsAt = $resultsNotification?->results_received_at ?? $resultsNotification?->created_at;

        $diffMinutes = ($sampleAt && $resultsAt)
            ? Carbon::parse($sampleAt)->diffInMinutes(Carbon::parse($resultsAt))
            : null;

        $folio = $this->resolveFolio($group, $purchase);
        $gdaConsecutivo = $group->pluck('gda_consecutivo')->filter()->first()
            ?: ($this->isGabineteOrder($folio) ? null : ($first->gda_order_id ?: null));

        $emailEntries = $group
            ->filter(fn (LaboratoryNotification $n) => $n->email_sent_at || $n->email_attempted_at || $n->email_error);

        return [
            'order_key' => $orderKey,
            'purchase_id' => $purchase?->id ?? $first->laboratory_purchase_id,
            'gda_consecutivo' => $gdaConsecutivo ? (string) $gdaConsecutivo : null,
            'gda_order_id' => $first->gda_order_id,
            'folio' => $folio,
            'is_gabinete' => $this->isGabineteOrder($folio ?: $first->gda_order_id),
            'brand' => $this->formatBrand($purchase),
            'count' => $group->count(),
            'summary' => [
                'sample_notifications' => $sampleNotifications->count(),
                'results_notifications' => $resultsNotifications->count(),
                'sample_at' => localizedDate($sampleAt)?->isoFormat('D MMM Y h:mm a'),
                'results_at' => localizedDate($resultsAt)?->isoFormat('D MMM Y h:mm a'),
                'diff_minutes' => $diffMinutes,
                'diff_label' => $this->formatDiffMinutes($diffMinutes),
                'emails' => [
                    'sample_sent_count' => $emailEntries
                        ->filter(fn (LaboratoryNotification $n) => $this->isSampleNotification($n) && $n->email_sent_at !== null)
                        ->count(),
                    'results_sent_count' => $emailEntries
                        ->filter(fn (LaboratoryNotification $n) => $this->isResultsNotification($n) && $n->email_sent_at !== null)
                        ->count(),
                ],
                'results_pdf' => $this->buildResultsPdfSummary($purchase, $resultsNotifications),
            ],
            'monitor_url' => route('admin.laboratory-notifications-monitor.index', [
                'search' => $gdaConsecutivo ?: ($folio ?: $orderKey),
            ]),
            'events' => $group
                ->sortBy('created_at')
                ->values()
                ->map(fn (LaboratoryNotification $notification) => $this->formatNotificationEvent($notification))
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatNotificationEvent(LaboratoryNotification $notification): array
    {
        $payload = is_array($notification->payload) ? $notification->payload : null;
        $etiqueta = data_get($payload, 'code.coding.0.infogda_muestras.0.infogda_etiqueta');

        return [
            'id' => $notification->id,
            'type' => $notification->notification_type,
            'type_label' => $this->notificationTypeLabel($notification),
            'linea_negocio' => $notification->lineanegocio,
            'status' => $notification->status,
            'gda_status' => $notification->gda_status,
            'gda_consecutivo' => $notification->gda_consecutivo,
            'gda_order_id' => $notification->gda_order_id,
            'infogda_etiqueta' => is_string($etiqueta) ? $etiqueta : null,
            'at' => localizedDate($notification->created_at)?->isoFormat('D MMM Y h:mm a'),
            'timestamp' => $notification->created_at?->timestamp ?? 0,
            'famedic_email' => [
                'sent_at' => localizedDate($notification->email_sent_at)?->isoFormat('D MMM Y h:mm a'),
                'attempted_at' => localizedDate($notification->email_attempted_at)?->isoFormat('D MMM Y h:mm a'),
                'recipient' => $notification->email_recipient_email,
                'error' => $notification->email_error,
                'notified' => $notification->email_sent_at !== null,
            ],
        ];
    }

    /**
     * @param  Collection<int, LaboratoryNotification>  $group
     */
    private function resolveFolio(Collection $group, ?LaboratoryPurchase $purchase): ?string
    {
        $resolver = app(ResolveConsultableGdaId::class);

        if ($purchase?->gda_order_id && $resolver->isConsultable($purchase->gda_order_id)) {
            return $purchase->gda_order_id;
        }

        foreach ($group as $notification) {
            $payload = is_array($notification->payload) ? $notification->payload : null;
            $etiqueta = data_get($payload, 'code.coding.0.infogda_muestras.0.infogda_etiqueta');

            if (is_string($etiqueta) && $resolver->isConsultable($etiqueta)) {
                return $etiqueta;
            }
        }

        return $purchase?->gda_order_id ?: $group->first()?->gda_order_id;
    }

    /**
     * @param  Collection<int, LaboratoryNotification>  $resultsNotifications
     * @return array<string, mixed>
     */
    private function buildResultsPdfSummary(?LaboratoryPurchase $purchase, Collection $resultsNotifications): array
    {
        if ($resultsNotifications->isEmpty()) {
            return [
                'label' => 'Sin notificaciones de resultados',
                'has_pdf_in_storage' => false,
                'available_at_gda' => false,
                'is_stale' => false,
            ];
        }

        $assessment = GdaResultsPdfStatus::assess($purchase, $resultsNotifications);

        if ($assessment->hasPdfInStorage) {
            $label = $assessment->isManual
                ? 'PDF manual en storage'
                : ($assessment->isStale
                    ? 'PDF GDA desactualizado'
                    : 'PDF GDA en storage');
        } elseif ($assessment->availableAtGda) {
            $label = 'Resultados en GDA — sin PDF en storage';
        } else {
            $label = 'Sin PDF de resultados registrado';
        }

        return [
            'label' => $label,
            'has_pdf_in_storage' => $assessment->hasPdfInStorage,
            'available_at_gda' => $assessment->availableAtGda,
            'is_stale' => $assessment->isStale,
            'freshness_status_label' => $assessment->freshnessStatusLabel,
        ];
    }

    /**
     * @return array{value: string, label: string, image_src: string}|null
     */
    private function formatBrand(?LaboratoryPurchase $purchase): ?array
    {
        $brand = $purchase?->brand;

        if ($brand === null) {
            return null;
        }

        return [
            'value' => $brand->value,
            'label' => $brand->label(),
            'image_src' => '/images/gda/'.$brand->imageSrc(),
        ];
    }

    private function isSampleNotification(LaboratoryNotification $notification): bool
    {
        return $notification->notification_type === LaboratoryNotification::TYPE_SAMPLE_COLLECTION
            || $notification->lineanegocio === LaboratoryNotification::LINEA_NEGOCIO_SAMPLE;
    }

    private function isResultsNotification(LaboratoryNotification $notification): bool
    {
        return $notification->notification_type === LaboratoryNotification::TYPE_RESULTS
            || $notification->lineanegocio === LaboratoryNotification::LINEA_NEGOCIO_RESULTS;
    }

    private function isGabineteOrder(?string $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        return (bool) preg_match('/[a-zA-Z]/', $value);
    }

    private function formatDiffMinutes(?int $minutes): ?string
    {
        if ($minutes === null) {
            return null;
        }

        if ($minutes < 60) {
            return "{$minutes} min";
        }

        $hours = intdiv($minutes, 60);
        $remainder = $minutes % 60;

        return "{$hours}h {$remainder}m";
    }

    /**
     * @return array<string, mixed>|null
     */
    private function notificationTypeLabel(LaboratoryNotification $notification): string
    {
        return match ($notification->notification_type) {
            LaboratoryNotification::TYPE_SAMPLE_COLLECTION, LaboratoryNotification::LINEA_NEGOCIO_SAMPLE => 'Toma de muestra (GDA)',
            LaboratoryNotification::TYPE_RESULTS, LaboratoryNotification::LINEA_NEGOCIO_RESULTS => 'Resultados (GDA)',
            LaboratoryNotification::TYPE_STATUS_UPDATE => 'Actualización de estado',
            default => $notification->lineanegocio
                ?? $notification->notification_type
                ?? 'Notificación GDA',
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolvePlatformAccess(User $user): ?array
    {
        $lastLogin = $user->last_login_at
            ? localizedDate($user->last_login_at)?->setTimezone(self::TZ)
            : null;

        $lastSessionTimestamp = DB::table('sessions')
            ->where('user_id', $user->id)
            ->max('last_activity');

        $lastSession = $lastSessionTimestamp
            ? Carbon::createFromTimestamp((int) $lastSessionTimestamp, self::TZ)
            : null;

        $lastAccess = collect([$lastLogin, $lastSession])
            ->filter()
            ->max();

        if ($lastAccess === null) {
            return null;
        }

        return [
            'last_access_at' => $lastAccess->isoFormat('D MMM Y h:mm a'),
            'last_login_at' => $lastLogin?->isoFormat('D MMM Y h:mm a'),
            'last_session_at' => $lastSession?->isoFormat('D MMM Y h:mm a'),
            'source' => $lastLogin && $lastSession
                ? ($lastLogin->gte($lastSession) ? 'login' : 'session')
                : ($lastLogin ? 'login' : 'session'),
        ];
    }
}
