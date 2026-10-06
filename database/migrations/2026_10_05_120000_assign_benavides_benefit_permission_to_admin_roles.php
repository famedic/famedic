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

        $permission = Permission::firstOrCreate(
            ['name' => 'benavides-benefit.manage', 'guard_name' => 'web'],
            array_filter([
                'permission_id' => null,
                'description' => $descriptionColumnExists ? 'Administrar beneficio Farmacias Benavides' : null,
            ], fn ($value) => $value !== null)
        );

        Role::query()
            ->whereIn('name', ['Administrador', 'superadmin'])
            ->where('guard_name', 'web')
            ->get()
            ->each(function (Role $role) use ($permission) {
                if (! $role->hasPermissionTo($permission->name)) {
                    $role->givePermissionTo($permission->name);
                }
            });

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permission = Permission::query()
            ->where('name', 'benavides-benefit.manage')
            ->where('guard_name', 'web')
            ->first();

        if ($permission) {
            Role::query()
                ->whereIn('name', ['Administrador', 'superadmin'])
                ->where('guard_name', 'web')
                ->get()
                ->each(fn (Role $role) => $role->revokePermissionTo($permission->name));
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
