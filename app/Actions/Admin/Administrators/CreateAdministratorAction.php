<?php

namespace App\Actions\Admin\Administrators;

use App\Models\Administrator;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateAdministratorAction
{
    public function __invoke(
        string $name,
        string $paternal_lastname,
        string $maternal_lastname,
        string $email,
        array $roles = [],
        bool $has_laboratory_concierge_account = false,
    ): Administrator {
        try {
            DB::beginTransaction();

            $user =  User::firstOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'paternal_lastname' => $paternal_lastname,
                    'maternal_lastname' => $maternal_lastname,
                ],
            );

            $administrator = Administrator::create([
                'user_id' => $user->id,
            ]);

            $administrator->syncRoles($roles);

            if ($has_laboratory_concierge_account) {
                $administrator->laboratoryConcierge()->create();
            }

            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            throw $th;
        }

        return $administrator;
    }
}
