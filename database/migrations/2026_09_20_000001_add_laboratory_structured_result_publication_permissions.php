<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            [
                'name' => 'laboratory-results.approve-publication',
                'description' => 'Aprobar resultados estructurados Shadow QA para futura publicación',
            ],
            [
                'name' => 'laboratory-results.publish',
                'description' => 'Publicar un reporte estructurado aprobado (piloto controlado)',
            ],
        ];

        foreach ($permissions as $definition) {
            Permission::query()->firstOrCreate(
                ['name' => $definition['name'], 'guard_name' => 'web'],
                ['description' => $definition['description']],
            );
        }

        $adminRole = Role::query()->where('name', 'Administrador')->first();

        if ($adminRole !== null) {
            foreach ($permissions as $definition) {
                if (! $adminRole->hasPermissionTo($definition['name'])) {
                    $adminRole->givePermissionTo($definition['name']);
                }
            }
        }

        app('cache')
            ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }
};
