<?php

namespace App\Actions\Odessa;

use App\Actions\Customers\GenerateMedicalAttentionIdAction;
use App\Models\Customer;
use App\Models\OdessaAfiliateAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateOdessaAfiliateAccountCustomerAction
{
    private GenerateMedicalAttentionIdAction $generateMedicalAttentionIdAction;

    public function __construct(GenerateMedicalAttentionIdAction $generateMedicalAttentionIdAction)
    {
        $this->generateMedicalAttentionIdAction = $generateMedicalAttentionIdAction;
    }

    public function __invoke(
        User $user,
        $odessaAfiliateIdentifier
    ): OdessaAfiliateAccount {

        DB::beginTransaction();

        try {
            $odessaAfiliateAccount = OdessaAfiliateAccount::create([
                'odessa_identifier' => $odessaAfiliateIdentifier
            ]);

            $odessaAfiliateAccount->customer()->save(new Customer([
                'medical_attention_identifier' => ($this->generateMedicalAttentionIdAction)(),
                'user_id' => $user->id
            ]));

            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            throw $th;
        }

        return $odessaAfiliateAccount;
    }
}
