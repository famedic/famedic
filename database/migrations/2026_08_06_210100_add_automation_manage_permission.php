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
            'description' => 'Monitorear Automation Operations Center (solo lectura / diagnóstico)',
        ] : [];

        Permission::firstOrCreate(
            ['name' => 'automation.manage', 'guard_name' => 'web'],
            array_merge(['permission_id' => null], $extra)
        );

        $adminRole = Role::where('name', 'Administrador')->first();
        if ($adminRole && ! $adminRole->hasPermissionTo('automation.manage')) {
            $adminRole->givePermissionTo('automation.manage');
        }

        // Roles que ya gestionan ActiveCampaign también ven la consola
        $acPermission = Permission::where('name', 'activecampaign.manage')->where('guard_name', 'web')->first();
        if ($acPermission) {
            foreach ($acPermission->roles as $role) {
                if (! $role->hasPermissionTo('automation.manage')) {
                    $role->givePermissionTo('automation.manage');
                }
            }
        }

        app('cache')
            ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }

    public function down(): void
    {
        $permission = Permission::where('name', 'automation.manage')->where('guard_name', 'web')->first();
        if ($permission) {
            $permission->delete();
        }

        app('cache')
            ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }
};
