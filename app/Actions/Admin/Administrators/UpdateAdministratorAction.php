<?php

namespace App\Actions\Admin\Administrators;

use App\Models\Administrator;
use Illuminate\Support\Facades\DB;

class UpdateAdministratorAction
{
    public function __invoke(
        string $name,
        string $paternal_lastname,
        string $maternal_lastname,
        string $email,
        array $roles = [],
        bool $has_laboratory_concierge_account = false,
        Administrator $administrator
    ): Administrator {
        try {
            DB::beginTransaction();

            $administrator->user->update([
                'name' => $name,
                'paternal_lastname' => $paternal_lastname,
                'maternal_lastname' => $maternal_lastname,
                'email' => $email,
            ]);

            $administrator->syncRoles($roles);

            $laboratoryConcierge = $administrator->laboratoryConcierge()->withTrashed()->first();

            if ($has_laboratory_concierge_account) {
                if ($laboratoryConcierge) {
                    if ($laboratoryConcierge->trashed()) {
                        $laboratoryConcierge->restore();
                    }
                } else {
                    $administrator->laboratoryConcierge()->create();
                }
            } else {
                if ($laboratoryConcierge && !$laboratoryConcierge->trashed()) {
                    $laboratoryConcierge->delete();
                }
            }

            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            throw $th;
        }

        return $administrator->refresh();
    }
}
