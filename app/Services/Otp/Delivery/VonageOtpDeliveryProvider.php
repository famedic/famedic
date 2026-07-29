<?php

namespace App\Services\Otp\Delivery;

use App\Contracts\Otp\OtpDeliveryProvider;
use GuzzleHttp\Client as GuzzleClient;
use Vonage\Client;
use Vonage\Client\Credentials\Basic;
use Vonage\SMS\Message\SMS;

final class VonageOtpDeliveryProvider implements OtpDeliveryProvider
{
    public function __construct(private readonly OtpDeliveryClassifier $classifier)
    {
    }

    public function send(OtpDeliveryRequest $request): OtpDeliveryResult
    {
        $key = trim((string) config('vonage.api_key'));
        $secret = trim((string) config('vonage.api_secret'));
        $from = $request->from ?? trim((string) config('vonage.sms_from'));
        if ($key === '' || $secret === '' || $from === '') {
            return new OtpDeliveryResult(OtpDeliveryResultClass::ProviderMisconfigured, null, $request->attemptNumber, 0, $this->alias());
        }

        $retries = max(0, (int) config('otp.p0a.delivery.max_retries', 1));
        $backoff = max(0, (int) config('otp.p0a.delivery.backoff_ms', 500));
        for ($retry = 0; $retry <= $retries; $retry++) {
            $attempt = $request->attemptNumber + $retry;
            $started = hrtime(true);
            try {
                $httpClient = new GuzzleClient([
                    'connect_timeout' => (int) config('otp.p0a.delivery.connect_timeout_seconds', 3),
                    'timeout' => (int) config('otp.p0a.delivery.request_timeout_seconds', 8),
                ]);
                $client = new Client(
                    new Basic($key, $secret),
                    [],
                    $httpClient,
                );
                $client->sms()->send(new SMS(
                    $request->destinationE164OrEmail,
                    $from,
                    "Tu codigo de verificacion Famedic es: {$request->plainCode}. Valido por 10 minutos.",
                ));

                return new OtpDeliveryResult(OtpDeliveryResultClass::Accepted, '2xx', $attempt, $this->elapsed($started), $this->alias());
            } catch (\Throwable $e) {
                $class = $this->classifier->classify($e);
                $result = new OtpDeliveryResult($class, $this->httpClass($e), $attempt, $this->elapsed($started), $this->alias());
                if (! $class->isTemporaryRetryable() || $retry === $retries) {
                    return $result;
                }
                if ($backoff > 0) {
                    usleep($backoff * 1000);
                }
            }
        }

        return new OtpDeliveryResult(OtpDeliveryResultClass::ProviderTemporaryFailure, null, $request->attemptNumber, 0, $this->alias());
    }

    public function alias(): string
    {
        return (string) config('otp.p0a.delivery.provider_alias', 'vonage');
    }

    private function elapsed(int $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }

    private function httpClass(\Throwable $e): ?string
    {
        $response = method_exists($e, 'getResponse') ? $e->getResponse() : null;
        $status = $response?->getStatusCode();
        if ($status === null) {
            return null;
        }

        return $status === 429 ? '429' : intdiv($status, 100).'xx';
    }
}
