<?php

namespace App\Data\Payments\HeyBanco;

class HeyBanco3dsStartResult
{
    public function __construct(
        public bool $success,
        public ?string $redirectUrl = null,
        public array $rawHeaders = [],
        public ?string $rawBody = null,
        public ?string $errorMessage = null,
        public ?string $codigoProc = null,
        public ?string $texto = null,
        public ?string $codigoRechazo = null,
        public array $sanitizedRequest = [],
    ) {}

    public static function success(string $redirectUrl, array $rawHeaders = [], ?string $rawBody = null): self
    {
        return new self(
            success: true,
            redirectUrl: $redirectUrl,
            rawHeaders: $rawHeaders,
            rawBody: $rawBody,
        );
    }

    public static function failure(
        string $message,
        ?string $codigoProc = null,
        ?string $texto = null,
        array $rawHeaders = [],
        ?string $rawBody = null,
    ): self {
        return new self(
            success: false,
            rawHeaders: $rawHeaders,
            rawBody: $rawBody,
            errorMessage: $message,
            codigoProc: $codigoProc,
            texto: $texto,
        );
    }
}
