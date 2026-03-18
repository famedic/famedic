<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            ['name' => 'logs-general.manage', 'description' => 'Ver y descargar logs'],
            ['name' => 'users.manage', 'description' => 'Ver listado y detalle de usuarios'],
            ['name' => 'efevoo-tokens.manage', 'description' => 'Ver tokens de Efevoo'],
            ['name' => 'tax-profiles.manage', 'description' => 'Monitorear perfiles fiscales'],
            ['name' => 'payment-attempts.manage', 'description' => 'Monitorear intentos de pago'],
        ];

        foreach ($permissions as $p) {
            Permission::firstOrCreate(
                ['name' => $p['name'], 'guard_name' => 'web'],
                ['description' => $p['description']]
            );
        }

        $adminRole = Role::where('name', 'Administrador')->first();
        if ($adminRole) {
            foreach ($permissions as $p) {
                if (! $adminRole->hasPermissionTo($p['name'])) {
                    $adminRole->givePermissionTo($p['name']);
                }
            }
        }

        app('cache')
            ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }
};

