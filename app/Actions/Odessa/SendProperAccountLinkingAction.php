<?php

namespace App\Actions\Odessa;

use App\DTOs\OdessaTokenData;
use App\Exceptions\OdessaAfiliateMemberAlreadyLinkedException;
use App\Exceptions\OdessaAfiliateMemberLinkingFailedException;
use App\Exceptions\OdessaAfiliateMemberMismatchException;
use App\Models\OdessaAfiliateAccount;
use Illuminate\Support\Facades\Http;

class SendProperAccountLinkingAction
{
    private GetOdessaPrivateTokenAction $getOdessaPrivateTokenAction;

    public function __construct(GetOdessaPrivateTokenAction $getOdessaPrivateTokenAction)
    {
        $this->getOdessaPrivateTokenAction = $getOdessaPrivateTokenAction;
    }

    public function __invoke(OdessaAfiliateAccount $odessaAfiliateAccount, OdessaTokenData $odessaTokenData): void
    {
        if ($odessaTokenData->hasLinkedOdessaAfiliateAccount) {
            throw new OdessaAfiliateMemberAlreadyLinkedException();
        }

        if ($odessaTokenData->odessaId != $odessaAfiliateAccount->odessa_identifier) {
            throw new OdessaAfiliateMemberMismatchException();
        }

        $token = ($this->getOdessaPrivateTokenAction)($odessaAfiliateAccount);

        $url = config('services.odessa.url') . 'setUser';

        $response = Http::withOptions([
            'verify' => false,
        ])->withHeaders([
            'Authorization' => (string)('Bearer ' . $token),
            'Accept' => 'application/json'
        ])->post($url, [
            'request' => [
                'idUser' => $odessaAfiliateAccount->id
            ]
        ]);

        if ($response->failed() || $response->json()['response']['errorCode'] != 0) {
            throw new OdessaAfiliateMemberLinkingFailedException(json_encode($response->json()));
        }

        return;
    }
}
