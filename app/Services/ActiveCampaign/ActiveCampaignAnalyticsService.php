<?php

namespace App\Services\ActiveCampaign;

use App\Support\ActiveCampaign\ActiveCampaignDashboardFilter;

/**
 * Analytics del Marketing Intelligence Center.
 * Capa de decisión sobre agregaciones existentes del Dashboard (única fuente).
 * No introduce consultas de negocio nuevas.
 */
class ActiveCampaignAnalyticsService
{
    private ActiveCampaignDashboardService $dashboard;

    public function __construct(ActiveCampaignDashboardService $dashboard)
    {
        $this->dashboard = $dashboard;
    }

    /**
     * @return array{filters: array<string, string>, meta: array<string, mixed>, domains: list<array<string, mixed>>}
     */
    public function build(ActiveCampaignDashboardFilter $filter): array
    {
        $overview = $this->dashboard->buildOverview($filter);

        $healthById = collect($overview['health'])->keyBy('id');
        $businessById = collect($overview['business'])->keyBy('id');

        return [
            'filters' => $filter->toArray(),
            'meta' => [
                ...$overview['meta'],
                'purpose' => 'Apoyar decisiones operativas, comerciales y de marketing con datos ya existentes en Famedic.',
                'source_of_truth' => 'ActiveCampaignDashboardService',
            ],
            'domains' => [
                $this->operationsDomain($healthById, $overview),
                $this->businessDomain($businessById, $overview),
                $this->customersDomain($healthById, $businessById, $overview),
                $this->marketingDomain($healthById, $businessById, $overview),
            ],
        ];
    }

    /**
     * Reutiliza buildCharts del Dashboard (misma cache / mismas series).
     *
     * @return array<string, mixed>
     */
    public function buildCharts(ActiveCampaignDashboardFilter $filter): array
    {
        return $this->dashboard->buildCharts($filter);
    }

    /**
     * @param  \Illuminate\Support\Collection<string, array<string, mixed>>  $health
     * @param  array<string, mixed>  $overview
     * @return array<string, mixed>
     */
    private function operationsDomain($health, array $overview): array
    {
        $errors = $this->numericCard($health->get('errors'));
        $backlog = $this->numericCard($health->get('backlog'));
        $integration = $health->get('integration');

        $insights = [];
        $recommendations = [];
        $risks = [];

        if ($backlog > 0) {
            $insights[] = $this->item(
                'Hay dispatches pendientes o en procesamiento en la cola local.',
                'disponible',
            );
            $risks[] = $this->item(
                'Backlog > 0: la sincronización puede retrasarse o saturarse.',
                'disponible',
            );
            $recommendations[] = $this->item(
                'Revisar workers/cola y la pantalla Logs / Health Center.',
                'disponible',
            );
        } else {
            $insights[] = $this->item(
                'Cola local sin backlog (pending/processing = 0).',
                'disponible',
            );
        }

        if ($errors > 0) {
            $insights[] = $this->item(
                "Se registraron {$errors} dispatches failed en el periodo.",
                'disponible',
            );
            $risks[] = $this->item(
                'Errores de sync en el periodo: impacto en créditos/promos/beneficiarios.',
                'disponible',
            );
            $recommendations[] = $this->item(
                'Priorizar revisión de errores recientes en Dashboard / Logs.',
                'disponible',
            );
        } else {
            $insights[] = $this->item(
                'Sin dispatches failed en el periodo seleccionado.',
                'disponible',
            );
        }

        $status = (string) ($integration['value'] ?? '');
        if (in_array($status, ['Sin credenciales', 'Desactivado'], true)) {
            $risks[] = $this->item(
                "Integración en estado «{$status}»: la operación de sync está comprometida.",
                'disponible',
            );
            $recommendations[] = $this->item(
                'Validar flags y credenciales en Configuración / Health Center.',
                'disponible',
            );
        }

        return $this->domain(
            id: 'operations',
            label: 'Operación',
            question: '¿La integración y la cola están saludables para operar hoy?',
            kpis: array_values(array_filter([
                $health->get('integration'),
                $health->get('errors'),
                $health->get('backlog'),
                $health->get('last_sync'),
            ])),
            chartKeys: ['sync_by_day', 'errors_by_day', 'dispatches_by_day'],
            insights: $insights,
            recommendations: $recommendations,
            risks: $risks,
            gaps: [],
        );
    }

