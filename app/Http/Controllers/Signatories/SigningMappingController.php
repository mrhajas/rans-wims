<?php

namespace App\Http\Controllers\Signatories;

use App\Models\SigningMapping;
use App\Models\SigningRole;
use App\Services\Signatories\MappingSeeder;
use App\Services\Signatories\SignatureResolver;
use App\Services\Workflow\WorkflowMessenger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** Document Setup: which signing roles each document carries, which recorded workflow action qualifies the signer, and what happens when a signature is missing. */
class SigningMappingController extends SignatoryBaseController
{
    public function index(Request $request)
    {
        $user = $this->allow($request, 'manage-signing-mappings', 'signatories_mappings');
        $acc = (int) $user->account_id;
        $rows = SigningMapping::query()->with('role')->where('account_id', $acc)->orderBy('document_type')->orderBy('slot')->get()->groupBy('document_type');
        $documents = collect(SignatureResolver::adapters())->map(fn ($class, $type) => [
            'type' => $type, 'label' => $class::label(), 'actions' => collect($class::actions())->map(fn ($l, $k) => ['value' => $k, 'label' => $l])->values(),
            'rows' => ($rows[$type] ?? collect())->map(fn ($m) => [
                'id' => $m->id, 'signing_role_id' => $m->signing_role_id, 'role' => optional($m->role)->label, 'workflow_action' => $m->workflow_action, 'slot' => $m->slot, 'is_required' => $m->is_required,
                'show_when' => $m->show_when, 'blocks_final' => $m->blocks_final, 'missing_behaviour' => $m->missing_behaviour, 'permission' => $m->permission, 'is_active' => $m->is_active,
            ])->values(),
        ])->values();

        return Inertia::render('Signatories/Mappings', [
            'documents' => $documents,
            'roles' => SigningRole::query()->where('account_id', $acc)->where('is_active', true)->orderBy('sort_order')->get(['id', 'label'])->map(fn ($r) => ['value' => $r->id, 'label' => $r->label])->values(),
            'focus' => (string) $request->query('document', ''),
        ]);
    }

    public function store(Request $request)
    {
        $user = $this->allow($request, 'manage-signing-mappings', 'signatories_mappings');
        $data = $this->validated($request, $user);
        if (SigningMapping::query()->where('account_id', $user->account_id)->where('document_type', $data['document_type'])->where('signing_role_id', $data['signing_role_id'])->where('workflow_action', $data['workflow_action'])->exists()) {
            return back()->withErrors(['signing_role_id' => __('This role is already set up for that action on this document.')]);
        }
        $m = SigningMapping::create($data + ['account_id' => $user->account_id]);
        $this->changed($user, 'Document signing setup added', $m);

        return back()->with('message', __('Added.'));
    }

    public function update(Request $request, int $mapping)
    {
        $user = $this->allow($request, 'manage-signing-mappings', 'signatories_mappings');
        $m = SigningMapping::query()->where('account_id', $user->account_id)->findOrFail($mapping);
        $data = $this->validated($request, $user, $m->document_type);
        $before = $m->only(['signing_role_id', 'workflow_action', 'slot', 'is_required', 'show_when', 'blocks_final', 'missing_behaviour', 'permission', 'is_active']);
        $m->update($data);
        log_activity('Document signing setup changed', ['document' => $m->document_type, 'before' => $before, 'after' => $m->only(array_keys($before))], null, 'Signatories');
        $this->changed($user, null, $m);

        return back()->with('message', __('Saved.'));
    }

    public function destroy(Request $request, int $mapping)
    {
        $user = $this->allow($request, 'manage-signing-mappings', 'signatories_mappings');
        $m = SigningMapping::query()->where('account_id', $user->account_id)->findOrFail($mapping);
        $m->delete();
        $this->changed($user, 'Document signing setup removed', $m);

        return back()->with('message', __('Removed.'));
    }

    /** Back to the starting setup of one document. */
    public function reset(Request $request, string $type)
    {
        $user = $this->allow($request, 'manage-signing-mappings', 'signatories_mappings');
        abort_unless(isset(SignatureResolver::adapters()[$type]), 404);
        $this->reauth($request, $user);
        $n = app(MappingSeeder::class)->seed((int) $user->account_id, $type, true);
        log_activity('Document signing setup restored to the starting values', ['document' => $type, 'rows' => $n], null, 'Signatories');
        $this->notify($user);

        return back()->with('message', __('Restored the starting setup.'));
    }

    private function validated(Request $request, $user, ?string $fixedType = null): array
    {
        $types = array_keys(SignatureResolver::adapters());
        $data = $request->validate([
            'document_type' => $fixedType ? ['nullable'] : ['required', Rule::in($types)],
            'signing_role_id' => ['required', 'integer', Rule::exists('signing_roles', 'id')->where('account_id', $user->account_id)],
            'workflow_action' => ['required', 'string', 'max:60'],
            'slot' => ['required', 'integer', 'between:1,12'], 'is_required' => ['required', 'boolean'], 'show_when' => ['required', 'in:always,after,final'],
            'blocks_final' => ['required', 'boolean'], 'missing_behaviour' => ['required', 'in:blank,flag,block'],
            'permission' => ['nullable', 'string', 'max:80', 'exists:permissions,name'], 'is_active' => ['required', 'boolean'],
        ]);
        $type = $fixedType ?: $data['document_type'];
        $class = SignatureResolver::adapters()[$type];
        abort_unless(isset($class::actions()[$data['workflow_action']]), 422, __('That action does not exist for this document.'));
        $data['document_type'] = $type;
        if ($data['blocks_final'] && ! $data['is_required']) {
            $data['blocks_final'] = false; // only a required signature can stop finalising
        }

        return $data;
    }

    private function changed($user, ?string $log, SigningMapping $m): void
    {
        if ($log) {
            log_activity($log, ['document' => $m->document_type, 'role_id' => $m->signing_role_id, 'action' => $m->workflow_action], null, 'Signatories');
        }
        $this->notify($user);
    }

    private function notify($user): void
    {
        WorkflowMessenger::dispatch('signatories', 'setup_changed', null, $user, ['account_id' => $user->account_id, 'title' => __('The signing setup changed'), 'message' => __(':n changed the signing setup.', ['n' => $user->name]), 'url' => route('signatories.mappings.index'), 'dedupe' => 'signatories|setup|' . intdiv(time(), 30)]);
    }
}
