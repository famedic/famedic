<?php

namespace App\Actions\Odessa;

use App\Actions\Odessa\CreateOdessaAfiliateAccountCustomerAction;
use App\Actions\Odessa\SendProperAccountLinkingAction;
use App\Actions\Users\CreateUserAction;
use App\DTOs\OdessaTokenData;
use App\Enums\Gender;
use App\Models\OdessaAfiliateAccount;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RegisterOdessaAfiliateCustomerAction
{
    private CreateUserAction $createUserAction;
    private CreateOdessaAfiliateAccountCustomerAction $createOdessaAfiliateAccountCustomerAction;
    private SendProperAccountLinkingAction $sendProperAccountLinkingAction;

    public function __construct(CreateUserAction $createUserAction, CreateOdessaAfiliateAccountCustomerAction $createOdessaAfiliateAccountCustomerAction, SendProperAccountLinkingAction $sendProperAccountLinkingAction)
    {
        $this->createUserAction = $createUserAction;
        $this->createOdessaAfiliateAccountCustomerAction = $createOdessaAfiliateAccountCustomerAction;
        $this->sendProperAccountLinkingAction = $sendProperAccountLinkingAction;
    }

    public function __invoke(
        string $email,
        ?string $name = null,
        ?string $paternalLastname = null,
        ?string $maternalLastname = null,
        ?Carbon $birthDate = null,
        ?Gender $gender = null,
        ?string $phone = null,
        ?string $phoneCountry = null,
        ?string $password = null,
        OdessaTokenData $odessaTokenData
    ): OdessaAfiliateAccount {

        DB::beginTransaction();

        try {
            $user = ($this->createUserAction)(
                name: $name,
                paternalLastname: $paternalLastname,
                maternalLastname: $maternalLastname,
                birthDate: $birthDate,
                gender: $gender,
                phone: $phone,
                phoneCountry: $phoneCountry,
                email: $email,
                password: $password,
                documentationAccepted: true
            );

            $odessaAfiliateAccount = ($this->createOdessaAfiliateAccountCustomerAction)(
                $user,
                $odessaTokenData->odessaId
            );

            ($this->sendProperAccountLinkingAction)($odessaAfiliateAccount, $odessaTokenData);

            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            throw $th;
        }

        return $odessaAfiliateAccount;
    }
}
