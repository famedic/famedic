<?php

namespace App\Services\CustomerIntelligence;

use App\Actions\Users\GenerateInvitationUrlAction;
use App\Models\Customer;
use App\Models\FamilyAccount;
use App\Models\OdessaAfiliateAccount;
use App\Models\RegularAccount;
use App\Models\User;
use App\Support\CustomerIntelligence\ReferralIntelligenceFilter;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReferralIntelligenceRepository
{
    /**
     * Usuarios referidos (invitados) en el periodo.
     */
    public function referralsQuery(ReferralIntelligenceFilter $filter, ?Carbon $from = null, ?Carbon $to = null): Builder
    {
        $from ??= $filter->start;
        $to ??= $filter->end;

        $query = User::query()
            ->whereNotNull('referred_by')
            ->whereBetween('created_at', [$from, $to]);

        $this->applyReferralFilters($query, $filter);

        return $query;
    }

    /**
     * Invitadores con al menos un referido en el periodo (según filtros).
     */
    public function invitersBaseQuery(ReferralIntelligenceFilter $filter, ?Carbon $from = null, ?Carbon $to = null): Builder
    {
        $from ??= $filter->start;
        $to ??= $filter->end;

        return User::query()
            ->whereHas('referrals', function (Builder $q) use ($filter, $from, $to) {
                $q->whereBetween('created_at', [$from, $to]);
                $this->applyReferralFilters($q, $filter, skipInviterSearch: true);
            })
            ->when($filter->search, function (Builder $q, string $search) {
                $q->where(function (Builder $inner) use ($search) {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('paternal_lastname', 'like', "%{$search}%")
                        ->orWhere('maternal_lastname', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->when($filter->accountType, function (Builder $q, string $type) {
                $map = [
                    'regular' => RegularAccount::class,
                    'odessa' => OdessaAfiliateAccount::class,
                    'familiar' => FamilyAccount::class,
                ];
                if (isset($map[$type])) {
                    $q->whereHas('customer', fn (Builder $c) => $c->where('customerable_type', $map[$type]));
                }
            })
            ->when($filter->company, function (Builder $q, string $company) {
                $q->whereHas('customer', function (Builder $c) use ($company) {
                    $c->where('customerable_type', OdessaAfiliateAccount::class)
                        ->whereHasMorph('customerable', [OdessaAfiliateAccount::class], function (Builder $oa) use ($company) {
                            $oa->whereHas('odessaAfiliatedCompany', fn (Builder $co) => $co->where('name', $company));
                        });
                });
            })
            ->when($filter->city, function (Builder $q, string $city) {
                $q->whereHas('customer.addresses', fn (Builder $a) => $a->where('city', $city));
            })
            ->when($filter->source, function (Builder $q, string $source) {
                if ($source === 'odessa') {
                    $q->whereHas('customer', fn (Builder $c) => $c->where('customerable_type', OdessaAfiliateAccount::class));
                } elseif ($source === 'familiar') {
                    $q->whereHas('customer', fn (Builder $c) => $c->where('customerable_type', FamilyAccount::class));
                } elseif ($source === 'regular') {
                    $q->whereHas('customer', fn (Builder $c) => $c->where('customerable_type', RegularAccount::class));
                }
            });
    }

    public function applyReferralFilters(Builder $query, ReferralIntelligenceFilter $filter, bool $skipInviterSearch = false): void
    {
        // Filtros sobre el referido (invitado).
        $query
            ->when(! $skipInviterSearch && $filter->search, function (Builder $q, string $search) {
                // Cuando se busca invitador, el filtro de referidos se aplica vía whereHas del invitador.
            })
            ->when($filter->status, function (Builder $q, string $status) {
                match ($status) {
                    'nuevo' => $q->whereNull('email_verified_at'),
                    'verificado' => $q->whereNotNull('email_verified_at')
                        ->where('created_at', '>=', now()->subDays(60))
                        ->whereDoesntHave('customer', function (Builder $c) {
                            $c->where(function (Builder $inner) {
                                $inner->whereHas('laboratoryPurchases')
                                    ->orWhereHas('onlinePharmacyPurchases')
                                    ->orWhereHas('medicalAttentionSubscriptions');
                            });
                        }),
                    'compro' => $q->whereHas('customer', function (Builder $c) {
                        $c->where(function (Builder $inner) {
                            $inner->whereHas('laboratoryPurchases')
                                ->orWhereHas('onlinePharmacyPurchases');
                        });
                    }),
                    'membresia' => $q->whereHas('customer.medicalAttentionSubscriptions'),
                    'inactivo' => $q->whereNotNull('email_verified_at')
                        ->where('created_at', '<', now()->subDays(60))
                        ->whereDoesntHave('customer', function (Builder $c) {
                            $c->where(function (Builder $inner) {
                                $inner->whereHas('laboratoryPurchases')
                                    ->orWhereHas('onlinePharmacyPurchases')
                                    ->orWhereHas('medicalAttentionSubscriptions');
                            });
                        }),
                    default => null,
                };
            });
    }

    public function countReferrals(ReferralIntelligenceFilter $filter, ?Carbon $from = null, ?Carbon $to = null): int
    {
        return $this->referralsQuery($filter, $from, $to)->count();
    }

    public function countInviters(ReferralIntelligenceFilter $filter, ?Carbon $from = null, ?Carbon $to = null): int
    {
        return $this->invitersBaseQuery($filter, $from, $to)->count();
    }

    public function countBuyers(ReferralIntelligenceFilter $filter, ?Carbon $from = null, ?Carbon $to = null): int
    {
        return $this->referralsQuery($filter, $from, $to)
            ->whereHas('customer', function (Builder $c) {
                $c->where(function (Builder $inner) {
                    $inner->whereHas('laboratoryPurchases')
                        ->orWhereHas('onlinePharmacyPurchases')
                        ->orWhereHas('medicalAttentionSubscriptions');
                });
            })
            ->count();
    }

    /**
     * Ingresos (MXN) de clientes referidos registrados en el periodo.
     */
    public function revenueMxn(ReferralIntelligenceFilter $filter, ?Carbon $from = null, ?Carbon $to = null): float
    {
        return $this->revenueCents($filter, $from, $to) / 100;
    }

    public function revenueCents(ReferralIntelligenceFilter $filter, ?Carbon $from = null, ?Carbon $to = null): int
    {
        $customerIds = $this->referredCustomerIds($filter, $from, $to);
        if ($customerIds->isEmpty()) {
            return 0;
        }

        $lab = (int) DB::table('laboratory_purchases')
            ->whereIn('customer_id', $customerIds)
            ->when($this->hasDeletedAt('laboratory_purchases'), fn ($q) => $q->whereNull('deleted_at'))
            ->sum('total_cents');

        $pharmacy = (int) DB::table('online_pharmacy_purchases')
            ->whereIn('customer_id', $customerIds)
            ->when($this->hasDeletedAt('online_pharmacy_purchases'), fn ($q) => $q->whereNull('deleted_at'))
            ->sum('total_cents');

        $membership = (int) DB::table('medical_attention_subscriptions')
            ->whereIn('customer_id', $customerIds)
            ->when($this->hasDeletedAt('medical_attention_subscriptions'), fn ($q) => $q->whereNull('deleted_at'))
            ->sum('price_cents');

        return $lab + $pharmacy + $membership;
    }

    public function creditsCents(ReferralIntelligenceFilter $filter, ?Carbon $from = null, ?Carbon $to = null): int
    {
        $from ??= $filter->start;
        $to ??= $filter->end;

        $userIds = $this->referralsQuery($filter, $from, $to)->pluck('id');
        if ($userIds->isEmpty()) {
            return 0;
        }

        return (int) DB::table('coupon_user')
            ->join('coupons', 'coupons.id', '=', 'coupon_user.coupon_id')
            ->whereIn('coupon_user.user_id', $userIds)
            ->whereBetween('coupon_user.assigned_at', [$from, $to])
            ->sum('coupons.amount_cents');
    }

    public function averageTicketMxn(ReferralIntelligenceFilter $filter, ?Carbon $from = null, ?Carbon $to = null): float
    {
        $buyers = $this->countBuyers($filter, $from, $to);
        if ($buyers === 0) {
            return 0.0;
        }

        return $this->revenueMxn($filter, $from, $to) / $buyers;
    }

    public function averageLtvMxn(ReferralIntelligenceFilter $filter): float
    {
        return $this->averageTicketMxn($filter);
    }

    /**
     * @return list<array{date: string, label: string, value: int}>
     */
    public function registrationsEvolution(ReferralIntelligenceFilter $filter): array
    {
        $rows = $this->referralsQuery($filter)
            ->selectRaw($this->bucketSelect($filter->granularity).' as bucket')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        return $rows->map(function ($row) use ($filter) {
            $date = (string) $row->bucket;

            return [
                'date' => $date,
                'label' => $this->formatBucketLabel($date, $filter->granularity),
                'value' => (int) $row->total,
            ];
        })->values()->all();
    }

    /**
     * @return list<array{key: string, label: string, value: int}>
     */
    public function referralStatusBreakdown(ReferralIntelligenceFilter $filter): array
    {
        $base = $this->referralsQuery($filter);
        $ids = (clone $base)->pluck('id');

        if ($ids->isEmpty()) {
            return [
                ['key' => 'nuevo', 'label' => 'Nuevo', 'value' => 0],
                ['key' => 'verificado', 'label' => 'Verificado', 'value' => 0],
                ['key' => 'compro', 'label' => 'Compró', 'value' => 0],
                ['key' => 'membresia', 'label' => 'Membresía', 'value' => 0],
                ['key' => 'inactivo', 'label' => 'Inactivo', 'value' => 0],
            ];
        }

        $users = User::query()
            ->whereIn('id', $ids)
            ->with([
                'customer' => fn ($q) => $q->withCount([
                    'laboratoryPurchases',
                    'onlinePharmacyPurchases',
                    'medicalAttentionSubscriptions',
                ]),
            ])
            ->get();

        $counts = [
            'nuevo' => 0,
            'verificado' => 0,
            'compro' => 0,
            'membresia' => 0,
            'inactivo' => 0,
        ];

        foreach ($users as $user) {
            $customer = $user->customer;
            $hasMembership = $customer && $customer->medical_attention_subscriptions_count > 0;
            $hasPurchase = $customer && (
                $customer->laboratory_purchases_count > 0
                || $customer->online_pharmacy_purchases_count > 0
            );

            if ($hasMembership) {
                $counts['membresia']++;
            } elseif ($hasPurchase) {
                $counts['compro']++;
            } elseif ($user->email_verified_at === null) {
                $counts['nuevo']++;
            } elseif ($user->created_at && $user->created_at->lt(now()->subDays(60))) {
                $counts['inactivo']++;
            } else {
                $counts['verificado']++;
            }
        }

        return [
            ['key' => 'nuevo', 'label' => 'Nuevo', 'value' => $counts['nuevo']],
            ['key' => 'verificado', 'label' => 'Verificado', 'value' => $counts['verificado']],
            ['key' => 'compro', 'label' => 'Compró', 'value' => $counts['compro']],
            ['key' => 'membresia', 'label' => 'Membresía', 'value' => $counts['membresia']],
            ['key' => 'inactivo', 'label' => 'Inactivo', 'value' => $counts['inactivo']],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function topInviters(ReferralIntelligenceFilter $filter, int $limit = 10): array
    {
        $inviters = $this->invitersBaseQuery($filter)
            ->withCount([
                'referrals as referrals_in_period' => function (Builder $q) use ($filter) {
                    $q->whereBetween('created_at', [$filter->start, $filter->end]);
                },
            ])
            ->orderByDesc('referrals_in_period')
            ->limit($limit)
            ->get(['id', 'name', 'paternal_lastname', 'maternal_lastname', 'email', 'phone']);

        return $this->enrichInviters($inviters, $filter)->all();
    }

    public function paginateInviters(ReferralIntelligenceFilter $filter, int $perPage = 15): LengthAwarePaginator
    {
        $paginator = $this->invitersBaseQuery($filter)
            ->with([
                'customer.user',
                'customer.customerable',
            ])
            ->withCount([
                'referrals as referrals_in_period' => function (Builder $q) use ($filter) {
                    $q->whereBetween('created_at', [$filter->start, $filter->end]);
                },
            ])
            ->orderByDesc('referrals_in_period')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        $enriched = $this->enrichInviters($paginator->getCollection(), $filter);
        $paginator->setCollection($enriched->values());

        return $paginator;
    }

    /**
     * @return list<array{label: string, value: int|float, unit?: string}>
     */
    public function companyLeaderboard(ReferralIntelligenceFilter $filter, int $limit = 10): array
    {
        try {
            if (! Schema::hasTable('odessa_afiliated_companies')) {
                return [];
            }

            $rows = DB::table('users as referred')
                ->join('users as inviter', 'inviter.id', '=', 'referred.referred_by')
                ->join('customers', 'customers.user_id', '=', 'inviter.id')
                ->join('odessa_afiliate_accounts', function ($join) {
                    $join->on('odessa_afiliate_accounts.id', '=', 'customers.customerable_id')
                        ->where('customers.customerable_type', OdessaAfiliateAccount::class);
                })
                ->join('odessa_afiliated_companies', 'odessa_afiliated_companies.id', '=', 'odessa_afiliate_accounts.odessa_afiliated_company_id')
                ->whereNotNull('referred.referred_by')
                ->whereBetween('referred.created_at', [$filter->start, $filter->end])
                ->when(Schema::hasColumn('odessa_afiliate_accounts', 'deleted_at'), fn ($q) => $q->whereNull('odessa_afiliate_accounts.deleted_at'))
                ->when(Schema::hasColumn('odessa_afiliated_companies', 'deleted_at'), fn ($q) => $q->whereNull('odessa_afiliated_companies.deleted_at'))
                ->groupBy('odessa_afiliated_companies.id', 'odessa_afiliated_companies.name')
                ->orderByDesc(DB::raw('COUNT(*)'))
                ->limit($limit)
                ->selectRaw('odessa_afiliated_companies.name as label, COUNT(*) as value')
                ->get();

            return $rows->map(fn ($row) => [
                'label' => (string) $row->label,
                'value' => (int) $row->value,
                'unit' => 'referidos',
            ])->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array{invite_to_register: float|null, register_to_purchase: float|null, purchase_to_second: float|null}
     */
    public function performanceTiming(ReferralIntelligenceFilter $filter): array
    {
        $referrals = $this->referralsQuery($filter)
            ->with(['referrer:id,created_at', 'customer.laboratoryPurchases', 'customer.onlinePharmacyPurchases'])
            ->limit(2000)
            ->get();

        $inviteToRegister = [];
        $registerToPurchase = [];
        $purchaseToSecond = [];

        foreach ($referrals as $referral) {
            if ($referral->referrer?->created_at && $referral->created_at) {
                $inviteToRegister[] = $referral->referrer->created_at->diffInDays($referral->created_at);
            }

            $purchaseDates = collect()
                ->merge($referral->customer?->laboratoryPurchases?->pluck('created_at') ?? [])
                ->merge($referral->customer?->onlinePharmacyPurchases?->pluck('created_at') ?? [])
                ->filter()
                ->sort()
                ->values();

            if ($purchaseDates->isNotEmpty() && $referral->created_at) {
                $first = Carbon::parse($purchaseDates->first());
                $registerToPurchase[] = $referral->created_at->diffInDays($first);

                if ($purchaseDates->count() > 1) {
                    $second = Carbon::parse($purchaseDates->get(1));
                    $purchaseToSecond[] = $first->diffInDays($second);
                }
            }
        }

        return [
            'invite_to_register' => $this->avgOrNull($inviteToRegister),
            'register_to_purchase' => $this->avgOrNull($registerToPurchase),
            'purchase_to_second' => $this->avgOrNull($purchaseToSecond),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function inviterDrawer(User $inviter, ReferralIntelligenceFilter $filter): ?array
    {
        $inviter->load([
            'customer.user',
            'customer.customerable',
            'referrals' => function ($q) use ($filter) {
                $q->whereBetween('created_at', [$filter->start, $filter->end])
                    ->with([
                        'customer.user',
                        'customer' => fn ($c) => $c->withCount([
                            'laboratoryPurchases',
                            'onlinePharmacyPurchases',
                            'medicalAttentionSubscriptions',
                        ]),
                    ])
                    ->latest()
                    ->limit(50);
            },
        ]);

        if ($inviter->customer?->customerable_type === OdessaAfiliateAccount::class) {
            $inviter->customer->customerable?->load('odessaAfiliatedCompany');
        }

        $metrics = $this->enrichInviters(collect([$inviter]), $filter)->first();
        $invitationUrl = app(GenerateInvitationUrlAction::class)($inviter);

        $timeline = [
            ['key' => 'registro', 'label' => 'Registro', 'at' => optional($inviter->created_at)?->timezone('America/Monterrey')->format('d/m/Y H:i')],
            ['key' => 'primer_referido', 'label' => 'Primer referido', 'at' => $metrics['first_referral_at'] ?? null],
            ['key' => 'ultimo_referido', 'label' => 'Último referido', 'at' => $metrics['last_referral_at'] ?? null],
            ['key' => 'primera_compra', 'label' => 'Primera compra (propios)', 'at' => $metrics['first_purchase_at'] ?? null],
            ['key' => 'ultima_compra', 'label' => 'Última compra (propios)', 'at' => $metrics['last_purchase_at'] ?? null],
            ['key' => 'creditos', 'label' => 'Créditos otorgados a referidos', 'at' => $metrics['credits_formatted'] ?? '$0 MXN'],
        ];

        $referrals = $inviter->referrals->map(function (User $referral) {
            $customer = $referral->customer;
            $hasMembership = $customer && $customer->medical_attention_subscriptions_count > 0;
            $hasPurchase = $customer && (
                $customer->laboratory_purchases_count > 0
                || $customer->online_pharmacy_purchases_count > 0
            );

            $status = match (true) {
                $hasMembership => 'membresia',
                $hasPurchase => 'compro',
                $referral->email_verified_at === null => 'nuevo',
                $referral->created_at && $referral->created_at->lt(now()->subDays(60)) => 'inactivo',
                default => 'verificado',
            };

            $spentCents = 0;
            if ($customer) {
                $spentCents = (int) DB::table('laboratory_purchases')->where('customer_id', $customer->id)->sum('total_cents')
                    + (int) DB::table('online_pharmacy_purchases')->where('customer_id', $customer->id)->sum('total_cents')
                    + (int) DB::table('medical_attention_subscriptions')->where('customer_id', $customer->id)->sum('price_cents');
            }

            return [
                'id' => $referral->id,
                'customer_id' => $customer?->id,
                'name' => $referral->full_name ?: $referral->email,
                'email' => $referral->email,
                'avatar' => $referral->profile_photo_url,
                'status' => $status,
                'status_label' => $this->statusLabel($status),
                'registered_at' => optional($referral->created_at)?->timezone('America/Monterrey')->format('d/m/Y'),
                'first_purchase_at' => null,
                'amount_formatted' => '$'.number_format($spentCents / 100, 0).' MXN',
                'health_score' => $this->proxyHealthScore($status, $spentCents),
                'customer_url' => $customer ? route('admin.customers.show', $customer->id) : route('admin.users.show', $referral->id),
            ];
        })->values()->all();

        $company = null;
        if ($inviter->customer?->customerable_type === OdessaAfiliateAccount::class) {
            $company = $inviter->customer->customerable?->odessaAfiliatedCompany?->name;
        }

        $accountType = match ($inviter->customer?->customerable_type) {
            OdessaAfiliateAccount::class => 'Odessa',
            FamilyAccount::class => 'Familiar',
            RegularAccount::class => 'Regular',
            default => 'Sin cuenta cliente',
        };

        return [
            'user_id' => $inviter->id,
            'customer_id' => $inviter->customer?->id,
            'general' => [
                'name' => $inviter->full_name ?: $inviter->email,
                'email' => $inviter->email,
                'phone' => $inviter->phone,
                'avatar' => $inviter->profile_photo_url,
                'company' => $company,
                'registered_at' => optional($inviter->created_at)?->timezone('America/Monterrey')->format('d/m/Y H:i'),
                'account_type' => $accountType,
                'referral_code' => 'FAM-'.$inviter->id,
                'invitation_url' => $invitationUrl,
            ],
            'metrics' => $metrics,
            'timeline' => $timeline,
            'analytics' => [
                ['label' => 'Referidos registrados', 'value' => $metrics['referrals'] ?? 0],
                ['label' => 'Compradores', 'value' => $metrics['buyers'] ?? 0],
                ['label' => 'Conversión', 'value' => ($metrics['conversion'] ?? 0).'%'],
                ['label' => 'Ingresos', 'value' => $metrics['revenue_formatted'] ?? '$0 MXN'],
                ['label' => 'Créditos', 'value' => $metrics['credits_formatted'] ?? '$0 MXN'],
                ['label' => 'Ticket promedio', 'value' => $metrics['ticket_formatted'] ?? '$0 MXN'],
                ['label' => 'LTV promedio', 'value' => $metrics['ticket_formatted'] ?? '$0 MXN'],
            ],
            'referrals' => $referrals,
            'links' => [
                'customer_360' => $inviter->customer
                    ? route('admin.customers.show', $inviter->customer->id)
                    : route('admin.users.show', $inviter->id),
                'customer_journey' => route('admin.customer-intelligence.customer-journey'),
                'customer_health' => route('admin.customer-intelligence.customer-health'),
                'dormant' => route('admin.customers.dormant'),
            ],
            'level' => $metrics['level'] ?? null,
        ];
    }

    /**
     * @return array{companies: list<string>, cities: list<string>}
     */
    public function filterOptions(): array
    {
        $companies = [];
        if (Schema::hasTable('odessa_afiliated_companies')) {
            $companies = DB::table('odessa_afiliated_companies')
                ->orderBy('name')
                ->limit(200)
                ->pluck('name')
                ->filter()
                ->values()
                ->all();
        }

        $cities = [];
        if (Schema::hasTable('addresses')) {
            $cities = DB::table('addresses')
                ->whereNotNull('city')
                ->where('city', '!=', '')
                ->distinct()
                ->orderBy('city')
                ->limit(200)
                ->pluck('city')
                ->all();
        }

        return [
            'companies' => $companies,
            'cities' => $cities,
        ];
    }

    /**
     * @param  Collection<int, User>  $inviters
     * @return Collection<int, array<string, mixed>>
     */
    private function enrichInviters(Collection $inviters, ReferralIntelligenceFilter $filter): Collection
    {
        if ($inviters->isEmpty()) {
            return collect();
        }

        $inviterIds = $inviters->pluck('id');

        $referralStats = DB::table('users')
            ->selectRaw('referred_by, COUNT(*) as referrals_count, MIN(created_at) as first_referral_at, MAX(created_at) as last_referral_at')
            ->whereIn('referred_by', $inviterIds)
            ->whereBetween('created_at', [$filter->start, $filter->end])
            ->groupBy('referred_by')
            ->get()
            ->keyBy('referred_by');

        $buyerStats = DB::table('users as referred')
            ->join('customers', 'customers.user_id', '=', 'referred.id')
            ->whereIn('referred.referred_by', $inviterIds)
            ->whereBetween('referred.created_at', [$filter->start, $filter->end])
            ->where(function ($q) {
                $q->whereExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('laboratory_purchases')
                        ->whereColumn('laboratory_purchases.customer_id', 'customers.id');
                })->orWhereExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('online_pharmacy_purchases')
                        ->whereColumn('online_pharmacy_purchases.customer_id', 'customers.id');
                })->orWhereExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('medical_attention_subscriptions')
                        ->whereColumn('medical_attention_subscriptions.customer_id', 'customers.id');
                });
            })
            ->groupBy('referred.referred_by')
            ->selectRaw('referred.referred_by, COUNT(DISTINCT referred.id) as buyers_count')
            ->pluck('buyers_count', 'referred_by');

        $revenueByInviter = $this->revenueByInviterIds($inviterIds, $filter);
        $creditsByInviter = $this->creditsByInviterIds($inviterIds, $filter);

        return $inviters->map(function (User $inviter) use ($referralStats, $buyerStats, $revenueByInviter, $creditsByInviter, $filter) {
            if ($inviter->customer?->customerable_type === OdessaAfiliateAccount::class) {
                $inviter->customer->customerable?->loadMissing('odessaAfiliatedCompany');
            }

            $stats = $referralStats->get($inviter->id);
            $referrals = (int) ($stats->referrals_count ?? $inviter->referrals_in_period ?? 0);
            $buyers = (int) ($buyerStats[$inviter->id] ?? 0);
            $revenueCents = (int) ($revenueByInviter[$inviter->id] ?? 0);
            $creditsCents = (int) ($creditsByInviter[$inviter->id] ?? 0);
            $conversion = $referrals > 0 ? round(($buyers / $referrals) * 100, 1) : 0.0;
            $ticket = $buyers > 0 ? ($revenueCents / 100) / $buyers : 0.0;
            $level = $this->resolveLevel($referrals, $revenueCents / 100, $conversion);

            $company = null;
            if ($inviter->customer?->customerable_type === OdessaAfiliateAccount::class) {
                $company = $inviter->customer->customerable?->odessaAfiliatedCompany?->name;
            }

            $row = [
                'id' => $inviter->id,
                'customer_id' => $inviter->customer?->id,
                'name' => $inviter->full_name ?: $inviter->email,
                'email' => $inviter->email,
                'phone' => $inviter->phone,
                'avatar' => $inviter->profile_photo_url,
                'company' => $company,
                'referrals' => $referrals,
                'buyers' => $buyers,
                'conversion' => $conversion,
                'revenue_cents' => $revenueCents,
                'revenue_formatted' => '$'.number_format($revenueCents / 100, 0).' MXN',
                'credits_cents' => $creditsCents,
                'credits_formatted' => '$'.number_format($creditsCents / 100, 0).' MXN',
                'ticket_formatted' => '$'.number_format($ticket, 0).' MXN',
                'last_referral_at' => isset($stats->last_referral_at)
                    ? Carbon::parse($stats->last_referral_at)->timezone('America/Monterrey')->format('d/m/Y')
                    : null,
                'first_referral_at' => isset($stats->first_referral_at)
                    ? Carbon::parse($stats->first_referral_at)->timezone('America/Monterrey')->format('d/m/Y')
                    : null,
                'first_purchase_at' => null,
                'last_purchase_at' => null,
                'status' => $referrals > 0 ? 'activo' : 'inactivo',
                'status_label' => $referrals > 0 ? 'Activo' : 'Inactivo',
                'level' => $level,
                'customer_url' => $inviter->customer
                    ? route('admin.customers.show', $inviter->customer->id)
                    : route('admin.users.show', $inviter->id),
            ];

            return $row;
        })->when(
            $filter->segment,
            fn (Collection $rows) => $rows->filter(
                fn (array $row) => ($row['level']['key'] ?? null) === $filter->segment
            )->values()
        )->values();
    }

    /**
     * @param  Collection<int, int>  $inviterIds
     * @return array<int, int>
     */
    private function revenueByInviterIds(Collection $inviterIds, ReferralIntelligenceFilter $filter): array
    {
        $lab = DB::table('users as referred')
            ->join('customers', 'customers.user_id', '=', 'referred.id')
            ->join('laboratory_purchases', 'laboratory_purchases.customer_id', '=', 'customers.id')
            ->whereIn('referred.referred_by', $inviterIds)
            ->whereBetween('referred.created_at', [$filter->start, $filter->end])
            ->groupBy('referred.referred_by')
            ->selectRaw('referred.referred_by, SUM(laboratory_purchases.total_cents) as total')
            ->pluck('total', 'referred_by');

        $pharmacy = DB::table('users as referred')
            ->join('customers', 'customers.user_id', '=', 'referred.id')
            ->join('online_pharmacy_purchases', 'online_pharmacy_purchases.customer_id', '=', 'customers.id')
            ->whereIn('referred.referred_by', $inviterIds)
            ->whereBetween('referred.created_at', [$filter->start, $filter->end])
            ->groupBy('referred.referred_by')
            ->selectRaw('referred.referred_by, SUM(online_pharmacy_purchases.total_cents) as total')
            ->pluck('total', 'referred_by');

        $membership = DB::table('users as referred')
            ->join('customers', 'customers.user_id', '=', 'referred.id')
            ->join('medical_attention_subscriptions', 'medical_attention_subscriptions.customer_id', '=', 'customers.id')
            ->whereIn('referred.referred_by', $inviterIds)
            ->whereBetween('referred.created_at', [$filter->start, $filter->end])
            ->groupBy('referred.referred_by')
            ->selectRaw('referred.referred_by, SUM(medical_attention_subscriptions.price_cents) as total')
            ->pluck('total', 'referred_by');

        $result = [];
        foreach ($inviterIds as $id) {
            $result[$id] = (int) ($lab[$id] ?? 0) + (int) ($pharmacy[$id] ?? 0) + (int) ($membership[$id] ?? 0);
        }

        return $result;
    }

    /**
     * @param  Collection<int, int>  $inviterIds
     * @return array<int, int>
     */
    private function creditsByInviterIds(Collection $inviterIds, ReferralIntelligenceFilter $filter): array
    {
        return DB::table('users as referred')
            ->join('coupon_user', 'coupon_user.user_id', '=', 'referred.id')
            ->join('coupons', 'coupons.id', '=', 'coupon_user.coupon_id')
            ->whereIn('referred.referred_by', $inviterIds)
            ->whereBetween('referred.created_at', [$filter->start, $filter->end])
            ->whereBetween('coupon_user.assigned_at', [$filter->start, $filter->end])
            ->groupBy('referred.referred_by')
            ->selectRaw('referred.referred_by, SUM(coupons.amount_cents) as total')
            ->pluck('total', 'referred_by')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * @return Collection<int, int>
     */
    private function referredCustomerIds(ReferralIntelligenceFilter $filter, ?Carbon $from = null, ?Carbon $to = null): Collection
    {
        return Customer::query()
            ->whereIn('user_id', $this->referralsQuery($filter, $from, $to)->select('id'))
            ->pluck('id');
    }

    /**
     * @return array{key: string, label: string, medal: string|null}
     */
    private function resolveLevel(int $referrals, float $revenueMxn, float $conversion): array
    {
        $score = ($referrals * 10) + ($revenueMxn / 100) + ($conversion * 2);

        return match (true) {
            $score >= 500 || $referrals >= 50 => ['key' => 'diamante', 'label' => 'Diamante', 'medal' => '💎'],
            $score >= 250 || $referrals >= 25 => ['key' => 'platino', 'label' => 'Platino', 'medal' => '💠'],
            $score >= 100 || $referrals >= 10 => ['key' => 'oro', 'label' => 'Oro', 'medal' => '🥇'],
            $score >= 40 || $referrals >= 5 => ['key' => 'plata', 'label' => 'Plata', 'medal' => '🥈'],
            default => ['key' => 'bronce', 'label' => 'Bronce', 'medal' => '🥉'],
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'nuevo' => 'Nuevo',
            'verificado' => 'Verificado',
            'compro' => 'Compró',
            'membresia' => 'Membresía',
            'inactivo' => 'Inactivo',
            default => ucfirst($status),
        };
    }

    private function proxyHealthScore(string $status, int $spentCents): int
    {
        $base = match ($status) {
            'membresia' => 88,
            'compro' => 75,
            'verificado' => 55,
            'nuevo' => 40,
            default => 25,
        };

        return min(99, $base + (int) min(15, $spentCents / 10000));
    }

    private function bucketSelect(string $granularity): string
    {
        return match ($granularity) {
            'week' => "DATE_FORMAT(created_at, '%x-W%v')",
            'month' => "DATE_FORMAT(created_at, '%Y-%m')",
            default => 'DATE(created_at)',
        };
    }

    private function formatBucketLabel(string $bucket, string $granularity): string
    {
        try {
            return match ($granularity) {
                'month' => Carbon::createFromFormat('Y-m', $bucket)->isoFormat('MMM Y'),
                'week' => $bucket,
                default => Carbon::parse($bucket)->isoFormat('D MMM'),
            };
        } catch (\Throwable) {
            return $bucket;
        }
    }

    /**
     * @param  list<float|int>  $values
     */
    private function avgOrNull(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        return round(array_sum($values) / count($values), 1);
    }

    private function hasDeletedAt(string $table): bool
    {
        return Schema::hasColumn($table, 'deleted_at');
    }
}
