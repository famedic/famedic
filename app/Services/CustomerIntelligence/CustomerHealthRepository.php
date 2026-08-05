<?php

namespace App\Services\CustomerIntelligence;

use App\Data\StatesMexico;
use App\DTOs\CustomerIntelligence\CustomerHealthScoreData;
use App\Models\Customer;
use App\Models\FamilyAccount;
use App\Models\OdessaAfiliateAccount;
use App\Models\RegularAccount;
use App\Support\CustomerIntelligence\CustomerHealthFilter;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CustomerHealthRepository
{
    public function customersQuery(CustomerHealthFilter $filter, ?Carbon $from = null, ?Carbon $to = null): Builder
    {
        $from ??= $filter->start;
        $to ??= $filter->end;

        return Customer::query()
            ->whereBetween('customers.created_at', [$from, $to])
            ->when($filter->search, function (Builder $q, string $search) {
                $q->whereHas('user', function (Builder $user) use ($search) {
                    $user->where(function (Builder $u) use ($search) {
                        $u->where('name', 'like', "%{$search}%")
                            ->orWhere('paternal_lastname', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
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
            ->when($filter->source, function (Builder $q, string $source) {
                match ($source) {
                    'referred' => $q->whereHas('user', fn (Builder $u) => $u->whereNotNull('referred_by')),
                    'odessa' => $q->where('customerable_type', OdessaAfiliateAccount::class),
                    'familiar' => $q->where('customerable_type', FamilyAccount::class),
                    'organico' => $q->where('customerable_type', RegularAccount::class)
                        ->whereHas('user', fn (Builder $u) => $u->whereNull('referred_by')),
                    default => null,
                };
            })
            ->when($filter->state, fn (Builder $q, string $state) => $q->whereHas('user', fn (Builder $u) => $u->where('state', $state)))
            ->when($filter->city, fn (Builder $q, string $city) => $q->whereHas('addresses', fn (Builder $a) => $a->where('city', $city)));
    }

    public function purchasesUnionQuery()
    {
        $lab = DB::table('laboratory_purchases')
            ->select('customer_id', 'created_at', 'total_cents', DB::raw("'lab' as channel"));
        $pharmacy = DB::table('online_pharmacy_purchases')
            ->select('customer_id', 'created_at', 'total_cents', DB::raw("'pharmacy' as channel"));
        $membership = DB::table('medical_attention_subscriptions')
            ->select('customer_id', 'created_at', DB::raw('price_cents as total_cents'), DB::raw("'membership' as channel"));

        if (Schema::hasColumn('laboratory_purchases', 'deleted_at')) {
            $lab->whereNull('deleted_at');
        }
        if (Schema::hasColumn('online_pharmacy_purchases', 'deleted_at')) {
            $pharmacy->whereNull('deleted_at');
        }
        if (Schema::hasColumn('medical_attention_subscriptions', 'deleted_at')) {
            $membership->whereNull('deleted_at');
        }

        return $lab->unionAll($pharmacy)->unionAll($membership);
    }

    /**
     * Calcula el health score completo de un cliente.
     */
    public function scoreCustomer(Customer $customer): CustomerHealthScoreData
    {
        $customer->loadMissing(['user', 'customerable']);

        $labCart = (int) ($customer->laboratory_cart_items_count ?? $customer->laboratoryCartItems()->count());
        $pharmCart = (int) ($customer->online_pharmacy_cart_items_count ?? $customer->onlinePharmacyCartItems()->count());
        $checkouts = (int) ($customer->laboratory_checkout_drafts_count ?? $customer->laboratoryCheckoutDrafts()->count());
        $labPurchases = (int) ($customer->laboratory_purchases_count ?? $customer->laboratoryPurchases()->count());
        $pharmPurchases = (int) ($customer->online_pharmacy_purchases_count ?? $customer->onlinePharmacyPurchases()->count());
        $memberships = (int) ($customer->medical_attention_subscriptions_count ?? $customer->medicalAttentionSubscriptions()->count());

        $purchaseCount = $labPurchases + $pharmPurchases + $memberships;
        $hasPurchase = $purchaseCount > 0;
        $emailVerified = (bool) $customer->user?->email_verified_at;
        $phoneVerified = (bool) $customer->user?->phone_verified_at;
        $membershipActive = (bool) $customer->medical_attention_subscription_is_active;
        $daysSinceActivity = $customer->updated_at ? (int) $customer->updated_at->diffInDays(now()) : 999;
        $daysSinceRegistration = $customer->created_at ? (int) $customer->created_at->diffInDays(now()) : 0;

        $revenueCents = (int) (
            ($customer->laboratory_purchases_sum_total_cents ?? 0)
            + ($customer->online_pharmacy_purchases_sum_total_cents ?? 0)
            + ($customer->medical_attention_subscriptions_sum_price_cents ?? 0)
        );

        if ($revenueCents === 0 && $hasPurchase) {
            $revenueCents = (int) DB::query()
                ->fromSub($this->purchasesUnionQuery(), 'p')
                ->where('customer_id', $customer->id)
                ->sum('total_cents');
        }

        $ltv = $revenueCents / 100;
        $avgTicket = $purchaseCount > 0 ? $ltv / $purchaseCount : 0;

        $lastPurchaseAt = null;
        if ($hasPurchase) {
            $lastPurchaseAt = DB::query()
                ->fromSub($this->purchasesUnionQuery(), 'p')
                ->where('customer_id', $customer->id)
                ->max('created_at');
        }
        $daysSincePurchase = $lastPurchaseAt
            ? (int) Carbon::parse($lastPurchaseAt)->diffInDays(now())
            : null;

        $score = 35;
        $positive = [];
        $negative = [];

        if ($emailVerified) {
            $score += 10;
            $positive[] = 'Verificó correo';
        } else {
            $score -= 8;
            $negative[] = 'Nunca verificó email';
        }

        if ($phoneVerified) {
            $score += 8;
            $positive[] = 'Verificó teléfono';
        } else {
            $score -= 5;
            $negative[] = 'Nunca verificó teléfono';
        }

        if ($daysSinceActivity <= 14) {
            $score += 10;
            $positive[] = 'Actividad reciente';
        } elseif ($daysSinceActivity <= 30) {
            $score += 4;
            $positive[] = 'Actividad en el último mes';
        } elseif ($daysSinceActivity >= 60) {
            $score -= 10;
            $negative[] = 'Sin actividad reciente';
        }

        if ($labCart > 0 || $labPurchases > 0) {
            $score += 8;
            $positive[] = 'Interés / compra en laboratorios';
        }
        if ($pharmCart > 0 || $pharmPurchases > 0) {
            $score += 8;
            $positive[] = 'Interés / compra en farmacia';
        }
        if ($memberships > 0 || $customer->medical_attention_identifier) {
            $score += 6;
            $positive[] = 'Interés en membresías';
        }
        if ($membershipActive) {
            $score += 8;
            $positive[] = 'Membresía activa';
        }

        if (($labCart + $pharmCart) > 0) {
            $score += 6;
            $positive[] = 'Agregó productos al carrito';
        }

        if ($checkouts > 0 && $hasPurchase) {
            $score += 5;
            $positive[] = 'Completó checkout';
        } elseif ($checkouts > 0 && ! $hasPurchase) {
            $score -= 12;
            $negative[] = 'Abandonó checkout';
        }

        if (($labCart + $pharmCart) > 0 && ! $hasPurchase && $checkouts === 0) {
            $score -= 8;
            $negative[] = 'Abandonó carrito';
        }

        if ($hasPurchase) {
            $score += 15;
            $positive[] = 'Realizó compra';
        } elseif ($daysSinceRegistration >= 30) {
            $score -= 10;
            $negative[] = 'Registrado sin comprar';
        }

        if ($purchaseCount >= 2) {
            $score += 8;
            $positive[] = 'Múltiples compras';
        }
        if ($purchaseCount >= 4) {
            $score += 5;
            $positive[] = 'Alta frecuencia de compra';
        }

        if ($avgTicket >= 800) {
            $score += 5;
            $positive[] = 'Alto ticket promedio';
        }

        if ($daysSincePurchase !== null && $daysSincePurchase >= 90) {
            $score -= 15;
            $negative[] = 'No compra hace meses';
        } elseif ($daysSincePurchase !== null && $daysSincePurchase >= 60) {
            $score -= 8;
            $negative[] = 'Compra enfriándose';
        }

        if (! $emailVerified && ! $phoneVerified && $purchaseCount === 0 && ($labCart + $pharmCart + $checkouts) === 0) {
            $score -= 10;
            $negative[] = 'Nunca interactúa';
        }

        // Proxies marketing (sin event stream AC aún).
        if ($emailVerified && $hasPurchase) {
            $score += 3;
            $positive[] = 'Engagement post-verificación (proxy email)';
        }

        $score = max(0, min(100, $score));
        $class = CustomerHealthScoreData::classify($score);

        $leadScore = $this->deriveLeadScore($score, $emailVerified, $phoneVerified, $labCart + $pharmCart, $checkouts, $purchaseCount);

        $probabilities = $this->deriveProbabilities(
            $score,
            $hasPurchase,
            $purchaseCount,
            $labCart,
            $pharmCart,
            $checkouts,
            $membershipActive,
            $memberships,
            $emailVerified,
            $phoneVerified,
            $daysSincePurchase,
            $daysSinceActivity,
        );

        $persona = $this->derivePersona($score, $ltv, $purchaseCount, $hasPurchase, $daysSincePurchase, $checkouts, $labCart + $pharmCart);
        $actions = $this->deriveActions($score, $persona, $probabilities, $hasPurchase, $checkouts);

        return new CustomerHealthScoreData(
            customerId: (int) $customer->id,
            healthScore: $score,
            band: $class['band'],
            bandLabel: $class['label'],
            leadScore: $leadScore,
            positiveSignals: $positive,
            negativeSignals: $negative,
            probabilities: $probabilities,
            recommendedActions: $actions,
            persona: $persona,
            ltv: round($ltv, 2),
            daysSincePurchase: $daysSincePurchase,
            daysSinceActivity: $daysSinceActivity,
        );
    }

    /**
     * @return array<string, float>
     */
    private function deriveProbabilities(
        int $score,
        bool $hasPurchase,
        int $purchaseCount,
        int $labCart,
        int $pharmCart,
        int $checkouts,
        bool $membershipActive,
        int $memberships,
        bool $emailVerified,
        bool $phoneVerified,
        ?int $daysSincePurchase,
        int $daysSinceActivity,
    ): array {
        $buy = min(95, max(5, $score * 0.75 + ($checkouts > 0 ? 10 : 0) + ($labCart + $pharmCart > 0 ? 5 : 0)));
        $churn = min(95, max(5, (100 - $score) * 0.8 + (($daysSincePurchase ?? 45) > 60 ? 10 : 0)));
        $email = min(90, max(8, ($emailVerified ? 55 : 15) + ($score * 0.25)));
        $whatsapp = min(90, max(8, ($phoneVerified ? 58 : 12) + ($score * 0.22)));
        $membership = min(88, max(5, ($membershipActive ? 70 : 15) + ($memberships > 0 ? 15 : 0) + ($score * 0.15)));
        $lab = min(92, max(5, ($labCart > 0 || $purchaseCount > 0 ? 40 : 12) + ($score * 0.35)));
        $pharmacy = min(92, max(5, ($pharmCart > 0 ? 42 : 10) + ($score * 0.3)));

        if ($hasPurchase && ($daysSincePurchase ?? 0) <= 30) {
            $buy = min(95, $buy + 8);
            $churn = max(5, $churn - 10);
        }

        if ($daysSinceActivity >= 60) {
            $churn = min(95, $churn + 12);
            $buy = max(5, $buy - 10);
        }

        return [
            'purchase' => round($buy, 1),
            'churn' => round($churn, 1),
            'email_response' => round($email, 1),
            'whatsapp_response' => round($whatsapp, 1),
            'membership' => round($membership, 1),
            'laboratory' => round($lab, 1),
            'pharmacy' => round($pharmacy, 1),
        ];
    }

    private function deriveLeadScore(
        int $health,
        bool $emailVerified,
        bool $phoneVerified,
        int $cartItems,
        int $checkouts,
        int $purchases,
    ): int {
        $lead = (int) round($health * 0.6);
        if ($emailVerified) {
            $lead += 8;
        }
        if ($phoneVerified) {
            $lead += 6;
        }
        $lead += min(15, $cartItems * 3);
        $lead += min(12, $checkouts * 6);
        $lead += min(20, $purchases * 5);

        return max(5, min(98, $lead));
    }

    private function derivePersona(
        int $score,
        float $ltv,
        int $purchaseCount,
        bool $hasPurchase,
        ?int $daysSincePurchase,
        int $checkouts,
        int $cartItems,
    ): string {
        if ($ltv >= 5000 && $purchaseCount >= 3 && $score >= 70) {
            return 'vip';
        }
        if ($ltv >= 2500 && $score >= 75) {
            return 'premium';
        }
        if ($ltv >= 1500) {
            return 'high_value';
        }
        if (! $hasPurchase && ($checkouts > 0 || $cartItems > 0) && $score >= 40) {
            return 'recoverable';
        }
        if (! $hasPurchase && $score < 40) {
            return 'dormant';
        }
        if ($hasPurchase && ($daysSincePurchase ?? 0) >= 90) {
            return 'lost';
        }
        if ($score < 45) {
            return 'high_risk';
        }
        if ($score >= 70 && ($checkouts > 0 || $cartItems > 0)) {
            return 'next_purchase';
        }
        if ($score >= 65) {
            return 'high_conversion';
        }

        return 'standard';
    }

    /**
     * @param  array<string, float>  $probabilities
     * @return list<string>
     */
    private function deriveActions(int $score, string $persona, array $probabilities, bool $hasPurchase, int $checkouts): array
    {
        $actions = [];

        if ($probabilities['purchase'] >= 65 && ! $hasPurchase) {
            $actions[] = 'Alta probabilidad de compra — enviar cupón del 10%';
        }
        if ($probabilities['churn'] >= 60) {
            $actions[] = 'Alto riesgo de abandono — enviar WhatsApp de reactivación';
        }
        if (in_array($persona, ['vip', 'premium', 'high_value'], true)) {
            $actions[] = 'Alto Lifetime Value — asignar ejecutivo / trato preferente';
        }
        if ($persona === 'recoverable' || ($checkouts > 0 && ! $hasPurchase)) {
            $actions[] = 'Cliente recuperable — crear campaña de carrito/checkout';
        }
        if ($persona === 'dormant' || $persona === 'lost') {
            $actions[] = 'Win-back — secuencia email + WhatsApp a 48h';
        }
        if ($probabilities['membership'] >= 55) {
            $actions[] = 'Proponer membresía con beneficio de recurrencia';
        }
        if ($actions === []) {
            $actions[] = $score >= 60
                ? 'Mantener nurturing de valor y upsell de laboratorio'
                : 'Reactivar con contenido educativo + incentivo suave';
        }

        return array_slice($actions, 0, 4);
    }

    /**
     * Escanea el cohort (limitado) para KPIs / histogram / scatter.
     *
     * @return array{
     *   scored: list<array<string, mixed>>,
     *   average: float,
     *   bands: array<string, int>,
     *   histogram: list<array{key: string, label: string, count: int}>,
     *   by_city: list<array{label: string, average: float, count: int}>,
     *   by_source: list<array{label: string, average: float, count: int}>,
     *   by_channel: list<array{label: string, average: float, count: int}>,
     *   segments: array<string, int>
     * }
     */
    public function analyzeCohort(CustomerHealthFilter $filter, int $limit = 800): array
    {
        $customers = $this->customersQuery($filter)
            ->with(['user', 'customerable', 'addresses' => fn ($q) => $q->latest()->limit(1)])
            ->withCount([
                'laboratoryCartItems',
                'onlinePharmacyCartItems',
                'laboratoryCheckoutDrafts',
                'laboratoryPurchases',
                'onlinePharmacyPurchases',
                'medicalAttentionSubscriptions',
            ])
            ->withSum('laboratoryPurchases', 'total_cents')
            ->withSum('onlinePharmacyPurchases', 'total_cents')
            ->withSum('medicalAttentionSubscriptions', 'price_cents')
            ->latest('customers.created_at')
            ->limit($limit)
            ->get();

        $scored = [];
        $bandCounts = [
            'excellent' => 0,
            'good' => 0,
            'at_risk' => 0,
            'critical' => 0,
            'lost' => 0,
        ];
        $histogram = [
            '0-20' => 0,
            '21-40' => 0,
            '41-60' => 0,
            '61-80' => 0,
            '81-100' => 0,
        ];
        $byCity = [];
        $bySource = [];
        $byChannelInterest = [
            'lab' => ['sum' => 0, 'count' => 0],
            'pharmacy' => ['sum' => 0, 'count' => 0],
            'membership' => ['sum' => 0, 'count' => 0],
        ];
        $segments = [
            'premium' => 0,
            'dormant' => 0,
            'recoverable' => 0,
            'lost' => 0,
            'vip' => 0,
            'high_value' => 0,
            'high_risk' => 0,
            'next_purchase' => 0,
            'high_conversion' => 0,
        ];

        $sum = 0;

        foreach ($customers as $customer) {
            $health = $this->scoreCustomer($customer);
            $bandCounts[$health->band] = ($bandCounts[$health->band] ?? 0) + 1;
            $sum += $health->healthScore;

            $bucket = match (true) {
                $health->healthScore <= 20 => '0-20',
                $health->healthScore <= 40 => '21-40',
                $health->healthScore <= 60 => '41-60',
                $health->healthScore <= 80 => '61-80',
                default => '81-100',
            };
            $histogram[$bucket]++;

            $city = $customer->addresses->first()?->city ?: 'Sin ciudad';
            $byCity[$city]['sum'] = ($byCity[$city]['sum'] ?? 0) + $health->healthScore;
            $byCity[$city]['count'] = ($byCity[$city]['count'] ?? 0) + 1;

            $source = $this->resolveSourceLabel($customer);
            $bySource[$source]['sum'] = ($bySource[$source]['sum'] ?? 0) + $health->healthScore;
            $bySource[$source]['count'] = ($bySource[$source]['count'] ?? 0) + 1;

            if (($customer->laboratory_purchases_count ?? 0) > 0 || ($customer->laboratory_cart_items_count ?? 0) > 0) {
                $byChannelInterest['lab']['sum'] += $health->healthScore;
                $byChannelInterest['lab']['count']++;
            }
            if (($customer->online_pharmacy_purchases_count ?? 0) > 0 || ($customer->online_pharmacy_cart_items_count ?? 0) > 0) {
                $byChannelInterest['pharmacy']['sum'] += $health->healthScore;
                $byChannelInterest['pharmacy']['count']++;
            }
            if (($customer->medical_attention_subscriptions_count ?? 0) > 0 || $customer->medical_attention_subscription_is_active) {
                $byChannelInterest['membership']['sum'] += $health->healthScore;
                $byChannelInterest['membership']['count']++;
            }

            if (isset($segments[$health->persona])) {
                $segments[$health->persona]++;
            }

            $scored[] = [
                'id' => $customer->id,
                'name' => $this->resolveName($customer),
                'email' => $customer->user?->email,
                'avatar' => $customer->user?->profile_photo_url,
                'city' => $city === 'Sin ciudad' ? null : $city,
                'source' => $source,
                'health_score' => $health->healthScore,
                'band' => $health->band,
                'band_label' => $health->bandLabel,
                'lead_score' => $health->leadScore,
                'ltv' => $health->ltv,
                'persona' => $health->persona,
                'probabilities' => $health->probabilities,
                'days_since_purchase' => $health->daysSincePurchase,
                'days_since_activity' => $health->daysSinceActivity,
                'recommended_actions' => $health->recommendedActions,
                'show_url' => route('admin.customers.show', $customer),
            ];
        }

        $count = max(1, count($scored));

        // Filtros post-score (band / segment) sobre el set analizado.
        $filtered = collect($scored)
            ->when($filter->healthBand, fn (Collection $c) => $c->where('band', $filter->healthBand))
            ->when($filter->segment, fn (Collection $c) => $c->where('persona', $filter->segment))
            ->values();

        return [
            'scored' => $filtered->all(),
            'average' => round($sum / $count, 1),
            'bands' => $bandCounts,
            'histogram' => collect($histogram)->map(fn (int $value, string $key) => [
                'key' => $key,
                'label' => $key,
                'count' => $value,
            ])->values()->all(),
            'by_city' => collect($byCity)
                ->map(fn (array $row, string $label) => [
                    'label' => $label,
                    'average' => round($row['sum'] / max(1, $row['count']), 1),
                    'count' => $row['count'],
                ])
                ->sortByDesc('average')
                ->take(8)
                ->values()
                ->all(),
            'by_source' => collect($bySource)
                ->map(fn (array $row, string $label) => [
                    'label' => $label,
                    'average' => round($row['sum'] / max(1, $row['count']), 1),
                    'count' => $row['count'],
                ])
                ->sortByDesc('average')
                ->values()
                ->all(),
            'by_channel' => collect([
                'lab' => 'Laboratorio',
                'pharmacy' => 'Farmacia',
                'membership' => 'Membresía',
            ])->map(function (string $label, string $key) use ($byChannelInterest) {
                $row = $byChannelInterest[$key];

                return [
                    'label' => $label,
                    'average' => $row['count'] > 0 ? round($row['sum'] / $row['count'], 1) : null,
                    'count' => $row['count'],
                ];
            })->values()->all(),
            'segments' => $segments,
            'sample_size' => count($scored),
        ];
    }

    public function paginateCustomers(CustomerHealthFilter $filter, int $perPage = 20): LengthAwarePaginator
    {
        $analysis = $this->analyzeCohort($filter);
        $rows = collect($analysis['scored']);

        $rows = match ($filter->sort) {
            'health_asc' => $rows->sortBy('health_score'),
            'ltv_desc' => $rows->sortByDesc('ltv'),
            'churn_desc' => $rows->sortByDesc(fn ($r) => $r['probabilities']['churn'] ?? 0),
            'recent' => $rows->sortBy('days_since_activity'),
            default => $rows->sortByDesc('health_score'),
        };

        $rows = $rows->values();
        $page = max(1, $filter->page ?? 1);
        $total = $rows->count();
        $slice = $rows->forPage($page, $perPage)->values();

        return new \Illuminate\Pagination\LengthAwarePaginator(
            $slice,
            $total,
            $perPage,
            $page,
            [
                'path' => request()->url(),
                'query' => request()->query(),
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function customerDrawer(Customer $customer): array
    {
        $customer->loadMissing([
            'user',
            'customerable',
            'laboratoryCartItems.laboratoryTest',
            'onlinePharmacyCartItems',
            'laboratoryCheckoutDrafts',
            'laboratoryPurchases',
            'onlinePharmacyPurchases',
            'medicalAttentionSubscriptions',
            'addresses',
        ]);

        $customer->loadCount([
            'laboratoryCartItems',
            'onlinePharmacyCartItems',
            'laboratoryCheckoutDrafts',
            'laboratoryPurchases',
            'onlinePharmacyPurchases',
            'medicalAttentionSubscriptions',
        ]);
        $customer->loadSum('laboratoryPurchases', 'total_cents');
        $customer->loadSum('onlinePharmacyPurchases', 'total_cents');
        $customer->loadSum('medicalAttentionSubscriptions', 'price_cents');

        $health = $this->scoreCustomer($customer);

        $timeline = [];
        $timeline[] = [
            'label' => 'Registro',
            'at' => $customer->formatted_created_at,
            'detail' => 'Alta en Famedic',
        ];
        if ($customer->user?->email_verified_at) {
            $timeline[] = [
                'label' => 'Email verificado',
                'at' => localizedDate($customer->user->email_verified_at)?->isoFormat('D MMM Y h:mm a'),
                'detail' => $customer->user->email,
            ];
        }
        if ($customer->user?->phone_verified_at) {
            $timeline[] = [
                'label' => 'Teléfono verificado',
                'at' => localizedDate($customer->user->phone_verified_at)?->isoFormat('D MMM Y h:mm a'),
                'detail' => $customer->user->full_phone,
            ];
        }
        foreach ($customer->laboratoryCartItems->take(4) as $item) {
            $timeline[] = [
                'label' => 'Producto laboratorio',
                'at' => localizedDate($item->created_at)?->isoFormat('D MMM Y h:mm a'),
                'detail' => $item->laboratoryTest?->name ?? 'Estudio',
            ];
        }
        foreach ($customer->laboratoryCheckoutDrafts->take(3) as $draft) {
            $timeline[] = [
                'label' => 'Checkout',
                'at' => localizedDate($draft->updated_at ?? $draft->created_at)?->isoFormat('D MMM Y h:mm a'),
                'detail' => 'Borrador de checkout',
            ];
        }
        foreach ($customer->laboratoryPurchases->take(3) as $purchase) {
            $timeline[] = [
                'label' => 'Compra laboratorio',
                'at' => localizedDate($purchase->created_at)?->isoFormat('D MMM Y h:mm a'),
                'detail' => 'Orden lab',
            ];
        }
        foreach ($customer->onlinePharmacyPurchases->take(2) as $purchase) {
            $timeline[] = [
                'label' => 'Compra farmacia',
                'at' => localizedDate($purchase->created_at)?->isoFormat('D MMM Y h:mm a'),
                'detail' => 'Orden farmacia',
            ];
        }
        foreach ($customer->medicalAttentionSubscriptions->take(2) as $sub) {
            $timeline[] = [
                'label' => 'Membresía',
                'at' => localizedDate($sub->created_at)?->isoFormat('D MMM Y h:mm a'),
                'detail' => 'Suscripción',
            ];
        }

        $favorites = $customer->laboratoryCartItems
            ->groupBy(fn ($item) => $item->laboratoryTest?->name ?? 'Estudio')
            ->map->count()
            ->sortDesc()
            ->take(5)
            ->map(fn ($count, $name) => ['name' => $name, 'count' => $count])
            ->values()
            ->all();

        return [
            'customer_id' => $customer->id,
            'summary' => [
                'name' => $this->resolveName($customer),
                'email' => $customer->user?->email,
                'phone' => $customer->user?->full_phone,
                'show_url' => route('admin.customers.show', $customer),
            ],
            'health' => $health->toArray(),
            'ai_summary' => $this->buildAiSummary($health, $customer),
            'timeline' => $timeline,
            'purchases' => [
                'lab' => $customer->laboratory_purchases_count,
                'pharmacy' => $customer->online_pharmacy_purchases_count,
                'membership' => $customer->medical_attention_subscriptions_count,
                'ltv_formatted' => $health->toArray()['ltv_formatted'],
            ],
            'favorites' => $favorites,
            'campaigns' => [
                'note' => 'Sync ActiveCampaign pendiente — estructura lista para eventos de apertura/clic.',
                'tags' => array_merge(
                    ['health-'.$health->band],
                    $health->persona !== 'standard' ? [$health->persona] : []
                ),
            ],
            'automations' => $health->recommendedActions,
        ];
    }

    private function buildAiSummary(CustomerHealthScoreData $health, Customer $customer): string
    {
        $name = $this->resolveName($customer);
        $parts = [
            "{$name} tiene Health Score {$health->healthScore} ({$health->bandLabel}).",
            'Persona: '.$health->persona.'.',
            'Probabilidad de compra '.$health->probabilities['purchase'].'% · riesgo de abandono '.$health->probabilities['churn'].'%.',
        ];
        if ($health->recommendedActions !== []) {
            $parts[] = 'Acción sugerida: '.$health->recommendedActions[0];
        }

        return implode(' ', $parts);
    }

    private function resolveSourceLabel(Customer $customer): string
    {
        if ($customer->user?->referred_by) {
            return 'Referidos';
        }

        return match ($customer->customerable_type) {
            OdessaAfiliateAccount::class => 'Odessa',
            FamilyAccount::class => 'Familiar',
            default => 'Orgánico',
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
}
