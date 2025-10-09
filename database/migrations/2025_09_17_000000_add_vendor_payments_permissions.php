<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Laboratory purchases vendor payments permission
        $labPermissionName = 'laboratory-purchases.manage.vendor-payments';
        if (! Permission::where('name', $labPermissionName)->exists()) {
            $parentLab = Permission::where('name', 'laboratory-purchases.manage')->first();
            Permission::create([
                'name' => $labPermissionName,
                'description' => 'Gestionar pagos a proveedor',
                'permission_id' => $parentLab ? $parentLab->id : null,
            ]);
        }

        // Online pharmacy purchases vendor payments permission
        $onlinePermissionName = 'online-pharmacy-purchases.manage.vendor-payments';
        if (! Permission::where('name', $onlinePermissionName)->exists()) {
            $parentOnline = Permission::where('name', 'online-pharmacy-purchases.manage')->first();
            Permission::create([
                'name' => $onlinePermissionName,
                'description' => 'Gestionar pagos a proveedor',
                'permission_id' => $parentOnline ? $parentOnline->id : null,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Permission::where('name', 'laboratory-purchases.manage.vendor-payments')->delete();
        Permission::where('name', 'online-pharmacy-purchases.manage.vendor-payments')->delete();
    }
};
