<?php

namespace App\Services\Otp\Monitoring;

final readonly class VonageSmsConnectionTestResult
{
    /**
     * @param  array<string, mixed>  $callback
     */
    public function __construct(
        public string $correlationId,
        public string $sentAt,
        public string $environment,
        public string $destinationMasked,
        public string $mode,
        public bool $accepted,
        public bool $interpretable,
        public ?int $vonageStatus,
        public ?string $providerMessageIdPrefix,
        public ?string $errorText,
        public int $elapsedMs,
        public array $callback,
        public string $diagnosis,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'correlation_id' => $this->correlationId,
            'sent_at' => $this->sentAt,
            'environment' => $this->environment,
            'destination_masked' => $this->destinationMasked,
            'mode' => $this->mode,
            'accepted' => $this->accepted,
            'interpretable' => $this->interpretable,
            'vonage_status' => $this->vonageStatus,
            'provider_message_id_prefix' => $this->providerMessageIdPrefix,
            'error_text' => $this->errorText,
            'elapsed_ms' => $this->elapsedMs,
            'callback' => $this->callback,
            'diagnosis' => $this->diagnosis,
        ];
    }
}