    /**
     * @param  \Illuminate\Support\Collection<string, array<string, mixed>>  $business
     * @param  array<string, mixed>  $overview
     * @return array<string, mixed>
     */
    private function businessDomain($business, array $overview): array
    {
        $lab = $business->get('lab');
        $pharmacy = $business->get('pharmacy');
        $membership = $business->get('membership');
        $promo = $business->get('promo');

        $insights = [];
        $recommendations = [];
        $risks = [];

        $insights[] = $this->item(
            'Volúmenes de lab, farmacia y membresías reflejan actividad de producto (proxy de triggers), no confirmación de sync AC.',
            'proxy',
        );

        if ($this->kpiDown($lab)) {
            $insights[] = $this->item(
                'Compras de laboratorio bajaron vs periodo anterior.',
                'proxy',
            );
            $recommendations[] = $this->item(
                'Contrastar con Journey/Contactos de pacientes lab y campañas de recuperación (cuando exista engagement).',
                'proxy',
            );
        }

        if ($this->kpiUp($pharmacy)) {
            $insights[] = $this->item(
                'Farmacia creció vs periodo anterior (volumen de compras).',
                'proxy',
            );
        }

        if ($this->kpiDown($membership)) {
            $risks[] = $this->item(
                'Altas de membresía en descenso vs periodo anterior.',
                'proxy',
            );
        }

        if ($promo && ($promo['delta_is_positive'] ?? null) === false) {
            $insights[] = $this->item(
                'Promociones synced disminuyeron vs periodo anterior.',
                'disponible',
            );
        }

        return $this->domain(
            id: 'business',
            label: 'Negocio',
            question: '¿Qué señales comerciales del periodo requieren atención?',
            kpis: array_values(array_filter([
                $lab,
                $pharmacy,
                $membership,
                $promo,
            ])),
            chartKeys: ['events_by_type'],
            insights: $insights,
            recommendations: $recommendations !== [] ? $recommendations : [
                $this->item(
                    'Usar Vista 360 / Journey en pacientes de alto valor para validar el embudo producto.',
                    'disponible',
                ),
            ],
            risks: $risks,
            gaps: [
                $this->gap('GMV agregado lab/farmacia en Analytics', 'proximamente', 'El monto existe en 360; el Dashboard aún no lo agrega a escala.'),
            ],
        );
    }

    /**
     * @param  \Illuminate\Support\Collection<string, array<string, mixed>>  $health
     * @param  \Illuminate\Support\Collection<string, array<string, mixed>>  $business
     * @param  array<string, mixed>  $overview
     * @return array<string, mixed>
     */
    private function customersDomain($health, $business, array $overview): array
    {
        $patients = $health->get('patients');
        $abandoned = $business->get('abandoned');

        $insights = [];
        $recommendations = [];
        $risks = [];

        $insights[] = $this->item(
            'Altas de pacientes miden creación de Contact, no sincronización confirmada a ActiveCampaign.',
            'proxy',
        );

        if ($abandoned) {
            $dir = $abandoned['delta_direction'] ?? 'flat';
            $worse = ($abandoned['delta_is_positive'] ?? null) === false;
            if ($dir === 'up' && $worse) {
                $insights[] = $this->item(
                    'Carritos abandonados tagged aumentaron vs periodo anterior.',
                    'disponible',
                );
                $risks[] = $this->item(
                    'Más abandonos tagged: posible fricción en checkout o caída de recuperación.',
                    'disponible',
                );
                $recommendations[] = $this->item(
                    'Revisar pacientes con tag de abandono en Contactos y su Journey.',
                    'disponible',
                );
            } elseif ($dir === 'down' && ($abandoned['delta_is_positive'] ?? null) === true) {
                $insights[] = $this->item(
                    'Abandonos tagged disminuyeron vs periodo anterior.',
                    'disponible',
                );
            }
        }

        return $this->domain(
            id: 'customers',
            label: 'Clientes',
            question: '¿Cómo evoluciona la base de pacientes y el abandono?',
            kpis: array_values(array_filter([
                $patients,
                $abandoned,
                $health->get('credits'),
            ])),
            chartKeys: [],
            insights: $insights,
            recommendations: $recommendations !== [] ? $recommendations : [
                $this->item(
                    'Para profundidad por persona usar Contactos → Vista 360 (no duplicar aquí la ficha).',
                    'disponible',
                ),
            ],
            risks: $risks,
            gaps: [
                $this->gap('Última actividad unificada CRM', 'instrumentacion', 'No hay evento de actividad canónico fuera del Timeline por paciente.'),
                $this->gap('Stock de membresías activas a escala', 'proximamente', 'Existe por paciente en 360; falta agregado reutilizable en Dashboard.'),
            ],
        );
    }

