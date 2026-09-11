<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = collect([
            'odessa-pre-enrollments.view',
            'odessa-pre-enrollments.manage',
            'odessa-pre-enrollments.actions.generate-credit',
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
            'odessa-pre-enrollments.view',
            'odessa-pre-enrollments.manage',
            'odessa-pre-enrollments.actions.generate-credit',
        ])->where('guard_name', 'web')->delete();
    }
};
