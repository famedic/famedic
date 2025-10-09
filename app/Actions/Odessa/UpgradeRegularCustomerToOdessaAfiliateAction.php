<?php

namespace App\Actions\Odessa;

use App\DTOs\OdessaTokenData;
use App\Exceptions\OdessaAfiliateMemberAlreadyLinkedException;
use App\Exceptions\OdessaIdAlreadyLinkedException;
use App\Models\Customer;
use App\Models\OdessaAfiliateAccount;
use Illuminate\Support\Facades\DB;

class UpgradeRegularCustomerToOdessaAfiliateAction
{
    public function __construct(
        private SendProperAccountLinkingAction $sendProperAccountLinkingAction,
    ) {}

    public function __invoke(Customer $customer, OdessaTokenData $odessaTokenData): OdessaAfiliateAccount
    {
        if ($odessaTokenData->hasLinkedOdessaAfiliateAccount) {
            throw new OdessaAfiliateMemberAlreadyLinkedException();
        }

        return DB::transaction(function () use ($customer, $odessaTokenData) {
            $existingOdessaAfiliateAccount = OdessaAfiliateAccount::with('customer')
                ->where('odessa_identifier', $odessaTokenData->odessaId)
                ->first();

            if ($existingOdessaAfiliateAccount) {
                throw new OdessaIdAlreadyLinkedException();
            }

            $regularAccount = $customer->customerable;

            $odessaAfiliateAccount = OdessaAfiliateAccount::create([
                'odessa_identifier' => $odessaTokenData->odessaId,
            ]);

            $customer->customerable()->associate($odessaAfiliateAccount);
            $customer->save();

            $regularAccount->delete();

            ($this->sendProperAccountLinkingAction)($odessaAfiliateAccount, $odessaTokenData);

            return $odessaAfiliateAccount;
        });
    }
}
