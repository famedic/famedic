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
            'description' => 'Administrar módulo ActiveCampaign (solo lectura/scaffold)',
        ] : [];

        Permission::firstOrCreate(
            ['name' => 'activecampaign.manage', 'guard_name' => 'web'],
            array_merge(['permission_id' => null], $extra)
        );

        $adminRole = Role::where('name', 'Administrador')->first();
        if ($adminRole && ! $adminRole->hasPermissionTo('activecampaign.manage')) {
            $adminRole->givePermissionTo('activecampaign.manage');
        }

        app('cache')
            ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }

    public function down(): void
    {
        $permission = Permission::where('name', 'activecampaign.manage')->where('guard_name', 'web')->first();
        if ($permission) {
            $permission->delete();
        }

        app('cache')
            ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }
};
