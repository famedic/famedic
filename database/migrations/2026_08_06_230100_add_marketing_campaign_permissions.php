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
            ['name' => 'marketing-campaigns.manage', 'guard_name' => 'web'],
            array_filter([
                'permission_id' => null,
                'description' => $descriptionColumnExists ? 'Administrar campañas y enlaces' : null,
            ], fn ($value) => $value !== null)
        );

        $edit = Permission::firstOrCreate(
            ['name' => 'marketing-campaigns.manage.edit', 'guard_name' => 'web'],
            array_filter([
                'permission_id' => $manage->id,
                'description' => $descriptionColumnExists ? 'Crear y editar campañas y enlaces' : null,
            ], fn ($value) => $value !== null)
        );

        // Administrador necesita manage (lectura) y manage.edit (CRUD futuro).
        // Sin edit, create/update/archive quedarían bloqueados por policy.
        $adminRole = Role::query()->where('name', 'Administrador')->first();
        if ($adminRole) {
            foreach ([$manage->name, $edit->name] as $permissionName) {
                if (! $adminRole->hasPermissionTo($permissionName)) {
                    $adminRole->givePermissionTo($permissionName);
                }
            }
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()
            ->whereIn('name', [
                'marketing-campaigns.manage.edit',
                'marketing-campaigns.manage',
            ])
            ->where('guard_name', 'web')
            ->delete();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
