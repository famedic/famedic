<?php

namespace App\Services\ActiveCampaign;

use App\Support\ActiveCampaign\ActiveCampaignDashboardFilter;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Health Center — consola operativa sobre datos existentes.
 * Reutiliza ActiveCampaignDashboardService; señales Laravel solo de config/DB.
 */
class ActiveCampaignHealthCenterService
{
    private const UNAVAILABLE = 'No disponible';

    private const UPCOMING = 'Próximamente';

    private ActiveCampaignDashboardService $dashboard;

    public function __construct(ActiveCampaignDashboardService $dashboard)
    {
        $this->dashboard = $dashboard;
    }

    /**
     * Payload inmediato: overview + integraciones + acciones.
     *
     * @return array<string, mixed>
     */
    public function buildCore(ActiveCampaignDashboardFilter $filter): array
    {
        $overview = $this->dashboard->buildOverview($filter);
        $healthById = collect($overview['health'])->keyBy('id');

        $errors = $this->intFromFormatted($healthById->get('errors')['value'] ?? '0');
        $backlog = $this->intFromFormatted($healthById->get('backlog')['value'] ?? '0');
        $integrationValue = (string) ($healthById->get('integration')['value'] ?? '');

        return [
            'overview' => $this->buildOverviewStatus($integrationValue, $errors, $backlog, $healthById),
            'integrations' => $this->buildIntegrations($healthById, $errors, $backlog),
            'actions' => $this->buildActions(),
            'meta' => [
                'generated_at' => $overview['meta']['generated_at'] ?? now('America/Monterrey')->format('d/m/Y H:i'),
                'source_of_truth' => 'ActiveCampaignDashboardService + config/DB Laravel',
                'previous_period' => $overview['meta']['previous_period'] ?? null,
            ],
        ];
    }

