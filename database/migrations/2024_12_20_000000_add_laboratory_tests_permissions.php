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
        // Laboratory tests manage permission
        $managePermissionName = 'laboratory-tests.manage';
        $managePermission = null;
        if (! Permission::where('name', $managePermissionName)->exists()) {
            $managePermission = Permission::create([
                'name' => $managePermissionName,
                'description' => 'Administrar catálogo de laboratorio',
                'permission_id' => null,
            ]);
        } else {
            $managePermission = Permission::where('name', $managePermissionName)->first();
        }

        // Laboratory tests manage.edit permission (child of manage)
        $manageEditPermissionName = 'laboratory-tests.manage.edit';
        if (! Permission::where('name', $manageEditPermissionName)->exists()) {
            Permission::create([
                'name' => $manageEditPermissionName,
                'description' => 'Editar catálogo de laboratorio',
                'permission_id' => $managePermission->id,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Permission::where('name', 'laboratory-tests.manage')->delete();
        Permission::where('name', 'laboratory-tests.manage.edit')->delete();
    }
};
