<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

return new class extends Migration
{
    public function up(): void
    {
        $permissionName = 'otp-movements.otp-reveal';

        $permission = Permission::where('name', $permissionName)
            ->where('guard_name', 'web')
            ->first();

        if (! $permission) {
            Permission::create([
                'name' => $permissionName,
                'description' => 'Revelar OTP login Akúbica en staging (diagnóstico RC1, un solo uso)',
                'guard_name' => 'web',
            ]);
        }

        $adminRole = Role::where('name', 'Administrador')->first();
        if ($adminRole && ! $adminRole->hasPermissionTo($permissionName)) {
            $adminRole->givePermissionTo($permissionName);
        }

        app('cache')
            ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }

    public function down(): void
    {
        Permission::where('name', 'otp-movements.otp-reveal')->where('guard_name', 'web')->delete();
    }
};
