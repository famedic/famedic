<?php

namespace App\Services\Otp\Monitoring;

use App\Contracts\Otp\VonageSmsSendGateway;
use App\Services\Otp\Delivery\VonageSmsDeliveryDiagnostics;
use App\Services\Otp\Delivery\VonageSmsSendResponseParser;
use App\Services\Otp\Delivery\VonageSmsWebhookUrl;
use App\Services\Otp\Registration\MexicoPhoneNormalizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Vonage\SMS\Message\SMS;

final class VonageSmsConnectionTestService
{
    public const MODE_SEND_ONLY = 'send_only';

    public const MODE_SEND_WITH_DLR = 'send_with_dlr';

    public function __construct(
        private readonly VonageSmsSendGateway $gateway,
        private readonly MexicoPhoneNormalizer $phoneNormalizer,
    ) {}

    /**
     * @return array{
     *     enabled: bool,
     *     allowed_destinations: list<string>,
     *     modes: array<string, array<string, mixed>>,
     *     production_blocked: bool,
     * }
     */
    public function metadata(): array
    {
        $callback = $this->callbackEligibility();

        return [
            'enabled' => $this->featureEnabled(),
            'allowed_destinations' => array_map(
                fn (string $destination): string => $this->maskPhone($destination),
                $this->allowedDestinations()
            ),
            'modes' => [
                self::MODE_SEND_ONLY => [
                    'label' => 'Solo envío',
                    'enabled' => true,
                    'description' => 'No adjunta callback DLR; útil en local para comprobar credenciales, red y aceptación de Vonage.',
                ],
                self::MODE_SEND_WITH_DLR => [
                    'label' => 'Envío + seguimiento DLR',
                    'enabled' => $callback['enabled'],
                    'description' => 'Adjunta callback DLR por mensaje cuando existe una base HTTPS pública válida.',
                    'disabled_reason' => $callback['enabled'] ? null : $callback['reason'],
                ],
            ],
            'production_blocked' => app()->environment('production')
                && ! (bool) config('vonage.sms_diagnostic.production_enabled', false),
        ];
    }

    public function send(int $administratorId, string $destination, string $mode): VonageSmsConnectionTestResult
    {
        $mode = $mode === self::MODE_SEND_WITH_DLR ? self::MODE_SEND_WITH_DLR : self::MODE_SEND_ONLY;
        $correlationId = (string) Str::uuid();
        $internalIdempotencyKey = (string) Str::uuid();
        $destinationE164 = $this->normalizeDestination($destination);
        $destinationMasked = $this->maskPhone($destinationE164);

        $this->assertEnabled();
        $this->assertAllowedDestination($destinationE164);

        $callback = $this->callbackEligibility();
        if ($mode === self::MODE_SEND_WITH_DLR && ! $callback['enabled']) {
            throw new AccessDeniedHttpException((string) $callback['reason']);
        }

        $this->assertRateLimits($administratorId, $destinationE164);

        $lock = Cache::lock($this->lockKey($administratorId, $destinationE164), 30);
        if (! $lock->get()) {
            throw new ConflictHttpException('Ya hay una prueba SMS en curso para este administrador y destino.');
        }

        try {
            return $this->attemptSend(
                $administratorId,
                $destinationE164,
                $destinationMasked,
                $mode,
                $callback,
                $correlationId,
                $internalIdempotencyKey,
            );
        } finally {
            optional($lock)->release();
        }
    }

