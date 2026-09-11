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

        $permissions = collect([
            'odessa-pre-enrollments.actions.murguia-register' => 'Alta individual Murguía desde preafiliación ODESSA',
            'odessa-pre-enrollments.actions.murguia-verify' => 'Verificar estado Murguía de preafiliación ODESSA',
            'odessa-pre-enrollments.actions.murguia-retry' => 'Reintentar alta Murguía de preafiliación ODESSA',
        ])->map(function (string $description, string $name) use ($hasDescription, $parent) {
            return Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ], array_filter([
                'description' => $hasDescription ? $description : null,
                'permission_id' => $parent->id,
            ]));
        });

        Role::where('name', 'Administrador')
            ->where('guard_name', 'web')
            ->first()
            ?->givePermissionTo($permissions->push($parent));
    }

    public function down(): void
    {
        Permission::whereIn('name', [
            'odessa-pre-enrollments.actions.murguia-register',
            'odessa-pre-enrollments.actions.murguia-verify',
            'odessa-pre-enrollments.actions.murguia-retry',
        ])->where('guard_name', 'web')->delete();
    }
};
