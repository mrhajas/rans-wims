<?php

namespace App\Http\Controllers;

use App\Models\Role;
use Inertia\Inertia;
use Illuminate\Http\Request;
use App\Http\Requests\RoleRequest;
use App\Http\Resources\RoleResource;
use App\Http\Resources\RoleCollection;
use App\Support\PermissionRegistry;

class RoleController extends Controller
{
    public function create()
    {
        return Inertia::render('Role/Form');
    }

    /**
     * Not on the Authorizable ability map (only index/edit/store/update/
     * destroy are), so it needs its own explicit check — treated as a
     * "create-roles" action since the result is a brand new, independent
     * role, not a change to the one being copied from.
     */
    public function duplicate(Request $request, Role $role)
    {
        $this->authorize('create-roles');

        $data = $request->validate([
            'name' => 'required|max:50|unique:roles,name',
        ]);

        $copy = Role::create([
            'name' => $data['name'],
            'guard_name' => $role->guard_name,
            'account_id' => $role->account_id,
        ]);

        $copy->givePermissionTo($role->permissions);
        \App\Services\UserNotifier::fireRole('role_created', $copy, __('Role Created'), 'The role ' . $copy->name . ' was created as a copy of ' . $role->name . ' by ' . $request->user()->name . '.', $request->user());

        return redirect()->route('roles.edit', $copy->id)
            ->with('message', __('Role duplicated from :name with :count permission(s).', ['name' => $role->name, 'count' => $role->permissions()->count()]));
    }

    public function destroy(Role $role)
    {
        if ($role->del()) {
            \App\Services\UserNotifier::fireRole('role_deleted', $role, __('Role Deleted'), 'The role ' . $role->name . ' was deleted by ' . (auth()->user()?->name ?? 'an administrator') . '.');

            return redirect()->route('roles.index')->with('message', __choice('action_text', ['record' => 'Role', 'action' => 'deleted']));
        }

        return back()->with('error', __('The record can not be deleted.'));
    }

    public function destroyPermanently(Role $role)
    {
        if ($role->delP()) {
            \App\Services\UserNotifier::fireRole('role_deleted', $role, __('Role Permanently Deleted'), 'The role ' . $role->name . ' was permanently deleted by ' . (auth()->user()?->name ?? 'an administrator') . '.');

            return redirect()->route('roles.index')->with('message', __choice('action_text', ['record' => 'Role', 'action' => 'permanently deleted']));
        }

        return back()->with('error', __('The record can not be deleted.'));
    }

    public function edit(Role $role)
    {
        if ($role->name == 'Super Admin') {
            return redirect()->route('roles.index')->with('error', __('Super Admin role can not be modified.'));
        }

        return Inertia::render('Role/Form', [
            'edit' => new RoleResource($role),
            'permissionRegistry' => PermissionRegistry::modules(),
        ]);
    }

    public function index(Request $request)
    {
        return Inertia::render('Role/Index', [
            'filters' => $request->all('search', 'trashed'),
            'roles'   => new RoleCollection(
                Role::ofAccount()->orderBy('name')->filter($request->only('search', 'trashed'))->paginate()
            ),
        ]);
    }

    /**
     * Not on the Authorizable ability map (only index/edit/store/update/
     * destroy are — "permissions" isn't among them), so like duplicate()
     * above this needs its own explicit check. Without it, this endpoint had
     * no authorization at all: any authenticated user could POST any
     * permission name here and grant it to any role, including their own.
     */
    public function permissions(Request $request, Role $role)
    {
        $this->authorize('update-roles');

        if ($role->name === 'Super Admin') {
            return back()->with('error', __('Super Admin role can not be modified.'));
        }

        $posted = collect($request->except('_method'))->flatten();
        $valid = $posted->intersect(PermissionRegistry::allPermissionNames())->values()->all();

        $before = $role->permissions()->pluck('name')->sort()->values()->all();
        $role->syncPermissions($valid);
        $after = $role->permissions()->pluck('name')->sort()->values()->all();
        if ($before !== $after) {
            $added = count(array_diff($after, $before));
            $removed = count(array_diff($before, $after));
            \App\Support\UserAudit::record(null, 'role_permissions', ['role' => $role->name, 'added' => array_slice(array_values(array_diff($after, $before)), 0, 12), 'removed' => array_slice(array_values(array_diff($before, $after)), 0, 12), 'added_count' => $added, 'removed_count' => $removed], null, $role->name);
            \App\Services\UserNotifier::fireRole('role_permissions_changed', $role, __('Role Permissions Changed'), 'The permissions of the role ' . $role->name . ' were changed by ' . $request->user()->name . ': ' . $added . ' added, ' . $removed . ' removed.', $request->user());
        }

        return back()->with('message', __('Role permissions has successfully saved.'));
    }

    public function restore(Role $role)
    {
        $role->restore();
        \App\Services\UserNotifier::fireRole('role_restored', $role, __('Role Restored'), 'The role ' . $role->name . ' was restored by ' . (auth()->user()?->name ?? 'an administrator') . '.');

        return back()->with('message', __choice('action_text', ['record' => 'Role', 'action' => 'restored']));
    }

    public function store(RoleRequest $request)
    {
        $role = Role::create($request->validated());
        // every role starts with the Dashboard Cards ability (it only arranges cards of modules the role can already open); it can be switched off on the permissions screen
        if ($cards = \Spatie\Permission\Models\Permission::query()->where('name', 'customize-dashboard-cards')->where('guard_name', 'web')->first()) {
            $role->givePermissionTo($cards);
        }
        \App\Services\UserNotifier::fireRole('role_created', $role, __('Role Created'), 'The role ' . $role->name . ' was created by ' . $request->user()->name . '.', $request->user());

        return redirect()->route('roles.index')->with('message', __choice('action_text', ['record' => 'Role', 'action' => 'created']));
    }

    public function update(RoleRequest $request, Role $role)
    {
        if ($role->name == 'Super Admin') {
            return redirect()->route('roles.index')->with('error', __('Super Admin role can not be modified.'));
        }

        $oldName = $role->name;
        $role->update($request->validated());
        $role->refresh();
        // "All warehouses": the people who hold this role get every project / warehouse now
        \App\Support\AllWarehouseRoles::syncRoleUsers($role);
        if ($oldName !== $role->name) {
            \App\Services\UserNotifier::fireRole('role_updated', $role, __('Role Edited'), 'The role ' . $oldName . ' was renamed to ' . $role->name . ' by ' . $request->user()->name . '.', $request->user());
        }

        return back()->with('message', __choice('action_text', ['record' => 'Role', 'action' => 'updated']));
    }
}
