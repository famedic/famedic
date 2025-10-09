<?php

namespace App\Actions\Admin\Administrators;

use App\Exceptions\OnlyAdministratorWithUserAndRolePermissionException;
use App\Models\Administrator;
use Illuminate\Support\Facades\DB;

class DestroyAdministratorAction
{
    public function __invoke(Administrator $administrator): void
    {
        if (
            $administrator->is_only_administrator_with_user_and_role_permission
        ) {
            throw new OnlyAdministratorWithUserAndRolePermissionException();
        }

        try {
            DB::beginTransaction();

            if ($administrator->laboratoryConcierge) {
                $administrator->laboratoryConcierge->delete();
            }

            $administrator->delete();

            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            throw $th;
        }
    }
}
