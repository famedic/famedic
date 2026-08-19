<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasDescription = Schema::hasColumn((new Permission)->getTable(), 'description');
        $parent = Permission::firstOrCreate(
            ['name' => 'odessa-pre-enrollments.actions', 'guard_name' => 'web'],
            array_filter([
                'description' => $hasDescription ? 'Ejecutar acciones de preafiliaciones ODESSA' : null,
            ])
        );

        $permission = Permission::firstOrCreate([
            'name' => 'odessa-pre-enrollments.actions.import',
            'guard_name' => 'web',
        ], array_filter([
            'description' => $hasDescription ? 'Importar preafiliaciones ODESSA analizadas' : null,
            'permission_id' => $parent->id,
        ]));

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
