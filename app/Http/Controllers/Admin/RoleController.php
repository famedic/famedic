<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Roles\CreateRoleAction;
use App\Actions\Admin\Roles\DestroyRoleAction;
use App\Actions\Admin\Roles\UpdateRoleAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Roles\RoleCreateRequest;
use App\Http\Requests\Admin\Roles\RoleDestroyRequest;
use App\Http\Requests\Admin\Roles\RoleEditRequest;
use App\Http\Requests\Admin\Roles\RoleIndexRequest;
use App\Http\Requests\Admin\Roles\RoleStoreRequest;
use App\Http\Requests\Admin\Roles\RoleUpdateRequest;
use App\Models\Permission;
use App\Models\Role;
use Inertia\Inertia;

class RoleController extends Controller
{
    public function getPermissionsKeyValue()
    {
        $permissionsConfig = config('famedic.permissions');
        $permissionsKeyValue = [];

        foreach ($permissionsConfig as $category => $permissions) {
            foreach ($permissions as $permission) {
                foreach ($permission as $name => $description) {
                    $fullPermissionName = $category.'.'.$name;
                    $permissionsKeyValue[$fullPermissionName] = $description;
                }
            }
        }

        return $permissionsKeyValue;
    }

    public function index(RoleIndexRequest $request)
    {
        return Inertia::render('Admin/Roles', [
            'roles' => Role::with('permissions')->orderBy('name')->paginate(),
            'permissionsNames' => $this->getPermissionsKeyValue(),
        ]);
    }

    public function create(RoleCreateRequest $request)
    {
        return Inertia::render('Admin/RoleCreation', [
            'permissions' => Permission::rootOnly()->with(['allPermissions'])->get(),
            'permissionsNames' => $this->getPermissionsKeyValue(),
        ]);
    }

    public function store(
        RoleStoreRequest $request,
        CreateRoleAction $action
    ) {
        $role = $action(
            name: $request->name,
            permissions: $request->permissions,
        );

        return redirect()->route('admin.roles.edit', ['role' => $role])
            ->flashMessage('Rol creado exitosamente.');
    }

    public function edit(
        RoleEditRequest $request,
        Role $role
    ) {
        return Inertia::render('Admin/Role', [
            'role' => $role->load('permissions'),
            'showDeleteButton' => ! $role->is_only_role_with_user_and_role_permission,
            'permissions' => Permission::rootOnly()->with(['allPermissions'])->get(),
            'permissionsNames' => $this->getPermissionsKeyValue(),
        ]);
    }

    public function update(
        RoleUpdateRequest $request,
        Role $role,
        UpdateRoleAction $action
    ) {
        $action(
            name: $request->name,
            permissions: $request->permissions,
            role: $role
        );

        return back()->flashMessage('Rol actualizado exitosamente.');
    }

    public function destroy(
        RoleDestroyRequest $request,
        Role $role,
        DestroyRoleAction $action
    ) {
        $action($role);

        return redirect()->route('admin.roles.index')
            ->flashMessage('Rol eliminado exitosamente.');
    }
}
