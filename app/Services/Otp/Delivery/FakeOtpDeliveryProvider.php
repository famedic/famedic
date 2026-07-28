<?php

namespace App\Services\Otp\Delivery;

use App\Contracts\Otp\OtpDeliveryProvider;
use App\Services\Otp\OtpAbuseKeyHasher;

final class FakeOtpDeliveryProvider implements OtpDeliveryProvider
{
    /** @var list<array{purpose:string,channel:string,destination_fingerprint:string,attempt:int}> */
    public array $sent = [];

    /** @var list<OtpDeliveryResultClass> */
    private array $sequence = [];

    public function __construct(private readonly OtpAbuseKeyHasher $hasher)
    {
    }

    public function alwaysAccept(): self
    {
        $this->sequence = [];

        return $this;
    }

    public function failOnceWith(OtpDeliveryResultClass $result): self
    {
        $this->sequence[] = $result;

        return $this;
    }

    public function failAlwaysWith(OtpDeliveryResultClass $result): self
    {
        $this->sequence = array_fill(0, 100, $result);

        return $this;
    }

    /** @param list<OtpDeliveryResultClass> $results */
    public function sequence(array $results): self
    {
        $this->sequence = $results;

        return $this;
    }

    public function send(OtpDeliveryRequest $request): OtpDeliveryResult
    {
        $this->sent[] = [
            'purpose' => $request->purpose,
            'channel' => $request->channel,
            'destination_fingerprint' => substr($this->hasher->hashOpaque('fake-delivery', $request->destinationE164OrEmail), 0, 16),
            'attempt' => $request->attemptNumber,
        ];
        $class = array_shift($this->sequence) ?? OtpDeliveryResultClass::Accepted;

        return new OtpDeliveryResult($class, null, $request->attemptNumber, 0, $this->alias());
    }

    public function alias(): string
    {
        return 'fake';
    }
}