    private function attemptSend(
        int $administratorId,
        string $destinationE164,
        string $destinationMasked,
        string $mode,
        array $callback,
        string $correlationId,
        string $internalIdempotencyKey,
    ): VonageSmsConnectionTestResult {
        $key = trim((string) config('vonage.api_key'));
        $secret = trim((string) config('vonage.api_secret'));
        $from = trim((string) config('vonage.sms_from'));
        if ($key === '' || $secret === '' || $from === '') {
            throw new AccessDeniedHttpException('Las credenciales Vonage o el remitente SMS no están configurados.');
        }

        $sms = new SMS(
            $destinationE164,
            $from,
            (string) config('vonage.sms_diagnostic.message')
        );
        $sms->setClientRef('smsdiag-'.substr(str_replace('-', '', $internalIdempotencyKey), 0, 24));

        $callbackApplied = false;
        if ($mode === self::MODE_SEND_WITH_DLR && is_string($callback['url'] ?? null)) {
            $sms->setDeliveryReceiptCallback($callback['url']);
            $callbackApplied = true;
        }

        $started = hrtime(true);
        $sentAt = now()->toIso8601String();

        try {
            $response = $this->gateway->send($sms, $key, $secret);
            $parsed = VonageSmsSendResponseParser::parse($response);
            $elapsed = $this->elapsed($started);

            $this->markRateLimits($administratorId, $destinationE164);
            $this->logAttempt('completed', [
                'administrator_id' => $administratorId,
                'correlation_id' => $correlationId,
                'internal_idempotency_key_prefix' => substr($internalIdempotencyKey, 0, 8),
                'environment' => app()->environment(),
                'destination_masked' => $destinationMasked,
                'mode' => $mode,
                'callback_applied' => $callbackApplied,
                'callback_host' => $callbackApplied ? parse_url((string) $callback['url'], PHP_URL_HOST) : null,
                'callback_url_length' => $callbackApplied ? strlen((string) $callback['url']) : null,
                'vonage_status' => $parsed->vonageStatus,
                'message_id_prefix' => $parsed->messageId !== null ? substr($parsed->messageId, 0, 8) : null,
                'accepted' => $parsed->accepted,
                'interpretable' => $parsed->interpretable,
            ]);

            return new VonageSmsConnectionTestResult(
                correlationId: $correlationId,
                sentAt: $sentAt,
                environment: app()->environment(),
                destinationMasked: $destinationMasked,
                mode: $mode,
                accepted: $parsed->accepted,
                interpretable: $parsed->interpretable,
                vonageStatus: $parsed->vonageStatus,
                providerMessageIdPrefix: $parsed->messageId !== null ? substr($parsed->messageId, 0, 8) : null,
                errorText: VonageSmsDeliveryDiagnostics::sanitizeErrorText($parsed->errorText),
                elapsedMs: $elapsed,
                callback: [
                    'applied' => $callbackApplied,
                    'host' => $callbackApplied ? parse_url((string) $callback['url'], PHP_URL_HOST) : null,
                    'disabled_reason' => $callbackApplied ? null : ($callback['reason'] ?? null),
                ],
                diagnosis: $this->diagnosis($parsed->accepted, $parsed->interpretable, $parsed->vonageStatus),
            );
        } catch (\Throwable $e) {
            $this->markRateLimits($administratorId, $destinationE164);
            $this->logAttempt('exception', [
                'administrator_id' => $administratorId,
                'correlation_id' => $correlationId,
                'internal_idempotency_key_prefix' => substr($internalIdempotencyKey, 0, 8),
                'environment' => app()->environment(),
                'destination_masked' => $destinationMasked,
                'mode' => $mode,
                'callback_applied' => $callbackApplied,
                'callback_host' => $callbackApplied && is_string($callback['url'] ?? null)
                    ? parse_url($callback['url'], PHP_URL_HOST)
                    : null,
                'callback_url_length' => $callbackApplied && is_string($callback['url'] ?? null)
                    ? strlen($callback['url'])
                    : null,
                'exception_class' => $e::class,
            ]);

            throw new HttpException(
                503,
                'Resultado incierto: Vonage no confirmó la aceptación del SMS. No se reintentó automáticamente.',
                $e
            );
        }
    }

    private function assertEnabled(): void
    {
        if (! $this->featureEnabled()) {
            throw new AccessDeniedHttpException('La herramienta de diagnóstico SMS está deshabilitada.');
        }

        if (app()->environment('production') && ! (bool) config('vonage.sms_diagnostic.production_enabled', false)) {
            throw new AccessDeniedHttpException('La herramienta de diagnóstico SMS no está habilitada para producción.');
        }

        if ($this->allowedDestinations() === []) {
            throw new AccessDeniedHttpException('La allowlist de destinos SMS de diagnóstico está vacía.');
        }
    }

    private function featureEnabled(): bool
    {
        return (bool) config('vonage.sms_diagnostic.enabled', false);
    }

    private function assertAllowedDestination(string $destinationE164): void
    {
        if (! in_array($destinationE164, $this->allowedDestinations(), true)) {
            throw new AccessDeniedHttpException('El destino no está permitido para diagnóstico SMS.');
        }
    }

