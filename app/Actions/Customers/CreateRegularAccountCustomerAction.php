<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Models\RegularAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateRegularAccountCustomerAction
{
    private GenerateMedicalAttentionIdAction $generateMedicalAttentionIdAction;

    public function __construct(GenerateMedicalAttentionIdAction $generateMedicalAttentionIdAction)
    {
        $this->generateMedicalAttentionIdAction = $generateMedicalAttentionIdAction;
    }

    public function __invoke(
        User $user
    ): RegularAccount {

        DB::beginTransaction();

        try {
            $regularAccount = RegularAccount::create();

            $regularAccount->customer()->save(new Customer([
                'medical_attention_identifier' => ($this->generateMedicalAttentionIdAction)(),
                'user_id' => $user->id
            ]));

            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            throw $th;
        }

        return $regularAccount;
    }
}
