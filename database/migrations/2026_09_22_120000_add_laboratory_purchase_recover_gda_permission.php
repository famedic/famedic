<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $extra = Schema::hasColumn((new Permission)->getTable(), 'description') ? [
            'description' => 'Recuperar pedidos de laboratorio con GDA incierto usando saldo a favor',
        ] : [];

        Permission::firstOrCreate(
            ['name' => 'laboratory-purchases.manage.recover-gda', 'guard_name' => 'web'],
            array_merge(['permission_id' => null], $extra)
        );

        foreach (['superadmin', 'Administrador'] as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
            if ($role && ! $role->hasPermissionTo('laboratory-purchases.manage.recover-gda')) {
                $role->givePermissionTo('laboratory-purchases.manage.recover-gda');
            }
        }

        app('cache')
            ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }

    public function down(): void
    {
        $permission = Permission::where('name', 'laboratory-purchases.manage.recover-gda')
            ->where('guard_name', 'web')
            ->first();

        if ($permission) {
            $permission->delete();
        }

        app('cache')
            ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }
};
