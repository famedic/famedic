<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::firstOrCreate(
            ['name' => 'monitoring-ai.manage', 'guard_name' => 'web'],
            ['description' => 'Usar el asistente IA de monitoreo']
        );

        $adminRole = Role::where('name', 'Administrador')->first();
        if ($adminRole && ! $adminRole->hasPermissionTo($permission->name)) {
            $adminRole->givePermissionTo($permission->name);
        }

        app('cache')
            ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }

    public function down(): void
    {
        $permission = Permission::where('name', 'monitoring-ai.manage')->first();
        if ($permission) {
            $permission->delete();
        }

        app('cache')
            ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }
};
