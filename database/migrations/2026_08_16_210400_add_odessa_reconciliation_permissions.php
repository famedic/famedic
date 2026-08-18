<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = collect([
            'odessa-reconciliation.view',
            'odessa-reconciliation.manage',
            'odessa-reconciliation.review',
            'odessa-reconciliation.actions.email',
            'odessa-reconciliation.actions.odessa',
            'odessa-reconciliation.actions.membership',
            'odessa-reconciliation.actions.murguia',
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
            'odessa-reconciliation.view',
            'odessa-reconciliation.manage',
            'odessa-reconciliation.review',
            'odessa-reconciliation.actions.email',
            'odessa-reconciliation.actions.odessa',
            'odessa-reconciliation.actions.membership',
            'odessa-reconciliation.actions.murguia',
        ])->where('guard_name', 'web')->delete();
    }
};
