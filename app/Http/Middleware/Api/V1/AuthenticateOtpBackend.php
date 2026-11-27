<?php

namespace App\Http\Middleware\Api\V1;

use App\Contracts\Api\V1\Auth\BackendClientAuthenticator;
use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AuthenticateOtpBackend
{
    public const ATTRIBUTE = self::class;

    public function __construct(private BackendClientAuthenticator $backend) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $request->attributes->remove(self::ATTRIBUTE);
        if (config('akubica_connectors.otp_enrollment.enabled') !== true || config('akubica_connectors.otp_enrollment.certified') !== true) {
            return $next($request);
        }
        try {
            $client = $this->backend->authenticate($request);
            if (! $client) {
                return ApiResponse::error('UNAUTHENTICATED', 'No autenticado.', 401);
            }
            if (! str_ends_with((string) $request->route()?->getName(), 'login.request-code')) {
                $id = $request->input('challenge_id');
                if (! is_string($id)) {
                    return ApiResponse::error('VALIDATION_ERROR', 'Solicitud no válida.', 422);
                }
                $bound = DB::table('otp_backend_challenges')->where('challenge_hash', hash('sha256', $id))->where('consumer_id', $client->consumerId)->where('channel_id', $client->channelId)->first(['expires_at']);
                if (! $bound) {
                    return ApiResponse::error('OTP_BACKEND_CONTEXT_INVALID', 'Confirmación no disponible.', 403);
                }
                // INT-030: expires_at is stored as wall-clock time in the application timezone (same convention as
                // created_at, otp_challenges.expires_at and the rest of the schema; the MySQL session stays UTC). Parse
                // it explicitly in that timezone so the comparison never depends on how the row was formatted.
                if (! \Illuminate\Support\Carbon::parse($bound->expires_at, config('app.timezone'))->isFuture()) {
                    // RC2 A11 (anexo): challenge propio del mismo consumidor/canal ya vencido -> 422 CODE_EXPIRED, sin Bearer.
                    // Un challenge de otro consumidor/canal o inexistente sigue en 403 (sin revelar su existencia).
                    return ApiResponse::error('CODE_EXPIRED', 'El codigo expiro. Solicita uno nuevo.', 422);
                }
            }
            $request->attributes->set(self::ATTRIBUTE, $client);
            try {
                $response = $next($request);
            } finally {
                $request->attributes->remove(self::ATTRIBUTE);
            }
            if ($response->getStatusCode() === 202) {
                $data = json_decode($response->getContent(), true)['data'] ?? [];
                if (is_string($data['challenge_id'] ?? null) && is_string($data['expires_at'] ?? null)) {
                    DB::transaction(function () use ($client, $data) {
                        $hash = hash('sha256', $data['challenge_id']);
                        $old = DB::table('otp_backend_challenges')->where('challenge_hash', $hash)->lockForUpdate()->first();
                        if ($old && ((string) $old->consumer_id !== $client->consumerId || (string) $old->channel_id !== $client->channelId)) {
                            throw new \DomainException('OTP context conflict');
                        }
                        DB::table('otp_backend_challenges')->updateOrInsert(['challenge_hash' => $hash], ['consumer_id' => $client->consumerId, 'channel_id' => $client->channelId, 'expires_at' => self::backendExpiry($data['expires_at'], $client->expiresAt), 'created_at' => $old?->created_at ?? now(), 'updated_at' => now()]);
                    });
                }
            }

            return $response;
        } catch (\Illuminate\Database\QueryException|\DomainException) {
            return ApiResponse::error('AUTHENTICATION_UNAVAILABLE','Autenticación no disponible.',503);
        }
    }

    /**
     * INT-030 (OBS-SEC-OTP-BACKEND-EXPIRY-TZ): the bound expiry is min(challenge expiry, machine credential expiry)
     * as an absolute instant, converted to the application timezone before it is written. Previously the value
     * was built with createFromTimestampUTC() and persisted as UTC wall-clock time, while the reader parses it as
     * application time, so with America/Mexico_City every backend challenge outlived its 5-minute TTL by 6 hours.
     * An unparsable expires_at keeps the previous fail-closed behaviour (epoch: the write is rejected, 503, no binding).
     */
    private static function backendExpiry(string $challengeExpiresAt, int $credentialExpiresAt): \Illuminate\Support\Carbon
    {
        $challenge = strtotime($challengeExpiresAt);

        return \Illuminate\Support\Carbon::createFromTimestamp(min($challenge === false ? 0 : $challenge, $credentialExpiresAt), config('app.timezone'));
    }
}
