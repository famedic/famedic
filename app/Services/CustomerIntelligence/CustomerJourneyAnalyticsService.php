<?php

namespace App\Services\CustomerIntelligence;

use App\DTOs\CustomerIntelligence\JourneyPathData;
use App\DTOs\CustomerIntelligence\JourneyStageData;
use App\Support\CustomerIntelligence\CustomerJourneyFilter;
use Illuminate\Support\Facades\Cache;

class CustomerJourneyAnalyticsService
{
    public function __construct(
        private CustomerJourneyRepository $repository,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(CustomerJourneyFilter $filter): array
    {
        $resolver = function () use ($filter) {
            $counts = $this->repository->stageCounts($filter);
            $previousCounts = $this->repository->stageCounts($filter, $filter->previousStart, $filter->previousEnd);
            $timing = $this->repository->timingMetrics($filter);
            $stages = $this->repository->buildStages($counts, $timing['avg_days_to_next']);
            $previousStages = $this->repository->buildStages($previousCounts);
            $sankeyLinks = $this->repository->sankeyLinks($counts);
            $paths = $this->repository->topPaths($counts);
            $heatmap = $this->repository->heatmap($filter);
            $predictive = $this->repository->predictiveSegments($filter);

            $registered = $counts['registration'] ?? 0;
            $purchased = $counts['first_purchase'] ?? 0;
            $conversion = $registered > 0 ? round(($purchased / $registered) * 100, 1) : null;
            $prevRegistered = $previousCounts['registration'] ?? 0;
            $prevPurchased = $previousCounts['first_purchase'] ?? 0;
            $prevConversion = $prevRegistered > 0 ? round(($prevPurchased / $prevRegistered) * 100, 1) : null;
            $abandonment = $registered > 0 ? round((($registered - $purchased) / $registered) * 100, 1) : null;

            return [
                'kpis' => $this->buildKpis($counts, $previousCounts, $conversion, $prevConversion, $abandonment, $timing),
                'stages' => array_map(fn (JourneyStageData $s) => $s->toArray(), $stages),
                'previous_stages' => array_map(fn (JourneyStageData $s) => $s->toArray(), $previousStages),
                'funnel' => $this->compactFunnel($stages),
                'sankey' => [
                    'links' => $sankeyLinks,
                    'nodes' => $this->sankeyNodes($sankeyLinks),
                ],
                'timeline' => $timing['timeline'],
                'heatmap' => $heatmap,
                'paths' => array_map(fn (JourneyPathData $p) => $p->toArray(), $paths),
                'marketing_insights' => $this->buildMarketingInsights($stages, $timing, $counts),
                'ai_insights' => $this->buildAiInsights($stages, $timing, $counts),
                'automations' => $this->buildAutomations(),
                'predictive' => $this->buildPredictiveCards($predictive, $registered),
                'compare' => [
                    'mode' => $filter->compareMode,
                    'current_label' => $filter->startLocal->toDateString().' — '.$filter->endLocal->toDateString(),
                    'previous_label' => $filter->previousStart->timezone('America/Monterrey')->toDateString()
                        .' — '.$filter->previousEnd->timezone('America/Monterrey')->toDateString(),
                    'current_conversion' => $conversion,
                    'previous_conversion' => $prevConversion,
                ],
                'meta' => [
                    'generated_at' => now('America/Monterrey')->format('d/m/Y H:i'),
                    'definitions' => [
                        'journey' => 'Cohorte de clientes registrados en el periodo y su avance por hitos disponibles en Famedic.',
                        'login' => 'Proxy: verificación de email o teléfono (sin event stream de sesiones aún).',
                        'search' => 'Proxy: interacción con carrito/compras (sin eventos de búsqueda nativos).',
                        'sankey' => 'Flujos estimados entre etapas; el nodo Abandono concentra caídas relevantes.',
                    ],
                    'data_gaps' => [
                        'Integrar eventos GA4 / FullStory / ActiveCampaign para hitos de visita y login reales.',
                        'UTM y Meta Ads permitirán atribución por campaña en el journey.',
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
     * @param  array<string, int>  $counts
     * @param  array<string, int>  $previous
     * @param  array<string, mixed>  $timing
     * @return list<array<string, mixed>>
     */
    private function buildKpis(
        array $counts,
        array $previous,
        ?float $conversion,
        ?float $prevConversion,
        ?float $abandonment,
        array $timing,
    ): array {
        return [
            $this->kpi('registered', 'Usuarios registrados', $counts['registration'] ?? 0, $previous['registration'] ?? 0, 'number', 'blue'),
            $this->kpi('active', 'Usuarios activos', $counts['first_login'] ?? 0, $previous['first_login'] ?? 0, 'number', 'green', hint: 'Con verificación / engagement'),
            $this->kpi('email_verified', 'Verificaron correo', $counts['email_verified'] ?? 0, $previous['email_verified'] ?? 0, 'number', 'slate'),
            $this->kpi('logged_in', 'Iniciaron sesión', $counts['first_login'] ?? 0, $previous['first_login'] ?? 0, 'number', 'blue', hint: 'Proxy de login'),
            $this->kpi('with_cart', 'Con carrito', $counts['added_cart'] ?? 0, $previous['added_cart'] ?? 0, 'number', 'orange'),
            $this->kpi('purchased', 'Compraron', $counts['first_purchase'] ?? 0, $previous['first_purchase'] ?? 0, 'number', 'green'),
            $this->kpi('conversion', 'Conversión total', $conversion, $prevConversion, 'percent', 'purple'),
            $this->kpi('abandonment', 'Abandono general', $abandonment, null, 'percent', 'red', higherIsWorse: true),
            $this->kpi('time_to_purchase', 'Registro → Compra', $timing['avg_reg_to_purchase'] ?? null, null, 'days', 'blue'),
        ];
    }

    /**
     * @param  list<JourneyStageData>  $stages
     * @return list<array<string, mixed>>
     */
    private function compactFunnel(array $stages): array
    {
        $keys = ['registration', 'email_verified', 'first_login', 'added_cart', 'started_checkout', 'first_purchase'];

        return collect($stages)
            ->filter(fn (JourneyStageData $s) => in_array($s->key, $keys, true))
            ->map(fn (JourneyStageData $s) => $s->toArray())
            ->values()
            ->all();
    }

    /**
     * @param  list<array{source: string, target: string, value: int}>  $links
     * @return list<array{name: string}>
     */
    private function sankeyNodes(array $links): array
    {
        return collect($links)
            ->flatMap(fn (array $link) => [$link['source'], $link['target']])
            ->unique()
            ->values()
            ->map(fn (string $name) => ['name' => $name])
            ->all();
    }

    /**
     * @param  list<JourneyStageData>  $stages
     * @param  array<string, mixed>  $timing
     * @param  array<string, int>  $counts
     * @return list<array{id: string, label: string, value: string, detail: string, tone: string}>
     */
    private function buildMarketingInsights(array $stages, array $timing, array $counts): array
    {
        $worstDrop = collect($stages)
            ->filter(fn (JourneyStageData $s) => $s->dropoffPercent !== null)
            ->sortByDesc(fn (JourneyStageData $s) => $s->dropoffPercent)
            ->first();

        $prevLabel = null;
        foreach ($stages as $index => $stage) {
            if ($worstDrop && $stage->key === $worstDrop->key && $index > 0) {
                $prevLabel = $stages[$index - 1]->label;
                break;
            }
        }

        $registered = max(1, $counts['registration'] ?? 1);
        $emailVerified = $counts['email_verified'] ?? 0;
        $purchased = $counts['first_purchase'] ?? 0;
        $verifiedPurchaseRate = $emailVerified > 0
            ? round(($purchased / max($emailVerified, 1)) * 100, 1)
            : null;

        return [
            [
                'id' => 'biggest_drop',
                'label' => 'Mayor abandono',
                'value' => $worstDrop
                    ? ($prevLabel ? "{$prevLabel} → {$worstDrop->label}" : $worstDrop->label)
                    : '—',
                'detail' => $worstDrop ? "{$worstDrop->dropoffPercent}% de caída en la transición" : 'Sin datos',
                'tone' => 'red',
            ],
            [
                'id' => 'time_to_buy',
                'label' => 'Tiempo promedio a compra',
                'value' => isset($timing['avg_reg_to_purchase'])
                    ? number_format((float) $timing['avg_reg_to_purchase'], 1).' días'
                    : '—',
                'detail' => 'Desde el registro hasta la primera compra',
                'tone' => 'blue',
            ],
            [
                'id' => 'email_lift',
                'label' => 'Impacto de verificar correo',
                'value' => $verifiedPurchaseRate !== null ? "{$verifiedPurchaseRate}% de verificados compran" : '—',
                'detail' => 'Los usuarios verificados concentran la conversión del cohort',
                'tone' => 'green',
            ],
            [
                'id' => 'cart_pressure',
                'label' => 'Presión de carrito',
                'value' => number_format($counts['added_cart'] ?? 0),
                'detail' => 'Usuarios del cohort que agregaron productos',
                'tone' => 'orange',
            ],
            [
                'id' => 'checkout_gap',
                'label' => 'Gap carrito → checkout',
                'value' => number_format(max(0, ($counts['added_cart'] ?? 0) - ($counts['started_checkout'] ?? 0))),
                'detail' => 'Oportunidad inmediata de recuperación',
                'tone' => 'orange',
            ],
            [
                'id' => 'frequent',
                'label' => 'Clientes frecuentes',
                'value' => number_format($counts['frequent'] ?? 0),
                'detail' => '3+ compras en el journey del cohort',
                'tone' => 'purple',
            ],
            [
                'id' => 'lab_interest',
                'label' => 'Interés en laboratorios',
                'value' => number_format($counts['visited_lab'] ?? 0),
                'detail' => round((($counts['visited_lab'] ?? 0) / $registered) * 100, 1).'% del cohort',
                'tone' => 'blue',
            ],
            [
                'id' => 'pharmacy_interest',
                'label' => 'Interés en farmacia',
                'value' => number_format($counts['visited_pharmacy'] ?? 0),
                'detail' => round((($counts['visited_pharmacy'] ?? 0) / $registered) * 100, 1).'% del cohort',
                'tone' => 'slate',
            ],
        ];
    }

    /**
     * @param  list<JourneyStageData>  $stages
     * @param  array<string, mixed>  $timing
     * @param  array<string, int>  $counts
     * @return array{headline: string, findings: list<string>, recommendations: list<string>}
     */
    private function buildAiInsights(array $stages, array $timing, array $counts): array
    {
        $registered = max(1, $counts['registration'] ?? 1);
        $beforeCheckout = max(0, $registered - ($counts['started_checkout'] ?? 0));
        $beforeCheckoutPct = round(($beforeCheckout / $registered) * 100, 1);
        $cartToCheckout = null;
        foreach ($stages as $stage) {
            if ($stage->key === 'started_checkout') {
                $cartToCheckout = $stage->dropoffPercent;
            }
        }

        $findings = [
            "El {$beforeCheckoutPct}% del cohort no llega a iniciar checkout.",
            'Los usuarios con interés en laboratorios concentran gran parte del top de conversión del funnel.',
            isset($timing['avg_reg_to_purchase'])
                ? "El tiempo medio a primera compra es de {$timing['avg_reg_to_purchase']} días — ventana crítica para nurturing."
                : 'Aún no hay suficiente volumen de compras para estimar el tiempo a conversión.',
            $cartToCheckout !== null
                ? "El cuello de botella carrito → checkout muestra {$cartToCheckout}% de abandono."
                : 'El mayor fricción suele aparecer entre exploración y checkout.',
        ];

        return [
            'headline' => 'AI Journey Analysis',
            'findings' => $findings,
            'recommendations' => [
                'Reducir campos del checkout',
                'Enviar WhatsApp a las 48 horas tras carrito abandonado',
                'Activar cupón de primera compra en días 5–10',
                'Recordatorio de verificación de email el día 1',
                'Priorizar tráfico hacia laboratorios de mayor conversión',
            ],
        ];
    }

    /**
     * @param  array{high_probability: int, at_risk: int, recoverable: int, lost: int}  $predictive
     * @return list<array{id: string, label: string, count: int, percent: float, tone: string, description: string}>
     */
    private function buildPredictiveCards(array $predictive, int $registered): array
    {
        $base = max(1, $registered);

        return [
            [
                'id' => 'high_probability',
                'label' => 'Alta probabilidad de comprar',
                'count' => $predictive['high_probability'],
                'percent' => round(($predictive['high_probability'] / $base) * 100, 1),
                'tone' => 'green',
                'description' => 'Checkout iniciado o carrito caliente sin compra',
            ],
            [
                'id' => 'at_risk',
                'label' => 'Usuarios en riesgo',
                'count' => $predictive['at_risk'],
                'percent' => round(($predictive['at_risk'] / $base) * 100, 1),
                'tone' => 'orange',
                'description' => 'Verificados sin compra',
            ],
            [
                'id' => 'recoverable',
                'label' => 'Usuarios recuperables',
                'count' => $predictive['recoverable'],
                'percent' => round(($predictive['recoverable'] / $base) * 100, 1),
                'tone' => 'blue',
                'description' => 'Con carrito y sin compra',
            ],
            [
                'id' => 'lost',
                'label' => 'Usuarios perdidos',
                'count' => $predictive['lost'],
                'percent' => round(($predictive['lost'] / $base) * 100, 1),
                'tone' => 'red',
                'description' => 'Sin actividad 30+ días y sin compra',
            ],
        ];
    }

    /**
     * @return list<array{id: string, label: string, description: string, icon: string, enabled: bool}>
     */
    private function buildAutomations(): array
    {
        return [
            ['id' => 'campaign', 'label' => 'Crear campaña', 'description' => 'Journey nurturing day 1–10', 'icon' => 'megaphone', 'enabled' => false],
            ['id' => 'whatsapp', 'label' => 'Enviar WhatsApp', 'description' => 'Recuperación de carrito', 'icon' => 'chat', 'enabled' => false],
            ['id' => 'email', 'label' => 'Enviar Email', 'description' => 'Secuencia post-registro', 'icon' => 'envelope', 'enabled' => false],
            ['id' => 'segment', 'label' => 'Crear segmento', 'description' => 'Exportar cohorte journey', 'icon' => 'queue', 'enabled' => false],
            ['id' => 'tag', 'label' => 'Agregar Tag', 'description' => 'Tag journey-stage', 'icon' => 'tag', 'enabled' => false],
            ['id' => 'coupon', 'label' => 'Crear Cupón', 'description' => 'Incentivo primera compra', 'icon' => 'ticket', 'enabled' => false],
            ['id' => 'automation', 'label' => 'Programar automatización', 'description' => 'Flujo ActiveCampaign', 'icon' => 'bolt', 'enabled' => false],
        ];
    }

    /**
     * @param  list<array{date?: string, label?: string, value?: int}>  $sparkline
     * @return array<string, mixed>
     */
    private function kpi(
        string $id,
        string $label,
        int|float|null $value,
        int|float|null $previous,
        string $format,
        string $tone,
        bool $higherIsWorse = false,
        ?string $hint = null,
        array $sparkline = [],
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

            return [
                'percent' => 100.0,
                'direction' => $direction,
                'is_positive' => $higherIsWorse ? $direction === 'down' : $direction === 'up',
            ];
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
            'percent' => number_format((float) $value, 1).'%',
            'days' => number_format((float) $value, 1).' días',
            default => number_format((float) $value),
        };
    }
}
