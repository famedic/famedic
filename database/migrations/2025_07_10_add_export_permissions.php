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
        // Add export permissions for laboratory purchases
        $laboratoryManagePermission = Permission::where('name', 'laboratory-purchases.manage')->first();
        if ($laboratoryManagePermission) {
            Permission::create([
                'name' => 'laboratory-purchases.manage.export',
                'description' => 'Descargar compras de laboratorio',
                'permission_id' => $laboratoryManagePermission->id,
                'guard_name' => 'web',
            ]);
        }

        // Add export permissions for online pharmacy purchases
        $pharmacyManagePermission = Permission::where('name', 'online-pharmacy-purchases.manage')->first();
        if ($pharmacyManagePermission) {
            Permission::create([
                'name' => 'online-pharmacy-purchases.manage.export',
                'description' => 'Descargar compras de farmacia en línea',
                'permission_id' => $pharmacyManagePermission->id,
                'guard_name' => 'web',
            ]);
        }

        // Add export permissions for medical attention subscriptions
        $medicalManagePermission = Permission::where('name', 'medical-attention-subscriptions.manage')->first();
        if ($medicalManagePermission) {
            Permission::create([
                'name' => 'medical-attention-subscriptions.manage.export',
                'description' => 'Descargar membresías médicas',
                'permission_id' => $medicalManagePermission->id,
                'guard_name' => 'web',
            ]);
        }

        // Add export permissions for customers
        $customersManagePermission = Permission::where('name', 'customers.manage')->first();
        if ($customersManagePermission) {
            Permission::create([
                'name' => 'customers.manage.export',
                'description' => 'Descargar clientes',
                'permission_id' => $customersManagePermission->id,
                'guard_name' => 'web',
            ]);
        }

        // Clear permission cache
        app('cache')
            ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }
};
