<?php

namespace App\Services\ActiveCampaign;

use App\Models\ActiveCampaignDispatch;
use App\Support\ActiveCampaign\ActiveCampaignDashboardFilter;
use Carbon\Carbon;

/**
 * Integrations Hub — catálogo operativo de conexiones externas.
 * No inventa sincronizaciones: solo config local + señales ya expuestas por DashboardService.
 */
class ActiveCampaignIntegrationsHubService
{
    private const UNAVAILABLE = 'No disponible';

    private const UPCOMING = 'Próximamente';

    private const NOT_CONFIGURED = 'No configurada';

    private ActiveCampaignDashboardService $dashboard;

    public function __construct(ActiveCampaignDashboardService $dashboard)
    {
        $this->dashboard = $dashboard;
    }

    /**
     * Payload inmediato: resumen + tarjetas de integración.
     *
     * @return array<string, mixed>
     */
    public function buildCore(ActiveCampaignDashboardFilter $filter): array
    {
        $overview = $this->dashboard->buildOverview($filter);
        $healthById = collect($overview['health'])->keyBy('id');

        $integrations = $this->buildIntegrations($healthById);

        return [
            'summary' => $this->buildSummary($integrations),
            'integrations' => $integrations,
            'meta' => [
                'generated_at' => $overview['meta']['generated_at'] ?? now('America/Monterrey')->format('d/m/Y H:i'),
                'source_of_truth' => 'config/services + ActiveCampaignDashboardService (sin inventar sync)',
                'note' => 'Probar conexión valida únicamente presencia de credenciales locales; no llama APIs externas.',
            ],
        ];
    }

