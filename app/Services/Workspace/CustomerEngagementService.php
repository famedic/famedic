<?php

namespace App\Services\Workspace;

use App\Services\ActiveCampaign\ActiveCampaignDashboardService;
use App\Support\ActiveCampaign\ActiveCampaignDashboardFilter;
use App\Support\Workspace\CustomerEngagementCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

class CustomerEngagementService
{
    public function __construct(
        private ActiveCampaignDashboardService $dashboard,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Request $request): array
    {
        $filter = ActiveCampaignDashboardFilter::fromRequest($request);
        $overview = $this->dashboard->buildOverview($filter);

        $healthById = collect($overview['health'] ?? [])->keyBy('id');
        $businessById = collect($overview['business'] ?? [])->keyBy('id');

        $kpis = [
            $this->fromCard('contacts', 'Contactos sincronizados', $healthById->get('patients'), 'proxy'),
            $this->fromCard('campaigns', 'Campañas activas', $businessById->get('promo'), 'instrumentacion', fallback: '—', hint: 'Promos / campañas sincronizadas'),
            $this->kpiStatic('automations', 'Automatizaciones', '—', 'instrumentacion', 'Conectado a Automation Center'),
            $this->kpiStatic('lead-score', 'Lead Score promedio', '—', 'proximamente', 'Scoring unificado multi-canal'),
            $this->kpiStatic('open-rate', 'Open Rate', '—', 'proximamente', 'Email · WhatsApp · SMS · Push'),
            $this->kpiStatic('ctr', 'CTR', '—', 'proximamente', 'Multi-canal'),
            $this->fromCard('conversions', 'Conversiones', $businessById->get('lab'), 'proxy', hint: 'Proxy: actividad comercial del periodo'),
            $this->fromCard('last-sync', 'Última sincronización', $healthById->get('last_sync'), 'disponible'),
            $this->fromCard('ac-status', 'Estado de ActiveCampaign', $healthById->get('integration'), 'disponible'),
        ];

        $tab = $request->string('tab')->toString() ?: 'dashboard';
        $sub = $request->filled('sub') ? $request->string('sub')->toString() : null;

        $navigation = $this->resolveNavigation();
        $validTabs = collect($navigation)->pluck('id')->all();
        if (! in_array($tab, $validTabs, true)) {
            $tab = 'dashboard';
        }

        $currentTab = collect($navigation)->firstWhere('id', $tab) ?? $navigation[0];
        $subtabs = $currentTab['subtabs'] ?? [];
        if ($subtabs !== []) {
            $validSubs = collect($subtabs)->pluck('id')->all();
            if (! $sub || ! in_array($sub, $validSubs, true)) {
                $sub = $validSubs[0] ?? null;
            }
        } else {
            $sub = null;
        }

        $activeSub = $sub ? collect($subtabs)->firstWhere('id', $sub) : null;

        return [
            'kpis' => $kpis,
            'navigation' => $navigation,
            'filters' => [
                'tab' => $tab,
                'sub' => $sub,
            ],
            'active_panel' => [
                'tab' => $tab,
                'tab_label' => $currentTab['label'] ?? 'Dashboard',
                'sub' => $sub,
                'sub_label' => $activeSub['label'] ?? null,
                'href' => $activeSub['href'] ?? null,
                'status' => $activeSub['status'] ?? 'active',
                'channel' => $activeSub['channel'] ?? null,
                'description' => $this->panelDescription($tab, $activeSub),
            ],
            'tables' => $overview['tables'] ?? [],
            'meta' => array_merge($overview['meta'] ?? [], [
                'generated_at' => now('America/Monterrey')->format('d/m/Y H:i'),
                'channels_ready' => ['email'],
                'channels_upcoming' => ['whatsapp', 'sms', 'push'],
            ]),
            'links' => [
                'full_dashboard' => route('admin.activecampaign.dashboard'),
                'contacts' => route('admin.activecampaign.contacts'),
                'automations' => route('admin.activecampaign.automations'),
                'analytics' => route('admin.activecampaign.analytics'),
                'health' => route('admin.activecampaign.health'),
                'settings' => route('admin.activecampaign.settings'),
                'activecampaign_hub' => route('admin.workspace.activecampaign'),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function resolveNavigation(): array
    {
        return collect(CustomerEngagementCatalog::navigation())
            ->map(function (array $tab) {
                $subtabs = collect($tab['subtabs'] ?? [])
                    ->map(function (array $sub) {
                        $route = $sub['route'] ?? null;
                        $href = null;
                        $status = 'coming_soon';

                        if ($route && Route::has($route)) {
                            try {
                                $href = route($route);
                                $status = 'active';
                            } catch (\Throwable) {
                                $href = null;
                                $status = 'coming_soon';
                            }
                        }

                        return [
                            'id' => $sub['id'],
                            'label' => $sub['label'],
                            'href' => $href,
                            'status' => $status,
                            'channel' => $sub['channel'] ?? null,
                        ];
                    })
                    ->values()
                    ->all();

                return [
                    'id' => $tab['id'],
                    'label' => $tab['label'],
                    'subtabs' => $subtabs,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>|null  $sub
     */
    private function panelDescription(string $tab, ?array $sub): string
    {
        if ($tab === 'dashboard') {
            return 'Pulso de CRM, campañas y sincronización ActiveCampaign.';
        }

        if (! $sub) {
            return '';
        }

        if (($sub['status'] ?? '') === 'coming_soon') {
            $channel = $sub['channel'] ?? null;
            if (in_array($channel, ['whatsapp', 'sms', 'push'], true)) {
                return 'Canal preparado para integración futura ('.strtoupper($channel).').';
            }

            return 'Módulo en roadmap de Customer Engagement.';
        }

        return 'Herramienta conectada a ActiveCampaign. Abre el módulo completo para operar.';
    }

    /**
     * @param  array<string, mixed>|null  $card
     */
    private function fromCard(
        string $id,
        string $label,
        ?array $card,
        string $truth,
        mixed $fallback = '—',
        ?string $hint = null,
    ): array {
        $value = $card['value_formatted'] ?? $card['value'] ?? $fallback;

        return [
            'id' => $id,
            'label' => $label,
            'value' => $value,
            'value_formatted' => is_string($value) || is_numeric($value) ? (string) $value : $fallback,
            'truth' => $card['truth'] ?? $truth,
            'hint' => $hint ?? ($card['hint'] ?? null),
            'tone' => $card['tone'] ?? 'slate',
        ];
    }

    private function kpiStatic(
        string $id,
        string $label,
        string $value,
        string $truth,
        ?string $hint = null,
    ): array {
        return [
            'id' => $id,
            'label' => $label,
            'value' => $value,
            'value_formatted' => $value,
            'truth' => $truth,
            'hint' => $hint,
            'tone' => 'slate',
        ];
    }
}
