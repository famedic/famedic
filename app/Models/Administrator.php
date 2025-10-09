<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Permission\Traits\HasRoles;

class Administrator extends Model
{
    use HasFactory, HasRoles, SoftDeletes;

    protected $guarded = [];

    protected $guard_name = 'web';

    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->whereHas('user', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', '%'.$search.'%')
                        ->orWhere('paternal_lastname', 'like', '%'.$search.'%')
                        ->orWhere('maternal_lastname', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%');
                });
            });
        })->when($filters['laboratory_concierge'] ?? null, function ($query, $laboratoryConcierge) {
            if ($laboratoryConcierge === 'active') {
                $query->whereHas('laboratoryConcierge');
            } elseif ($laboratoryConcierge === 'inactive') {
                $query->whereDoesntHave('laboratoryConcierge');
            }
        })->when($filters['role'] ?? null, function ($query, $roleId) {
            if ($roleId === 'no_roles') {
                $query->whereDoesntHave('roles');
            } else {
                $query->whereHas('roles', function ($query) use ($roleId) {
                    $query->where('roles.id', $roleId);
                });
            }
        });
    }

    public function scopeOrderByUserName(Builder $query, $direction = 'asc')
    {
        return $query->join('users', 'users.id', '=', 'administrators.user_id')
            ->orderBy('users.name', $direction)
            ->select('administrators.*');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function laboratoryConcierge(): HasOne
    {
        return $this->hasOne(LaboratoryConcierge::class);
    }

    protected function isOnlyAdministratorWithAdministratorsAndRolesPermission(): Attribute
    {
        $usersAndRolesPermission = 'administrators.manage';
        $administratorPermissions = $this->getAllPermissions()->pluck('name')->toArray();

        return Attribute::make(
            get: function () use ($usersAndRolesPermission, $administratorPermissions) {
                if (! in_array($usersAndRolesPermission, $administratorPermissions)) {
                    return false;
                }

                return self::permission($usersAndRolesPermission)->count() === 1;
            },
        );
    }
}
