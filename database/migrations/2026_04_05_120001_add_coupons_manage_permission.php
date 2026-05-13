<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::firstOrCreate(
            ['name' => 'coupons.manage', 'guard_name' => 'web'],
        );

        $adminRole = Role::where('name', 'Administrador')->first();
        if ($adminRole && ! $adminRole->hasPermissionTo('coupons.manage')) {
            $adminRole->givePermissionTo($permission);
        }

        app('cache')
            ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }

    public function down(): void
    {
        Permission::where('name', 'coupons.manage')->where('guard_name', 'web')->delete();

        app('cache')
            ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }
};
