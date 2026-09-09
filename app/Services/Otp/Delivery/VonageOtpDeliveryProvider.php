<?php

namespace App\Services\Otp\Delivery;

use App\Contracts\Otp\OtpDeliveryProvider;
use App\Contracts\Otp\VonageSmsSendGateway;
use Vonage\SMS\Message\SMS;

final class VonageOtpDeliveryProvider implements OtpDeliveryProvider
{
    public function __construct(
        private readonly OtpDeliveryClassifier $classifier,
        private readonly VonageSmsSendGateway $gateway,
    ) {
    }

    public function send(OtpDeliveryRequest $request): OtpDeliveryResult
    {
        $key = trim((string) config('vonage.api_key'));
        $secret = trim((string) config('vonage.api_secret'));
        $from = $request->from ?? trim((string) config('vonage.sms_from'));
        if ($key === '' || $secret === '' || $from === '') {
            return new OtpDeliveryResult(OtpDeliveryResultClass::ProviderMisconfigured, null, $request->attemptNumber, 0, $this->alias());
        }

        $started = hrtime(true);
        $attempt = $request->attemptNumber;

        $smsWithCallback = $this->buildSms($request, $from);
        $callback = VonageSmsPerMessageCallbackApplicator::apply($smsWithCallback);

        $firstOutcome = $this->attemptSend(
            $smsWithCallback,
            $key,
            $secret,
            $attempt,
            $started,
            $request,
            $callback,
        );

        if ($firstOutcome->result->resultClass === OtpDeliveryResultClass::Accepted) {
            return $firstOutcome->result;
        }

        if ($firstOutcome->callbackFallbackEligible) {
            VonageSmsDeliveryDiagnostics::log('callback_fallback_without_per_message', [
                'correlation_id' => $request->correlationId,
                'failure_stage' => 'callback_rejected_pre_queue',
                'vonage_status' => $firstOutcome->vonageStatus,
                'vonage_error_text' => VonageSmsDeliveryDiagnostics::sanitizeErrorText($firstOutcome->vonageErrorText),
                'callback_applied' => true,
                'callback_host' => $callback->callbackHost,
                'callback_url_length' => $callback->callbackUrlLength,
            ]);

            $smsWithoutCallback = $this->buildSms($request, $from);
            $skippedCallback = new VonageSmsCallbackApplyResult(
                applied: false,
                skipReason: 'callback_rejected_by_provider',
            );

            $secondOutcome = $this->attemptSend(
                $smsWithoutCallback,
                $key,
                $secret,
                $attempt,
                $started,
                $request,
                $skippedCallback,
            );

            if ($secondOutcome->result->resultClass === OtpDeliveryResultClass::Accepted) {
                return $secondOutcome->result;
            }

            return $secondOutcome->result;
        }

        return $firstOutcome->result;
    }

    public function alias(): string
    {
        return (string) config('otp.p0a.delivery.provider_alias', 'vonage');
    }

    private function buildSms(OtpDeliveryRequest $request, string $from): SMS
    {
        return new SMS(
            $request->destinationE164OrEmail,
            $from,
            "Tu codigo de verificacion Famedic es: {$request->plainCode}. Valido por 10 minutos.",
        );
    }

    private function attemptSend(
        SMS $sms,
        string $key,
        string $secret,
        int $attempt,
        int $started,
        OtpDeliveryRequest $request,
        VonageSmsCallbackApplyResult $callback,
    ): VonageSmsSendAttemptOutcome {
        try {
            $response = $this->gateway->send($sms, $key, $secret);
            $parsed = VonageSmsSendResponseParser::parse($response);

            if (! $parsed->interpretable) {
                VonageSmsDeliveryDiagnostics::log('provider_outcome_uncertain', [
                    'correlation_id' => $request->correlationId,
                    'failure_stage' => 'vonage_response_uninterpretable',
                    'callback_applied' => $callback->applied,
                    'callback_host' => $callback->callbackHost,
                    'callback_url_length' => $callback->callbackUrlLength,
                ]);

                return new VonageSmsSendAttemptOutcome(new OtpDeliveryResult(
                    OtpDeliveryResultClass::InvalidProviderResponse,
                    '2xx',
                    $attempt,
                    $this->elapsed($started),
                    $this->alias(),
                ));
            }

            if (! $parsed->accepted) {
                VonageSmsDeliveryDiagnostics::log('vonage_response_not_accepted', [
                    'correlation_id' => $request->correlationId,
                    'failure_stage' => 'vonage_response_rejected',
                    'vonage_status' => $parsed->vonageStatus,
                    'vonage_error_text' => VonageSmsDeliveryDiagnostics::sanitizeErrorText($parsed->errorText),
                    'callback_applied' => $callback->applied,
                    'callback_host' => $callback->callbackHost,
                    'callback_url_length' => $callback->callbackUrlLength,
                    'message_id_prefix' => $parsed->messageId !== null ? substr($parsed->messageId, 0, 8) : null,
                ]);

                $fallbackEligible = VonageSmsCallbackFallbackPolicy::allowsFallbackWithoutCallback($callback, $parsed);

                return new VonageSmsSendAttemptOutcome(
                    new OtpDeliveryResult(
                        $this->mapVonageStatus($parsed->vonageStatus),
                        '2xx',
                        $attempt,
                        $this->elapsed($started),
                        $this->alias(),
                    ),
                    callbackFallbackEligible: $fallbackEligible,
                    vonageStatus: $parsed->vonageStatus,
                    vonageErrorText: $parsed->errorText,
                );
            }

            VonageSmsDeliveryDiagnostics::log('vonage_sms_accepted', [
                'correlation_id' => $request->correlationId,
                'failure_stage' => 'vonage_response_accepted',
                'vonage_status' => $parsed->vonageStatus,
                'callback_applied' => $callback->applied,
                'callback_skip_reason' => $callback->skipReason,
                'callback_host' => $callback->callbackHost,
                'callback_url_length' => $callback->callbackUrlLength,
                'message_id_prefix' => $parsed->messageId !== null ? substr($parsed->messageId, 0, 8) : null,
            ]);

            return new VonageSmsSendAttemptOutcome(new OtpDeliveryResult(
                OtpDeliveryResultClass::Accepted,
                '2xx',
                $attempt,
                $this->elapsed($started),
                $this->alias(),
                providerMessageId: $parsed->messageId,
            ));
        } catch (\Throwable $e) {
            $class = $this->classifier->classify($e);

            VonageSmsDeliveryDiagnostics::log('provider_outcome_uncertain', [
                'correlation_id' => $request->correlationId,
                'failure_stage' => 'vonage_send_exception',
                'exception_class' => $e::class,
                'http_status_class' => $this->httpClass($e),
                'callback_applied' => $callback->applied,
                'callback_host' => $callback->callbackHost,
                'callback_url_length' => $callback->callbackUrlLength,
            ]);

            return new VonageSmsSendAttemptOutcome(new OtpDeliveryResult(
                $class,
                $this->httpClass($e),
                $attempt,
                $this->elapsed($started),
                $this->alias(),
            ));
        }
    }

    private function mapVonageStatus(?int $status): OtpDeliveryResultClass
    {
        return match ($status) {
            1 => OtpDeliveryResultClass::RateLimitedByProvider,
            5 => OtpDeliveryResultClass::ProviderTemporaryFailure,
            4 => OtpDeliveryResultClass::ProviderMisconfigured,
            default => OtpDeliveryResultClass::ProviderPermanentFailure,
        };
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
