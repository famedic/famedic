<?php

namespace App\Services\Otp\Delivery;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;

final class OtpDeliveryClassifier
{
    public function classify(\Throwable $exception, ?int $httpStatus = null): OtpDeliveryResultClass
    {
        $status = $httpStatus ?? $this->statusCode($exception);

        if ($exception instanceof \Vonage\Client\Exception\ThrottleException || $status === 429) {
            return OtpDeliveryResultClass::RateLimitedByProvider;
        }

        if ($exception instanceof \Vonage\Client\Exception\Server || ($status !== null && $status >= 500)) {
            return OtpDeliveryResultClass::ProviderTemporaryFailure;
        }

        if ($exception instanceof \Vonage\Client\Exception\Request || ($status !== null && $status >= 400)) {
            return OtpDeliveryResultClass::ProviderPermanentFailure;
        }

        if ($exception instanceof ConnectException) {
            return OtpDeliveryResultClass::TransportError;
        }

        if ($exception instanceof RequestException) {
            $context = $exception->getHandlerContext();
            if (($context['errno'] ?? null) === 28 || ($context['timed_out'] ?? false) === true) {
                return OtpDeliveryResultClass::Timeout;
            }

            return OtpDeliveryResultClass::TransportError;
        }

        return OtpDeliveryResultClass::TransportError;
    }

    private function statusCode(\Throwable $exception): ?int
    {
        if (! method_exists($exception, 'getResponse')) {
            return null;
        }

        $response = $exception->getResponse();

        return $response instanceof ResponseInterface ? $response->getStatusCode() : null;
    }
}
