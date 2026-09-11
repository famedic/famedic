<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Permission::query()->firstOrCreate([
            'name' => 'laboratory-purchases.manage.billing-reports',
            'guard_name' => 'web',
        ]);
    }

    public function down(): void
    {
        Permission::query()
            ->where('name', 'laboratory-purchases.manage.billing-reports')
            ->where('guard_name', 'web')
            ->delete();
    }
};