    private function assertRateLimits(int $administratorId, string $destinationE164): void
    {
        $adminKey = $this->adminRateLimitKey($administratorId);
        if (RateLimiter::tooManyAttempts($adminKey, 3)) {
            throw new HttpException(
                429,
                'Límite alcanzado: máximo tres pruebas por administrador cada diez minutos.',
                null,
                ['Retry-After' => (string) RateLimiter::availableIn($adminKey)]
            );
        }

        $destinationKey = $this->destinationRateLimitKey($destinationE164);
        if (RateLimiter::tooManyAttempts($destinationKey, 3)) {
            throw new HttpException(
                429,
                'Límite alcanzado: máximo tres pruebas por destino cada treinta minutos.',
                null,
                ['Retry-After' => (string) RateLimiter::availableIn($destinationKey)]
            );
        }
    }

    private function markRateLimits(int $administratorId, string $destinationE164): void
    {
        RateLimiter::hit($this->adminRateLimitKey($administratorId), 10 * 60);
        RateLimiter::hit($this->destinationRateLimitKey($destinationE164), 30 * 60);
    }

    private function normalizeDestination(string $destination): string
    {
        try {
            return (string) $this->phoneNormalizer->normalize($destination, 'MX')->e164();
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'destination' => 'El destino telefónico no es válido.',
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function allowedDestinations(): array
    {
        $raw = (string) config('vonage.sms_diagnostic.allowed_destinations', '');

        return collect(explode(',', $raw))
            ->map(fn (string $value): string => trim($value))
            ->filter()
            ->map(function (string $value): ?string {
                try {
                    return (string) $this->phoneNormalizer->normalize($value, 'MX')->e164();
                } catch (\Throwable) {
                    return null;
                }
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array{enabled: bool, reason: ?string, url: ?string}
     */
    private function callbackEligibility(): array
    {
        $url = VonageSmsWebhookUrl::deliveryReceiptCallback();
        if ($url === null) {
            return [
                'enabled' => false,
                'reason' => 'No existe una URL DLR HTTPS válida con token configurado.',
                'url' => null,
            ];
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($scheme !== 'https') {
            return ['enabled' => false, 'reason' => 'La base DLR debe usar HTTPS.', 'url' => null];
        }

        if ($this->isPrivateOrLocalHost($host)) {
            return [
                'enabled' => false,
                'reason' => 'La base DLR apunta a localhost, IP privada o dominio local no alcanzable por Vonage.',
                'url' => null,
            ];
        }

        return ['enabled' => true, 'reason' => null, 'url' => $url];
    }

    private function isPrivateOrLocalHost(string $host): bool
    {
        if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return true;
        }

        foreach (['.localhost', '.local', '.test', '.internal', '.lan'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        $ip = filter_var($host, FILTER_VALIDATE_IP);
        if ($ip === false) {
            return false;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    private function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return '***'.substr($digits, -4);
    }

    private function adminRateLimitKey(int $administratorId): string
    {
        return 'otp-movements:test-sms:admin:'.$administratorId;
    }

    private function destinationRateLimitKey(string $destinationE164): string
    {
        return 'otp-movements:test-sms:destination:'.hash('sha256', $destinationE164);
    }

    private function lockKey(int $administratorId, string $destinationE164): string
    {
        return 'otp-movements:test-sms:lock:'.$administratorId.':'.hash('sha256', $destinationE164);
    }

    private function elapsed(int $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }

    private function diagnosis(bool $accepted, bool $interpretable, ?int $vonageStatus): string
    {
        if (! $interpretable) {
            return 'Vonage respondió, pero FAMEDIC no pudo interpretar la respuesta. No se reintentó.';
        }

        if ($accepted) {
            return 'Vonage aceptó el SMS de prueba. En modo Solo envío esto confirma credenciales, red y aceptación del proveedor.';
        }

        return 'Vonage rechazó el SMS de prueba con status '.($vonageStatus ?? 'desconocido').'. Revisa configuración, saldo, remitente o política del proveedor.';
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logAttempt(string $stage, array $context): void
    {
        Log::info('vonage_sms_connection_test', array_intersect_key($context + [
            'stage' => $stage,
        ], array_flip([
            'stage',
            'administrator_id',
            'correlation_id',
            'internal_idempotency_key_prefix',
            'environment',
            'destination_masked',
            'mode',
            'callback_applied',
            'callback_host',
            'callback_url_length',
            'vonage_status',
            'message_id_prefix',
            'accepted',
            'interpretable',
            'exception_class',
        ])));
    }
}
