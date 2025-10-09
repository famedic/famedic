<?php

namespace App\Actions\Customers;

use App\Actions\MedicalAttention\CreateFamilyMemberSubscriptionAction;
use App\Enums\Gender;
use App\Enums\Kinship;
use App\Models\Customer;
use App\Models\FamilyAccount;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CreateFamilyAccountCustomerAction
{
    private GenerateMedicalAttentionIdAction $generateMedicalAttentionIdAction;

    private CreateFamilyMemberSubscriptionAction $createFamilyMemberSubscriptionAction;

    public function __construct(
        GenerateMedicalAttentionIdAction $generateMedicalAttentionIdAction,
        CreateFamilyMemberSubscriptionAction $createFamilyMemberSubscriptionAction
    ) {
        $this->generateMedicalAttentionIdAction = $generateMedicalAttentionIdAction;
        $this->createFamilyMemberSubscriptionAction = $createFamilyMemberSubscriptionAction;
    }

    public function __invoke(
        string $name,
        string $paternal_lastname,
        string $maternal_lastname,
        Carbon $birth_date,
        Gender $gender,
        Kinship $kinship,
        Customer $customer
    ): FamilyAccount {

        DB::beginTransaction();

        try {
            $familyAccount = FamilyAccount::create([
                'name' => $name,
                'paternal_lastname' => $paternal_lastname,
                'maternal_lastname' => $maternal_lastname,
                'birth_date' => $birth_date->toDateString(),
                'gender' => $gender->value,
                'kinship' => $kinship,
                'customer_id' => $customer->id,
            ]);

            $familyAccount->customer()->save(new Customer([
                'medical_attention_identifier' => ($this->generateMedicalAttentionIdAction)(),
                'medical_attention_subscription_expires_at' => $customer->medical_attention_subscription_expires_at,
            ]));

            $activeParentSubscription = $customer->medicalAttentionSubscriptions()
                ->active()
                ->whereNull('parent_subscription_id')
                ->first();

            if ($activeParentSubscription) {
                ($this->createFamilyMemberSubscriptionAction)(
                    $familyAccount->customer,
                    $activeParentSubscription
                );
            }

            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            throw $th;
        }

        return $familyAccount;
    }
}
