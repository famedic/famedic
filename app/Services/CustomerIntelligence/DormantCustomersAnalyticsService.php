<?php

namespace App\Services\CustomerIntelligence;

use App\Data\StatesMexico;
use App\Support\CustomerIntelligence\DormantCustomersFilter;
use Illuminate\Support\Facades\Cache;

class DormantCustomersAnalyticsService
{
    public function __construct(
        private DormantCustomersRepository $repository,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(DormantCustomersFilter $filter): array
    {
        $resolver = function () use ($filter) {
            $dormantCount = $this->repository->countDormant($filter);
            $previousDormant = $this->repository->countDormant($filter, $filter->previousStart, $filter->previousEnd);
            $avgDaysDormant = $this->repository->averageDaysSinceRegistration($filter);
            $avgDaysToPurchase = $this->repository->averageDaysToFirstPurchase($filter);
            $recovered = $this->repository->recoveredCount($filter);
            $previousRecovered = $this->repository->recoveredCount($filter, $filter->previousStart, $filter->previousEnd);
            $avgTicketCents = $this->repository->averageTicketCents();
            $potentialRevenue = ($dormantCount * $avgTicketCents) / 100;
            $previousPotential = ($previousDormant * $avgTicketCents) / 100;
            $conversion = $this->repository->historicalConversionPercent($filter);

            $evolution = $this->repository->dormantEvolution($filter);
            $buckets = $this->repository->daysSinceRegistrationBuckets($filter);
            $bySource = $this->repository->byRegistrationSource($filter);
            $funnel = $this->repository->conversionFunnel($filter);
            $byState = $this->repository->byState($filter);
            $byCity = $this->repository->byCity($filter);
            $segments = $this->repository->segmentCounts($filter);
            $sourceConversion = $this->repository->sourceConversion($filter);
            $timing = $this->repository->advancedTimingMetrics($filter);

            $spark = collect($evolution)->map(fn (array $row) => [
                'date' => $row['date'],
                'label' => $row['label'],
                'value' => $row['value'],
            ])->take(-14)->values()->all();

            return [
                'kpis' => [
                    $this->kpi(
                        id: 'abandoned',
                        label: 'Clientes dormidos',
                        value: $dormantCount,
                        previous: $previousDormant,
                        format: 'number',
                        tone: 'orange',
                        sparkline: $spark,
                        higherIsWorse: true,
                        hint: 'Registrados sin compra en el periodo',
                    ),
                    $this->kpi(
                        id: 'tag',
                        label: 'Tiempo promedio desde registro',
                        value: $avgDaysDormant,
                        previous: null,
                        format: 'days',
                        tone: 'slate',
                        sparkline: [],
                        hint: 'Días promedio sin comprar',
                    ),
                    $this->kpi(
                        id: 'sales',
                        label: 'Tiempo a primera compra',
                        value: $avgDaysToPurchase,
                        previous: null,
                        format: 'days',
                        tone: 'blue',
                        sparkline: [],
                        hint: 'Solo clientes que sí compraron',
                    ),
                    $this->kpi(
                        id: 'recovered',
                        label: 'Clientes recuperados',
                        value: $recovered,
                        previous: $previousRecovered,
                        format: 'number',
                        tone: 'green',
                        sparkline: [],
                        hint: 'Primera compra en el periodo',
                    ),
                    $this->kpi(
                        id: 'recovered_value',
                        label: 'Ingresos potenciales',
                        value: $potentialRevenue,
                        previous: $previousPotential,
                        format: 'money',
                        tone: 'purple',
                        sparkline: [],
                        hint: 'Dormidos × ticket promedio',
                    ),
                    $this->kpi(
                        id: 'conversion',
                        label: 'Conversión histórica',
                        value: $conversion,
                        previous: null,
                        format: 'percent',
                        tone: 'blue',
                        sparkline: [],
                        hint: 'Registrados del periodo que compraron',
                    ),
                ],
                'evolution' => $evolution,
                'days_buckets' => $buckets,
                'by_source' => $bySource,
                'funnel' => $funnel,
                'by_state' => $byState,
                'by_city' => $byCity,
                'segments' => $this->buildSegments($segments),
                'source_conversion' => $sourceConversion,
                'marketing_intelligence' => $this->buildMarketingIntelligence(
                    $avgDaysToPurchase,
                    $sourceConversion,
                    $byCity,
                    $byState,
                    $segments,
                ),
                'ai_insights' => $this->buildAiInsights(
                    $funnel,
                    $sourceConversion,
                    $avgDaysToPurchase,
                    $dormantCount,
                    $segments,
                ),
                'automations' => $this->buildAutomations(),
                'advanced_metrics' => [
                    'avg_reg_to_purchase' => $timing['avg_reg_to_purchase'],
                    'avg_reg_to_cart' => $timing['avg_reg_to_cart'],
                    'avg_cart_to_purchase' => $timing['avg_cart_to_purchase'],
                    'conversion_by_source' => $sourceConversion['by_source'],
                    'conversion_by_state' => $byState,
                    'conversion_by_city' => $byCity,
                    'avg_ticket' => $avgTicketCents / 100,
                    'potential_ltv' => $potentialRevenue,
                    'recovered' => $recovered,
                    'dormant' => $dormantCount,
                ],
                'meta' => [
                    'generated_at' => now('America/Monterrey')->format('d/m/Y H:i'),
                    'previous_period' => [
                        'start_date' => $filter->previousStart->timezone('America/Monterrey')->toDateString(),
                        'end_date' => $filter->previousEnd->timezone('America/Monterrey')->toDateString(),
                    ],
                    'definitions' => [
                        'dormant' => 'Cliente sin compras de laboratorio, farmacia ni membresía.',
                        'recovered' => 'Cliente cuya primera compra ocurrió en el periodo seleccionado.',
                        'source' => 'Fuente derivada de tipo de cuenta y referidos (sin UTM nativo aún).',
                        'funnel' => 'Embudo aproximado con verificación, carritos y checkouts disponibles.',
                        'ai' => 'Lead score y probabilidad estimados a partir de engagement interno.',
                    ],
                    'data_gaps' => [
                        'No existe campo nativo de fuente de registro (UTM/campaña).',
                        'No hay tracking de último login ni dispositivo; se usa actividad del modelo.',
                        'ActiveCampaign en drawer es estructural; la sync profunda se conecta después.',
                    ],
                ],
            ];
        };

        if ($filter->bustCache) {
            Cache::forget($filter->cacheKey());
        }

        return Cache::remember($filter->cacheKey(), now()->addMinutes(5), $resolver);
    }

    /**
     * @param  list<array{date: string, label: string, value: int}>  $sparkline
     * @return array<string, mixed>
     */
    private function kpi(
        string $id,
        string $label,
        int|float|null $value,
        int|float|null $previous,
        string $format,
        string $tone,
        array $sparkline,
        bool $higherIsWorse = false,
        ?string $hint = null,
    ): array {
        $delta = $this->delta($value, $previous, $higherIsWorse);

        return [
            'id' => $id,
            'label' => $label,
            'value' => $value,
            'value_formatted' => $this->formatValue($value, $format),
            'previous_value' => $previous,
            'previous_formatted' => $this->formatValue($previous, $format),
            'format' => $format,
            'tone' => $tone,
            'delta_percent' => $delta['percent'],
            'delta_direction' => $delta['direction'],
            'delta_is_positive' => $delta['is_positive'],
            'sparkline' => $sparkline,
            'hint' => $hint,
        ];
    }

    /**
     * @return array{percent: float|null, direction: string, is_positive: bool|null}
     */
    private function delta(int|float|null $current, int|float|null $previous, bool $higherIsWorse): array
    {
        if ($current === null || $previous === null) {
            return ['percent' => null, 'direction' => 'flat', 'is_positive' => null];
        }

        if ((float) $previous === 0.0) {
            if ((float) $current === 0.0) {
                return ['percent' => 0.0, 'direction' => 'flat', 'is_positive' => null];
            }

            $direction = $current > 0 ? 'up' : 'down';
            $isPositive = $higherIsWorse ? $direction === 'down' : $direction === 'up';

            return ['percent' => 100.0, 'direction' => $direction, 'is_positive' => $isPositive];
        }

        $raw = (($current - $previous) / abs($previous)) * 100;
        $direction = $raw > 0.05 ? 'up' : ($raw < -0.05 ? 'down' : 'flat');
        $isPositive = match ($direction) {
            'flat' => null,
            'up' => ! $higherIsWorse,
            'down' => $higherIsWorse,
        };

        return [
            'percent' => round(abs($raw), 1),
            'direction' => $direction,
            'is_positive' => $isPositive,
        ];
    }

    private function formatValue(int|float|null $value, string $format): string
    {
        if ($value === null) {
            return '—';
        }

        return match ($format) {
            'money' => '$'.number_format((float) $value, 0).' MXN',
            'percent' => number_format((float) $value, 1).'%',
            'days' => number_format((float) $value, 1).' días',
            default => number_format((float) $value),
        };
    }

    /**
     * @param  array<string, int>  $counts
     * @return list<array{id: string, label: string, count: int, description: string, filter: array<string, string>}>
     */
    private function buildSegments(array $counts): array
    {
        return [
            ['id' => 'registered_7d', 'label' => 'Registrados hace 7 días', 'count' => $counts['registered_7d'], 'description' => 'Ventana ideal de nurturing inicial', 'filter' => ['days_bucket' => '0-7']],
            ['id' => 'registered_15d', 'label' => 'Registrados hace 15 días', 'count' => $counts['registered_15d'], 'description' => 'Seguimiento de mid-funnel', 'filter' => ['days_bucket' => '8-30']],
            ['id' => 'registered_30d', 'label' => 'Registrados hace 30 días', 'count' => $counts['registered_30d'], 'description' => 'Riesgo de enfriamiento', 'filter' => ['days_bucket' => '8-30']],
            ['id' => 'abandoned_cart', 'label' => 'Con carrito abandonado', 'count' => $counts['abandoned_cart'], 'description' => 'Alta intención de compra', 'filter' => []],
            ['id' => 'unverified_email', 'label' => 'Sin verificar email', 'count' => $counts['unverified_email'], 'description' => 'Bloqueo de comunicación', 'filter' => ['email_verification' => 'unverified']],
            ['id' => 'unverified_phone', 'label' => 'Sin verificar teléfono', 'count' => $counts['unverified_phone'], 'description' => 'Sin canal SMS/WhatsApp', 'filter' => ['phone_verification' => 'unverified']],
            ['id' => 'verified_both', 'label' => 'Email y teléfono verificados', 'count' => $counts['verified_both'], 'description' => 'Listos para campañas', 'filter' => ['email_verification' => 'verified', 'phone_verification' => 'verified']],
            ['id' => 'visited_lab', 'label' => 'Visitaron laboratorios', 'count' => $counts['visited_lab_cart'], 'description' => 'Interés en estudios', 'filter' => []],
            ['id' => 'visited_pharmacy', 'label' => 'Visitaron farmacia', 'count' => $counts['visited_pharmacy_cart'], 'description' => 'Interés en medicamentos', 'filter' => []],
            ['id' => 'referred', 'label' => 'Clientes referidos', 'count' => $counts['referred'], 'description' => 'Origen referencial', 'filter' => ['referral_status' => 'referred']],
            ['id' => 'inactive_30d', 'label' => 'Sin actividad 30+ días', 'count' => $counts['no_login_proxy_30d'], 'description' => 'Candidatos a win-back', 'filter' => ['days_bucket' => '90+']],
            ['id' => 'period', 'label' => 'Dormidos del periodo', 'count' => $counts['period_dormant'], 'description' => 'Según filtros actuales', 'filter' => []],
        ];
    }

    /**
     * @param  array{by_source: list, best: ?array, worst: ?array}  $sourceConversion
     * @param  list<array{label: string, value: int}>  $byCity
     * @param  list<array{label: string, value: int}>  $byState
     * @param  array<string, int>  $segments
     * @return list<array{id: string, label: string, value: string, detail: string, tone: string}>
     */
    private function buildMarketingIntelligence(
        ?float $avgDaysToPurchase,
        array $sourceConversion,
        array $byCity,
        array $byState,
        array $segments,
    ): array {
        $best = $sourceConversion['best'] ?? null;
        $worst = $sourceConversion['worst'] ?? null;
        $topCity = $byCity[0] ?? null;
        $topState = $byState[0] ?? null;

        $windowStart = $avgDaysToPurchase !== null ? max(1, (int) round($avgDaysToPurchase * 0.45)) : 8;
        $windowEnd = $avgDaysToPurchase !== null ? max($windowStart + 3, (int) round($avgDaysToPurchase * 0.9)) : 16;

        return [
            [
                'id' => 'best_contact_window',
                'label' => 'Mejor momento para contactar',
                'value' => "Días {$windowStart}–{$windowEnd}",
                'detail' => $avgDaysToPurchase !== null
                    ? "Los clientes suelen realizar su primera compra alrededor del día {$avgDaysToPurchase}."
                    : 'Aún no hay suficiente historial de primera compra.',
                'tone' => 'blue',
            ],
            [
                'id' => 'best_source',
                'label' => 'Fuente con mayor conversión',
                'value' => $best ? "{$best['label']} · {$best['conversion']}%" : '—',
                'detail' => $best ? "{$best['converted']} convertidos de {$best['registered']} registros" : 'Sin datos',
                'tone' => 'green',
            ],
            [
                'id' => 'worst_source',
                'label' => 'Fuente con menor conversión',
                'value' => $worst ? "{$worst['label']} · {$worst['conversion']}%" : '—',
                'detail' => $worst ? "{$worst['dormant']} dormidos de {$worst['registered']} registros" : 'Sin datos',
                'tone' => 'orange',
            ],
            [
                'id' => 'top_city_abandon',
                'label' => 'Ciudad con mayor abandono',
                'value' => $topCity['label'] ?? '—',
                'detail' => $topCity ? number_format($topCity['value']).' clientes dormidos' : 'Sin direcciones',
                'tone' => 'red',
            ],
            [
                'id' => 'top_state_abandon',
                'label' => 'Estado con mayor abandono',
                'value' => $topState['label'] ?? '—',
                'detail' => $topState ? number_format($topState['value']).' clientes dormidos' : 'Sin estados',
                'tone' => 'red',
            ],
            [
                'id' => 'lab_interest',
                'label' => 'Interés en laboratorio',
                'value' => number_format($segments['visited_lab_cart']),
                'detail' => 'Dormidos con ítems de laboratorio en carrito',
                'tone' => 'purple',
            ],
            [
                'id' => 'pharmacy_interest',
                'label' => 'Interés en farmacia',
                'value' => number_format($segments['visited_pharmacy_cart']),
                'detail' => 'Dormidos con ítems de farmacia en carrito',
                'tone' => 'purple',
            ],
            [
                'id' => 'abandoned_carts',
                'label' => 'Carritos abandonados',
                'value' => number_format($segments['abandoned_cart']),
                'detail' => 'Oportunidad inmediata de recuperación',
                'tone' => 'orange',
            ],
            [
                'id' => 'verification_gap',
                'label' => 'Brecha de verificación',
                'value' => number_format($segments['unverified_email'] + $segments['unverified_phone']),
                'detail' => 'Email o teléfono sin verificar',
                'tone' => 'slate',
            ],
            [
                'id' => 'referred_dormant',
                'label' => 'Referidos dormidos',
                'value' => number_format($segments['referred']),
                'detail' => 'Candidatos a incentivo de referida',
                'tone' => 'blue',
            ],
        ];
    }

    /**
     * @param  list<array{stage: string, label: string, value: int, dropoff_percent: float|null}>  $funnel
     * @param  array{by_source: list, best: ?array, worst: ?array}  $sourceConversion
     * @param  array<string, int>  $segments
     * @return array{headline: string, findings: list<string>, recommendations: list<string>}
     */
    private function buildAiInsights(
        array $funnel,
        array $sourceConversion,
        ?float $avgDaysToPurchase,
        int $dormantCount,
        array $segments,
    ): array {
        $findings = [];

        $checkoutStage = collect($funnel)->firstWhere('stage', 'checkout');
        $purchaseStage = collect($funnel)->firstWhere('stage', 'purchase');
        $exploredStage = collect($funnel)->firstWhere('stage', 'explored');

        if ($checkoutStage && $exploredStage && $exploredStage['value'] > 0) {
            $drop = $checkoutStage['dropoff_percent'] ?? null;
            if ($drop !== null && $drop > 0) {
                $findings[] = "{$drop}% de quienes agregan al carrito no llegan a iniciar checkout.";
            }
        }

        if ($purchaseStage && $checkoutStage && $checkoutStage['value'] > 0) {
            $abandon = $purchaseStage['dropoff_percent'] ?? null;
            if ($abandon !== null) {
                $findings[] = "{$abandon}% abandona entre checkout y primera compra.";
            }
        }

        $best = $sourceConversion['best'] ?? null;
        $worst = $sourceConversion['worst'] ?? null;
        if ($best) {
            $findings[] = "La fuente {$best['label']} convierte al {$best['conversion']}%, la más alta del periodo.";
        }
        if ($worst && $best && $worst['key'] !== $best['key']) {
            $findings[] = "{$worst['label']} solo convierte al {$worst['conversion']}% — revisar calidad de tráfico.";
        }

        if ($avgDaysToPurchase !== null) {
            $d7 = max(1, (int) round($avgDaysToPurchase * 0.4));
            $d10 = max($d7 + 1, (int) round($avgDaysToPurchase * 0.6));
            $findings[] = "Los clientes que reciben seguimiento entre el día {$d7} y {$d10} están alineados a la ventana histórica de primera compra ({$avgDaysToPurchase} días).";
        }

        $findings[] = "Hay ".number_format($dormantCount).' clientes dormidos en el periodo filtrado; '
            .number_format($segments['abandoned_cart']).' ya mostraron intención con carrito.';

        $recommendations = [
            'Enviar recordatorio automatizado a los 7 días del registro',
            'Ofrecer cupón del 10% a dormidos con carrito abandonado',
            'Crear campaña win-back para clientes sin compras mayores a 30 días',
            'Priorizar WhatsApp en perfiles con teléfono verificado',
            'Activar secuencia de verificación para emails pendientes',
            'Reforzar presupuesto en la fuente de mayor conversión',
        ];

        return [
            'headline' => 'Análisis automático del embudo de activación',
            'findings' => $findings,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * @return list<array{id: string, label: string, description: string, icon: string, enabled: bool}>
     */
    private function buildAutomations(): array
    {
        return [
            ['id' => 'email', 'label' => 'Enviar Email', 'description' => 'Secuencia de reactivación', 'icon' => 'envelope', 'enabled' => false],
            ['id' => 'whatsapp', 'label' => 'Enviar WhatsApp', 'description' => 'Mensaje de primera compra', 'icon' => 'chat', 'enabled' => false],
            ['id' => 'coupon', 'label' => 'Crear Cupón', 'description' => 'Incentivo 10% primera compra', 'icon' => 'ticket', 'enabled' => false],
            ['id' => 'tag', 'label' => 'Agregar Tag', 'description' => 'Tag dormant en ActiveCampaign', 'icon' => 'tag', 'enabled' => false],
            ['id' => 'automation', 'label' => 'Crear Automatización', 'description' => 'Flujo day-7 / day-15 / day-30', 'icon' => 'bolt', 'enabled' => false],
            ['id' => 'list', 'label' => 'Mover Lista ActiveCampaign', 'description' => 'Lista Clientes Dormidos', 'icon' => 'queue', 'enabled' => false],
            ['id' => 'push', 'label' => 'Enviar Push', 'description' => 'Notificación app (próximamente)', 'icon' => 'bell', 'enabled' => false],
            ['id' => 'followup', 'label' => 'Programar Seguimiento', 'description' => 'Tarea comercial / growth', 'icon' => 'calendar', 'enabled' => false],
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function stateOptions(): array
    {
        return collect(StatesMexico::todos())
            ->map(fn (string $label, string $value) => [
                'value' => $value,
                'label' => $label,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function cityOptions(): array
    {
        return $this->repository->availableCities()
            ->map(fn (string $city) => [
                'value' => $city,
                'label' => $city,
            ])
            ->values()
            ->all();
    }
}
