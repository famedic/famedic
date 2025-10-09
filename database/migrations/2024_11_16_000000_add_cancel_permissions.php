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
        // Laboratory purchases cancel permission
        $labPermissionName = 'laboratory-purchases.manage.cancel';
        if (! Permission::where('name', $labPermissionName)->exists()) {
            $parentLab = Permission::where('name', 'laboratory-purchases.manage')->first();
            Permission::create([
                'name' => $labPermissionName,
                'description' => 'Cancelar compras',
                'permission_id' => $parentLab ? $parentLab->id : null,
            ]);
        }

        // Online pharmacy purchases cancel permission
        $onlinePermissionName = 'online-pharmacy-purchases.manage.cancel';
        if (! Permission::where('name', $onlinePermissionName)->exists()) {
            $parentOnline = Permission::where('name', 'online-pharmacy-purchases.manage')->first();
            Permission::create([
                'name' => $onlinePermissionName,
                'description' => 'Cancelar compras',
                'permission_id' => $parentOnline ? $parentOnline->id : null,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Permission::where('name', 'laboratory-purchases.manage.cancel')->delete();
        Permission::where('name', 'online-pharmacy-purchases.manage.cancel')->delete();
    }
};
