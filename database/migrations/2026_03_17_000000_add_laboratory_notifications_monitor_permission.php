<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

return new class extends Migration
{
    public function up(): void
    {
        $permissionName = 'laboratory-notifications.monitor';

        $permission = Permission::where('name', $permissionName)
            ->where('guard_name', 'web')
            ->first();

        if (! $permission) {
            $permission = Permission::create([
                'name' => $permissionName,
                'description' => 'Monitorear notificaciones de laboratorio',
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
};

