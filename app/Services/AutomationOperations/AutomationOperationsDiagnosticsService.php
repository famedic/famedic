<?php

namespace App\Services\AutomationOperations;

use App\DTOs\AutomationOperations\AutomationDiagnosticResult;
use App\Models\AutomationOperationEvent;
use App\Services\Orders\Drivers\ActiveCampaignOrderDriver;
use App\Services\Orders\OrderAutomationDispatcher;
use App\Services\Payments\Drivers\ActiveCampaignPaymentDriver;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Read-only / smoke diagnostics for the Operations Center.
 * Does not run checkout, fulfill, or business automation handlers.
 */
class AutomationOperationsDiagnosticsService
{
    public function __construct(
        private AutomationOperationsRecorder $recorder,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function catalog(): array
    {
        return [
            [
                'key' => 'driver',
                'label' => 'Test Driver',
                'description' => 'Verifica que los drivers ActiveCampaign de pago y orden sean instanciables.',
            ],
            [
                'key' => 'activecampaign',
                'label' => 'Test ActiveCampaign',
                'description' => 'Ping de credenciales /users/me (solo lectura).',
            ],
            [
                'key' => 'email',
                'label' => 'Test Email',
                'description' => 'Canal Email — pendiente de driver.',
            ],
            [
                'key' => 'whatsapp',
                'label' => 'Test WhatsApp',
                'description' => 'Canal WhatsApp — pendiente de driver.',
            ],
            [
                'key' => 'dispatcher',
                'label' => 'Test Dispatcher',
                'description' => 'Resuelve OrderAutomationDispatcher y lista drivers registrados.',
            ],
        ];
    }

    public function run(string $key): AutomationDiagnosticResult
    {
        return match ($key) {
            'driver' => $this->testDrivers(),
            'activecampaign' => $this->testActiveCampaign(),
            'email' => $this->planned('email', 'Test Email', 'EmailOrderDriver aún no está registrado.'),
            'whatsapp' => $this->planned('whatsapp', 'Test WhatsApp', 'WhatsAppOrderDriver aún no está registrado.'),
            'dispatcher' => $this->testDispatcher(),
            default => new AutomationDiagnosticResult(
                key: $key,
                label: 'Desconocido',
                status: 'failed',
                message: "Diagnóstico «{$key}» no existe.",
            ),
        };
    }

    private function testDrivers(): AutomationDiagnosticResult
    {
        $started = hrtime(true);
        $details = [];

        try {
            $payment = app(ActiveCampaignPaymentDriver::class);
            $details['payment_driver'] = class_basename($payment);
            $order = app(ActiveCampaignOrderDriver::class);
            $details['order_driver'] = class_basename($order);
            $details['payment_class_exists'] = true;
            $details['order_class_exists'] = true;

            $ms = (int) ((hrtime(true) - $started) / 1_000_000);

            $this->recorder->record([
                'automation' => 'Diagnostic',
                'driver' => 'ActiveCampaignOrderDriver',
                'operation' => 'test_driver',
                'result' => AutomationOperationEvent::RESULT_SUCCESS,
                'duration_ms' => $ms,
                'retryable' => false,
                'meta' => $details,
            ]);

            return new AutomationDiagnosticResult(
                key: 'driver',
                label: 'Test Driver',
                status: 'success',
                message: 'Drivers de pago y orden instanciables correctamente.',
                durationMs: $ms,
                details: $details,
            );
        } catch (Throwable $e) {
            $ms = (int) ((hrtime(true) - $started) / 1_000_000);
            $this->recorder->record([
                'automation' => 'Diagnostic',
                'driver' => null,
                'operation' => 'test_driver',
                'result' => AutomationOperationEvent::RESULT_FAILED,
                'duration_ms' => $ms,
                'retryable' => true,
                'meta' => ['error' => $e->getMessage()],
            ]);

            return new AutomationDiagnosticResult(
                key: 'driver',
                label: 'Test Driver',
                status: 'failed',
                message: $e->getMessage(),
                durationMs: $ms,
                details: $details,
            );
        }
    }

    private function testActiveCampaign(): AutomationDiagnosticResult
    {
        $started = hrtime(true);
        $endpoint = config('services.activecampaign.endpoint');
        $token = config('services.activecampaign.token');

        if (! $endpoint || ! $token) {
            return new AutomationDiagnosticResult(
                key: 'activecampaign',
                label: 'Test ActiveCampaign',
                status: 'failed',
                message: 'Credenciales ActiveCampaign no configuradas.',
                details: ['configured' => false],
            );
        }

        $endpoint = rtrim((string) $endpoint, '/');
        if (str_ends_with($endpoint, '/api/3')) {
            $endpoint = substr($endpoint, 0, -strlen('/api/3'));
        }

        try {
            $response = Http::withHeaders([
                'Api-Token' => $token,
                'Accept' => 'application/json',
            ])
                ->timeout(12)
                ->get($endpoint.'/api/3/users/me');

            $ms = (int) ((hrtime(true) - $started) / 1_000_000);
            $ok = $response->successful();

            $this->recorder->record([
                'automation' => 'Diagnostic',
                'driver' => 'ActiveCampaign',
                'operation' => 'test_activecampaign',
                'result' => $ok ? AutomationOperationEvent::RESULT_SUCCESS : AutomationOperationEvent::RESULT_FAILED,
                'duration_ms' => $ms,
                'retryable' => ! $ok,
                'meta' => ['http_status' => $response->status()],
            ]);

            return new AutomationDiagnosticResult(
                key: 'activecampaign',
                label: 'Test ActiveCampaign',
                status: $ok ? 'success' : 'failed',
                message: $ok
                    ? 'API ActiveCampaign respondió correctamente (/users/me).'
                    : 'ActiveCampaign respondió HTTP '.$response->status(),
                durationMs: $ms,
                details: [
                    'http_status' => $response->status(),
                    'configured' => true,
                ],
            );
        } catch (Throwable $e) {
            $ms = (int) ((hrtime(true) - $started) / 1_000_000);
            $this->recorder->record([
                'automation' => 'Diagnostic',
                'driver' => 'ActiveCampaign',
                'operation' => 'test_activecampaign',
                'result' => AutomationOperationEvent::RESULT_FAILED,
                'duration_ms' => $ms,
                'retryable' => true,
                'meta' => ['error' => $e->getMessage()],
            ]);

            return new AutomationDiagnosticResult(
                key: 'activecampaign',
                label: 'Test ActiveCampaign',
                status: 'failed',
                message: $e->getMessage(),
                durationMs: $ms,
            );
        }
    }

    private function testDispatcher(): AutomationDiagnosticResult
    {
        $started = hrtime(true);

        try {
            $dispatcher = app(OrderAutomationDispatcher::class);
            $registered = config('order_automation.drivers', []);
            $names = array_map(
                fn ($c) => is_string($c) ? class_basename($c) : class_basename($c::class),
                $registered
            );

            $ms = (int) ((hrtime(true) - $started) / 1_000_000);
            $details = [
                'drivers_registered' => count($registered),
                'driver_names' => $names,
                'dispatcher_class' => class_basename($dispatcher),
            ];

            $this->recorder->record([
                'automation' => 'Dispatcher',
                'driver' => 'OrderAutomationDispatcher',
                'operation' => 'test_dispatcher',
                'result' => AutomationOperationEvent::RESULT_SUCCESS,
                'duration_ms' => $ms,
                'retryable' => false,
                'meta' => $details,
            ]);

            return new AutomationDiagnosticResult(
                key: 'dispatcher',
                label: 'Test Dispatcher',
                status: 'success',
                message: 'Dispatcher resuelto con '.count($registered).' driver(s).',
                durationMs: $ms,
                details: $details,
            );
        } catch (Throwable $e) {
            $ms = (int) ((hrtime(true) - $started) / 1_000_000);
            $this->recorder->record([
                'automation' => 'Dispatcher',
                'driver' => 'OrderAutomationDispatcher',
                'operation' => 'test_dispatcher',
                'result' => AutomationOperationEvent::RESULT_FAILED,
                'duration_ms' => $ms,
                'retryable' => true,
                'meta' => ['error' => $e->getMessage()],
            ]);

            return new AutomationDiagnosticResult(
                key: 'dispatcher',
                label: 'Test Dispatcher',
                status: 'failed',
                message: $e->getMessage(),
                durationMs: $ms,
            );
        }
    }

    private function planned(string $key, string $label, string $message): AutomationDiagnosticResult
    {
        $this->recorder->record([
            'automation' => 'Diagnostic',
            'driver' => null,
            'operation' => 'test_'.$key,
            'result' => AutomationOperationEvent::RESULT_SKIPPED,
            'duration_ms' => 0,
            'retryable' => false,
            'meta' => ['reason' => 'planned'],
        ]);

        return new AutomationDiagnosticResult(
            key: $key,
            label: $label,
            status: 'skipped',
            message: $message,
            durationMs: 0,
            details: ['status' => 'planned'],
        );
    }
}
