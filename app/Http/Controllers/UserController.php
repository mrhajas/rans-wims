<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use Inertia\Inertia;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use App\Http\Requests\UserRequest;
use App\Services\UserNotifier;
use App\Http\Resources\UserResource;
use App\Http\Resources\UserCollection;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    /**
     * Mirrors RoleController's "Super Admin role can not be modified" guard
     * (app/Http/Controllers/RoleController.php) — applied here because this
     * controller is the OTHER place that role is reachable. Without it,
     * create-users/update-users (ordinary, grantable permissions) let any
     * holder mint a brand-new Super Admin account or edit an existing one.
     */
    private function selectableRoles()
    {
        return Role::ofAccount()
            ->when(! auth()->user()->hasRole('Super Admin'), fn ($q) => $q->where('name', '!=', 'Super Admin'))
            ->get();
    }

    /** Strips 'Super Admin' from a submitted roles list unless the acting user already holds it themselves. */
    private function guardedRoles(array $roles): array
    {
        if (auth()->user()->hasRole('Super Admin')) {
            return $roles;
        }

        return array_values(array_diff($roles, ['Super Admin']));
    }

    /** Only Super Admin may act on another Super Admin's account at all — not just role assignment, but disabling/deleting it too. */
    private function blockedFromActingOnSuperAdmin(User $target): bool
    {
        return $target->hasRole('Super Admin') && ! auth()->user()->hasRole('Super Admin');
    }

    public function create()
    {
        return Inertia::render('User/Form', [
            'passwordHint' => \App\Support\PasswordPolicy::hint(),
            'roles'      => $this->selectableRoles(),
            'warehouses' => Warehouse::ofAccount()->active()->visible()->get(['id', 'name', 'status', 'completed_at']),
        ]);
    }

    public function destroy(User $user)
    {
        if ($user->id == auth()->id()) {
            return back()->with('error', __('You should not delete your own account.'));
        }

        if ($this->blockedFromActingOnSuperAdmin($user)) {
            return back()->with('error', __('Only a Super Admin can delete a Super Admin account.'));
        }

        if ($user->del()) {
            UserNotifier::fire('deleted', $user, __('User Deleted'), $user->name . ' (' . $user->username . ') was deleted.');

            return redirect()->route('users.index')->with('message', __choice('action_text', ['record' => 'User', 'action' => 'deleted']));
        }

        return back()->with('error', __('The record can not be deleted.'));
    }

    public function destroyPermanently(User $user)
    {
        if ($user->id == auth()->id()) {
            return back()->with('error', __('You should not delete your own account.'));
        }

        if ($this->blockedFromActingOnSuperAdmin($user)) {
            return back()->with('error', __('Only a Super Admin can delete a Super Admin account.'));
        }

        if ($user->delP()) {
            UserNotifier::fire('deleted', $user, __('User Permanently Deleted'), $user->name . ' (' . $user->username . ') was permanently deleted.');

            return redirect()->route('users.index')->with('message', __choice('action_text', ['record' => 'User', 'action' => 'deleted']));
        }

        return back()->with('error', __('The record can not be deleted.'));
    }

    public function disable2FA(User $user)
    {
        if ($this->blockedFromActingOnSuperAdmin($user)) {
            return back()->with('error', __('Only a Super Admin can disable 2FA on a Super Admin account.'));
        }

        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_email_enabled_at' => null, 'two_factor_sms_enabled_at' => null, 'two_factor_phone' => null])->save();
        UserNotifier::fire('two_factor_disabled', $user, __('Two-Factor Switched Off'), 'Two-factor sign-in was switched off for ' . $user->name . ' (' . $user->username . ') by ' . (auth()->user()?->name ?? 'an administrator') . '.');

        return back()->with('message', __choice('action_text', ['record' => 'Tow factor authentication', 'action' => 'disabled']));
    }

    /**
     * Reset Password (Users page): makes a new password for the chosen user and shows it to the person who asked, once. Needs the "reset-password-users"
     * permission (Super Admin has it; assign it to others on the Roles screen). Only a Super Admin can reset a Super Admin's password.
     */
    public function resetPassword(Request $request)
    {
        abort_unless($request->user()?->can('reset-password-users'), 403, __('You are not allowed to reset passwords.'));

        $data = $request->validate(['user_id' => 'required|integer']);
        $target = User::ofAccount()->findOrFail($data['user_id']);

        if ($this->blockedFromActingOnSuperAdmin($target)) {
            return response()->json(['message' => __('Only a Super Admin can reset the password of a Super Admin.')], 403);
        }
        if (demo() && $target->id == 1) {
            return response()->json(['message' => 'This feature is disabled on demo'], 403);
        }

        $password = \App\Support\PasswordPolicy::generate();
        $target->forceFill(['password' => Hash::make($password)])->save();
        UserNotifier::fire('password_set_by_admin', $target, __('Password Set by an Administrator'), 'A new password was generated for ' . $target->name . ' (' . $target->username . ') by ' . ($request->user()->name ?? 'an administrator') . '.');

        return response()->json(['password' => $password, 'user' => ['id' => $target->id, 'name' => $target->name, 'username' => $target->username]])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    public function disableAccount(Request $request, User $user)
    {
        if ($user->id == auth()->id()) {
            return back()->with('error', __('You should not disable your own account.'));
        }
        if (demo() && $user->id == 1) {
            return back()->with('error', 'This feature is disabled on demo');
        }

        if ($this->blockedFromActingOnSuperAdmin($user)) {
            return back()->with('error', __('Only a Super Admin can disable a Super Admin account.'));
        }

        $request->validate(['password' => 'required|string']);

        if (! Hash::check($request->input('password'), auth()->user()->password)) {
            return back()->withErrors(['password' => __('The password you entered is incorrect.')]);
        }

        $user->forceFill([
            'is_active' => false,
            'disabled_at' => now(),
            'disabled_by' => auth()->id(),
        ])->save();
        UserNotifier::fire('disabled', $user, __('User Disabled'), $user->name . ' (' . $user->username . ') was disabled.');

        return back()->with('message', __('User account disabled. This user can no longer log in, but their data remains intact.'));
    }

    public function enableAccount(Request $request, User $user)
    {
        if ($this->blockedFromActingOnSuperAdmin($user)) {
            return back()->with('error', __('Only a Super Admin can enable a Super Admin account.'));
        }

        $request->validate(['password' => 'required|string']);

        if (! Hash::check($request->input('password'), auth()->user()->password)) {
            return back()->withErrors(['password' => __('The password you entered is incorrect.')]);
        }

        $user->forceFill([
            'is_active' => true,
            'disabled_at' => null,
            'disabled_by' => null,
        ])->save();
        UserNotifier::fire('enabled', $user, __('User Re-enabled'), $user->name . ' (' . $user->username . ') was re-enabled.');

        return back()->with('message', __('User account re-enabled.'));
    }

    public function edit(User $user)
    {
        if ($this->blockedFromActingOnSuperAdmin($user)) {
            return redirect()->route('users.index')->with('error', __('Only a Super Admin can edit a Super Admin account.'));
        }

        return Inertia::render('User/Form', [
            'passwordHint' => \App\Support\PasswordPolicy::hint(),
            'edit'       => new UserResource($user),
            'roles'      => $this->selectableRoles(),
            'warehouses' => Warehouse::ofAccount()->active()->visible()->get(['id', 'name', 'status', 'completed_at']),
            'staffLink'  => UserStaffLinkController::payloadFor($user),
            'faceEnrolled' => auth()->user()?->hasRole('Super Admin') && \App\Services\Face\FaceService::enrolled($user),
        ]);
    }

    public function index(Request $request)
    {
        $filters = $request->all('search', 'role', 'trashed', 'status');

        // A user is "protected" when any second step is on: authenticator app, Email code or SMS code.
        $protected = fn ($q) => $q->whereNotNull('two_factor_secret')
            ->orWhereNotNull('two_factor_email_enabled_at')
            ->orWhereNotNull('two_factor_sms_enabled_at');

        $users = User::ofAccount()
            ->with(['roles:id,name', 'warehouses:id,name', 'staff:id,user_id,staff_number'])
            ->orderBy('name')
            ->filter($filters)
            ->when($filters['status'] ?? null, function ($query, $status) use ($protected) {
                match ($status) {
                    'active' => $query->where('is_active', true),
                    'disabled' => $query->where('is_active', false),
                    'protected' => $query->where($protected),
                    'unprotected' => $query->whereNot($protected),
                    'online' => $query->where('last_seen_at', '>=', now()->subMinutes(5)),
                    default => null,
                };
            })
            ->paginate();

        // Account-wide figures for the summary cards (live users only, unaffected by the filters above).
        $base = fn () => User::ofAccount();

        $canReset = (bool) auth()->user()?->can('reset-password-users');

        return Inertia::render('User/Index', [
            'canResetPassword' => $canReset,
            // the people the Reset Password window lets you pick (only sent to someone who may use it)
            'resetUsers' => $canReset ? User::ofAccount()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'username'])->map(fn ($u) => ['id' => $u->id, 'label' => $u->name . ' (@' . $u->username . ')'])->values() : [],
            'filters' => $filters,
            'roles'   => Role::ofAccount()->get(),
            'users'   => new UserCollection($users),
            'stats'   => [
                'total'       => $base()->count(),
                'active'      => $base()->where('is_active', true)->count(),
                'disabled'    => $base()->where('is_active', false)->count(),
                'protected'   => $base()->where($protected)->count(),
                'online'      => $base()->where('last_seen_at', '>=', now()->subMinutes(5))->count(),
            ],
        ]);
    }

    public function restore(User $user)
    {
        $user->restore();
        UserNotifier::fire('restored', $user, __('User Restored'), $user->name . ' (' . $user->username . ') was restored by ' . (auth()->user()?->name ?? 'an administrator') . '.');

        return back()->with('message', __choice('action_text', ['record' => 'User', 'action' => 'restored']));
    }

    public function store(UserRequest $request)
    {
        $data = $request->validated();
        $phone = $data['phone'] ?? null;
        unset($data['phone']);

        $extraAttributes = (array) ($data['extra_attributes'] ?? []);
        if ($phone !== null && $phone !== '') {
            $extraAttributes['phone'] = $phone;
        }
        $data['extra_attributes'] = empty($extraAttributes) ? null : $extraAttributes;

        $user = User::create($data);
        $user->assignRole($this->guardedRoles($request->input('roles')));
        $user->warehouses()->sync($request->input('warehouses', []));
        \App\Support\AllWarehouseRoles::apply($user); // a role with "All warehouses" means every warehouse
        \App\Support\UserAudit::roles($user, [], $user->fresh()->getRoleNames()->all());
        \App\Support\UserAudit::warehouses($user, [], $user->warehouses()->withoutGlobalScopes()->pluck('warehouses.name')->all());
        UserNotifier::fire('created', $user, __('User Created'), $user->name . ' (' . $user->username . ') was created.');

        return redirect()->route('users.index')->with('message', __choice('action_text', ['record' => 'User', 'action' => 'created']));
    }

    public function update(UserRequest $request, User $user)
    {
        $self = $user->id == auth()->id();
        if ($self && ! \App\Services\Licensing\LicenceService::isManager(auth()->user())) {
            return back()->with('error', __('You should not update your own account.'));
        }
        if (demo() && $user->id == 1) {
            return back()->with('error', 'This feature is disabled on demo');
        }

        if ($this->blockedFromActingOnSuperAdmin($user)) {
            return back()->with('error', __('Only a Super Admin can update a Super Admin account.'));
        }

        $data = $request->validated();
        $phone = $data['phone'] ?? null;
        unset($data['phone']);

        $extraAttributes = (array) ($user->extra_attributes ?? []);
        if ($phone !== null && $phone !== '') {
            $extraAttributes['phone'] = $phone;
        } else {
            unset($extraAttributes['phone']);
        }
        $data['extra_attributes'] = empty($extraAttributes) ? null : $extraAttributes;

        $rolesBefore = $user->getRoleNames()->all();
        $warehousesBefore = $user->warehouses()->withoutGlobalScopes()->pluck('warehouses.name')->all();
        $user->update($data);
        $roles = $this->guardedRoles($request->input('roles'));
        if ($self && ! in_array('Super Admin', $roles, true)) {
            $roles[] = 'Super Admin'; // Dr Hajas edits himself but can never lock himself out of the system
        }
        $user->syncRoles($roles);
        $user->warehouses()->sync($request->input('warehouses', []));
        \App\Support\AllWarehouseRoles::apply($user); // a role with "All warehouses" means every warehouse
        \App\Support\UserAudit::roles($user, $rolesBefore, $user->fresh()->getRoleNames()->all());
        \App\Support\UserAudit::warehouses($user, $warehousesBefore, $user->warehouses()->withoutGlobalScopes()->pluck('warehouses.name')->all());
        UserNotifier::fire('updated', $user, __('User Edited'), $user->name . ' (' . $user->username . ') was edited.');
        if ($request->filled('password')) {
            UserNotifier::fire('password_set_by_admin', $user, __('Password Set by an Administrator'), 'A new password was set for ' . $user->name . ' (' . $user->username . ') by ' . (auth()->user()?->name ?? 'an administrator') . '.');
        }

        return back()->with('message', __choice('action_text', ['record' => 'User', 'action' => 'updated']));
    }
}
