<?php

namespace App\Actions\Payments\HeyBanco;

use App\Data\Payments\HeyBanco\HeyBanco3dsStartResult;
use App\Exceptions\HeyBancoPaymentException;
use App\Models\Payment3dsSession;
use App\Services\Payments\HeyBanco\HeyBanco3dsClient;

class StartHeyBanco3dsTokenChargeAction
{
    public function __construct(
        private HeyBanco3dsClient $client,
    ) {}

    public function __invoke(Payment3dsSession $session): HeyBanco3dsStartResult
    {
        $session->loadMissing('paymentMethod');

        $paymentMethod = $session->paymentMethod;

        if (! $paymentMethod) {
            throw new HeyBancoPaymentException('Método de pago no encontrado para la sesión 3DS.');
        }

        $result = $this->client->startTokenCharge($session, $paymentMethod);
        $failureStatus = $this->mapStartFailureStatus($result->codigoProc);

        $session->update([
            'raw_request' => $result->sanitizedRequest,
            'raw_response' => [
                'headers' => $result->rawHeaders,
                'body' => $result->rawBody,
            ],
            'request_hash' => $result->sanitizedRequest['BNRG_HASH'] ?? null,
            'status' => $result->success ? 'redirect_required' : $failureStatus,
            'redirect_url' => $result->redirectUrl,
            'bnrg_text' => $result->texto,
            'bnrg_codigo_proc' => $result->codigoProc,
            'bnrg_codigo_rechazo' => $result->codigoRechazo,
            'failed_at' => $result->success ? null : now(),
        ]);

        $session->paymentAttempt?->update([
            'status' => $result->success ? 'pending_3ds' : $failureStatus,
            'processor_message' => $result->errorMessage ?? $result->texto,
            'processor_code' => $result->codigoProc,
        ]);

        $session->paymentTransaction?->update([
            'status' => $result->success ? 'pending_3ds' : $failureStatus,
            'bnrg_text' => $result->texto,
            'bnrg_codigo_proc' => $result->codigoProc,
            'bnrg_codigo_rechazo' => $result->codigoRechazo,
        ]);

        if (! $result->success) {
            throw new HeyBancoPaymentException(
                $result->errorMessage ?? 'No se pudo iniciar la autenticación 3D Secure.'
            );
        }

        return $result;
    }

    private function mapStartFailureStatus(?string $codigoProc): string
    {
        return match (strtoupper((string) $codigoProc)) {
            'R' => 'rejected',
            'D' => 'declined',
            'T' => 'timeout',
            'X' => 'failed',
            default => 'failed',
        };
    }
}
