<?php

namespace App\Http\Controllers\Simulation;

use App\Http\Controllers\Controller;
use App\Services\Simulation\SimulationAccounts;
use App\Support\PasswordPolicy;
use App\Support\PermissionRegistry;
use App\Support\Simulation\Environment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;

/**
 * Production Super Admins manage the people who may sign in to Simulation WIMS (Category Authority accounts). Production side only: Simulation WIMS itself
 * never reaches this page. Passwords are hashed straight into the simulation database; nothing here ever shows or returns one.
 */
class SimulationAccountsController extends Controller
{
    public function __construct(private SimulationAccounts $accounts)
    {
        $this->setAbilities([]);
    }

    private function guard(): void
    {
        $u = auth()->user();
        abort_unless($u && ! Environment::isSimulation() && ($u->hasRole('Super Admin') || $u->can('manage-simulation-accounts')), 403);
    }

    private function ready(): bool
    {
        return Environment::available();
    }

    public function index(Request $request)
    {
        $this->guard();
        $ready = $this->ready();
        $never = (array) config('simulation.never_permissions');
        $modules = collect(PermissionRegistry::modules())->map(fn ($m, $k) => ['key' => $k, 'label' => (string) ($m['label'] ?? $k), 'abilities' => collect($m['abilities'] ?? [])->except($never)->map(fn ($l, $n) => ['name' => $n, 'label' => (string) $l])->values()->all()])
            ->filter(fn ($m) => count($m['abilities']))->values();
        $data = ['ready' => $ready, 'enabled' => (bool) config('simulation.enabled'), 'accounts' => [], 'activity' => [], 'logins' => [], 'modules' => $modules, 'hint' => PasswordPolicy::hint(), 'role' => (string) config('simulation.role'),
            'alertOnly' => (array) config('simulation.alert_only_usernames'), 'workflow_url' => route('simulation.workflow')];
        if ($ready) {
            try {
                $data['accounts'] = $this->accounts->all();
                $data['activity'] = $this->accounts->activity(60);
                $data['logins'] = $this->accounts->logins(60);
            } catch (\Throwable $e) {
                $data['ready'] = false;
                $data['error'] = __('The simulation database cannot be reached. Ask the person who looks after the server.');
                \Log::error('Simulation accounts page', ['error' => $e->getMessage()]);
            }
        }

        return Inertia::render('Simulation/Accounts', $data);
    }

    public function store(Request $request)
    {
        $this->guard();
        abort_unless($this->ready(), 404);
        $d = $request->validate(['username' => ['required', 'regex:/^[A-Za-z0-9._-]{3,40}$/'], 'name' => 'required|string|max:120', 'password' => ['required', PasswordPolicy::rule()], 'permissions' => 'nullable|array', 'permissions.*' => 'string|max:120']);
        $this->accounts->create($d, $request->user()->id);

        return back()->with('message', __('Simulation account created.'));
    }

    public function update(Request $request, int $id)
    {
        $this->guard();
        abort_unless($this->ready(), 404);
        $d = $request->validate(['username' => ['required', 'regex:/^[A-Za-z0-9._-]{3,40}$/'], 'name' => 'required|string|max:120', 'password' => ['nullable', PasswordPolicy::rule()], 'permissions' => 'nullable|array', 'permissions.*' => 'string|max:120']);
        $this->exists($id);
        $this->accounts->update($id, $d, $request->user()->id);

        return back()->with('message', __('Simulation account saved.'));
    }

    public function access(Request $request, int $id)
    {
        $this->guard();
        abort_unless($this->ready(), 404);
        $d = $request->validate(['permissions' => 'nullable|array', 'permissions.*' => 'string|max:120']);
        $this->exists($id);
        $this->accounts->setAccess($id, $d['permissions'] ?? null);
        log_activity('Simulation account access changed', ['user' => $id, 'custom' => ! empty($d['permissions'])], null, 'Simulation');

        return back()->with('message', __('Access saved.'));
    }

    public function active(Request $request, int $id)
    {
        $this->guard();
        abort_unless($this->ready(), 404);
        $d = $request->validate(['active' => 'required|boolean']);
        $this->exists($id);
        $this->accounts->setActive($id, (bool) $d['active'], $request->user()->id);

        return back()->with('message', $d['active'] ? __('Account activated.') : __('Account deactivated and signed out.'));
    }

    public function password(Request $request, int $id)
    {
        $this->guard();
        abort_unless($this->ready(), 404);
        $d = $request->validate(['password' => ['required', PasswordPolicy::rule()]]);
        $this->exists($id);
        $this->accounts->resetPassword($id, $d['password'], $request->user()->id);

        return back()->with('message', __('Password changed and the person was signed out.'));
    }

    public function revoke(Request $request, int $id)
    {
        $this->guard();
        abort_unless($this->ready(), 404);
        $this->exists($id);
        $this->accounts->revoke($id, $request->user()->id);

        return back()->with('message', __('Access revoked.'));
    }

    private function exists(int $id): void
    {
        abort_unless(collect($this->accounts->all())->contains('id', $id), 404);
    }
}
