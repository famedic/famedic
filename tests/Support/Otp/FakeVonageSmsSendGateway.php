<?php

namespace Tests\Support\Otp;

use App\Contracts\Otp\VonageSmsSendGateway;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Vonage\SMS\Collection;
use Vonage\SMS\Message\SMS;

final class FakeVonageSmsSendGateway implements VonageSmsSendGateway
{
    public int $calls = 0;

    /** @var list<\Closure(SMS, int): Collection|\Throwable> */
    private array $handlers = [];

    public function push(\Closure $handler): self
    {
        $this->handlers[] = $handler;

        return $this;
    }

    public function pushCollection(Collection $collection): self
    {
        return $this->push(static fn (): Collection => $collection);
    }

    public function pushConnectException(string $message = 'Connection timed out'): self
    {
        return $this->push(static fn (): never => throw new ConnectException(
            $message,
            new Request('POST', 'https://rest.nexmo.com/sms/json'),
        ));
    }

    public function send(SMS $sms, string $apiKey, string $apiSecret): Collection
    {
        $this->calls++;
        $handler = array_shift($this->handlers);

        if ($handler === null) {
            throw new \RuntimeException('FakeVonageSmsSendGateway: no handler queued for call '.$this->calls);
        }

        $result = $handler($sms, $this->calls);

        if ($result instanceof Collection) {
            return $result;
        }

        throw $result;
    }
}

function vonageSmsCollection(int $status, ?string $errorText = null, ?string $messageId = null): Collection
{
    $message = [
        'to' => '525512345678',
        'status' => $status,
        'remaining-balance' => '12.34',
        'message-price' => '0.04500',
        'network' => '334020',
    ];

    if ($messageId !== null) {
        $message['message-id'] = $messageId;
    }

    if ($errorText !== null) {
        $message['error-text'] = $errorText;
    }

    return new Collection([
        'message-count' => 1,
        'messages' => [$message],
    ]);
}

function makeVonageOtpProvider(FakeVonageSmsSendGateway $gateway): \App\Services\Otp\Delivery\VonageOtpDeliveryProvider
{
    app()->instance(\App\Contracts\Otp\VonageSmsSendGateway::class, $gateway);
    config([
        'vonage.api_key' => 'test-key',
        'vonage.api_secret' => 'test-secret',
        'vonage.sms_from' => 'Famedic',
        'otp.p0a.delivery.provider_alias' => 'vonage',
    ]);

    return app(\App\Services\Otp\Delivery\VonageOtpDeliveryProvider::class);
}

function vonageOtpDeliveryRequest(): \App\Services\Otp\Delivery\OtpDeliveryRequest
{
    return new \App\Services\Otp\Delivery\OtpDeliveryRequest(
        purpose: 'akubica_register',
        channel: 'sms',
        destinationE164OrEmail: '525512345678',
        plainCode: '123456',
        correlationId: (string) \Illuminate\Support\Str::uuid(),
        attemptNumber: 1,
        from: null,
    );
}
