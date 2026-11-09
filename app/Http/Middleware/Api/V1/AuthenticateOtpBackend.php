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
                if (! DB::table('otp_backend_challenges')->where('challenge_hash', hash('sha256', $id))->where('consumer_id', $client->consumerId)->where('channel_id', $client->channelId)->where('expires_at', '>', now())->exists()) {
                    return ApiResponse::error('OTP_BACKEND_CONTEXT_INVALID', 'Confirmación no disponible.', 403);
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
                        DB::table('otp_backend_challenges')->updateOrInsert(['challenge_hash' => $hash], ['consumer_id' => $client->consumerId, 'channel_id' => $client->channelId, 'expires_at' => \Illuminate\Support\Carbon::createFromTimestampUTC(min(strtotime($data['expires_at']), $client->expiresAt)), 'created_at' => $old?->created_at ?? now(), 'updated_at' => now()]);
                    });
                }
            }

            return $response;
        } catch (\Illuminate\Database\QueryException|\DomainException) {
            return ApiResponse::error('AUTHENTICATION_UNAVAILABLE','Autenticación no disponible.',503);
        }
    }
}
