<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Administrators\CreateAdministratorAction;
use App\Actions\Admin\Administrators\DestroyAdministratorAction;
use App\Actions\Admin\Administrators\UpdateAdministratorAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Administrators\CreateAdministratorRequest;
use App\Http\Requests\Admin\Administrators\DestroyAdministratorRequest;
use App\Http\Requests\Admin\Administrators\EditAdministratorRequest;
use App\Http\Requests\Admin\Administrators\IndexAdministratorRequest;
use App\Http\Requests\Admin\Administrators\StoreAdministratorRequest;
use App\Http\Requests\Admin\Administrators\UpdateAdministratorRequest;
use App\Models\Administrator;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;

class AdministratorController extends Controller
{
    public function index(IndexAdministratorRequest $request)
    {
        $filters = collect($request->only('search', 'laboratory_concierge', 'role'))->filter()->all();

        return Inertia::render('Admin/Administrators', [
            'administrators' => Administrator::filter($filters)
                ->with(['user', 'roles', 'laboratoryConcierge'])
                ->orderByUserName()
                ->paginate()
                ->withQueryString(),
            'filters' => $filters,
            'roles' => Role::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(CreateAdministratorRequest $request)
    {
        return Inertia::render('Admin/AdministratorCreation', [
            'roles' => Role::all(),
        ]);
    }

    public function store(StoreAdministratorRequest $request, CreateAdministratorAction $action)
    {
        $administrator = $action(
            name: $request->name,
            paternal_lastname: $request->paternal_lastname,
            maternal_lastname: $request->maternal_lastname,
            email: $request->email,
            roles: $request->roles,
            has_laboratory_concierge_account: $request->has_laboratory_concierge_account
        );

        return redirect()->route('admin.administrators.edit', ['administrator' => $administrator])
            ->flashMessage('Administrador creado exitosamente');
    }

    public function edit(EditAdministratorRequest $request, Administrator $administrator)
    {
        $showDeleteButton = ! $administrator->is_only_administrator_with_user_and_role_permission &&
            $administrator->user_id != auth()->user()->id;

        return Inertia::render('Admin/Administrator', [
            'administrator' => $administrator->load(['roles', 'user', 'laboratoryConcierge']),
            'roles' => Role::all(),
            'showDeleteButton' => $showDeleteButton,
        ]);
    }

    public function update(UpdateAdministratorRequest $request, Administrator $administrator, UpdateAdministratorAction $action)
    {
        $action(
            administrator: $administrator,
            name: $request->name,
            paternal_lastname: $request->paternal_lastname,
            maternal_lastname: $request->maternal_lastname,
            email: $request->email,
            roles: $request->roles,
            has_laboratory_concierge_account: $request->has_laboratory_concierge_account
        );

        return redirect()->back()
            ->flashMessage('Administrador actualizado exitosamente');
    }

    public function destroy(DestroyAdministratorRequest $request, Administrator $administrator, DestroyAdministratorAction $action)
    {
        $action($administrator);

        return redirect()->route('admin.administrators.index')
            ->flashMessage('Administrador eliminado exitosamente');
    }
}
