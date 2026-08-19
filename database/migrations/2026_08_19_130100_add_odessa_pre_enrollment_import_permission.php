<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $parent = Permission::firstOrCreate(
            ['name' => 'odessa-pre-enrollments.actions', 'guard_name' => 'web'],
            ['description' => 'Ejecutar acciones de preafiliaciones ODESSA'],
        );

        $permission = Permission::firstOrCreate([
            'name' => 'odessa-pre-enrollments.actions.import',
            'guard_name' => 'web',
        ], [
            'description' => 'Importar preafiliaciones ODESSA analizadas',
            'permission_id' => $parent->id,
        ]);

        Role::where('name', 'Administrador')
            ->where('guard_name', 'web')
            ->first()
            ?->givePermissionTo([$parent, $permission]);
    }

    public function down(): void
    {
        Permission::where('name', 'odessa-pre-enrollments.actions.import')
            ->where('guard_name', 'web')
            ->delete();

        $parent = Permission::where('name', 'odessa-pre-enrollments.actions')
            ->where('guard_name', 'web')
            ->first();

        if ($parent && ! Permission::where('permission_id', $parent->id)->exists()) {
            $parent->delete();
        }
    }
};
