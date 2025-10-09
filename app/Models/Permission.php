<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Spatie\Permission\Models\Permission as SpatiePermission;

class Permission extends SpatiePermission
{
    protected static $unguarded = true;

    protected $appends = [
        'formatted_name'
    ];

    public function permission()
    {
        return $this->belongsTo(Permission::class);
    }

    public function childPermissions()
    {
        return $this->hasMany(Permission::class);
    }

    public function scopeRootOnly($query)
    {
        return $query->whereNull('permission_id');
    }

    public function allPermissions()
    {
        return $this->childPermissions()->with('allPermissions');
    }

    protected function formattedName(): Attribute
    {
        return Attribute::make(
            get: function () {
                $permissionsConfig = config('famedic.permissions', []);
                $permissionParts = explode('.', $this->name);
                $description = null;

                foreach ($permissionsConfig as $category => $permissions) {
                    if ($category === $permissionParts[0]) {
                        foreach ($permissions as $permission) {
                            foreach ($permission as $name => $desc) {
                                if ($category . '.' . $name === $this->name) {
                                    $description = $desc;
                                    break 3; // Exit all loops
                                }
                            }
                        }
                    }
                }

                return $description ?? $this->name;
            },
        );
    }
}
