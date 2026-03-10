<?php

namespace App\Actions\Odessa;

use App\Actions\Customers\GenerateMedicalAttentionIdAction;
use App\Models\Customer;
use App\Models\OdessaAfiliateAccount;
use App\Models\User;
use Illuminate\Support\Facades\Log;
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

            /*            
            //Ver la forma de traer estos datos sin necesidad de decodificar el token nuevamente
            $odessaAfiliateAccount = OdessaAfiliateAccount::create([
                'odessa_identifier' => $odessaAfiliateIdentifier,
                'client_id' => $odessaTokenData->clientId,
                'empresa' => $odessaTokenData->empresa,
                'nombre' => $odessaTokenData->nombre,
                'planta_id' => $odessaTokenData->plantaId,
            ]);
            */
            Log::info('CREATING ODESSA AFILIATE ACCOUNT', [
                'odessa_identifier' => $odessaAfiliateIdentifier,
                'user_id' => $user->id
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