    /**
     * Información secundaria diferida (probes de config + último error AC).
     *
     * @return array{probes: list<array<string, mixed>>, details: array<string, mixed>}
     */
    public function buildDeferred(): array
    {
        return [
            'probes' => $this->buildProbes(),
            'details' => [
                'activecampaign_last_error' => $this->activeCampaignLastError(),
                'mailgun_mailer' => (string) config('mail.default', 'log'),
            ],
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<string, array<string, mixed>>  $healthById
     * @return list<array<string, mixed>>
     */
    private function buildIntegrations($healthById): array
    {
        return [
            $this->activeCampaignCard($healthById),
            $this->mailgunCard(),
            $this->placeholderCard('firebase', 'Firebase', 'SDK / Service Account'),
            $this->placeholderCard('google_analytics', 'Google Analytics', 'OAuth / Measurement ID'),
            $this->placeholderCard('meta_ads', 'Meta Ads', 'Marketing API Token'),
            $this->placeholderCard('whatsapp', 'WhatsApp', 'Cloud API Token'),
            $this->placeholderCard('future', 'Futuras integraciones', self::UPCOMING),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<string, array<string, mixed>>  $healthById
     * @return array<string, mixed>
     */
    private function activeCampaignCard($healthById): array
    {
        $configured = filled(config('services.activecampaign.endpoint'))
            && filled(config('services.activecampaign.token'));
        $enabled = (bool) config('services.activecampaign.enabled', true);
        $coupons = $enabled && (bool) config('services.activecampaign.coupons_enabled', true);

        if (! $configured) {
            $status = self::NOT_CONFIGURED;
            $tone = 'amber';
        } elseif (! $enabled) {
            $status = 'Desactivada';
            $tone = 'amber';
        } elseif (! $coupons) {
            $status = 'Parcial';
            $tone = 'amber';
        } else {
            $status = (string) ($healthById->get('integration')['value'] ?? 'Operativo');
            $tone = 'lime';
        }

        $errors = (string) ($healthById->get('errors')['value'] ?? '0');
        $lastSync = (string) ($healthById->get('last_sync')['value'] ?? self::UNAVAILABLE);

        return [
            'id' => 'activecampaign',
            'name' => 'ActiveCampaign',
            'status' => $status,
            'last_sync' => $lastSync,
            'last_error' => $errors === '0' ? 'Sin errores en el periodo' : $errors.' error(es) en el periodo',
            'configuration' => $configured
                ? 'Endpoint + token configurados'.($enabled ? '' : ' · flag disabled')
                : self::NOT_CONFIGURED,
            'version' => self::UNAVAILABLE,
            'auth_type' => 'API Token',
            'truth' => 'disponible',
            'tone' => $tone,
            'category' => 'marketing',
            'actions' => [
                'test' => [
                    'enabled' => true,
                    'mode' => 'config_probe',
                    'label' => 'Probar conexión',
                    'hint' => 'Valida credenciales locales (sin llamar a la API).',
                ],
                'logs' => [
                    'enabled' => true,
                    'href' => route('admin.activecampaign.logs'),
                    'label' => 'Ver Logs',
                    'hint' => null,
                ],
                'settings' => [
                    'enabled' => true,
                    'href' => route('admin.activecampaign.settings'),
                    'label' => 'Configuración',
                    'hint' => null,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mailgunCard(): array
    {
        $domain = config('services.mailgun.domain');
        $secret = config('services.mailgun.secret');
        $configured = filled($domain) && filled($secret);
        $defaultMailer = (string) config('mail.default', 'log');
        $mailgunIsDefault = $defaultMailer === 'mailgun';

        if (! $configured) {
            $status = self::NOT_CONFIGURED;
            $tone = 'zinc';
            $configuration = self::NOT_CONFIGURED;
        } else {
            $status = $mailgunIsDefault ? 'Configurada (mailer default)' : 'Configurada';
            $tone = 'lime';
            $configuration = 'Domain + secret presentes'
                .($mailgunIsDefault ? '' : ' · MAIL_MAILER='.$defaultMailer);
        }

        return [
            'id' => 'mailgun',
            'name' => 'Mailgun',
            'status' => $status,
            'last_sync' => self::UNAVAILABLE,
            'last_error' => self::UNAVAILABLE,
            'configuration' => $configuration,
            'version' => self::UNAVAILABLE,
            'auth_type' => 'API Key',
            'truth' => $configured ? 'disponible' : 'no_configurada',
            'tone' => $tone,
            'category' => 'messaging',
            'actions' => [
                'test' => [
                    'enabled' => $configured,
                    'mode' => 'config_probe',
                    'label' => 'Probar conexión',
                    'hint' => $configured
                        ? 'Valida presencia de domain/secret (sin llamar a Mailgun).'
                        : 'Configura MAILGUN_DOMAIN y MAILGUN_SECRET.',
                ],
                'logs' => [
                    'enabled' => false,
                    'href' => null,
                    'label' => 'Ver Logs',
                    'hint' => self::UPCOMING,
                ],
                'settings' => [
                    'enabled' => false,
                    'href' => null,
                    'label' => 'Configuración',
                    'hint' => 'Variables de entorno (.env / config/services.php).',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function placeholderCard(string $id, string $name, string $authType): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'status' => self::UPCOMING,
            'last_sync' => self::UPCOMING,
            'last_error' => self::UPCOMING,
            'configuration' => self::UPCOMING,
            'version' => self::UPCOMING,
            'auth_type' => $authType,
            'truth' => 'proximamente',
            'tone' => 'zinc',
            'category' => 'planned',
            'actions' => [
                'test' => [
                    'enabled' => false,
                    'mode' => null,
                    'label' => 'Probar conexión',
                    'hint' => self::UPCOMING,
                ],
                'logs' => [
                    'enabled' => false,
                    'href' => null,
                    'label' => 'Ver Logs',
                    'hint' => self::UPCOMING,
                ],
                'settings' => [
                    'enabled' => false,
                    'href' => null,
                    'label' => 'Configuración',
                    'hint' => self::UPCOMING,
                ],
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $integrations
     * @return list<array<string, mixed>>
     */
    private function buildSummary(array $integrations): array
    {
        $available = collect($integrations)->where('truth', 'disponible')->count();
        $notConfigured = collect($integrations)->where('truth', 'no_configurada')->count();
        $upcoming = collect($integrations)->where('truth', 'proximamente')->count();

        return [
            [
                'id' => 'available',
                'label' => 'Disponibles',
                'value' => (string) $available,
                'hint' => 'Con datos o config local legible',
                'tone' => 'sky',
            ],
            [
                'id' => 'not_configured',
                'label' => 'No configuradas',
                'value' => (string) $notConfigured,
                'hint' => 'Claves ausentes en config',
                'tone' => 'amber',
            ],
            [
                'id' => 'upcoming',
                'label' => 'Próximamente',
                'value' => (string) $upcoming,
                'hint' => 'Placeholders listos para crecer',
                'tone' => 'zinc',
            ],
            [
                'id' => 'total',
                'label' => 'En catálogo',
                'value' => (string) count($integrations),
                'hint' => 'Hub extensible',
                'tone' => 'default',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildProbes(): array
    {
        $acConfigured = filled(config('services.activecampaign.endpoint'))
            && filled(config('services.activecampaign.token'));
        $mgConfigured = filled(config('services.mailgun.domain'))
            && filled(config('services.mailgun.secret'));

        return [
            [
                'id' => 'activecampaign',
                'name' => 'ActiveCampaign',
                'result' => $acConfigured ? 'Credenciales locales presentes' : self::NOT_CONFIGURED,
                'ok' => $acConfigured,
                'scope' => 'config_only',
                'checked_at' => now('America/Monterrey')->format('d/m/Y H:i'),
            ],
            [
                'id' => 'mailgun',
                'name' => 'Mailgun',
                'result' => $mgConfigured ? 'Domain + secret presentes' : self::NOT_CONFIGURED,
                'ok' => $mgConfigured,
                'scope' => 'config_only',
                'checked_at' => now('America/Monterrey')->format('d/m/Y H:i'),
            ],
        ];
    }

    /**
     * @return array{when: string, message: string, truth: string}
     */
    private function activeCampaignLastError(): array
    {
        $row = ActiveCampaignDispatch::query()
            ->where('status', ActiveCampaignDispatch::STATUS_FAILED)
            ->orderByDesc('updated_at')
            ->first(['last_error', 'updated_at', 'event_type']);

        if (! $row) {
            return [
                'when' => self::UNAVAILABLE,
                'message' => 'Sin dispatches fallidos registrados',
                'event_type' => null,
                'truth' => 'disponible',
            ];
        }

        $message = trim((string) ($row->last_error ?? ''));
        if ($message === '') {
            $message = self::UNAVAILABLE;
        } else {
            $message = preg_replace('/\s+/', ' ', $message) ?? $message;
            $message = preg_replace('/(\/[\w.\-]+)+/', '[path]', $message) ?? $message;
            if (mb_strlen($message) > 160) {
                $message = mb_substr($message, 0, 157).'…';
            }
        }

        return [
            'when' => $row->updated_at
                ? Carbon::parse($row->updated_at)->timezone('America/Monterrey')->format('d/m/Y H:i')
                : self::UNAVAILABLE,
            'message' => $message,
            'event_type' => $row->event_type,
            'truth' => 'disponible',
        ];
    }
}