    /**
     * Secciones diferidas (infra + alertas con queries ligeras extra).
     *
     * @return array{infrastructure: list<array<string, mixed>>, alerts: list<array<string, mixed>>}
     */
    public function buildDeferred(ActiveCampaignDashboardFilter $filter): array
    {
        $overview = $this->dashboard->buildOverview($filter);

        return [
            'infrastructure' => $this->buildInfrastructure($overview),
            'alerts' => $this->buildAlerts($overview),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<string, array<string, mixed>>  $healthById
     * @return array<string, mixed>
     */
    private function buildOverviewStatus(string $integrationValue, int $errors, int $backlog, $healthById): array
    {
        $level = 'green';
        $label = 'Todo funcionando';
        $detail = 'Integración y cola local sin señales críticas en el periodo.';

        $criticalIntegration = in_array($integrationValue, ['Sin credenciales', 'Desactivado'], true);
        $partial = $integrationValue === 'Parcial';

        if ($criticalIntegration || $errors >= 25) {
            $level = 'red';
            $label = 'Problemas críticos';
            $detail = $criticalIntegration
                ? "Estado de integración: {$integrationValue}."
                : "Errores de dispatch elevados ({$errors}) en el periodo.";
        } elseif ($errors > 0 || $backlog > 0 || $partial) {
            $level = 'yellow';
            $label = 'Advertencias';
            $parts = [];
            if ($partial) {
                $parts[] = 'integración parcial';
            }
            if ($errors > 0) {
                $parts[] = "{$errors} errores";
            }
            if ($backlog > 0) {
                $parts[] = "backlog {$backlog}";
            }
            $detail = 'Señales a revisar: '.implode(', ', $parts).'.';
        }

        return [
            'level' => $level,
            'label' => $label,
            'detail' => $detail,
            'emoji' => match ($level) {
                'green' => '🟢',
                'yellow' => '🟡',
                default => '🔴',
            },
            'signals' => [
                [
                    'label' => 'Integración AC',
                    'value' => $integrationValue !== '' ? $integrationValue : self::UNAVAILABLE,
                    'truth' => 'disponible',
                ],
                [
                    'label' => 'Errores (periodo)',
                    'value' => (string) $errors,
                    'truth' => 'disponible',
                ],
                [
                    'label' => 'Backlog',
                    'value' => (string) $backlog,
                    'truth' => 'disponible',
                ],
                [
                    'label' => 'Última sync',
                    'value' => $healthById->get('last_sync')['value'] ?? self::UNAVAILABLE,
                    'truth' => 'disponible',
                ],
            ],
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<string, array<string, mixed>>  $healthById
     * @return list<array<string, mixed>>
     */
    private function buildIntegrations($healthById, int $errors, int $backlog): array
    {
        $cards = [
            [
                'id' => 'activecampaign',
                'label' => 'ActiveCampaign',
                'status' => $healthById->get('integration')['value'] ?? self::UNAVAILABLE,
                'last_sync' => $healthById->get('last_sync')['value'] ?? self::UNAVAILABLE,
                'recent_errors' => (string) $errors,
                'latency' => self::UNAVAILABLE,
                'truth' => 'disponible',
                'tone' => $this->integrationTone($healthById->get('integration')['value'] ?? '', $errors, $backlog),
                'hint' => 'Flags locales + dispatches (DashboardService)',
            ],
        ];

        foreach ([
            'Google Analytics',
            'Meta Ads',
            'WhatsApp',
            'Mailgun',
            'Firebase',
        ] as $label) {
            $cards[] = [
                'id' => strtolower(str_replace(' ', '_', $label)),
                'label' => $label,
                'status' => self::UPCOMING,
                'last_sync' => self::UPCOMING,
                'recent_errors' => self::UPCOMING,
                'latency' => self::UPCOMING,
                'truth' => 'proximamente',
                'tone' => 'zinc',
                'hint' => 'Integración futura del Marketing Intelligence Center',
            ];
        }

        return $cards;
    }

    /**
     * @param  array<string, mixed>  $overview
     * @return list<array<string, mixed>>
     */
    private function buildInfrastructure(array $overview): array
    {
        $healthById = collect($overview['health'])->keyBy('id');
        $backlog = $this->intFromFormatted($healthById->get('backlog')['value'] ?? '0');

        $queueConnection = (string) config('queue.default', 'sync');
        $queueSize = self::UNAVAILABLE;
        $queueTruth = 'disponible';

        try {
            if (in_array($queueConnection, ['database', 'redis', 'beanstalkd', 'sqs'], true)) {
                $queueSize = (string) Queue::connection($queueConnection)->size();
            } elseif ($queueConnection === 'sync') {
                $queueSize = 'sync (sin cola persistente)';
            } else {
                $queueTruth = 'disponible';
                $queueSize = 'Driver: '.$queueConnection;
            }
        } catch (Throwable) {
            $queueSize = self::UNAVAILABLE;
        }

        $failedJobs = self::UNAVAILABLE;
        if (Schema::hasTable('failed_jobs')) {
            try {
                $failedJobs = (string) DB::table('failed_jobs')->count();
            } catch (Throwable) {
                $failedJobs = self::UNAVAILABLE;
            }
        }

        $scheduleItems = 0;
        if (config('services.activecampaign.tag_abandoned_carts_enabled', true)) {
            $scheduleItems++;
        }
        if (config('services.activecampaign.coupons_expiring_enabled', false)) {
            $scheduleItems++;
        }

        $redisStatus = self::UNAVAILABLE;
        $redisTruth = 'disponible';
        $cacheDriver = (string) config('cache.default');
        $queueUsesRedis = $queueConnection === 'redis';
        $cacheUsesRedis = $cacheDriver === 'redis';

        if ($queueUsesRedis || $cacheUsesRedis) {
            try {
                Redis::connection()->ping();
                $redisStatus = 'Respondió ping';
            } catch (Throwable) {
                $redisStatus = self::UNAVAILABLE;
            }
        } else {
            $redisStatus = 'No configurado como cache/queue default';
            $redisTruth = 'disponible';
        }

        return [
            [
                'id' => 'queue',
                'label' => 'Queue',
                'value' => $queueConnection,
                'detail' => 'Tamaño: '.$queueSize,
                'truth' => $queueTruth,
                'tone' => 'sky',
            ],
            [
                'id' => 'scheduler',
                'label' => 'Scheduler',
                'value' => $scheduleItems > 0 ? "{$scheduleItems} comando(s) AC registrados" : 'Sin comandos AC habilitados',
                'detail' => 'Según config + routes/console (no confirma worker activo)',
                'truth' => 'disponible',
                'tone' => 'default',
            ],
            [
                'id' => 'dispatches',
                'label' => 'Dispatches',
                'value' => 'Backlog '.$backlog,
                'detail' => 'pending + processing (DashboardService)',
                'truth' => 'disponible',
                'tone' => $backlog > 0 ? 'amber' : 'lime',
            ],
            [
                'id' => 'failed_jobs',
                'label' => 'Failed jobs',
                'value' => $failedJobs,
                'detail' => 'Tabla failed_jobs de Laravel',
                'truth' => 'disponible',
                'tone' => ($failedJobs !== self::UNAVAILABLE && (int) $failedJobs > 0) ? 'red' : 'default',
            ],
            [
                'id' => 'storage',
                'label' => 'Storage',
                'value' => (string) config('filesystems.default', 'local'),
                'detail' => 'Disco default (config/filesystems)',
                'truth' => 'disponible',
                'tone' => 'default',
            ],
            [
                'id' => 'mail',
                'label' => 'Correo',
                'value' => (string) config('mail.default', 'log'),
                'detail' => 'Mailer default (config/mail)',
                'truth' => 'disponible',
                'tone' => 'default',
            ],
            [
                'id' => 'redis',
                'label' => 'Redis',
                'value' => $redisStatus,
                'detail' => 'Solo si cache/queue usan redis',
                'truth' => $redisTruth,
                'tone' => 'zinc',
            ],
            [
                'id' => 'cache',
                'label' => 'Cache',
                'value' => $cacheDriver,
                'detail' => 'Store default (config/cache)',
                'truth' => 'disponible',
                'tone' => 'default',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $overview
     * @return list<array<string, mixed>>
     */
    private function buildAlerts(array $overview): array
    {
        $healthById = collect($overview['health'])->keyBy('id');
        $errors = $this->intFromFormatted($healthById->get('errors')['value'] ?? '0');
        $backlog = $this->intFromFormatted($healthById->get('backlog')['value'] ?? '0');
        $recentErrors = $overview['tables']['recent_errors'] ?? [];

        $alerts = [];

        if ($errors > 0) {
            $alerts[] = [
                'id' => 'dispatch-errors',
                'severity' => 'warning',
                'title' => 'Dispatches fallidos en el periodo',
                'detail' => "{$errors} failed (agregación Dashboard).",
                'truth' => 'disponible',
            ];
        }

        if ($backlog > 0) {
            $alerts[] = [
                'id' => 'backlog',
                'severity' => $backlog >= 50 ? 'critical' : 'warning',
                'title' => 'Backlog de dispatches',
                'detail' => "{$backlog} en pending/processing.",
                'truth' => 'disponible',
            ];
        }

        foreach (array_slice($recentErrors, 0, 5) as $row) {
            $alerts[] = [
                'id' => 'error-'.$row['id'],
                'severity' => 'warning',
                'title' => 'Error reciente: '.($row['event_type'] ?? 'dispatch'),
                'detail' => ($row['last_error'] ?? self::UNAVAILABLE).' · '.($row['when'] ?? ''),
                'truth' => 'disponible',
            ];
        }

        $lastException = self::UNAVAILABLE;
        if (Schema::hasTable('failed_jobs')) {
            try {
                $latest = DB::table('failed_jobs')->orderByDesc('id')->first(['failed_at', 'exception']);
                if ($latest) {
                    $snippet = $latest->exception
                        ? $this->sanitizeExceptionSnippet((string) $latest->exception)
                        : self::UNAVAILABLE;
                    $when = $latest->failed_at
                        ? Carbon::parse($latest->failed_at)->timezone('America/Monterrey')->format('d/m/Y H:i')
                        : self::UNAVAILABLE;
                    $lastException = "{$when} — {$snippet}";
                    $alerts[] = [
                        'id' => 'last-failed-job',
                        'severity' => 'critical',
                        'title' => 'Última excepción (failed_jobs)',
                        'detail' => $lastException,
                        'truth' => 'disponible',
                    ];
                }
            } catch (Throwable) {
                $alerts[] = [
                    'id' => 'last-failed-job',
                    'severity' => 'info',
                    'title' => 'Última excepción',
                    'detail' => self::UNAVAILABLE,
                    'truth' => 'disponible',
                ];
            }
        }

        if ($alerts === []) {
            $alerts[] = [
                'id' => 'all-clear',
                'severity' => 'ok',
                'title' => 'Sin alertas activas',
                'detail' => 'No hay errores de periodo, backlog ni failed_jobs recientes reportados.',
                'truth' => 'disponible',
            ];
        }

        return $alerts;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildActions(): array
    {
        return [
            [
                'id' => 'logs',
                'label' => 'Ver Logs',
                'href' => route('admin.activecampaign.logs'),
                'enabled' => true,
                'hint' => null,
            ],
            [
                'id' => 'analytics',
                'label' => 'Ver Analytics',
                'href' => route('admin.activecampaign.analytics'),
                'enabled' => true,
                'hint' => null,
            ],
            [
                'id' => 'journey',
                'label' => 'Ver Customer Journey',
                'href' => route('admin.activecampaign.customer-journey'),
                'enabled' => true,
                'hint' => null,
            ],
            [
                'id' => 'settings',
                'label' => 'Ir a Configuración',
                'href' => route('admin.activecampaign.settings'),
                'enabled' => true,
                'hint' => null,
            ],
            [
                'id' => 'retry-sync',
                'label' => 'Reintentar sincronización',
                'href' => null,
                'enabled' => false,
                'hint' => 'No disponible: no hay endpoint de reintento masivo en Famedic.',
            ],
        ];
    }

    private function integrationTone(string $status, int $errors, int $backlog): string
    {
        if (in_array($status, ['Sin credenciales', 'Desactivado'], true) || $errors >= 25) {
            return 'red';
        }
        if ($status === 'Parcial' || $errors > 0 || $backlog > 0) {
            return 'amber';
        }

        return 'lime';
    }

    private function intFromFormatted(string $value): int
    {
        return (int) (preg_replace('/[^\d]/', '', $value) ?? '0');
    }

    private function sanitizeExceptionSnippet(string $exception): string
    {
        $line = trim(explode("\n", $exception)[0] ?? '');
        $line = preg_replace('/\s+/', ' ', $line) ?? $line;
        $line = preg_replace('/(\/[\w.\-]+)+/', '[path]', $line) ?? $line;

        return mb_strlen($line) > 120 ? mb_substr($line, 0, 117).'…' : $line;
    }
}
