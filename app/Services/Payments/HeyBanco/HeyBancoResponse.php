<?php

namespace App\Services\Payments\HeyBanco;

class HeyBancoResponse
{
    public function __construct(
        public array $rawHeaders,
        public array $normalizedHeaders,
        public ?array $rawRequest = null,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->normalizedHeaders[strtoupper($key)] ?? $default;
    }

    public function codigoProc(): ?string
    {
        return $this->get('BNRG_CODIGO_PROC');
    }

    public function codigoProcTrans(): ?string
    {
        return $this->get('BNRG_CODIGO_PROC_TRANS');
    }

    public function codigoRechazo(): ?string
    {
        return $this->get('BNRG_CODIGO_RECHAZO');
    }

    public function texto(): ?string
    {
        return $this->get('BNRG_TEXTO');
    }

    public function token(): ?string
    {
        return $this->get('BNRG_TOKEN');
    }

    public function referencia(): ?string
    {
        return $this->get('BNRG_REFERENCIA');
    }

    public function codigoAut(): ?string
    {
        return $this->get('BNRG_CODIGO_AUT');
    }

    public function folio(): ?string
    {
        return $this->get('BNRG_FOLIO');
    }

    public function idMedio(): ?string
    {
        return $this->get('BNRG_ID_MEDIO');
    }

    public function idAfiliacion(): ?string
    {
        return $this->get('BNRG_ID_AFILIACION');
    }

    public function monto(): ?string
    {
        return $this->get('BNRG_MONTO_TRANS');
    }

    public function estadoTrans(): ?string
    {
        return $this->get('BNRG_ESTADO_TRANS');
    }

    public function tipoTrans(): ?string
    {
        return $this->get('BNRG_TIPO_TRANS');
    }

    public function isApproved(): bool
    {
        return $this->codigoProc() === 'A';
    }

    public function isRejected(): bool
    {
        return $this->codigoProc() === 'R';
    }

    public function isDeclined(): bool
    {
        return $this->codigoProc() === 'D';
    }

    public function isTimeout(): bool
    {
        return $this->codigoProc() === 'T';
    }

    public function isFailed(): bool
    {
        return $this->codigoProc() === 'X';
    }

    /**
     * Verificación exitosa de una venta previa.
     */
    public function isVerificationApproved(): bool
    {
        return $this->codigoProc() === 'A'
            && $this->codigoProcTrans() === 'A'
            && $this->estadoTrans() === 'C';
    }

    public function statusLabel(): string
    {
        return match ($this->codigoProc()) {
            'A' => 'approved',
            'D' => 'declined',
            'R' => 'rejected',
            'T' => 'timeout',
            'X' => 'failed',
            default => 'unknown',
        };
    }

    public function toArray(): array
    {
        return [
            'raw_headers' => $this->rawHeaders,
            'normalized_headers' => $this->normalizedHeaders,
            'codigo_proc' => $this->codigoProc(),
            'codigo_proc_trans' => $this->codigoProcTrans(),
            'codigo_rechazo' => $this->codigoRechazo(),
            'texto' => $this->texto(),
            'token' => $this->token(),
            'referencia' => $this->referencia(),
            'codigo_aut' => $this->codigoAut(),
            'folio' => $this->folio(),
            'status' => $this->statusLabel(),
        ];
    }
}
