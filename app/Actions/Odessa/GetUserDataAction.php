<?php

namespace App\Actions\Odessa;

use App\DTOs\OdessaUserData;
use App\Exceptions\OdessaGetUserDataFailedException;
use App\Models\OdessaAfiliateAccount;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class GetUserDataAction
{
    public function __construct(
        private GetOdessaPrivateTokenAction $getOdessaPrivateTokenAction,
    ) {}

    /**
     * @return OdessaUserData[]
     */
    public function __invoke(OdessaAfiliateAccount $odessaAfiliateAccount): array
    {
        $token = ($this->getOdessaPrivateTokenAction)($odessaAfiliateAccount);

        return $this->fetchWithToken($token);
    }

    /**
     * @return OdessaUserData[]
     */
    public function fetchWithToken(string $token): array
    {
        $url = config('services.odessa.url') . 'getUserData';

        $response = $this->sendRequest($token, $url);

        if ($response->failed()) {
            throw new OdessaGetUserDataFailedException(json_encode($response->json()));
        }

        $responseBody = $this->normalizeResponsePayload($response->json());

        if (($responseBody['intError'] ?? null) !== 0) {
            throw new OdessaGetUserDataFailedException(json_encode($response->json()));
        }

        return array_map(
            fn (array $userData) => OdessaUserData::fromArray($userData),
            $responseBody['UserData'],
        );
    }

    /**
     * Odessa documenta response como objeto, pero en QA devuelve un arreglo con un sobre.
     *
     * @return array{intError: int|null, chrMessage: string|null, UserData: array<int, array<string, mixed>>}
     */
    private function normalizeResponsePayload(array $json): array
    {
        $response = $json['response'] ?? null;

        if (! is_array($response)) {
            return [
                'intError' => null,
                'chrMessage' => null,
                'UserData' => [],
            ];
        }

        if (array_is_list($response)) {
            $userData = [];

            foreach ($response as $envelope) {
                if (! is_array($envelope)) {
                    continue;
                }

                if (($envelope['intError'] ?? 0) !== 0) {
                    return [
                        'intError' => $envelope['intError'] ?? null,
                        'chrMessage' => $envelope['chrMessage'] ?? null,
                        'UserData' => [],
                    ];
                }

                $userData = array_merge($userData, $envelope['UserData'] ?? []);
            }

            return [
                'intError' => 0,
                'chrMessage' => '',
                'UserData' => $userData,
            ];
        }

        return [
            'intError' => $response['intError'] ?? null,
            'chrMessage' => $response['chrMessage'] ?? null,
            'UserData' => $response['UserData'] ?? [],
        ];
    }

    public function sendRequest(string $token, string $url): Response
    {
        return Http::withOptions([
            'verify' => false,
        ])->withHeaders([
            'Authorization' => (string) ('Bearer ' . $token),
            'Accept' => 'application/json',
        ])->get($url);
    }
}