    /**
     * @param  \Illuminate\Support\Collection<string, array<string, mixed>>  $health
     * @param  \Illuminate\Support\Collection<string, array<string, mixed>>  $business
     * @param  array<string, mixed>  $overview
     * @return array<string, mixed>
     */
    private function marketingDomain($health, $business, array $overview): array
    {
        $credits = $health->get('credits');
        $promo = $business->get('promo');

        $insights = [
            $this->item(
                'Hoy el marketing medible en Famedic es outbound local (créditos/promos synced y dispatches).',
                'disponible',
            ),
        ];

        $recommendations = [
            $this->item(
                'Usar top event_type (gráfica diferida) para priorizar qué flujos cupón/promo revisar.',
                'disponible',
            ),
        ];

        $risks = [];
        $errors = $this->numericCard($health->get('errors'));
        if ($errors > 0) {
            $risks[] = $this->item(
                'Fallos de dispatch pueden dejar campañas de cupón/crédito incompletas.',
                'disponible',
            );
        }

        return $this->domain(
            id: 'marketing',
            label: 'Marketing',
            question: '¿Qué señales de marketing outbound están confiables y qué falta instrumentar?',
            kpis: array_values(array_filter([$credits, $promo])),
            chartKeys: ['events_by_type'],
            insights: $insights,
            recommendations: $recommendations,
            risks: $risks,
            gaps: [
                $this->gap('Aperturas de email', 'instrumentacion', 'Requiere ActiveCampaign API / webhooks.'),
                $this->gap('Clicks en campañas', 'instrumentacion', 'Requiere ActiveCampaign API / webhooks.'),
                $this->gap('Tags espejo por contacto', 'instrumentacion', 'No hay tabla local de tags AC.'),
                $this->gap('Automatizaciones remotas', 'instrumentacion', 'Inventario AC no sincronizado.'),
                $this->gap('Google Analytics / Meta / WhatsApp', 'proximamente', 'Fuentes planned en meta.sources del Dashboard.'),
            ],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $kpis
     * @param  list<string>  $chartKeys
     * @param  list<array<string, mixed>>  $insights
     * @param  list<array<string, mixed>>  $recommendations
     * @param  list<array<string, mixed>>  $risks
     * @param  list<array<string, mixed>>  $gaps
     * @return array<string, mixed>
     */
    private function domain(
        string $id,
        string $label,
        string $question,
        array $kpis,
        array $chartKeys,
        array $insights,
        array $recommendations,
        array $risks,
        array $gaps,
    ): array {
        return [
            'id' => $id,
            'label' => $label,
            'question' => $question,
            'kpis' => $kpis,
            'chart_keys' => $chartKeys,
            'insights' => $insights,
            'recommendations' => $recommendations,
            'risks' => $risks !== [] ? $risks : [
                $this->item('Sin riesgos detectados con las reglas actuales sobre datos existentes.', 'disponible'),
            ],
            'gaps' => $gaps,
        ];
    }

    /**
     * @return array{text: string, truth: string}
     */
    private function item(string $text, string $truth): array
    {
        return [
            'text' => $text,
            'truth' => $truth,
        ];
    }

    /**
     * @return array{label: string, truth: string, reason: string}
     */
    private function gap(string $label, string $truth, string $reason): array
    {
        return [
            'label' => $label,
            'truth' => $truth,
            'reason' => $reason,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $card
     */
    private function numericCard(?array $card): int
    {
        if (! $card) {
            return 0;
        }

        $raw = preg_replace('/[^\d]/', '', (string) ($card['value'] ?? '0')) ?? '0';

        return (int) $raw;
    }

    /**
     * @param  array<string, mixed>|null  $kpi
     */
    private function kpiDown(?array $kpi): bool
    {
        return $kpi
            && ($kpi['delta_direction'] ?? '') === 'down'
            && ($kpi['delta_is_positive'] ?? null) === false;
    }

    /**
     * @param  array<string, mixed>|null  $kpi
     */
    private function kpiUp(?array $kpi): bool
    {
        return $kpi
            && ($kpi['delta_direction'] ?? '') === 'up'
            && ($kpi['delta_is_positive'] ?? null) === true;
    }
}
