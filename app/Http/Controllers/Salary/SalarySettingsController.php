<?php

namespace App\Http\Controllers\Salary;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\SalaryComponent;
use App\Models\SalaryNotificationRule;
use App\Models\SalaryStatutorySetting;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SalarySettingsController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        $this->authorize('manage-salary-settings');

        $accountId = $request->user()->account_id ?? 1;

        $statutory = SalaryStatutorySetting::forAccount($accountId);

        return Inertia::render('Salary/Settings/Index', [
            'components' => SalaryComponent::forAccount($accountId),
            'roles' => Role::orderBy('name')->pluck('name'),
            'statutorySettings' => [
                'is_enabled' => $statutory->is_enabled,
                'paye_bands' => $statutory->paye_bands,
                'nssf_employee_rate' => $statutory->nssf_employee_rate,
                'nssf_employer_rate' => $statutory->nssf_employer_rate,
                'wcf_rate' => $statutory->wcf_rate,
                'sdl_rate' => $statutory->sdl_rate,
            ],
        ]);
    }

    public function storeComponent(Request $request)
    {
        $this->authorize('manage-salary-settings');

        $data = $request->validate([
            'key' => 'required|string|max:60|alpha_dash',
            'label' => 'required|string|max:190',
            'type' => 'required|in:earning,deduction',
        ]);

        $accountId = $request->user()->account_id ?? 1;

        $component = SalaryComponent::create($data + ['account_id' => $accountId, 'is_system' => false, 'is_active' => true, 'sort_order' => SalaryComponent::where('account_id', $accountId)->max('sort_order') + 1]);

        log_activity(__('Salary component added.'), ['key' => $component->key, 'label' => $component->label], $component, 'Salary Settings');

        \App\Services\SalaryNotifier::fire('settings_changed', null, $request->user(), __('Salary Settings Changed'), 'Salary component added by ' . $request->user()->name . '.');

        return back()->with('message', __('Salary component added.'));
    }

    public function updateComponent(Request $request, $id)
    {
        $this->authorize('manage-salary-settings');

        $component = SalaryComponent::findOrFail($id);
        abort_if($component->is_system, 422, 'A system component cannot be edited.');

        $component->update($request->validate([
            'label' => 'required|string|max:190',
            'is_active' => 'required|boolean',
        ]));

        log_activity(__('Salary component updated.'), ['key' => $component->key, 'label' => $component->label], $component, 'Salary Settings');

        \App\Services\SalaryNotifier::fire('settings_changed', null, $request->user(), __('Salary Settings Changed'), 'Salary component updated by ' . $request->user()->name . '.');

        return back()->with('message', __('Salary component updated.'));
    }

    public function destroyComponent(Request $request, $id)
    {
        $this->authorize('manage-salary-settings');

        $component = SalaryComponent::findOrFail($id);
        abort_if($component->is_system, 422, 'A system component cannot be deleted.');

        $component->delete();

        log_activity(__('Salary component removed.'), ['key' => $component->key, 'label' => $component->label], $component, 'Salary Settings');

        \App\Services\SalaryNotifier::fire('settings_changed', null, $request->user(), __('Salary Settings Changed'), 'Salary component removed by ' . $request->user()->name . '.');

        return back()->with('message', __('Salary component removed.'));
    }

    /** Rates are softcoded here (never hardcoded in the calculator) because tax law changes independently of this app — verify against the current Finance Act before relying on them. */
    public function updateStatutorySettings(Request $request)
    {
        $this->authorize('manage-salary-settings');

        $data = $request->validate([
            'is_enabled' => 'required|boolean',
            'paye_bands' => 'required|array|min:1',
            'paye_bands.*.up_to' => 'nullable|numeric|min:0',
            'paye_bands.*.rate' => 'required|numeric|min:0|max:1',
            'nssf_employee_rate' => 'required|numeric|min:0|max:1',
            'nssf_employer_rate' => 'required|numeric|min:0|max:1',
            'wcf_rate' => 'required|numeric|min:0|max:1',
            'sdl_rate' => 'required|numeric|min:0|max:1',
        ]);

        $accountId = $request->user()->account_id ?? 1;

        $settings = SalaryStatutorySetting::updateOrCreate(
            ['account_id' => $accountId],
            $data + ['updated_by_user_id' => $request->user()->id]
        );

        log_activity(__('Salary statutory settings updated.'), ['is_enabled' => $data['is_enabled']], $settings, 'Salary Settings');

        \App\Services\SalaryNotifier::fire('settings_changed', null, $request->user(), __('Salary Settings Changed'), 'Statutory settings updated by ' . $request->user()->name . '.');

        return back()->with('message', __('Statutory settings updated.'));
    }
}
