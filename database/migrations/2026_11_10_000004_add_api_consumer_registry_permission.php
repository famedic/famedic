<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Permission::firstOrCreate(
            ['name' => 'api-consumers.manage', 'guard_name' => 'web'],
            ['permission_id' => null]
        );

        app('cache')
            ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }

    public function down(): void
    {
        $permission = Permission::where('name', 'api-consumers.manage')
            ->where('guard_name', 'web')
            ->first();

        if (! $permission) {
            return;
        }

        $tables = config('permission.table_names');
        $assigned = DB::table($tables['role_has_permissions'])
            ->where('permission_id', $permission->id)
            ->exists()
            || DB::table($tables['model_has_permissions'])
                ->where('permission_id', $permission->id)
                ->exists();

        if ($assigned) {
            throw new \DomainException('Cannot remove an assigned API consumer registry permission');
        }

        $permission->delete();
        app('cache')
            ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }
};
