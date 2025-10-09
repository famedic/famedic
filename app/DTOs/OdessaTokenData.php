<?php

namespace App\DTOs;

use App\Models\OdessaAfiliateAccount;
use Carbon\Carbon;
use stdClass;

class OdessaTokenData
{
    public function __construct(
        public string $odessaId,
        public string $odessaAfiliateAccountId,
        public ?OdessaAfiliateAccount $odessaAfiliateAccount,
        public bool $hasLinkedOdessaAfiliateAccount,
        public ?OdessaAfiliateAccount $linkedOdessaAfiliateAccount,
        public Carbon $expiration,
    ) {}
    public static function fromObject(
        stdClass $token
    ): self {
        $tokenIdUserIsEmpty = empty($token->idUser);

        return new self(
            odessaId: $token->idOdessa,
            odessaAfiliateAccountId: $token->idUser,
            odessaAfiliateAccount: OdessaAfiliateAccount::whereOdessaIdentifier($token->idOdessa)->first(),
            hasLinkedOdessaAfiliateAccount: !$tokenIdUserIsEmpty,
            linkedOdessaAfiliateAccount: !$tokenIdUserIsEmpty ? OdessaAfiliateAccount::find($token->idUser) : null,
            expiration: Carbon::createFromTimestampUTC($token->exp)->setTimezone('America/Monterrey'),
        );
    }
}
