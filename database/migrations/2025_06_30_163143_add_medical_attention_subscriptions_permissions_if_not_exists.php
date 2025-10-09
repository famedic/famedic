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
        // Only create if it doesn't exist
        if (! Permission::where('name', 'medical-attention-subscriptions.manage')->where('guard_name', 'web')->exists()) {
            Permission::create([
                'name' => 'medical-attention-subscriptions.manage',
                'guard_name' => 'web',
                'description' => 'Administrar membresías médicas',
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {}
};
