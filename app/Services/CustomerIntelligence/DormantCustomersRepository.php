<?php

namespace App\Services\CustomerIntelligence;

use App\Data\StatesMexico;
use App\Models\Customer;
use App\Models\FamilyAccount;
use App\Models\OdessaAfiliateAccount;
use App\Models\RegularAccount;
use App\Support\CustomerIntelligence\DormantCustomersFilter;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DormantCustomersRepository
{
    /**
     * Clientes registrados sin ninguna compra (lab, farmacia, membresía).
     */
    public function dormantBaseQuery(DormantCustomersFilter $filter, ?Carbon $from = null, ?Carbon $to = null): Builder
    {
        $query = Customer::query()
            ->whereDoesntHave('laboratoryPurchases')
            ->whereDoesntHave('onlinePharmacyPurchases')
            ->whereDoesntHave('medicalAttentionSubscriptions');

        $from ??= $filter->start;
        $to ??= $filter->end;

        $query->whereBetween('customers.created_at', [$from, $to]);

        $this->applyFilters($query, $filter);

        return $query;
    }

    public function applyFilters(Builder $query, DormantCustomersFilter $filter): void
    {
        $query
            ->when($filter->search, function (Builder $q, string $search) {
                $q->where(function (Builder $inner) use ($search) {
                    $inner->whereHas('user', function (Builder $user) use ($search) {
                        $user->where(function (Builder $u) use ($search) {
                            $u->where('name', 'like', "%{$search}%")
                                ->orWhere('paternal_lastname', 'like', "%{$search}%")
                                ->orWhere('maternal_lastname', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%")
                                ->orWhere('phone', 'like', "%{$search}%");
                        });
                    })->orWhereHasMorph('customerable', [FamilyAccount::class], function (Builder $fa) use ($search) {
                        $fa->where('name', 'like', "%{$search}%")
                            ->orWhere('paternal_lastname', 'like', "%{$search}%")
                            ->orWhere('maternal_lastname', 'like', "%{$search}%");
                    });
                });
            })
            ->when($filter->accountType, function (Builder $q, string $type) {
                $map = [
                    'regular' => RegularAccount::class,
                    'odessa' => OdessaAfiliateAccount::class,
                    'familiar' => FamilyAccount::class,
                ];
                if (isset($map[$type])) {
                    $q->where('customerable_type', $map[$type]);
                }
            })
            ->when($filter->city, function (Builder $q, string $city) {
                $q->whereHas('addresses', fn (Builder $a) => $a->where('city', $city));
            })
            ->when($filter->state, function (Builder $q, string $state) {
                $q->where(function (Builder $inner) use ($state) {
                    $inner->whereHas('user', fn (Builder $u) => $u->where('state', $state))
                        ->orWhereHas('addresses', fn (Builder $a) => $a->where('state', $state));
                });
            })
            ->when($filter->emailVerification === 'verified', fn (Builder $q) => $q->whereHas('user', fn (Builder $u) => $u->whereNotNull('email_verified_at')))
            ->when($filter->emailVerification === 'unverified', fn (Builder $q) => $q->whereHas('user', fn (Builder $u) => $u->whereNull('email_verified_at')))
            ->when($filter->phoneVerification === 'verified', fn (Builder $q) => $q->whereHas('user', fn (Builder $u) => $u->whereNotNull('phone_verified_at')))
            ->when($filter->phoneVerification === 'unverified', fn (Builder $q) => $q->whereHas('user', fn (Builder $u) => $u->whereNull('phone_verified_at')))
            ->when($filter->referralStatus === 'referred', fn (Builder $q) => $q->whereHas('user', fn (Builder $u) => $u->whereNotNull('referred_by')))
            ->when($filter->referralStatus === 'not_referred', fn (Builder $q) => $q->whereHas('user', fn (Builder $u) => $u->whereNull('referred_by')))
            ->when($filter->registrationSource, function (Builder $q, string $source) {
                match ($source) {
                    'referred' => $q->whereHas('user', fn (Builder $u) => $u->whereNotNull('referred_by')),
                    'odessa' => $q->where('customerable_type', OdessaAfiliateAccount::class),
                    'familiar' => $q->where('customerable_type', FamilyAccount::class),
                    'organico' => $q->where('customerable_type', RegularAccount::class)
                        ->whereHas('user', fn (Builder $u) => $u->whereNull('referred_by')),
                    default => null,
                };
            })
            ->when($filter->daysBucket, function (Builder $q, string $bucket) {
                $now = now();
                match ($bucket) {
                    '0-7' => $q->where('customers.created_at', '>=', $now->copy()->subDays(7)),
                    '8-30' => $q->whereBetween('customers.created_at', [$now->copy()->subDays(30), $now->copy()->subDays(8)]),
                    '31-60' => $q->whereBetween('customers.created_at', [$now->copy()->subDays(60), $now->copy()->subDays(31)]),
                    '61-90' => $q->whereBetween('customers.created_at', [$now->copy()->subDays(90), $now->copy()->subDays(61)]),
                    '90+' => $q->where('customers.created_at', '<', $now->copy()->subDays(90)),
                    default => null,
                };
            });
    }

    public function countDormant(DormantCustomersFilter $filter, ?Carbon $from = null, ?Carbon $to = null): int
    {
        return (int) $this->dormantBaseQuery($filter, $from, $to)->count();
    }

    public function averageDaysSinceRegistration(DormantCustomersFilter $filter): ?float
    {
        $avg = $this->dormantBaseQuery($filter)
            ->selectRaw('AVG(TIMESTAMPDIFF(DAY, customers.created_at, UTC_TIMESTAMP())) as avg_days')
            ->value('avg_days');

        return $avg !== null ? round((float) $avg, 1) : null;
    }

    /**
     * Tiempo promedio registro → primera compra (clientes que sí compraron).
     */
    public function averageDaysToFirstPurchase(DormantCustomersFilter $filter): ?float
    {
        $sub = $this->firstPurchaseSubquery();

        $avg = DB::table('customers')
            ->joinSub($sub, 'fp', 'fp.customer_id', '=', 'customers.id')
            ->whereBetween('customers.created_at', [$filter->start, $filter->end])
            ->selectRaw('AVG(TIMESTAMPDIFF(DAY, customers.created_at, fp.first_purchase_at)) as avg_days')
            ->value('avg_days');

        return $avg !== null ? round((float) $avg, 1) : null;
    }

    /**
     * Clientes que realizaron su primera compra en el periodo.
     */
    public function recoveredCount(DormantCustomersFilter $filter, ?Carbon $from = null, ?Carbon $to = null): int
    {
        $from ??= $filter->start;
        $to ??= $filter->end;
        $sub = $this->firstPurchaseSubquery();

        return (int) DB::table('customers')
            ->joinSub($sub, 'fp', 'fp.customer_id', '=', 'customers.id')
            ->whereBetween('fp.first_purchase_at', [$from, $to])
            ->count();
    }

    public function averageTicketCents(): float
    {
        $lab = (float) (DB::table('laboratory_purchases')->avg('total_cents') ?? 0);
        $pharmacy = (float) (DB::table('online_pharmacy_purchases')->avg('total_cents') ?? 0);
        $membership = (float) (DB::table('medical_attention_subscriptions')->avg('price_cents') ?? 0);

        $parts = array_filter([$lab, $pharmacy, $membership], fn ($v) => $v > 0);

        if ($parts === []) {
            return 0.0;
        }

        return array_sum($parts) / count($parts);
    }

    public function historicalConversionPercent(DormantCustomersFilter $filter): ?float
    {
        $registered = (int) Customer::query()
            ->whereBetween('created_at', [$filter->start, $filter->end])
            ->count();

        if ($registered === 0) {
            return null;
        }

        $converted = (int) Customer::query()
            ->whereBetween('created_at', [$filter->start, $filter->end])
            ->where(function (Builder $q) {
                $q->whereHas('laboratoryPurchases')
                    ->orWhereHas('onlinePharmacyPurchases')
                    ->orWhereHas('medicalAttentionSubscriptions');
            })
            ->count();

        return round(($converted / $registered) * 100, 1);
    }

    /**
     * @return list<array{date: string, label: string, value: int}>
     */
    public function dormantEvolution(DormantCustomersFilter $filter): array
    {
        $format = match ($filter->granularity) {
            'week' => '%x-W%v',
            'month' => '%Y-%m',
            'year' => '%Y',
            default => '%Y-%m-%d',
        };

        $rows = $this->dormantBaseQuery($filter)
            ->selectRaw("DATE_FORMAT(CONVERT_TZ(customers.created_at, '+00:00', '-06:00'), '{$format}') as period_key, COUNT(*) as cnt")
            ->groupBy('period_key')
            ->orderBy('period_key')
            ->pluck('cnt', 'period_key');

        return $rows->map(fn ($cnt, $key) => [
            'date' => (string) $key,
            'label' => (string) $key,
            'value' => (int) $cnt,
        ])->values()->all();
    }

    /**
     * @return list<array{label: string, value: int, key: string}>
     */
    public function daysSinceRegistrationBuckets(DormantCustomersFilter $filter): array
    {
        $rows = $this->dormantBaseQuery($filter)
            ->selectRaw("
                CASE
                    WHEN TIMESTAMPDIFF(DAY, customers.created_at, UTC_TIMESTAMP()) <= 7 THEN '0-7'
                    WHEN TIMESTAMPDIFF(DAY, customers.created_at, UTC_TIMESTAMP()) <= 30 THEN '8-30'
                    WHEN TIMESTAMPDIFF(DAY, customers.created_at, UTC_TIMESTAMP()) <= 60 THEN '31-60'
                    WHEN TIMESTAMPDIFF(DAY, customers.created_at, UTC_TIMESTAMP()) <= 90 THEN '61-90'
                    ELSE '90+'
                END as bucket,
                COUNT(*) as cnt
            ")
            ->groupByRaw("
                CASE
                    WHEN TIMESTAMPDIFF(DAY, customers.created_at, UTC_TIMESTAMP()) <= 7 THEN '0-7'
                    WHEN TIMESTAMPDIFF(DAY, customers.created_at, UTC_TIMESTAMP()) <= 30 THEN '8-30'
                    WHEN TIMESTAMPDIFF(DAY, customers.created_at, UTC_TIMESTAMP()) <= 60 THEN '31-60'
                    WHEN TIMESTAMPDIFF(DAY, customers.created_at, UTC_TIMESTAMP()) <= 90 THEN '61-90'
                    ELSE '90+'
                END
            ")
            ->pluck('cnt', 'bucket');

        $order = [
            '0-7' => '0–7 días',
            '8-30' => '8–30 días',
            '31-60' => '31–60 días',
            '61-90' => '61–90 días',
            '90+' => '90+ días',
        ];

        return collect($order)->map(fn (string $label, string $key) => [
            'key' => $key,
            'label' => $label,
            'value' => (int) ($rows[$key] ?? 0),
        ])->values()->all();
    }

    /**
     * Fuente derivada: referido / odessa / familiar / orgánico.
     *
     * @return list<array{label: string, value: int, key: string}>
     */
    public function byRegistrationSource(DormantCustomersFilter $filter): array
    {
        $base = $this->dormantBaseQuery($filter)
            ->leftJoin('users', 'users.id', '=', 'customers.user_id')
            ->selectRaw("
                CASE
                    WHEN users.referred_by IS NOT NULL THEN 'referred'
                    WHEN customers.customerable_type = ? THEN 'odessa'
                    WHEN customers.customerable_type = ? THEN 'familiar'
                    ELSE 'organico'
                END as source_key,
                COUNT(*) as cnt
            ", [OdessaAfiliateAccount::class, FamilyAccount::class])
            ->groupBy('source_key')
            ->pluck('cnt', 'source_key');

        $labels = [
            'organico' => 'Orgánico / Web',
            'referred' => 'Referidos',
            'odessa' => 'Odessa',
            'familiar' => 'Familiar',
        ];

        return collect($labels)->map(fn (string $label, string $key) => [
            'key' => $key,
            'label' => $label,
            'value' => (int) ($base[$key] ?? 0),
        ])->filter(fn (array $row) => $row['value'] > 0)->values()->all();
    }

    /**
     * Embudo aproximado con datos disponibles en Famedic.
     *
     * @return list<array{stage: string, label: string, value: int, dropoff_percent: float|null}>
     */
    public function conversionFunnel(DormantCustomersFilter $filter): array
    {
        $registered = (int) Customer::query()
            ->whereBetween('created_at', [$filter->start, $filter->end])
            ->count();

        $emailVerified = (int) Customer::query()
            ->whereBetween('customers.created_at', [$filter->start, $filter->end])
            ->whereHas('user', fn (Builder $u) => $u->whereNotNull('email_verified_at'))
            ->count();

        $withCart = (int) Customer::query()
            ->whereBetween('customers.created_at', [$filter->start, $filter->end])
            ->where(function (Builder $q) {
                $q->whereHas('laboratoryCartItems')
                    ->orWhereHas('onlinePharmacyCartItems');
            })
            ->count();

        $withCheckout = (int) Customer::query()
            ->whereBetween('customers.created_at', [$filter->start, $filter->end])
            ->whereHas('laboratoryCheckoutDrafts')
            ->count();

        $purchased = (int) Customer::query()
            ->whereBetween('customers.created_at', [$filter->start, $filter->end])
            ->where(function (Builder $q) {
                $q->whereHas('laboratoryPurchases')
                    ->orWhereHas('onlinePharmacyPurchases')
                    ->orWhereHas('medicalAttentionSubscriptions');
            })
            ->count();

        $stages = [
            ['stage' => 'registration', 'label' => 'Registro', 'value' => $registered],
            ['stage' => 'email_verified', 'label' => 'Email verificado', 'value' => $emailVerified],
            ['stage' => 'explored', 'label' => 'Agregó al carrito', 'value' => $withCart],
            ['stage' => 'checkout', 'label' => 'Inició checkout', 'value' => $withCheckout],
            ['stage' => 'purchase', 'label' => 'Primera compra', 'value' => $purchased],
        ];

        $result = [];
        $prev = null;
        foreach ($stages as $stage) {
            $dropoff = null;
            if ($prev !== null && $prev > 0) {
                $dropoff = round((($prev - $stage['value']) / $prev) * 100, 1);
            }
            $result[] = [
                ...$stage,
                'dropoff_percent' => $dropoff,
            ];
            $prev = $stage['value'];
        }

        return $result;
    }

    /**
     * @return list<array{label: string, value: int, key: string}>
     */
    public function byState(DormantCustomersFilter $filter): array
    {
        $rows = $this->dormantBaseQuery($filter)
            ->join('users', 'users.id', '=', 'customers.user_id')
            ->whereNotNull('users.state')
            ->where('users.state', '!=', '')
            ->selectRaw('users.state as state_key, COUNT(*) as cnt')
            ->groupBy('users.state')
            ->orderByDesc('cnt')
            ->limit(12)
            ->get();

        return $rows->map(fn ($row) => [
            'key' => $row->state_key,
            'label' => StatesMexico::obtenerNombre($row->state_key) ?? $row->state_key,
            'value' => (int) $row->cnt,
        ])->all();
    }

    /**
     * @return list<array{label: string, value: int, key: string}>
     */
    public function byCity(DormantCustomersFilter $filter): array
    {
        $rows = $this->dormantBaseQuery($filter)
            ->join('addresses', 'addresses.customer_id', '=', 'customers.id')
            ->whereNotNull('addresses.city')
            ->where('addresses.city', '!=', '')
            ->selectRaw('addresses.city as city_key, COUNT(DISTINCT customers.id) as cnt')
            ->groupBy('addresses.city')
            ->orderByDesc('cnt')
            ->limit(12)
            ->get();

        return $rows->map(fn ($row) => [
            'key' => $row->city_key,
            'label' => $row->city_key,
            'value' => (int) $row->cnt,
        ])->all();
    }

    /**
     * @return array<string, int>
     */
    public function segmentCounts(DormantCustomersFilter $filter): array
    {
        $now = now();

        $base = fn () => Customer::query()
            ->whereDoesntHave('laboratoryPurchases')
            ->whereDoesntHave('onlinePharmacyPurchases')
            ->whereDoesntHave('medicalAttentionSubscriptions');

        return [
            'registered_7d' => (int) $base()->where('created_at', '>=', $now->copy()->subDays(7))->count(),
            'registered_15d' => (int) $base()->where('created_at', '>=', $now->copy()->subDays(15))->count(),
            'registered_30d' => (int) $base()->where('created_at', '>=', $now->copy()->subDays(30))->count(),
            'abandoned_cart' => (int) $base()
                ->where(function (Builder $q) {
                    $q->whereHas('laboratoryCartItems')
                        ->orWhereHas('onlinePharmacyCartItems')
                        ->orWhereHas('laboratoryCheckoutDrafts');
                })
                ->count(),
            'unverified_email' => (int) $base()->whereHas('user', fn (Builder $u) => $u->whereNull('email_verified_at'))->count(),
            'unverified_phone' => (int) $base()->whereHas('user', fn (Builder $u) => $u->whereNull('phone_verified_at'))->count(),
            'verified_both' => (int) $base()->whereHas('user', function (Builder $u) {
                $u->whereNotNull('email_verified_at')->whereNotNull('phone_verified_at');
            })->count(),
            'visited_lab_cart' => (int) $base()->whereHas('laboratoryCartItems')->count(),
            'visited_pharmacy_cart' => (int) $base()->whereHas('onlinePharmacyCartItems')->count(),
            'referred' => (int) $base()->whereHas('user', fn (Builder $u) => $u->whereNotNull('referred_by'))->count(),
            'no_login_proxy_30d' => (int) $base()->where('customers.updated_at', '<', $now->copy()->subDays(30))->count(),
            'period_dormant' => $this->countDormant($filter),
        ];
    }

    /**
     * @return array{avg_reg_to_cart: float|null, avg_cart_to_purchase: float|null, avg_reg_to_purchase: float|null}
     */
    public function advancedTimingMetrics(DormantCustomersFilter $filter): array
    {
        return [
            'avg_reg_to_purchase' => $this->averageDaysToFirstPurchase($filter),
            'avg_reg_to_cart' => null,
            'avg_cart_to_purchase' => null,
        ];
    }

    /**
     * @return array{by_source: list<array{label: string, conversion: float, dormant: int, converted: int}>, best: ?array, worst: ?array}
     */
    public function sourceConversion(DormantCustomersFilter $filter): array
    {
        $sources = [
            'organico' => ['label' => 'Orgánico / Web', 'type' => RegularAccount::class, 'referred' => false],
            'referred' => ['label' => 'Referidos', 'type' => null, 'referred' => true],
            'odessa' => ['label' => 'Odessa', 'type' => OdessaAfiliateAccount::class, 'referred' => null],
            'familiar' => ['label' => 'Familiar', 'type' => FamilyAccount::class, 'referred' => null],
        ];

        $rows = [];
        foreach ($sources as $key => $meta) {
            $registeredQuery = Customer::query()->whereBetween('created_at', [$filter->start, $filter->end]);
            $convertedQuery = Customer::query()
                ->whereBetween('created_at', [$filter->start, $filter->end])
                ->where(function (Builder $q) {
                    $q->whereHas('laboratoryPurchases')
                        ->orWhereHas('onlinePharmacyPurchases')
                        ->orWhereHas('medicalAttentionSubscriptions');
                });

            if ($meta['type']) {
                $registeredQuery->where('customerable_type', $meta['type']);
                $convertedQuery->where('customerable_type', $meta['type']);
            }
            if ($meta['referred'] === true) {
                $registeredQuery->whereHas('user', fn (Builder $u) => $u->whereNotNull('referred_by'));
                $convertedQuery->whereHas('user', fn (Builder $u) => $u->whereNotNull('referred_by'));
            } elseif ($meta['referred'] === false) {
                $registeredQuery->whereHas('user', fn (Builder $u) => $u->whereNull('referred_by'));
                $convertedQuery->whereHas('user', fn (Builder $u) => $u->whereNull('referred_by'));
            }

            $registered = (int) $registeredQuery->count();
            $converted = (int) $convertedQuery->count();
            $dormant = max(0, $registered - $converted);

            if ($registered === 0) {
                continue;
            }

            $rows[] = [
                'key' => $key,
                'label' => $meta['label'],
                'registered' => $registered,
                'converted' => $converted,
                'dormant' => $dormant,
                'conversion' => round(($converted / $registered) * 100, 1),
            ];
        }

        $sorted = collect($rows)->sortByDesc('conversion')->values();

        return [
            'by_source' => $sorted->all(),
            'best' => $sorted->first(),
            'worst' => $sorted->last(),
        ];
    }

    public function paginateDormant(DormantCustomersFilter $filter, int $perPage = 20): LengthAwarePaginator
    {
        return $this->dormantBaseQuery($filter)
            ->with([
                'user.referrer',
                'customerable',
                'addresses' => fn ($q) => $q->latest()->limit(1),
            ])
            ->withCount([
                'laboratoryCartItems',
                'onlinePharmacyCartItems',
                'laboratoryCheckoutDrafts',
            ])
            ->latest('customers.created_at')
            ->paginate($perPage)
            ->withQueryString()
            ->through(function (Customer $customer) {
                $days = $customer->created_at
                    ? (int) $customer->created_at->diffInDays(now())
                    : 0;

                $address = $customer->addresses->first();
                $source = $this->resolveSource($customer);
                $leadScore = $this->estimateLeadScore($customer, $days);
                $aiProbability = min(95, max(5, $leadScore));

                return [
                    'id' => $customer->id,
                    'avatar' => $customer->user?->profile_photo_url ?? null,
                    'name' => $this->resolveName($customer),
                    'email' => $this->resolveEmail($customer),
                    'phone' => $this->resolvePhone($customer),
                    'city' => $address?->city,
                    'state' => $customer->user?->state
                        ? (StatesMexico::obtenerNombre($customer->user->state) ?? $customer->user->state)
                        : ($address?->state),
                    'registered_at' => $customer->formatted_created_at,
                    'registered_at_raw' => $customer->created_at?->toIso8601String(),
                    'days_without_purchase' => $days,
                    'last_activity_at' => localizedDate($customer->updated_at)?->isoFormat('D MMM Y h:mm a'),
                    'registration_source' => $source['label'],
                    'registration_source_key' => $source['key'],
                    'account_type' => $customer->formatted_account_type ?? null,
                    'laboratory_cart_items_count' => $customer->laboratory_cart_items_count,
                    'pharmacy_cart_items_count' => $customer->online_pharmacy_cart_items_count,
                    'checkout_attempts' => $customer->laboratory_checkout_drafts_count,
                    'abandoned_carts' => ($customer->laboratory_cart_items_count + $customer->online_pharmacy_cart_items_count) > 0 ? 1 : 0,
                    'products_viewed_proxy' => $customer->laboratory_cart_items_count + $customer->online_pharmacy_cart_items_count,
                    'email_verified' => (bool) $customer->user?->email_verified_at,
                    'phone_verified' => (bool) $customer->user?->phone_verified_at,
                    'lead_score' => $leadScore,
                    'ai_probability' => $aiProbability,
                    'gender' => $customer->user?->formatted_gender ?? $customer->customerable?->formatted_gender ?? null,
                    'birth_date' => $customer->user?->formatted_birth_date ?? $customer->customerable?->formatted_birth_date ?? null,
                    'show_url' => route('admin.customers.show', $customer),
                ];
            });
    }

    /**
     * @return array{general: array, timeline: list<array>, activity: array, purchases: array, activecampaign: array, ai: array}
     */
    public function customerDrawer(Customer $customer): array
    {
        $customer->loadMissing([
            'user.referrer',
            'customerable',
            'addresses',
            'laboratoryCartItems.laboratoryTest',
            'onlinePharmacyCartItems',
            'laboratoryCheckoutDrafts',
        ]);

        $days = $customer->created_at ? (int) $customer->created_at->diffInDays(now()) : 0;
        $leadScore = $this->estimateLeadScore($customer, $days);
        $aiProbability = min(95, max(5, $leadScore));
        $address = $customer->addresses->first();
        $source = $this->resolveSource($customer);

        $timeline = [
            [
                'type' => 'registration',
                'label' => 'Registro',
                'at' => $customer->formatted_created_at,
                'detail' => 'Alta en plataforma · '.$source['label'],
            ],
        ];

        if ($customer->user?->email_verified_at) {
            $timeline[] = [
                'type' => 'email_verified',
                'label' => 'Email verificado',
                'at' => localizedDate($customer->user->email_verified_at)?->isoFormat('D MMM Y h:mm a'),
                'detail' => $customer->user->email,
            ];
        }

        if ($customer->user?->phone_verified_at) {
            $timeline[] = [
                'type' => 'phone_verified',
                'label' => 'Teléfono verificado',
                'at' => localizedDate($customer->user->phone_verified_at)?->isoFormat('D MMM Y h:mm a'),
                'detail' => $customer->user->full_phone ?? null,
            ];
        }

        foreach ($customer->laboratoryCartItems->take(5) as $item) {
            $timeline[] = [
                'type' => 'lab_cart',
                'label' => 'Producto de laboratorio en carrito',
                'at' => localizedDate($item->created_at)?->isoFormat('D MMM Y h:mm a'),
                'detail' => $item->laboratoryTest?->name ?? 'Estudio',
            ];
        }

        foreach ($customer->laboratoryCheckoutDrafts->take(3) as $draft) {
            $timeline[] = [
                'type' => 'checkout',
                'label' => 'Checkout iniciado',
                'at' => localizedDate($draft->updated_at ?? $draft->created_at)?->isoFormat('D MMM Y h:mm a'),
                'detail' => 'Borrador de checkout de laboratorio',
            ];
        }

        $labVisits = $customer->laboratoryCartItems->count();
        $pharmacyVisits = $customer->onlinePharmacyCartItems->count();
        $checkouts = $customer->laboratoryCheckoutDrafts->count();

        $insights = [];
        if ($labVisits > 0) {
            $insights[] = "visitó laboratorios / agregó {$labVisits} ítem(s) al carrito";
        }
        if ($pharmacyVisits > 0) {
            $insights[] = "agregó {$pharmacyVisits} producto(s) de farmacia";
        }
        if ($checkouts > 0) {
            $insights[] = "abandonó {$checkouts} intento(s) de checkout";
        }
        if ($days >= 30) {
            $insights[] = "lleva {$days} días registrado sin comprar";
        }
        if ($aiProbability >= 60) {
            $insights[] = 'tiene alta probabilidad de comprar en los próximos 10 días';
        } elseif ($aiProbability >= 35) {
            $insights[] = 'tiene probabilidad media de conversión con un incentivo';
        } else {
            $insights[] = 'requiere nurturing prolongado antes de convertir';
        }

        return [
            'customer_id' => $customer->id,
            'general' => [
                'name' => $this->resolveName($customer),
                'email' => $this->resolveEmail($customer),
                'phone' => $this->resolvePhone($customer),
                'gender' => $customer->user?->formatted_gender ?? $customer->customerable?->formatted_gender ?? null,
                'birth_date' => $customer->user?->formatted_birth_date ?? $customer->customerable?->formatted_birth_date ?? null,
                'city' => $address?->city,
                'state' => $customer->user?->state
                    ? (StatesMexico::obtenerNombre($customer->user->state) ?? $customer->user->state)
                    : null,
                'registered_at' => $customer->formatted_created_at,
                'days_registered' => $days,
                'last_activity_at' => localizedDate($customer->updated_at)?->isoFormat('D MMM Y h:mm a'),
                'registration_source' => $source['label'],
                'account_type' => $customer->formatted_account_type ?? null,
                'show_url' => route('admin.customers.show', $customer),
            ],
            'timeline' => $timeline,
            'activity' => [
                'sessions_proxy' => max(1, $labVisits + $pharmacyVisits + $checkouts),
                'lab_cart_items' => $labVisits,
                'pharmacy_cart_items' => $pharmacyVisits,
                'checkout_drafts' => $checkouts,
                'last_visit' => localizedDate($customer->updated_at)?->isoFormat('D MMM Y h:mm a'),
                'device' => null,
                'os' => null,
                'browser' => null,
            ],
            'purchases' => [
                'has_purchased' => false,
                'message' => 'Nunca ha realizado una compra',
            ],
            'activecampaign' => [
                'lead_score' => $leadScore,
                'tags' => $this->suggestedTags($customer, $days),
                'automations' => [],
                'lists' => ['Clientes dormidos'],
                'custom_fields' => [
                    'days_without_purchase' => $days,
                    'source' => $source['label'],
                ],
                'last_campaign' => null,
                'last_open' => null,
                'last_click' => null,
            ],
            'ai' => [
                'probability' => $aiProbability,
                'summary' => 'Este cliente: '.implode('; ', $insights).'.',
                'bullets' => $insights,
                'recommended_action' => $days <= 16
                    ? 'Contactar con oferta de primera compra en los próximos 3 días.'
                    : 'Reactivar con cupón y secuencia WhatsApp + email.',
            ],
        ];
    }

    /**
     * @return Collection<int, string>
     */
    public function availableCities(): Collection
    {
        return DB::table('addresses')
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->distinct()
            ->orderBy('city')
            ->limit(200)
            ->pluck('city');
    }

    /**
     * @return \Illuminate\Database\Query\Builder
     */
    private function firstPurchaseSubquery()
    {
        $lab = DB::table('laboratory_purchases')
            ->select('customer_id', DB::raw('MIN(created_at) as first_at'));
        $pharmacy = DB::table('online_pharmacy_purchases')
            ->select('customer_id', DB::raw('MIN(created_at) as first_at'));
        $membership = DB::table('medical_attention_subscriptions')
            ->select('customer_id', DB::raw('MIN(created_at) as first_at'));

        if (\Illuminate\Support\Facades\Schema::hasColumn('laboratory_purchases', 'deleted_at')) {
            $lab->whereNull('deleted_at');
        }
        if (\Illuminate\Support\Facades\Schema::hasColumn('online_pharmacy_purchases', 'deleted_at')) {
            $pharmacy->whereNull('deleted_at');
        }
        if (\Illuminate\Support\Facades\Schema::hasColumn('medical_attention_subscriptions', 'deleted_at')) {
            $membership->whereNull('deleted_at');
        }

        $lab->groupBy('customer_id');
        $pharmacy->groupBy('customer_id');
        $membership->groupBy('customer_id');

        $union = $lab->unionAll($pharmacy)->unionAll($membership);

        return DB::query()
            ->fromSub($union, 'purchases')
            ->select('customer_id', DB::raw('MIN(first_at) as first_purchase_at'))
            ->groupBy('customer_id');
    }

    /**
     * @return array{key: string, label: string}
     */
    private function resolveSource(Customer $customer): array
    {
        if ($customer->user?->referred_by) {
            return ['key' => 'referred', 'label' => 'Referidos'];
        }

        return match ($customer->customerable_type) {
            OdessaAfiliateAccount::class => ['key' => 'odessa', 'label' => 'Odessa'],
            FamilyAccount::class => ['key' => 'familiar', 'label' => 'Familiar'],
            default => ['key' => 'organico', 'label' => 'Orgánico / Web'],
        };
    }

    private function resolveName(Customer $customer): string
    {
        if ($customer->customerable_type === FamilyAccount::class) {
            return $customer->customerable?->full_name
                ?? trim(($customer->customerable?->name ?? '').' '.($customer->customerable?->paternal_lastname ?? ''))
                ?: 'Familiar #'.$customer->id;
        }

        return $customer->user?->full_name
            ?? trim(($customer->user?->name ?? '').' '.($customer->user?->paternal_lastname ?? ''))
            ?: 'Cliente #'.$customer->id;
    }

    private function resolveEmail(Customer $customer): ?string
    {
        if ($customer->customerable_type === FamilyAccount::class) {
            $customer->loadMissing('customerable.parentCustomer.user');

            return $customer->customerable?->parentCustomer?->user?->email;
        }

        return $customer->user?->email;
    }

    private function resolvePhone(Customer $customer): ?string
    {
        if ($customer->customerable_type === FamilyAccount::class) {
            $customer->loadMissing('customerable.parentCustomer.user');

            return $customer->customerable?->parentCustomer?->user?->full_phone;
        }

        return $customer->user?->full_phone;
    }

    private function estimateLeadScore(Customer $customer, int $days): int
    {
        $score = 20;

        if ($customer->user?->email_verified_at) {
            $score += 15;
        }
        if ($customer->user?->phone_verified_at) {
            $score += 15;
        }

        $cartItems = ($customer->laboratory_cart_items_count ?? $customer->laboratoryCartItems()->count())
            + ($customer->online_pharmacy_cart_items_count ?? $customer->onlinePharmacyCartItems()->count());
        $score += min(25, $cartItems * 5);

        $checkouts = $customer->laboratory_checkout_drafts_count ?? $customer->laboratoryCheckoutDrafts()->count();
        $score += min(20, $checkouts * 10);

        if ($days <= 16) {
            $score += 10;
        } elseif ($days > 60) {
            $score -= 10;
        }

        return max(5, min(98, $score));
    }

    /**
     * @return list<string>
     */
    private function suggestedTags(Customer $customer, int $days): array
    {
        $tags = ['dormant'];

        if ($days <= 7) {
            $tags[] = 'new-7d';
        } elseif ($days <= 30) {
            $tags[] = 'new-30d';
        } else {
            $tags[] = 'cold-30d+';
        }

        if (($customer->laboratory_cart_items_count ?? 0) > 0 || ($customer->online_pharmacy_cart_items_count ?? 0) > 0) {
            $tags[] = 'abandoned-cart';
        }

        if (! $customer->user?->email_verified_at) {
            $tags[] = 'email-unverified';
        }

        return $tags;
    }
}
