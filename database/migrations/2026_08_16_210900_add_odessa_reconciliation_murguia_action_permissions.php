<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = collect([
            'odessa-reconciliation.actions.murguia.activate',
            'odessa-reconciliation.actions.murguia.deactivate',
        ])->map(fn (string $name) => Permission::firstOrCreate([
            'name' => $name,
            'guard_name' => 'web',
        ]));

        $adminRole = Role::where('name', 'Administrador')->where('guard_name', 'web')->first();
        if ($adminRole) {
            $adminRole->givePermissionTo($permissions);
        }
    }

    public function down(): void
    {
        Permission::whereIn('name', [
            'odessa-reconciliation.actions.murguia.activate',
            'odessa-reconciliation.actions.murguia.deactivate',
        ])->where('guard_name', 'web')->delete();
    }
};
