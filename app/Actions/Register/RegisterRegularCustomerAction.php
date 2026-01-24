<?php

namespace App\Actions\Register;

use App\Actions\Customers\CreateRegularAccountCustomerAction;
use App\Actions\Users\CreateUserAction;
use App\Enums\Gender;
use App\Events\UserRegistered;
use App\Models\RegularAccount;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        ?string $state = null,
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
                referrerUserId: $referrerUserId,
                state: $state,
            );

            $regularAccount = ($this->createRegularCustomerAction)($user);

            DB::commit();

            Log::info('RegisterRegularCustomerAction: Usuario creado', [
                'user_id' => $user->id,
                'email' => $user->email,
                'paternal_lastname' => $user->paternal_lastname,
                'maternal_lastname' => $user->maternal_lastname,
                'birth_date' => $user->birth_date,
                'gender' => $user->gender?->value,
                'phone' => $user->phone,
                'phone_country' => $user->phone_country,
                'state' => $user->state,
                'referred_by' => $user->referred_by,
            ]);
            // ========================================
            // NUEVO: Disparar evento para ActiveCampaign
            // ========================================
            if (config('activecampaign.sync.enabled', false)) {
                $metadata = [
                    'tags' => [
                        config('activecampaign.tags.registro_nuevo', 'RegistroNuevo'),
                        'Paciente',
                        'RegistroWeb',
                    ],
                    'source' => 'web_registration',
                    'registration_date' => now()->toISOString(),
                ];

                \App\Events\UserRegistered::dispatch($user, $metadata);

                Log::info('ActiveCampaign: Evento disparado', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                ]);
            }

            Log::info('RegisterRegularCustomerAction: Evento UserRegistered disparado', [
                'user_id' => $user->id,
            ]);
            // ========================================
        } catch (\Throwable $th) {
            DB::rollBack();
            throw $th;
        }

        return $regularAccount;
    }
}
