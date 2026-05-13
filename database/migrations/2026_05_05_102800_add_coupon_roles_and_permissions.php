<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $guardName = 'web';

        $autorizadorRole = Role::firstOrCreate([
            'name' => 'autorizador',
            'guard_name' => $guardName,
        ]);

        $superadminRole = Role::firstOrCreate([
            'name' => 'superadmin',
            'guard_name' => $guardName,
        ]);

        $couponPermissionNames = [
            'cupones.view',
            'cupones.create',
            'cupones.edit',
            'cupones.delete',
            'cupones.config',
        ];

        $couponPermissions = collect($couponPermissionNames)->map(function (string $permissionName) use ($guardName) {
            return Permission::firstOrCreate([
                'name' => $permissionName,
                'guard_name' => $guardName,
            ]);
        });

        $autorizadorRole->syncPermissions($couponPermissions);
        $superadminRole->syncPermissions(Permission::query()->where('guard_name', $guardName)->get());

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $guardName = 'web';
        $couponPermissionNames = [
            'cupones.view',
            'cupones.create',
            'cupones.edit',
            'cupones.delete',
            'cupones.config',
        ];

        $autorizadorRole = Role::where('name', 'autorizador')->where('guard_name', $guardName)->first();
        if ($autorizadorRole) {
            $autorizadorRole->syncPermissions([]);
            $autorizadorRole->delete();
        }

        $superadminRole = Role::where('name', 'superadmin')->where('guard_name', $guardName)->first();
        if ($superadminRole) {
            foreach ($couponPermissionNames as $permissionName) {
                if ($superadminRole->hasPermissionTo($permissionName)) {
                    $superadminRole->revokePermissionTo($permissionName);
                }
            }
        }

        Permission::whereIn('name', $couponPermissionNames)
            ->where('guard_name', $guardName)
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
