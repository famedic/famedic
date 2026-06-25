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
            Permission::firstOrCreate(
                ['name' => 'laboratory-purchases.manage.export', 'guard_name' => 'web'],
                [
                    'description' => 'Descargar compras de laboratorio',
                    'permission_id' => $laboratoryManagePermission->id,
                ]
            );
        }

        // Add export permissions for online pharmacy purchases
        $pharmacyManagePermission = Permission::where('name', 'online-pharmacy-purchases.manage')->first();
        if ($pharmacyManagePermission) {
            Permission::firstOrCreate(
                ['name' => 'online-pharmacy-purchases.manage.export', 'guard_name' => 'web'],
                [
                    'description' => 'Descargar compras de farmacia en línea',
                    'permission_id' => $pharmacyManagePermission->id,
                ]
            );
        }

        // Add export permissions for medical attention subscriptions
        $medicalManagePermission = Permission::where('name', 'medical-attention-subscriptions.manage')->first();
        if ($medicalManagePermission) {
            Permission::firstOrCreate(
                ['name' => 'medical-attention-subscriptions.manage.export', 'guard_name' => 'web'],
                [
                    'description' => 'Descargar membresías médicas',
                    'permission_id' => $medicalManagePermission->id,
                ]
            );
        }

        // Add export permissions for customers
        $customersManagePermission = Permission::where('name', 'customers.manage')->first();
        if ($customersManagePermission) {
            Permission::firstOrCreate(
                ['name' => 'customers.manage.export', 'guard_name' => 'web'],
                [
                    'description' => 'Descargar clientes',
                    'permission_id' => $customersManagePermission->id,
                ]
            );
        }

        // Clear permission cache
        app('cache')
            ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }
};
