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
            'description' => 'Ver monitor de configuración (solo lectura)',
        ] : [];

        $view = Permission::firstOrCreate(
            ['name' => 'view_config_monitor', 'guard_name' => 'web'],
            array_merge(['permission_id' => null], $extra)
        );

        $extraChild = Schema::hasColumn((new Permission)->getTable(), 'description') ? [
            'description' => 'Administrar grupos y claves monitoreadas (metadatos)',
        ] : [];

        Permission::firstOrCreate(
            ['name' => 'config_monitor.manage_metadata', 'guard_name' => 'web'],
            array_merge(['permission_id' => $view->id], $extraChild)
        );

        $adminRole = Role::where('name', 'Administrador')->first();
        if ($adminRole) {
            foreach (['view_config_monitor', 'config_monitor.manage_metadata'] as $name) {
                if (! $adminRole->hasPermissionTo($name)) {
                    $adminRole->givePermissionTo($name);
                }
            }
        }

        app('cache')
            ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }
};
