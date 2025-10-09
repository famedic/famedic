<?php

namespace App\Actions\Register;

use App\Actions\Customers\CreateRegularAccountCustomerAction;
use App\Actions\Users\CreateUserAction;
use App\Enums\Gender;
use App\Models\RegularAccount;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RegisterRegularCustomerAction
{
    private CreateUserAction $createUserAction;
    private CreateRegularAccountCustomerAction $createRegularCustomerAction;

    public function __construct(CreateUserAction $createUserAction, CreateRegularAccountCustomerAction $createRegularCustomerAction)
    {
        $this->createUserAction = $createUserAction;
        $this->createRegularCustomerAction = $createRegularCustomerAction;
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
        ?int $referrerUserId = null,
    ): RegularAccount {

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
                documentationAccepted: true,
                referrerUserId: $referrerUserId
            );

            $regularAccount = ($this->createRegularCustomerAction)($user);

            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            throw $th;
        }

        return $regularAccount;
    }
}
