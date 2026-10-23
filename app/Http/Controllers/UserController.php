<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Role;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', User::class);

        $users = User::with('roles')
            ->when($request->q, fn ($q, $term) => $q->where(fn ($w) => $w->where('name', 'like', "%$term%")->orWhere('email', 'like', "%$term%")))
            ->when($request->role, fn ($q, $role) => $q->whereHas('roles', fn ($r) => $r->where('name', $role)))
            ->orderBy('name')->paginate(15)->withQueryString();

        return view('users.index', ['users' => $users, 'roles' => Role::orderBy('name')->get()]);
    }

    public function create(): View
    {
        Gate::authorize('create', User::class);

        return view('users.form', ['user' => new User(['is_active' => true]), 'roles' => Role::orderBy('name')->get()]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $request) {
            $user = User::create([
                'name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'],
                'is_active' => $request->boolean('is_active', true),
            ]);
            $user->roles()->sync($data['roles']);
            AuditLogger::log('roles_changed', $user, [], ['roles' => Role::whereIn('id', $data['roles'])->pluck('name')->all()]);
        });

        return redirect()->route('users.index')->with('success', 'User created.');
    }

    public function edit(User $user): View
    {
        Gate::authorize('update', $user);

        return view('users.form', ['user' => $user->load('roles'), 'roles' => Role::orderBy('name')->get()]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();
        $newRoleNames = Role::whereIn('id', $data['roles'])->pluck('name')->all();

        if ($this->isLastActiveAdmin($user) && ! in_array('admin', $newRoleNames, true)) {
            return back()->withInput()->withErrors(['roles' => 'This is the only active administrator; they must keep the admin role.']);
        }

        DB::transaction(function () use ($data, $user, $newRoleNames) {
            $oldRoleNames = $user->roles()->pluck('name')->all();

            $user->fill(['name' => $data['name'], 'email' => $data['email']]);
            if (! empty($data['password'])) {
                $user->password = $data['password'];
            }
            $user->save();

            $user->roles()->sync($data['roles']);
            if (array_diff($oldRoleNames, $newRoleNames) || array_diff($newRoleNames, $oldRoleNames)) {
                AuditLogger::log('roles_changed', $user, ['roles' => $oldRoleNames], ['roles' => $newRoleNames]);
            }
            if (! empty($data['password'])) {
                AuditLogger::log('password_changed', $user);
            }
        });

        return redirect()->route('users.index')->with('success', 'User updated.');
    }

    /** Users are deactivated, never deleted (their name stays on the audit trail and on documents). */
    public function toggle(User $user): RedirectResponse
    {
        Gate::authorize('toggle', $user);

        if ($user->is_active && $this->isLastActiveAdmin($user)) {
            return back()->with('error', 'You cannot deactivate the only active administrator.');
        }

        $user->update(['is_active' => ! $user->is_active]);

        return back()->with('success', $user->is_active ? 'User reactivated.' : 'User deactivated.');
    }

    private function isLastActiveAdmin(User $user): bool
    {
        if (! $user->is_active || ! $user->isAdmin()) {
            return false;
        }

        return User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('name', 'admin'))->count() <= 1;
    }
}
