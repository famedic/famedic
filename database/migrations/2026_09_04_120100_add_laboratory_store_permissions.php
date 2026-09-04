<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $descriptionColumnExists = Schema::hasColumn((new Permission)->getTable(), 'description');

        $manage = Permission::firstOrCreate(
            ['name' => 'laboratory-stores.manage', 'guard_name' => 'web'],
            array_filter([
                'description' => $descriptionColumnExists ? 'Administrar sucursales de laboratorio' : null,
                'permission_id' => null,
            ], fn ($value) => $value !== null)
        );

        $edit = Permission::firstOrCreate(
            ['name' => 'laboratory-stores.manage.edit', 'guard_name' => 'web'],
            array_filter([
                'description' => $descriptionColumnExists ? 'Editar sucursales de laboratorio' : null,
                'permission_id' => $manage->id,
            ], fn ($value) => $value !== null)
        );

        $adminRole = Role::where('name', 'Administrador')->first();
        if ($adminRole) {
            foreach ([$manage, $edit] as $permission) {
                if (! $adminRole->hasPermissionTo($permission->name)) {
                    $adminRole->givePermissionTo($permission->name);
                }
            }
        }

        app('cache')
            ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }

    public function down(): void
    {
        Permission::whereIn('name', [
            'laboratory-stores.manage.edit',
            'laboratory-stores.manage',
        ])->delete();

        app('cache')
            ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }
};
