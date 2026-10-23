<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateRolePermissionsRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class RoleController extends Controller
{
    public function index(): View
    {
        Gate::authorize('roles.manage');

        return view('roles.index', ['roles' => Role::withCount(['permissions', 'users'])->orderBy('name')->get()]);
    }

    public function edit(Role $role): View
    {
        Gate::authorize('roles.manage');

        return view('roles.edit', [
            'role' => $role,
            'groups' => Permission::orderBy('group')->orderBy('name')->get()->groupBy('group'),
            'granted' => $role->permissions()->pluck('permissions.id')->all(),
        ]);
    }

    public function update(UpdateRolePermissionsRequest $request, Role $role): RedirectResponse
    {
        // The admin role is a super-role (the Gate passes it everywhere); its permission list is not editable.
        abort_if($role->name === 'admin', 403, 'The administrator role cannot be changed.');

        $ids = $request->validated()['permissions'] ?? [];

        DB::transaction(function () use ($role, $ids) {
            $old = $role->permissions()->pluck('name')->sort()->values()->all();
            $role->permissions()->sync($ids);
            $new = $role->permissions()->pluck('name')->sort()->values()->all();

            AuditLogger::log('permissions_changed', $role,
                ['removed' => array_values(array_diff($old, $new))],
                ['added' => array_values(array_diff($new, $old))]);
        });

        return redirect()->route('roles.index')->with('success', "Permissions for “{$role->label}” saved.");
    }
}
