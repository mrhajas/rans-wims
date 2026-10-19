<?php

namespace App\Http\Controllers\Signatories;

use App\Models\SigningMapping;
use App\Models\SigningRole;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

/** Signing roles (Prepared By, Approved By ...): rename, order, switch off, add. A role that a document already uses cannot be deleted, only switched off. */
class SigningRoleController extends SignatoryBaseController
{
    public function index(Request $request)
    {
        $user = $this->allow($request, 'manage-signing-roles', 'signatories_roles');
        $used = SigningMapping::query()->where('account_id', $user->account_id)->selectRaw('signing_role_id, count(*) c')->groupBy('signing_role_id')->pluck('c', 'signing_role_id');

        return Inertia::render('Signatories/Roles', [
            'roles' => SigningRole::query()->where('account_id', $user->account_id)->orderBy('sort_order')->get()->map(fn ($r) => ['id' => $r->id, 'key' => $r->key, 'label' => $r->label, 'description' => $r->description, 'sort_order' => $r->sort_order, 'is_active' => $r->is_active, 'used' => (int) ($used[$r->id] ?? 0)])->values(),
        ]);
    }

    public function store(Request $request)
    {
        $user = $this->allow($request, 'manage-signing-roles', 'signatories_roles');
        $data = $request->validate(['label' => ['required', 'string', 'max:60'], 'description' => ['nullable', 'string', 'max:255']]);
        $key = Str::slug($data['label'], '_');
        abort_if($key === '', 422);
        if (SigningRole::query()->where('account_id', $user->account_id)->where('key', $key)->exists()) {
            return back()->withErrors(['label' => __('A signing role with this name already exists.')]);
        }
        $role = SigningRole::create(['account_id' => $user->account_id, 'key' => $key, 'label' => $data['label'], 'description' => $data['description'] ?? null, 'sort_order' => (int) SigningRole::query()->where('account_id', $user->account_id)->max('sort_order') + 10, 'is_active' => true]);
        log_activity('Signing role added', ['role' => $role->label], null, 'Signatories');

        return back()->with('message', __('Signing role added.'));
    }

    public function update(Request $request, int $role)
    {
        $user = $this->allow($request, 'manage-signing-roles', 'signatories_roles');
        $r = SigningRole::query()->where('account_id', $user->account_id)->findOrFail($role);
        $data = $request->validate(['label' => ['required', 'string', 'max:60'], 'description' => ['nullable', 'string', 'max:255'], 'sort_order' => ['required', 'integer', 'between:0,9999'], 'is_active' => ['required', 'boolean']]);
        $before = $r->only(['label', 'description', 'sort_order', 'is_active']);
        $r->update($data);
        log_activity('Signing role changed', ['role' => $r->key, 'before' => $before, 'after' => $r->only(['label', 'description', 'sort_order', 'is_active'])], null, 'Signatories');

        return back()->with('message', __('Saved.'));
    }

    public function destroy(Request $request, int $role)
    {
        $user = $this->allow($request, 'manage-signing-roles', 'signatories_roles');
        $r = SigningRole::query()->where('account_id', $user->account_id)->findOrFail($role);
        if (SigningMapping::query()->where('signing_role_id', $r->id)->exists() || \Illuminate\Support\Facades\DB::table('signature_snapshots')->where('account_id', $user->account_id)->where('signing_role_key', $r->key)->exists()) {
            return back()->withErrors(['label' => __('This role is used by a document setup or by a signed document, so it cannot be deleted. Switch it off instead.')]);
        }
        $r->delete();
        log_activity('Signing role deleted', ['role' => $r->key], null, 'Signatories');

        return back()->with('message', __('Deleted.'));
    }
}
