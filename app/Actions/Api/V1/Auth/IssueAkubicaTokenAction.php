<?php

namespace App\Actions\Api\V1\Auth;

use App\Models\User;

class IssueAkubicaTokenAction
{
    /**
     * @return array{token: string, token_type: string, expires_in: int, expires_at: string}
     */
    public function __invoke(User $user): array
    {
        $tokenResult = $user->createToken(
            config('akubica.token_name'),
            config('akubica.token_abilities'),
        );

        $accessToken = $tokenResult->accessToken;
        $expiresAt = $accessToken->expires_at
            ?? now()->addMinutes((int) config('akubica.token_ttl_minutes', 1440));

        $expiresIn = (int) max(0, $expiresAt->getTimestamp() - now()->getTimestamp());

        return [
            'token' => $tokenResult->plainTextToken,
            'token_type' => 'Bearer',
            'expires_in' => $expiresIn,
            'expires_at' => $expiresAt->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
